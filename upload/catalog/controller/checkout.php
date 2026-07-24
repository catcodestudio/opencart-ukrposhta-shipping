<?php
namespace Opencart\Catalog\Controller\Extension\Ukrposhta;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/cache.php';

class Checkout extends \Opencart\System\Engine\Controller {
	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function client(): ?\Opencart\System\Library\Ukrposhta\Client {
		$rawB = (string)$this->config->get('shipping_ukrposhta_bearer');
		if ($rawB === '') return null;
		$bearer  = \Opencart\System\Library\Ukrposhta\Crypto::decrypt($rawB);
		$sandbox = (bool)$this->config->get('shipping_ukrposhta_sandbox');
		// Read-only build: classifier authorizes with the Bearer alone (no token).
		return new \Opencart\System\Library\Ukrposhta\Client($bearer, '', $sandbox);
	}

	public function regions(): void {
		$client = $this->client();
		$this->jsonResponse(['regions' => $client ? \Opencart\System\Library\Ukrposhta\Cache::getRegions($this->db, $client) : []]);
	}

	public function searchCities(): void {
		$regionId = trim((string)($this->request->post['region_id'] ?? ''));
		$query    = trim((string)($this->request->post['q'] ?? ''));
		$client   = $this->client();
		$this->jsonResponse(['cities' => $client ? \Opencart\System\Library\Ukrposhta\Cache::searchCities($this->db, $client, $regionId, $query) : []]);
	}

	public function getOffices(): void {
		$cityId     = trim((string)($this->request->post['city_id'] ?? ''));
		$districtId = trim((string)($this->request->post['district_id'] ?? ''));
		$regionId   = trim((string)($this->request->post['region_id'] ?? ''));
		if ($cityId === '') { $this->jsonResponse(['offices' => []]); return; }
		$client = $this->client();
		$this->jsonResponse(['offices' => $client ? \Opencart\System\Library\Ukrposhta\Cache::getOffices($this->db, $client, $cityId, $districtId, $regionId) : []]);
	}

	public function setSelection(): void {
		$this->session->data['up_region_id']        = trim((string)($this->request->post['region_id'] ?? ''));
		$this->session->data['up_region_name']      = trim((string)($this->request->post['region_name'] ?? ''));
		$this->session->data['up_city_id']          = trim((string)($this->request->post['city_id'] ?? ''));
		$this->session->data['up_city_name']        = trim((string)($this->request->post['city_name'] ?? ''));
		$this->session->data['up_office_postindex'] = trim((string)($this->request->post['office_postindex'] ?? ''));
		$this->session->data['up_office_name']      = trim((string)($this->request->post['office_name'] ?? ''));
		$this->applyToShippingAddress();
		$this->jsonResponse(['ok' => true]);
	}

	public function getSelection(): void {
		$this->jsonResponse([
			'region_id'        => (string)($this->session->data['up_region_id'] ?? ''),
			'region_name'      => (string)($this->session->data['up_region_name'] ?? ''),
			'city_id'          => (string)($this->session->data['up_city_id'] ?? ''),
			'city_name'        => (string)($this->session->data['up_city_name'] ?? ''),
			'office_postindex' => (string)($this->session->data['up_office_postindex'] ?? ''),
			'office_name'      => (string)($this->session->data['up_office_name'] ?? ''),
		]);
	}

	/**
	 * Persists the picked Ukrposhta city/office into the CORE OpenCart session
	 * shipping address (real office postindex included). The theme may have
	 * saved the address (register.save) BEFORE the customer picked an office,
	 * freezing the page-load placeholder — writing the session here makes the
	 * final order address independent of the click order.
	 */
	private function applyToShippingAddress(): void {
		// Never stomp another carrier's address: only apply while Ukrposhta is
		// the chosen shipping method, or no method has been chosen yet.
		$method = (string)($this->session->data['shipping_method']['code'] ?? '');
		if ($method !== '' && strpos($method, 'ukrposhta.') !== 0) {
			return;
		}
		$city = (string)($this->session->data['up_city_name'] ?? '');
		if ($city === '') {
			return;
		}
		$country = $this->ukraineCountry();
		if (!$country) {
			return;
		}
		$zone   = $this->matchZone((int)$country['country_id'], (string)($this->session->data['up_region_name'] ?? ''));
		$office = (string)($this->session->data['up_office_name'] ?? '');
		$index  = (string)($this->session->data['up_office_postindex'] ?? '');
		$prev   = (array)($this->session->data['shipping_address'] ?? []);
		$this->session->data['shipping_address'] = [
			'address_id'     => (int)($prev['address_id'] ?? 0),
			'firstname'      => (string)($prev['firstname'] ?? ''),
			'lastname'       => (string)($prev['lastname'] ?? ''),
			'company'        => '',
			'address_1'      => $office !== '' ? $office : 'Укрпошта',
			'address_2'      => '',
			'city'           => $city,
			'postcode'       => $index,
			'zone_id'        => $zone ? (int)$zone['zone_id'] : (int)($prev['zone_id'] ?? 0),
			'zone'           => $zone ? (string)$zone['name'] : (string)($prev['zone'] ?? ''),
			'zone_code'      => $zone ? (string)$zone['code'] : (string)($prev['zone_code'] ?? ''),
			'country_id'     => (int)$country['country_id'],
			'country'        => (string)($country['name'] ?? 'Ukraine'),
			'iso_code_2'     => (string)($country['iso_code_2'] ?? 'UA'),
			'iso_code_3'     => (string)($country['iso_code_3'] ?? 'UKR'),
			'address_format' => (string)($country['address_format'] ?? ''),
			'custom_field'   => (array)($prev['custom_field'] ?? []),
		];
	}

	/** Store country if it is Ukraine, else the Ukraine row — carrier ships domestically only. */
	private function ukraineCountry(): array {
		$this->load->model('localisation/country');
		$info = $this->model_localisation_country->getCountry((int)$this->config->get('config_country_id'));
		if (!$info || strtoupper((string)($info['iso_code_2'] ?? '')) !== 'UA') {
			$row = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = 'UA' AND status = 1")->row;
			if ($row) {
				$info = $this->model_localisation_country->getCountry((int)$row['country_id']);
			}
		}
		return is_array($info) ? $info : [];
	}

	/**
	 * Matches a Ukrposhta region name (Cyrillic, e.g. "Дніпропетровська") against
	 * the store's zone list, which is frequently transliterated
	 * ("Dnipropetrovs'ka Oblast'"). Normalized prefix match both ways; null when
	 * nothing matches — never a blind first-row fallback.
	 */
	private function matchZone(int $country_id, string $area): ?array {
		$key = self::latinize(preg_replace('/\s*(область|обл\.?|oblast\'?|м\.)\s*/iu', ' ', $area));
		if ($key === '') {
			return null;
		}
		$rows = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone` WHERE country_id = " . (int)$country_id . " AND status = 1")->rows;
		foreach ($rows as $row) {
			$name = self::latinize((string)$row['name']);
			if ($name !== '' && strpos($name, $key) === 0) {
				return $row;
			}
		}
		foreach ($rows as $row) {
			$name = self::latinize((string)$row['name']);
			if ($name !== '' && strpos($key, $name) === 0) {
				return $row;
			}
		}
		return null;
	}

	/** Cyrillic → national-standard Latin, lowercased, a-z only (mbstring-free). */
	private static function latinize(string $s): string {
		static $map = [
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie',
			'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l',
			'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
			'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia',
			'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'h', 'Ґ' => 'g', 'Д' => 'd', 'Е' => 'e', 'Є' => 'ie',
			'Ж' => 'zh', 'З' => 'z', 'И' => 'y', 'І' => 'i', 'Ї' => 'i', 'Й' => 'i', 'К' => 'k', 'Л' => 'l',
			'М' => 'm', 'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
			'Ф' => 'f', 'Х' => 'kh', 'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch', 'Ь' => '', 'Ю' => 'iu', 'Я' => 'ia',
			"'" => '', '’' => '',
		];
		return preg_replace('/[^a-z]/', '', strtolower(strtr($s, $map)));
	}
}
