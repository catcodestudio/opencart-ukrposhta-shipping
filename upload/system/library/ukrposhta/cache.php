<?php
namespace Opencart\System\Library\Ukrposhta;

require_once __DIR__ . '/client.php';

/**
 * Cache-first lookups over the Ukrposhta Address Classifier:
 *   - regions  : synced once (≈25 rows), refreshed weekly.
 *   - cities   : searched live per region, cached incrementally.
 *   - offices  : lazy per city, 7-day TTL (mirrors NP warehouse caching).
 */
class Cache {
	private const OFFICE_TTL_DAYS = 7;

	public static function getRegions($db, Client $client): array {
		$rows = $db->query("SELECT region_id, region_ua FROM `" . DB_PREFIX . "up_regions` ORDER BY region_ua")->rows;
		if ($rows) {
			return array_map(fn($r) => ['id' => (string)$r['region_id'], 'name' => (string)$r['region_ua']], $rows);
		}
		return self::syncRegions($db, $client);
	}

	public static function syncRegions($db, Client $client): array {
		$resp = $client->getRegions('');
		$entries = Client::entries($resp);
		$out = [];
		foreach ($entries as $e) {
			$id   = (string)($e['REGION_ID'] ?? '');
			$name = (string)($e['REGION_UA'] ?? '');
			if ($id === '' || $name === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "up_regions` SET region_id = '" . $db->escape($id) . "', region_ua = '" . $db->escape($name) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE region_ua = VALUES(region_ua), updated_at = VALUES(updated_at)");
			$out[] = ['id' => $id, 'name' => $name];
		}
		usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
		return $out;
	}

	public static function searchCities($db, Client $client, string $regionId, string $query): array {
		$q = trim($query);
		if ($regionId === '' || mb_strlen($q) < 2) {
			return [];
		}
		// Cache-first within the region. Each escape() result must sit inside its
		// own quotes with nothing else: on the PDO driver escape() returns a bound
		// placeholder (":0"), so a wildcard appended inside the quotes breaks the
		// SQL. Wildcards go through escape() as part of the raw value — never
		// escape an already-escaped string.
		$lower = mb_strtolower($q);
		$rows = $db->query("SELECT city_id, district_id, city_ua FROM `" . DB_PREFIX . "up_cities` WHERE region_id = '" . $db->escape($regionId) . "' AND (LOWER(city_ua) LIKE '" . $db->escape($lower . '%') . "' OR LOWER(city_ua) LIKE '" . $db->escape('%' . $lower . '%') . "') ORDER BY (LOWER(city_ua) = '" . $db->escape($lower) . "') DESC, CHAR_LENGTH(city_ua) ASC LIMIT 20")->rows;
		if ($rows) {
			return array_map(fn($r) => [
				'id'          => (string)$r['city_id'],
				'district_id' => (string)$r['district_id'],
				'name'        => (string)$r['city_ua'],
			], $rows);
		}
		// Live fetch + incremental cache.
		$resp = $client->getCities($regionId, $q);
		$entries = Client::entries($resp);
		$out = [];
		foreach ($entries as $e) {
			$cid  = (string)($e['CITY_ID'] ?? '');
			$did  = (string)($e['DISTRICT_ID'] ?? '');
			$name = (string)($e['CITY_UA'] ?? '');
			if ($cid === '' || $name === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "up_cities` SET city_id = '" . $db->escape($cid) . "', region_id = '" . $db->escape($regionId) . "', district_id = '" . $db->escape($did) . "', city_ua = '" . $db->escape($name) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE region_id = VALUES(region_id), district_id = VALUES(district_id), city_ua = VALUES(city_ua), updated_at = VALUES(updated_at)");
			$out[] = ['id' => $cid, 'district_id' => $did, 'name' => $name];
		}
		return $out;
	}

	public static function getOffices($db, Client $client, string $cityId, string $districtId = '', string $regionId = ''): array {
		$fresh = $db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "up_offices` WHERE city_id = '" . $db->escape($cityId) . "' AND updated_at > DATE_SUB(NOW(), INTERVAL " . self::OFFICE_TTL_DAYS . " DAY)")->row;
		if (!empty($fresh) && (int)$fresh['cnt'] > 0) {
			return self::rowsToOffices($db->query("SELECT postindex, name, address, is_postomat FROM `" . DB_PREFIX . "up_offices` WHERE city_id = '" . $db->escape($cityId) . "' ORDER BY is_postomat ASC, CAST(postindex AS UNSIGNED)")->rows);
		}
		$resp = $client->getPostOfficesByCity($cityId, $districtId, $regionId);
		$entries = Client::entries($resp);
		if (!$entries) {
			// Return whatever stale cache we have rather than nothing.
			return self::rowsToOffices($db->query("SELECT postindex, name, address, is_postomat FROM `" . DB_PREFIX . "up_offices` WHERE city_id = '" . $db->escape($cityId) . "' ORDER BY is_postomat ASC, CAST(postindex AS UNSIGNED)")->rows);
		}
		$db->query("DELETE FROM `" . DB_PREFIX . "up_offices` WHERE city_id = '" . $db->escape($cityId) . "'");
		$out = [];
		foreach ($entries as $e) {
			$pi   = (string)($e['POSTINDEX'] ?? '');
			$name = (string)($e['PO_SHORT'] ?? ($e['PO_LONG'] ?? ''));
			$addr = (string)($e['ADDRESS'] ?? '');
			$term = ((string)($e['POSTTERMINAL'] ?? '0')) === '1' ? 1 : 0;
			if ($pi === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "up_offices` SET city_id = '" . $db->escape($cityId) . "', postindex = '" . $db->escape($pi) . "', name = '" . $db->escape($name) . "', address = '" . $db->escape($addr) . "', is_postomat = " . $term . ", updated_at = NOW() ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), is_postomat = VALUES(is_postomat), updated_at = VALUES(updated_at)");
			$out[] = ['postindex' => $pi, 'name' => $name, 'address' => $addr, 'is_postomat' => $term];
		}
		usort($out, fn($a, $b) => ($a['is_postomat'] <=> $b['is_postomat']) ?: ((int)$a['postindex'] <=> (int)$b['postindex']));
		return $out;
	}

	private static function rowsToOffices(array $rows): array {
		return array_map(fn($r) => [
			'postindex'   => (string)$r['postindex'],
			'name'        => (string)$r['name'],
			'address'     => (string)$r['address'],
			'is_postomat' => (int)$r['is_postomat'],
		], $rows);
	}
}
