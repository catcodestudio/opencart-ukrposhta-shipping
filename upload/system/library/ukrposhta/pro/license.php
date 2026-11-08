<?php
/**
 * CatCode licence client for Ukrposhta Shipping (OpenCart). PHP 7.0+.
 *
 *  - a purchased key pasted in the settings → verify, then activate;
 *  - "Try Pro for 7 days" → POST /trial issues a 7-day key and activates it;
 *  - isPro(): a purchase the server confirmed once stays Pro for good (an
 *    OpenCart licence is one payment, no term); a trial ends with its days.
 *
 * Settings live in the module's own setting group, one row per key, written by
 * CcUpSettingStore so the core settings form never overwrites them.
 */
class CcUpLicense {
	const PREFIX = 'shipping_ukrposhta_';
	const GROUP  = 'shipping_ukrposhta';

	const DEFAULT_ENDPOINT       = 'https://catcode.com.ua/wp-json/catcode/v1/license';
	const DEFAULT_TRIAL_ENDPOINT = 'https://catcode.com.ua/wp-json/catcode/v1/trial';

	/** Canonical module slug — post_name of the module page on catcode.com.ua. */
	const EXPECTED_SLUG = 'ukrposhta-shipping-for-opencart-4';
	const MODULE_URL    = 'https://catcode.com.ua/modules/ukrposhta-shipping-for-opencart-4/';

	const TRIAL_DAYS   = 7;
	const GRACE_DAYS   = 14;
	const HTTP_TIMEOUT = 8;

	public static function endpoint() {
		return defined('CATCODE_LICENSE_API') ? (string)constant('CATCODE_LICENSE_API') : self::DEFAULT_ENDPOINT;
	}

	public static function trialEndpoint() {
		return defined('CATCODE_TRIAL_API') ? (string)constant('CATCODE_TRIAL_API') : self::DEFAULT_TRIAL_ENDPOINT;
	}

	private static function get($config, $key) {
		return (string)$config->get(self::PREFIX . $key);
	}

	/** Licence keys owned by the server answers — the settings form must never post them. */
	public static function serverOwned() {
		return array('license_key', 'license_status', 'license_checked_at', 'license_expires_at', 'license_data', 'license_kind', 'license_owned', 'trial_started');
	}

	public static function hasKey($config) {
		return trim(self::get($config, 'license_key')) !== '';
	}

	public static function key($config) {
		return trim(self::get($config, 'license_key'));
	}

	public static function trialStarted($config) {
		return (int)self::get($config, 'trial_started');
	}

	public static function trialAvailable($config) {
		return self::trialStarted($config) <= 0 && !self::hasKey($config);
	}

	public static function trialDaysLeft($config) {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			return self::TRIAL_DAYS;
		}
		return max(0, self::TRIAL_DAYS - (int)floor((time() - $started) / 86400));
	}

	public static function isOwned($config) {
		return self::hasKey($config) && self::get($config, 'license_owned') === '1';
	}

	public static function isTrialKey($config) {
		$kind = self::get($config, 'license_kind');
		if ($kind !== '') {
			return $kind === 'trial';
		}
		return self::hasKey($config) && self::trialStarted($config) > 0;
	}

	public static function isPro($config) {
		// A confirmed purchase is for good: no later verdict and no outage takes Pro away.
		if (self::isOwned($config)) {
			return true;
		}
		if (self::get($config, 'license_status') !== 'valid') {
			return false;
		}
		$checkedAt = self::get($config, 'license_checked_at');
		if ($checkedAt === '') {
			return false;
		}
		if (self::isTrialKey($config) && self::trialStarted($config) > 0 && self::trialDaysLeft($config) <= 0) {
			return false;
		}
		return (time() - (int)strtotime($checkedAt)) / 86400 <= self::GRACE_DAYS;
	}

	/** State for the settings screen: none | trial | trial_ended | owned | active | invalid. */
	public static function state($config) {
		if (!self::hasKey($config)) {
			return 'none';
		}
		if (self::isOwned($config)) {
			return 'owned';
		}
		$pro = self::isPro($config);
		if (self::isTrialKey($config)) {
			return $pro ? 'trial' : 'trial_ended';
		}
		return $pro ? 'active' : 'invalid';
	}

	public static function activate($config, $db, $key) {
		$key = trim((string)$key);
		if ($key === '') {
			return array('ok' => false, 'error' => 'missing_key');
		}
		// verify does not use an activation slot: a typo or another product's key burns nothing.
		$pre = self::call($key, 'verify');
		if (empty($pre['ok'])) {
			return $pre;
		}
		$result = self::call($key, 'activate');
		self::store($db, $config, $key, $result);
		return $result;
	}

	public static function startTrial($config, $db, $email) {
		$email = trim((string)$email);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return array('ok' => false, 'error' => 'bad_email');
		}
		if (!self::trialAvailable($config)) {
			return array('ok' => false, 'error' => 'trial_used_site');
		}
		$response = self::post(self::trialEndpoint(), array(
			'email'       => $email,
			'module_slug' => self::EXPECTED_SLUG,
			'site_url'    => self::siteUrl(),
		));
		if (empty($response['ok']) || empty($response['key'])) {
			if (empty($response['error'])) {
				$response['error'] = (int)(isset($response['http']) ? $response['http'] : 0) === 429 ? 'rate_limited' : 'network';
			}
			return $response;
		}
		$key    = (string)$response['key'];
		$result = self::call($key, 'activate');
		self::store($db, $config, $key, $result, array(
			'trial_started' => (string)time(),
			'license_kind'  => 'trial',
		));
		$result['key']        = $key;
		$result['expires_at'] = (string)(isset($response['expires_at']) ? $response['expires_at'] : '');
		return $result;
	}

	public static function verify($config, $db) {
		$key = self::key($config);
		if ($key === '') {
			return array('ok' => false, 'error' => 'no_key');
		}
		$result = self::call($key, 'verify');
		self::store($db, $config, $key, $result);
		return $result;
	}

	/** Daily re-check from an admin page or cron. */
	public static function maybeVerify($config, $db) {
		if (!self::hasKey($config)) {
			return null;
		}
		$checked = self::get($config, 'license_checked_at');
		if ($checked !== '' && time() - (int)strtotime($checked) < 86400) {
			return null;
		}
		return self::verify($config, $db);
	}

	public static function deactivate($config, $db) {
		$key    = self::key($config);
		$result = $key === '' ? array('ok' => true) : self::call($key, 'deactivate');
		$patch  = array();
		foreach (array('license_key', 'license_status', 'license_checked_at', 'license_expires_at', 'license_data', 'license_owned', 'license_kind') as $k) {
			$patch[self::PREFIX . $k] = '';
		}
		$store = new CcUpSettingStore($db);
		$store->editSetting(self::GROUP, $patch);
		foreach ($patch as $k => $v) {
			$config->set($k, $v);
		}
		return $result;
	}

	public static function errorCode(array $r) {
		return isset($r['error']) ? (string)$r['error'] : '';
	}

	private static function call($key, $action) {
		$data = self::post(self::endpoint(), array(
			'key'      => $key,
			'site_url' => self::siteUrl(),
			'action'   => $action,
		));
		// The server sends module_slug and product_slug; either matching = our key.
		if (!empty($data['ok'])) {
			$slugs = array_filter(array(
				isset($data['module_slug']) ? (string)$data['module_slug'] : '',
				isset($data['product_slug']) ? (string)$data['product_slug'] : '',
			));
			if ($slugs && !in_array(self::EXPECTED_SLUG, $slugs, true)) {
				$data['ok']    = false;
				$data['error'] = 'wrong_product';
			}
		}
		if (empty($data['ok']) && empty($data['error'])) {
			$http = (int)(isset($data['http']) ? $data['http'] : 0);
			if (!empty($data['expired'])) {
				$data['error'] = 'expired';
			} elseif ($http === 429) {
				$data['error'] = 'rate_limited';
			} elseif ($http === 401) {
				$data['error'] = 'invalid_key';
			} elseif ($http === 403) {
				$data['error'] = 'limit_reached';
			}
		}
		return $data;
	}

	private static function post($url, array $payload) {
		if (!function_exists('curl_init')) {
			return array('ok' => false, 'error' => 'network', 'http' => 0);
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
			CURLOPT_POSTFIELDS     => json_encode($payload),
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 5,
		));
		$raw  = curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($raw === false) {
			return array('ok' => false, 'error' => 'network', 'http' => 0);
		}
		$data = json_decode((string)$raw, true);
		// Our server always answers with `ok`; anything else (WAF, proxy, 5xx page) is no verdict.
		if (!is_array($data) || !array_key_exists('ok', $data)) {
			return array('ok' => false, 'error' => 'bad_response', 'http' => $http);
		}
		$data['http']        = $http;
		$data['verified_at'] = date('Y-m-d H:i:s');
		return $data;
	}

	private static function siteUrl() {
		$base = '';
		foreach (array('HTTPS_CATALOG', 'HTTP_CATALOG', 'HTTPS_SERVER', 'HTTP_SERVER') as $c) {
			if (defined($c)) {
				$base = (string)constant($c);
				break;
			}
		}
		$parts = parse_url($base);
		if (!$parts || empty($parts['host'])) {
			return '';
		}
		return strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . strtolower($parts['host']);
	}

	private static function isTransportFailure(array $r) {
		if (!empty($r['ok'])) {
			return false;
		}
		$e = isset($r['error']) ? (string)$r['error'] : '';
		if (in_array($e, array('network', 'bad_response', 'rate_limited'), true)) {
			return true;
		}
		$http = (int)(isset($r['http']) ? $r['http'] : 0);
		return $http === 0 || $http >= 500;
	}

	private static function kindOf(array $r, array $extra) {
		if (isset($extra['license_kind'])) {
			return (string)$extra['license_kind'];
		}
		if (array_key_exists('trial', $r)) {
			return !empty($r['trial']) ? 'trial' : 'purchase';
		}
		return '';
	}

	/** An outage is not a verdict: then only the raw answer is kept. */
	private static function store($db, $config, $key, array $result, array $extra = array()) {
		$P     = self::PREFIX;
		$patch = array($P . 'license_key' => $key, $P . 'license_data' => json_encode($result));
		if (self::key($config) !== $key) {
			// A new key starts a new history: the purchase latch belongs to the key.
			$patch[$P . 'license_owned'] = '';
			$patch[$P . 'license_kind']  = '';
		}
		if (!self::isTransportFailure($result) || self::key($config) === '') {
			$patch[$P . 'license_status']     = !empty($result['ok']) ? 'valid' : 'invalid';
			$patch[$P . 'license_checked_at'] = isset($result['verified_at']) ? (string)$result['verified_at'] : date('Y-m-d H:i:s');
			$patch[$P . 'license_expires_at'] = isset($result['expires_at']) ? (string)$result['expires_at'] : '';
			$kind = self::kindOf($result, $extra);
			if ($kind !== '') {
				$patch[$P . 'license_kind'] = $kind;
			}
			if ($kind === 'purchase' && !empty($result['ok'])) {
				$patch[$P . 'license_owned'] = '1';
			}
		}
		foreach ($extra as $k => $v) {
			$patch[$P . $k] = $v;
		}
		$store = new CcUpSettingStore($db);
		$store->editSetting(self::GROUP, $patch);
		foreach ($patch as $k => $v) {
			$config->set($k, $v);
		}
	}
}

/**
 * Writes changed keys of a setting group one row each, leaving the rest alone.
 * The catalog copy of model setting/setting has no editSetting(), and the admin
 * one replaces the whole group — neither is safe for licence and Pro values.
 */
class CcUpSettingStore {
	private $db;

	public function __construct($db) {
		$this->db = $db;
	}

	public function getSetting($code, $store_id = 0) {
		$data  = array();
		$query = $this->db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$store_id . "' AND `code` = '" . $this->db->escape((string)$code) . "'");
		foreach ($query->rows as $row) {
			$data[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
		}
		return $data;
	}

	public function editSetting($code, array $data, $store_id = 0) {
		$code = (string)$code;
		foreach ($data as $key => $value) {
			if (strpos((string)$key, $code) !== 0) {
				continue;
			}
			$serialized = is_array($value) ? 1 : 0;
			$stored     = $serialized ? json_encode($value) : (string)$value;
			$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$store_id . "' AND `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape((string)$key) . "'");
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$store_id . "', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape((string)$key) . "', `value` = '" . $this->db->escape($stored) . "', `serialized` = '" . (int)$serialized . "'");
		}
	}
}
