<?php
/**
 * Ukrposhta Shipping Pro for OpenCart 2.3 / 3 / 4 (one file for all three). PHP 7.0+.
 *
 * Shipments from orders (with cash on delivery), labels, form 103a registry,
 * delivery statuses and the tracking number for the customer. The OpenCart
 * controllers only pass URLs, permissions and a mail sender; everything else
 * lives here so the three builds behave the same.
 *
 * Ukrposhta contract — API documentation 16.10.2026 and Status tracking 04.03.2026
 * (dev.ukrposhta.ua/documentation).
 */
require_once __DIR__ . '/license.php';

class CcUpProApi {
	private $bearer;
	private $token;
	private $trackingBearer;
	private $root;
	private $timeout = 25;

	public function __construct($bearer, $token, $trackingBearer, $sandbox) {
		$this->bearer         = trim((string)$bearer);
		$this->token          = trim((string)$token);
		$this->trackingBearer = trim((string)$trackingBearer) !== '' ? trim((string)$trackingBearer) : $this->bearer;
		$this->root           = $sandbox ? 'https://dev.ukrposhta.ua' : 'https://www.ukrposhta.ua';
		// Local test stand only: the API answered by a mock.
		if (defined('CC_UKRPOSHTA_API_ROOT') && (string)constant('CC_UKRPOSHTA_API_ROOT') !== '') {
			$this->root = rtrim((string)constant('CC_UKRPOSHTA_API_ROOT'), '/');
		}
	}

	private function tok($url) {
		return $url . (strpos($url, '?') !== false ? '&' : '?') . 'token=' . rawurlencode($this->token);
	}

	private function send($method, $url, $body, $bearer, $accept) {
		$ch      = curl_init($url);
		$headers = array('Authorization: Bearer ' . $bearer, 'Accept: ' . $accept);
		$opts    = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
		);
		if ($body !== null) {
			$headers[]                = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		$raw  = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);
		if ($raw === false) {
			return array('success' => false, 'status' => 0, 'data' => null, 'body' => '', 'errors' => array('network: ' . $err));
		}
		$raw     = (string)$raw;
		$decoded = json_decode($raw, true);
		$ok      = $code >= 200 && $code < 300;
		return array(
			'success' => $ok,
			'status'  => $code,
			'data'    => $decoded,
			'body'    => $raw,
			'errors'  => $ok ? array() : self::errorsOf($decoded, $code),
		);
	}

	public static function errorsOf($decoded, $code) {
		$errors = array();
		if (is_array($decoded)) {
			foreach (array('message', 'description', 'error') as $k) {
				if (!empty($decoded[$k]) && is_scalar($decoded[$k])) {
					$errors[] = (string)$decoded[$k];
				}
			}
			if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
				foreach ($decoded['errors'] as $e) {
					$errors[] = is_array($e) ? (string)(isset($e['message']) ? $e['message'] : json_encode($e)) : (string)$e;
				}
			}
		}
		if (!$errors) {
			$errors[] = 'HTTP ' . (int)$code;
		}
		return array_values(array_unique($errors));
	}

	private function json($method, $url, $body = null, $bearer = '') {
		return $this->send($method, $url, $body, $bearer !== '' ? $bearer : $this->bearer, 'application/json');
	}

	private function pdf($method, $url, $body = null) {
		$r = $this->send($method, $url, $body, $this->bearer, 'application/pdf');
		if ($r['success'] && strpos($r['body'], '%PDF') === 0) {
			return array('success' => true, 'body' => $r['body']);
		}
		$r['success'] = false;
		if (!$r['errors']) {
			$r['errors'] = array('HTTP ' . $r['status']);
		}
		return $r;
	}

	public function createAddress($postcode) {
		return $this->json('POST', $this->root . '/ecom/0.0.1/addresses', array('postcode' => (string)$postcode));
	}

	public function createClient(array $client) {
		return $this->json('POST', $this->tok($this->root . '/ecom/0.0.1/clients'), $client);
	}

	public function createShipment(array $shipment) {
		return $this->json('POST', $this->tok($this->root . '/ecom/0.0.1/shipments'), $shipment);
	}

	/** Only a shipment still in status CREATED can be deleted. */
	public function deleteShipment($uuid) {
		return $this->json('DELETE', $this->tok($this->root . '/ecom/0.0.1/shipments/' . rawurlencode($uuid)));
	}

	public function createGroup($name, $clientUuid, $type) {
		return $this->json('POST', $this->tok($this->root . '/ecom/0.0.1/shipment-groups'), array('name' => $name, 'clientUuid' => $clientUuid, 'type' => $type));
	}

	public function addToGroup($group, $shipment) {
		return $this->json('POST', $this->tok($this->root . '/ecom/0.0.1/shipment-groups/' . rawurlencode($group) . '/shipments/' . rawurlencode($shipment)));
	}

	public function sticker($barcode, $size) {
		$url = $this->tok($this->root . '/forms/ecom/0.0.1/shipments/' . rawurlencode($barcode) . '/sticker');
		return $this->pdf('GET', $size !== '' ? $url . '&size=' . rawurlencode($size) : $url);
	}

	public function stickers(array $barcodes, $size) {
		$body = array();
		foreach ($barcodes as $b) {
			$body[(string)$b] = new stdClass();
		}
		$url = $this->tok($this->root . '/forms/ecom/0.0.1/shipments/stickers-by-barcodes');
		return $this->pdf('POST', $size !== '' ? $url . '&size=' . rawurlencode($size) : $url, $body);
	}

	public function form103a($group) {
		return $this->pdf('GET', $this->tok($this->root . '/forms/ecom/0.0.1/shipment-groups/' . rawurlencode($group) . '/form103a') . '&showSenderName=true');
	}

	public function statuses(array $barcodes) {
		return $this->json('POST', $this->root . '/status-tracking/0.0.1/statuses', array_values(array_slice($barcodes, 0, 50)), $this->trackingBearer);
	}
}

class CcUpPro {
	const TRACK_URL = 'https://track.ukrposhta.ua/tracking_UA.html?barcode=';

	/** Tracking event → lifecycle (Status tracking API, table of events). */
	private static $events = array(
		10601 => 'CREATED', 10100 => 'REGISTERED', 20700 => 'DELIVERING', 20800 => 'DELIVERING', 20900 => 'DELIVERING',
		21500 => 'DELIVERING', 31100 => 'DELIVERING', 21700 => 'IN_DEPARTMENT', 21400 => 'STORAGE', 31400 => 'FORWARDING',
		41000 => 'DELIVERED', 48000 => 'DELIVERED', 41010 => 'RETURNED', 10600 => 'CANCELLED', 10602 => 'CANCELLED', 10603 => 'DELETED',
	);
	private static $final = array('DELIVERED', 'RETURNED', 'CANCELLED', 'DELETED');

	/** Pro settings: key => default. Secrets are encrypted. */
	public static $fields = array(
		'token' => '', 'tracking_bearer' => '', 'sender_type' => 'INDIVIDUAL', 'sender_last_name' => '', 'sender_first_name' => '',
		'sender_middle_name' => '', 'sender_company' => '', 'sender_code' => '', 'sender_phone' => '', 'sender_iban' => '', 'sender_uuid' => '',
		'pro_paid_by_recipient' => '1', 'pro_cod' => '1', 'pro_cod_payments' => 'cod', 'pro_cod_amount' => 'total', 'pro_cod_fee_recipient' => '1',
		'default_weight' => '0.5', 'default_length' => '30', 'default_width' => '20', 'default_height' => '10', 'pro_on_fail' => 'RETURN',
		'pro_sms' => '0', 'pro_label_size' => '', 'pro_status_delivered' => '0', 'pro_status_returned' => '0', 'pro_customer_tracking' => '1',
		'pro_customer_mail' => '1',
	);
	private static $secrets = array('token', 'tracking_bearer');

	private $db;
	private $config;
	private $encrypt;
	private $decrypt;
	private $lang;
	private $L;
	private $all;
	/** @var callable|null fn($to, $subject, $text) */
	public $mailer;

	public function __construct($db, $config, $encrypt, $decrypt, $langCode) {
		$this->db      = $db;
		$this->config  = $config;
		$this->encrypt = $encrypt;
		$this->decrypt = $decrypt;
		$all           = require __DIR__ . '/pro_lang.php';
		$this->all     = $all;
		$code          = strtolower((string)$langCode);
		$this->lang    = strpos($code, 'uk') === 0 || strpos($code, 'ua') === 0 ? 'uk' : (strpos($code, 'ru') === 0 ? 'ru' : 'en');
		$this->L       = $all[$this->lang] + $all['en'];
	}

	public function t($key) {
		$s    = isset($this->L[$key]) ? $this->L[$key] : $key;
		$args = func_get_args();
		array_shift($args);
		return $args ? vsprintf($s, $args) : $s;
	}

	/** A string in the shop's storefront language (for the customer e-mail). */
	private function tShop($key) {
		$code = strtolower((string)$this->config->get('config_language'));
		$lang = strpos($code, 'uk') === 0 || strpos($code, 'ua') === 0 ? 'uk' : (strpos($code, 'ru') === 0 ? 'ru' : 'en');
		$L    = $this->all[$lang] + $this->all['en'];
		$args = func_get_args();
		array_shift($args);
		return vsprintf(isset($L[$key]) ? $L[$key] : $key, $args);
	}

	private function opt($key) {
		$v = $this->config->get(CcUpLicense::PREFIX . $key);
		if ($v === null || $v === '') {
			return isset(self::$fields[$key]) ? self::$fields[$key] : '';
		}
		return (string)$v;
	}

	private function secret($key) {
		$raw = (string)$this->config->get(CcUpLicense::PREFIX . $key);
		return $raw === '' ? '' : (string)call_user_func($this->decrypt, $raw);
	}

	public function isPro() {
		return CcUpLicense::isPro($this->config);
	}

	private static function h($s) {
		return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
	}

	private static function pc5($raw) {
		$d = preg_replace('/\D/', '', (string)$raw);
		return $d === '' ? '' : str_pad(substr($d, 0, 5), 5, '0', STR_PAD_LEFT);
	}

	private static function cut($s, $n) {
		return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n, 'UTF-8') : substr((string)$s, 0, $n);
	}

	private static function len($s) {
		return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : (int)preg_match_all('/./us', (string)$s);
	}

	public static function phone($raw) {
		$d = (string)preg_replace('/\D+/', '', (string)$raw);
		if (strlen($d) === 12 && strpos($d, '380') === 0) {
			return substr($d, 2);
		}
		if (strlen($d) === 9) {
			return '0' . $d;
		}
		return $d;
	}

	private function api() {
		$bearer = $this->secret('bearer');
		$token  = $this->secret('token');
		if ($bearer === '' || $token === '') {
			return null;
		}
		return new CcUpProApi($bearer, $token, $this->secret('tracking_bearer'), (bool)$this->config->get(CcUpLicense::PREFIX . 'sandbox'));
	}

	private function fail($what, array $r) {
		if ((int)(isset($r['status']) ? $r['status'] : -1) === 0) {
			return $what . ': ' . $this->t('e_no_answer');
		}
		return $what . (!empty($r['errors']) ? ': ' . implode('; ', $r['errors']) : '.');
	}

	public function gaps() {
		$g = array();
		if ($this->secret('bearer') === '') { $g[] = $this->t('gap_bearer'); }
		if ($this->secret('token') === '') { $g[] = $this->t('gap_token'); }
		if (self::pc5($this->config->get(CcUpLicense::PREFIX . 'sender_postcode')) === '') { $g[] = $this->t('gap_postcode'); }
		if (trim($this->opt('sender_uuid')) === '') { $g[] = $this->t('gap_sender'); }
		return $g;
	}

	// ------------------------------------------------------------- storage

	/** Columns the Pro features add to the free build's up_shipment table. */
	public function ensureSchema() {
		$t = DB_PREFIX . 'up_shipment';
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . $t . "` (
			`shipment_id` int NOT NULL AUTO_INCREMENT, `order_id` int NOT NULL, `barcode` varchar(32) DEFAULT NULL,
			`shipment_uuid` varchar(64) DEFAULT NULL, `recipient_postindex` varchar(10) DEFAULT NULL,
			`recipient_city_name` varchar(255) DEFAULT NULL, `recipient_office_name` varchar(255) DEFAULT NULL,
			`status_code` int DEFAULT '0', `status_text` varchar(255) DEFAULT NULL, `created_at` datetime DEFAULT NULL,
			`last_polled_at` datetime DEFAULT NULL, PRIMARY KEY (`shipment_id`), KEY `order_id` (`order_id`)
		) DEFAULT CHARSET=utf8mb4");
		$have = array();
		foreach ($this->db->query("SHOW COLUMNS FROM `" . $t . "`")->rows as $r) {
			$have[$r['Field']] = true;
		}
		$add = array(
			'barcode' => "varchar(32) DEFAULT NULL", 'shipment_uuid' => "varchar(64) DEFAULT NULL", 'recipient_uuid' => "varchar(64) DEFAULT NULL",
			'lifecycle' => "varchar(20) DEFAULT NULL", 'delivery_price' => "decimal(15,2) DEFAULT NULL", 'cod_amount' => "decimal(15,2) DEFAULT NULL",
			'declared_cost' => "decimal(15,2) DEFAULT NULL", 'weight_g' => "int DEFAULT NULL", 'group_uuid' => "varchar(64) DEFAULT NULL",
			'last_error' => "varchar(1000) DEFAULT NULL", 'status_code' => "int DEFAULT '0'", 'status_text' => "varchar(255) DEFAULT NULL",
			'last_polled_at' => "datetime DEFAULT NULL", 'created_at' => "datetime DEFAULT NULL",
		);
		foreach ($add as $col => $def) {
			if (!isset($have[$col])) {
				$this->db->query("ALTER TABLE `" . $t . "` ADD `" . $col . "` " . $def);
			}
		}
	}

	private function row($orderId) {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "up_shipment` WHERE order_id = '" . (int)$orderId . "' ORDER BY shipment_id DESC LIMIT 1");
		return $q->num_rows ? $q->row : null;
	}

	private function save($orderId, array $set) {
		$parts = array();
		foreach ($set as $k => $v) {
			// Times come from the database clock, the same one the queries compare with.
			$parts[] = "`" . $k . "` = " . ($v === null ? 'NULL' : ($v === 'NOW()' ? 'NOW()' : "'" . $this->db->escape((string)$v) . "'"));
		}
		$row = $this->row($orderId);
		if ($row) {
			$this->db->query("UPDATE `" . DB_PREFIX . "up_shipment` SET " . implode(', ', $parts) . " WHERE shipment_id = '" . (int)$row['shipment_id'] . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "up_shipment` SET order_id = '" . (int)$orderId . "', created_at = NOW(), " . implode(', ', $parts));
		}
	}

	private function order($orderId) {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$orderId . "'");
		return $q->num_rows ? $q->row : null;
	}

	private static function code($raw) {
		$raw = (string)$raw;
		if ($raw !== '' && $raw[0] === '{') {
			$j = json_decode($raw, true);
			$raw = is_array($j) && isset($j['code']) ? (string)$j['code'] : '';
		}
		$p = explode('.', $raw);
		return strtolower(trim($p[0]));
	}

	private static function paymentCode(array $o) {
		if (!empty($o['payment_code'])) {
			return self::code($o['payment_code']);
		}
		return isset($o['payment_method']) ? self::code($o['payment_method']) : '';
	}

	private static function shippingCode(array $o) {
		if (!empty($o['shipping_code'])) {
			return self::code($o['shipping_code']);
		}
		return isset($o['shipping_method']) ? self::code($o['shipping_method']) : '';
	}

	private function postindex(array $o) {
		$r = $this->row((int)$o['order_id']);
		$pi = $r && !empty($r['recipient_postindex']) ? $r['recipient_postindex'] : '';
		if ($pi === '' && self::shippingCode($o) === 'ukrposhta') {
			$pi = (string)$o['shipping_postcode'];
		}
		return self::pc5($pi);
	}

	private function weightG(array $o) {
		$kgClass = $this->db->query("SELECT wc.value FROM `" . DB_PREFIX . "weight_class` wc LEFT JOIN `" . DB_PREFIX . "weight_class_description` wcd ON wc.weight_class_id = wcd.weight_class_id WHERE LOWER(wcd.unit) IN ('kg','кг') LIMIT 1");
		$kgValue = $kgClass->num_rows ? (float)$kgClass->row['value'] : 0.0;
		$rows = $this->db->query("SELECT op.quantity, p.weight, p.weight_class_id, wc.value AS class_value FROM `" . DB_PREFIX . "order_product` op LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id LEFT JOIN `" . DB_PREFIX . "weight_class` wc ON wc.weight_class_id = p.weight_class_id WHERE op.order_id = '" . (int)$o['order_id'] . "'")->rows;
		$kg = 0.0;
		foreach ($rows as $r) {
			$w = (float)$r['weight'];
			if ($w <= 0) {
				continue;
			}
			$from = (float)$r['class_value'];
			$kg  += ($kgValue > 0 && $from > 0 ? $w * ($kgValue / $from) : $w) * (int)$r['quantity'];
		}
		if ($kg <= 0) {
			$kg = (float)str_replace(',', '.', $this->opt('default_weight'));
		}
		return (int)max(1, round($kg * 1000));
	}

	private function shippingTotal($orderId) {
		$q = $this->db->query("SELECT SUM(value) AS s FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$orderId . "' AND code = 'shipping'");
		return $q->num_rows ? (float)$q->row['s'] : 0.0;
	}

	public function isCod(array $o) {
		$ids = array_filter(array_map('trim', explode(',', strtolower($this->opt('pro_cod_payments')))));
		return in_array(self::paymentCode($o), $ids, true);
	}

	public function draft(array $o) {
		$total = round((float)$o['total'], 2);
		$goods = max(0.0, round($total - $this->shippingTotal((int)$o['order_id']), 2));
		$cod   = 0.0;
		if ($this->opt('pro_cod') === '1' && $this->isCod($o)) {
			$cod = $this->opt('pro_cod_amount') === 'goods' ? $goods : $total;
		}
		$declared = max($goods, $cod);
		return array(
			'weight'   => $this->weightG($o),
			'length'   => max(1, (int)$this->opt('default_length')),
			'width'    => max(1, (int)$this->opt('default_width')),
			'height'   => max(1, (int)$this->opt('default_height')),
			'declared' => $declared,
			'cod'      => $cod,
			'type'     => (string)($this->config->get(CcUpLicense::PREFIX . 'service_type') ?: 'STANDARD'),
			'postcode' => $this->postindex($o),
		);
	}

	// ------------------------------------------------------------- actions

	public function createSender() {
		$api = $this->api();
		if (!$api) {
			return array('ok' => false, 'message' => $this->t('e_sender_keys'));
		}
		$pc    = self::pc5($this->config->get(CcUpLicense::PREFIX . 'sender_postcode'));
		$phone = self::phone($this->opt('sender_phone'));
		if ($pc === '' || strlen($phone) < 10) {
			return array('ok' => false, 'message' => $this->t('e_sender_fields'));
		}
		$addr = $api->createAddress($pc);
		if (!$addr['success'] || empty($addr['data']['id'])) {
			return array('ok' => false, 'message' => $this->fail($this->t('e_sender_addr'), $addr));
		}
		$type   = in_array($this->opt('sender_type'), array('INDIVIDUAL', 'PRIVATE_ENTREPRENEUR', 'COMPANY'), true) ? $this->opt('sender_type') : 'INDIVIDUAL';
		$client = array('type' => $type, 'addressId' => (int)$addr['data']['id'], 'phoneNumber' => $phone);
		if ($type === 'COMPANY') {
			$client['name']   = trim($this->opt('sender_company'));
			$client['edrpou'] = preg_replace('/\D/', '', $this->opt('sender_code'));
		} else {
			$client['lastName']  = trim($this->opt('sender_last_name'));
			$client['firstName'] = trim($this->opt('sender_first_name'));
			if (trim($this->opt('sender_middle_name')) !== '') {
				$client['middleName'] = trim($this->opt('sender_middle_name'));
			}
			if ($type === 'PRIVATE_ENTREPRENEUR') {
				$client['tin'] = preg_replace('/\D/', '', $this->opt('sender_code'));
				if (trim($this->opt('sender_company')) !== '') {
					$client['name'] = trim($this->opt('sender_company'));
				}
			}
		}
		$iban = strtoupper(preg_replace('/\s+/', '', $this->opt('sender_iban')));
		if ($iban !== '') {
			$client['bankAccount'] = $iban;
		}
		$r = $api->createClient($client);
		if (!$r['success'] || empty($r['data']['uuid'])) {
			return array('ok' => false, 'message' => $this->fail($this->t('e_sender_client'), $r));
		}
		$this->saveSettings(array('sender_uuid' => (string)$r['data']['uuid']));
		return array('ok' => true, 'message' => $this->t('ok_sender', (string)$r['data']['uuid']));
	}

	/** Pro settings form → setting rows (one per key; licence rows are never touched). */
	public function saveSettings(array $in) {
		$patch = array();
		foreach (self::$fields as $k => $def) {
			if (!array_key_exists($k, $in)) {
				continue;
			}
			$v = trim((string)$in[$k]);
			if (in_array($k, self::$secrets, true)) {
				if ($v === '') {
					continue; // keep the stored one
				}
				$v = (string)call_user_func($this->encrypt, $v);
			}
			$patch[CcUpLicense::PREFIX . $k] = $v;
		}
		if ((string)$this->config->get(CcUpLicense::PREFIX . 'cron_key') === '') {
			$patch[CcUpLicense::PREFIX . 'cron_key'] = substr(md5(uniqid('', true) . mt_rand()), 0, 24);
		}
		$store = new CcUpSettingStore($this->db);
		$store->editSetting(CcUpLicense::GROUP, $patch);
		foreach ($patch as $k => $v) {
			$this->config->set($k, $v);
		}
		return array('ok' => true, 'message' => $this->t('ok_saved'));
	}

	public function create($orderId) {
		if (!$this->isPro()) {
			return array('ok' => false, 'message' => $this->t('e_not_pro'));
		}
		$o = $this->order($orderId);
		if (!$o) {
			return array('ok' => false, 'message' => 'order not found');
		}
		$row = $this->row($orderId);
		if ($row && !empty($row['barcode'])) {
			return array('ok' => true, 'message' => $this->t('exists', $row['barcode']), 'barcode' => $row['barcode']);
		}
		$draft = $this->draft($o);
		if ($draft['postcode'] === '') {
			return array('ok' => false, 'message' => $this->t('e_not_up'));
		}
		if ($g = $this->gaps()) {
			return array('ok' => false, 'message' => implode(' ', $g));
		}
		$lock = 'ccup_ship_' . (int)$orderId;
		// One shipment per order even on a double click or two admins at once.
		if ((string)$this->db->query("SELECT GET_LOCK('" . $lock . "', 0) AS l")->row['l'] !== '1') {
			return array('ok' => false, 'message' => $this->t('busy'));
		}
		try {
			$row = $this->row($orderId);
			if ($row && !empty($row['barcode'])) {
				return array('ok' => true, 'message' => $this->t('exists', $row['barcode']), 'barcode' => $row['barcode']);
			}
			return $this->createLocked($o, $draft, $row);
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('" . $lock . "') AS l");
		}
	}

	private function errorOut($orderId, $msg) {
		$this->save($orderId, array('last_error' => self::cut($msg, 990)));
		return array('ok' => false, 'message' => $msg);
	}

	private function createLocked(array $o, array $draft, $row) {
		$id    = (int)$o['order_id'];
		$api   = $this->api();
		$phone = self::phone($o['telephone']);
		$first = trim((string)($o['shipping_firstname'] !== '' ? $o['shipping_firstname'] : $o['firstname']));
		$last  = trim((string)($o['shipping_lastname'] !== '' ? $o['shipping_lastname'] : $o['lastname']));
		$middle = '';
		if (strpos($first, ' ') !== false) {
			list($first, $middle) = explode(' ', preg_replace('/\s+/u', ' ', $first), 2);
		}
		if (strlen($phone) < 10) {
			return $this->errorOut($id, $this->t('e_phone'));
		}
		if (self::len($first) < 2 || self::len($last) < 2) {
			return $this->errorOut($id, $this->t('e_name'));
		}
		$recipient = $row && !empty($row['recipient_uuid']) ? (string)$row['recipient_uuid'] : '';
		if ($recipient === '') {
			$addr = $api->createAddress($draft['postcode']);
			if (!$addr['success'] || empty($addr['data']['id'])) {
				return $this->errorOut($id, $this->fail($this->t('e_recipient_addr'), $addr));
			}
			$client = array('type' => 'INDIVIDUAL', 'lastName' => $last, 'firstName' => $first, 'addressId' => (int)$addr['data']['id'], 'phoneNumber' => $phone, 'externalId' => 'oc-' . $id);
			if ($middle !== '') {
				$client['middleName'] = $middle;
			}
			if (!empty($o['email'])) {
				$client['email'] = (string)$o['email'];
			}
			$r = $api->createClient($client);
			if (!$r['success'] || empty($r['data']['uuid'])) {
				return $this->errorOut($id, $this->fail($this->t('e_recipient'), $r));
			}
			$recipient = (string)$r['data']['uuid'];
			$this->save($id, array('recipient_uuid' => $recipient, 'recipient_postindex' => $draft['postcode']));
		}
		$parcel = array('weight' => $draft['weight'], 'length' => $draft['length'], 'width' => $draft['width'], 'height' => $draft['height']);
		if ($draft['declared'] > 0) {
			$parcel['declaredPrice'] = $draft['declared'];
		}
		$body = array(
			'sender'            => array('uuid' => $this->opt('sender_uuid')),
			'recipient'         => array('uuid' => $recipient),
			'type'              => $draft['type'],
			'deliveryType'      => 'W2W',
			'paidByRecipient'   => $this->opt('pro_paid_by_recipient') === '1',
			'externalId'        => (string)$id,
			'description'       => $this->t('desc', $id),
			'onFailReceiveType' => $this->opt('pro_on_fail'),
			'sms'               => $this->opt('pro_sms') === '1',
			'parcels'           => array($parcel),
		);
		if ($draft['cod'] > 0) {
			$body['postPay']                = $draft['cod'];
			$body['postPayPaidByRecipient'] = $this->opt('pro_cod_fee_recipient') === '1';
			// To a bank account only for a FOP or a company sender (section 4, postPay).
			if ($this->opt('sender_type') !== 'INDIVIDUAL' && trim($this->opt('sender_iban')) !== '') {
				$body['transferPostPayToBankAccount'] = true;
			}
		}
		$r = $api->createShipment($body);
		if (!$r['success'] || empty($r['data']['barcode'])) {
			return $this->errorOut($id, $this->fail($this->t('e_shipment'), $r));
		}
		$bc = (string)$r['data']['barcode'];
		$this->save($id, array(
			'barcode' => $bc, 'shipment_uuid' => isset($r['data']['uuid']) ? (string)$r['data']['uuid'] : '', 'lifecycle' => 'CREATED',
			'status_code' => 0, 'status_text' => $this->t('ls_CREATED'), 'delivery_price' => isset($r['data']['deliveryPrice']) ? (float)$r['data']['deliveryPrice'] : null,
			'cod_amount' => $draft['cod'], 'declared_cost' => $draft['declared'], 'weight_g' => $draft['weight'], 'group_uuid' => null, 'last_error' => '',
			'last_polled_at' => null,
		));
		$note = $draft['cod'] > 0 ? $this->tShop('h_created_cod', $bc, number_format($draft['cod'], 2, '.', '')) : $this->tShop('h_created', $bc);
		$link = self::TRACK_URL . rawurlencode($bc);
		$show = $this->opt('pro_customer_tracking') === '1';
		$this->history($id, (int)$o['order_status_id'], $note . "\n" . $this->tShop('h_track', $link), $show);
		if ($show && $this->opt('pro_customer_mail') === '1' && is_callable($this->mailer) && !empty($o['email'])) {
			call_user_func($this->mailer, (string)$o['email'], $this->tShop('mail_subject', $id), $this->tShop('mail_body', $id, $bc, $link));
		}
		return array('ok' => true, 'message' => $this->t('ok_created', $bc), 'barcode' => $bc);
	}

	/** Order history entry, optionally visible to the customer; status change optional. */
	private function history($orderId, $statusId, $comment, $notify) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET order_id = '" . (int)$orderId . "', order_status_id = '" . (int)$statusId . "', notify = '" . ($notify ? 1 : 0) . "', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
	}

	public function delete($orderId) {
		if (!$this->isPro()) {
			return array('ok' => false, 'message' => $this->t('e_not_pro'));
		}
		$row = $this->row($orderId);
		if (!$row || empty($row['barcode'])) {
			return array('ok' => false, 'message' => $this->t('e_no_shipment'));
		}
		if (!empty($row['lifecycle']) && $row['lifecycle'] !== 'CREATED') {
			return array('ok' => false, 'message' => $this->t('e_accepted'));
		}
		$api = $this->api();
		if (!$api) {
			return array('ok' => false, 'message' => implode(' ', $this->gaps()));
		}
		$r = $api->deleteShipment(!empty($row['shipment_uuid']) ? $row['shipment_uuid'] : $row['barcode']);
		if (!$r['success']) {
			return array('ok' => false, 'message' => $this->fail($this->t('e_delete'), $r));
		}
		$bc = $row['barcode'];
		$this->save($orderId, array('barcode' => null, 'shipment_uuid' => null, 'lifecycle' => null, 'status_code' => 0, 'status_text' => null, 'group_uuid' => null, 'delivery_price' => null, 'last_polled_at' => null));
		$o = $this->order($orderId);
		$this->history($orderId, $o ? (int)$o['order_status_id'] : 0, $this->tShop('h_deleted', $bc), false);
		return array('ok' => true, 'message' => $this->t('ok_deleted', $bc));
	}

	private function size() {
		$s = $this->opt('pro_label_size');
		return in_array($s, array('SIZE_A4', 'SIZE_A5'), true) ? $s : '';
	}

	/** @return array{ok:bool,message?:string,pdf?:string} */
	public function labels(array $ids) {
		if (!$this->isPro()) {
			return array('ok' => false, 'message' => $this->t('e_not_pro'));
		}
		$bcs = array();
		foreach ($ids as $id) {
			$r = $this->row((int)$id);
			if ($r && !empty($r['barcode'])) {
				$bcs[] = $r['barcode'];
			}
		}
		if (!$bcs) {
			return array('ok' => false, 'message' => $this->t('e_none_selected'));
		}
		$api = $this->api();
		if (!$api) {
			return array('ok' => false, 'message' => implode(' ', $this->gaps()));
		}
		$r = count($bcs) === 1 ? $api->sticker($bcs[0], $this->size()) : $api->stickers($bcs, $this->size());
		return $r['success'] ? array('ok' => true, 'pdf' => $r['body']) : array('ok' => false, 'message' => $this->fail($this->t('e_labels'), $r));
	}

	public function registry(array $ids) {
		if (!$this->isPro()) {
			return array('ok' => false, 'message' => $this->t('e_not_pro'));
		}
		$rows = array();
		foreach ($ids as $id) {
			$r = $this->row((int)$id);
			if ($r && !empty($r['shipment_uuid'])) {
				$rows[] = $r;
			}
		}
		if (!$rows) {
			return array('ok' => false, 'message' => $this->t('e_none_selected'));
		}
		$api = $this->api();
		if (!$api) {
			return array('ok' => false, 'message' => implode(' ', $this->gaps()));
		}
		$groups = array_unique(array_map(function ($r) { return (string)$r['group_uuid']; }, $rows));
		if (count($groups) === 1 && reset($groups) !== '') {
			$group = (string)reset($groups);
		} else {
			$c = $api->createGroup($this->t('group_name', date('d.m.Y H:i')), $this->opt('sender_uuid'), (string)($this->config->get(CcUpLicense::PREFIX . 'service_type') ?: 'STANDARD'));
			if (!$c['success'] || empty($c['data']['uuid'])) {
				return array('ok' => false, 'message' => $this->fail($this->t('e_group'), $c));
			}
			$group  = (string)$c['data']['uuid'];
			$failed = array();
			foreach ($rows as $r) {
				$a = $api->addToGroup($group, $r['shipment_uuid']);
				if (!$a['success']) {
					$failed[] = '#' . $r['order_id'] . ' (' . implode('; ', $a['errors']) . ')';
					continue;
				}
				$this->save((int)$r['order_id'], array('group_uuid' => $group));
			}
			if (count($failed) === count($rows)) {
				return array('ok' => false, 'message' => $this->t('e_group_add', implode(', ', $failed)));
			}
		}
		$r = $api->form103a($group);
		return $r['success'] ? array('ok' => true, 'pdf' => $r['body']) : array('ok' => false, 'message' => $this->fail($this->t('e_103a'), $r));
	}

	/** Refresh statuses of these orders; returns how many changed. */
	public function track(array $ids) {
		if (!$this->isPro()) {
			return 0;
		}
		$by = array();
		foreach ($ids as $id) {
			$r = $this->row((int)$id);
			if ($r && !empty($r['barcode'])) {
				$by[$r['barcode']] = $r;
			}
		}
		$api = $this->api();
		if (!$by || !$api) {
			return 0;
		}
		$changed = 0;
		foreach (array_chunk(array_keys($by), 50) as $chunk) {
			$resp = $api->statuses($chunk);
			$last = array();
			if ($resp['success'] && is_array($resp['data'])) {
				foreach ($resp['data'] as $s) {
					if (!is_array($s) || empty($s['barcode'])) {
						continue;
					}
					$b = (string)$s['barcode'];
					if (!isset($last[$b]) || (int)(isset($s['step']) ? $s['step'] : 0) >= (int)(isset($last[$b]['step']) ? $last[$b]['step'] : 0)) {
						$last[$b] = $s;
					}
				}
			}
			foreach ($chunk as $b) {
				$row = $by[$b];
				$this->save((int)$row['order_id'], array('last_polled_at' => 'NOW()'));
				if (isset($last[$b]) && $this->apply($row, $last[$b])) {
					$changed++;
				}
			}
		}
		return $changed;
	}

	private function apply(array $row, array $s) {
		$event = (int)(isset($s['event']) ? $s['event'] : 0);
		$code  = isset(self::$events[$event]) ? self::$events[$event] : 'DELIVERING';
		if ((int)$row['status_code'] === $event && (string)$row['lifecycle'] === $code) {
			return false;
		}
		$name  = trim((string)(isset($s['eventName']) ? $s['eventName'] : ''));
		$where = trim((string)(isset($s['name']) ? $s['name'] : ''));
		$id    = (int)$row['order_id'];
		$this->save($id, array('status_code' => $event, 'lifecycle' => $code, 'status_text' => $name !== '' ? $name : $this->t('ls_' . $code)));
		$o      = $this->order($id);
		$status = $o ? (int)$o['order_status_id'] : 0;
		$target = 0;
		if ($code === 'DELIVERED') {
			$target = (int)$this->opt('pro_status_delivered');
		} elseif ($code === 'RETURNED') {
			$target = (int)$this->opt('pro_status_returned');
		}
		$text = $this->tShop('h_status', $row['barcode'], ($name !== '' ? $name : $this->tShop('ls_' . $code)) . ($where !== '' ? ' (' . $where . ')' : ''));
		if ($target > 0 && $target !== $status) {
			$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . $target . "', date_modified = NOW() WHERE order_id = '" . $id . "'");
			$status = $target;
		}
		$this->history($id, $status, $text, false);
		return true;
	}

	/** Cron / page open: open shipments not checked for 2 hours, at most 50. */
	public function trackDue() {
		if (!$this->isPro()) {
			return 0;
		}
		$q = $this->db->query("SELECT order_id FROM `" . DB_PREFIX . "up_shipment` WHERE barcode IS NOT NULL AND barcode <> '' AND (lifecycle IS NULL OR lifecycle NOT IN ('" . implode("','", self::$final) . "')) AND (last_polled_at IS NULL OR last_polled_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)) AND created_at > DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY last_polled_at ASC LIMIT 50");
		$ids = array();
		foreach ($q->rows as $r) {
			$ids[] = (int)$r['order_id'];
		}
		return $ids ? $this->track($ids) : 0;
	}

	/** One endpoint for the settings and shipments screens. */
	public function handle($op, array $post) {
		$cfg = $this->config;
		$db  = $this->db;
		switch ($op) {
			case 'lic_activate':
				$r = CcUpLicense::activate($cfg, $db, isset($post['key']) ? $post['key'] : '');
				return !empty($r['ok']) ? array('ok' => true, 'message' => $this->t('ok_activated'), 'reload' => true) : array('ok' => false, 'message' => $this->licErr($r));
			case 'lic_trial':
				$r = CcUpLicense::startTrial($cfg, $db, isset($post['email']) ? $post['email'] : '');
				return !empty($r['ok']) ? array('ok' => true, 'message' => $this->t('ok_trial'), 'reload' => true) : array('ok' => false, 'message' => $this->licErr($r));
			case 'lic_verify':
				$r = CcUpLicense::verify($cfg, $db);
				return !empty($r['ok']) ? array('ok' => true, 'message' => $this->t('ok_activated'), 'reload' => true) : array('ok' => false, 'message' => $this->licErr($r));
			case 'lic_deactivate':
				CcUpLicense::deactivate($cfg, $db);
				return array('ok' => true, 'message' => $this->t('ok_released'), 'reload' => true);
			case 'save':
				if (!$this->isPro()) {
					return array('ok' => false, 'message' => $this->t('e_not_pro'));
				}
				$this->ensureSchema();
				return $this->saveSettings($post) + array('reload' => true);
			case 'sender':
				if (!$this->isPro()) {
					return array('ok' => false, 'message' => $this->t('e_not_pro'));
				}
				return $this->createSender() + array('reload' => true);
			case 'create':
			case 'delete':
			case 'track':
				$this->ensureSchema();
				$ids = array_slice(array_filter(array_map('intval', explode(',', isset($post['ids']) ? (string)$post['ids'] : ''))), 0, 50);
				if (!$ids) {
					return array('ok' => false, 'message' => $this->t('select_some'));
				}
				if ($op === 'track') {
					$n = $this->track($ids);
					return array('ok' => true, 'message' => $n ? $this->t('ok_tracked', $n) : $this->t('no_new_status'), 'reload' => true);
				}
				if (count($ids) === 1) {
					$r = $op === 'create' ? $this->create($ids[0]) : $this->delete($ids[0]);
					return $r + array('reload' => true);
				}
				$okN = 0;
				$errs = array();
				foreach ($ids as $id) {
					$r = $this->create($id);
					if (!empty($r['ok'])) {
						$okN++;
					} else {
						$errs[] = '#' . $id . ': ' . $r['message'];
					}
				}
				return array('ok' => !$errs, 'message' => trim($this->t('bulk_done', $okN) . ' ' . implode(' ', $errs)), 'reload' => true);
		}
		return array('ok' => false, 'message' => 'unknown op');
	}

	private function licErr(array $r) {
		$k = 'e_' . CcUpLicense::errorCode($r);
		return isset($this->L[$k]) ? $this->L[$k] : $this->t('e_default');
	}

	// ------------------------------------------------------------- views

	private function css() {
		return '<style>.ccup{font-size:14px;line-height:1.5;color:#1f2937}.ccup-card{background:#fff;border:1px solid #d9dee5;border-radius:8px;padding:16px 20px;margin:0 0 16px}'
			. '.ccup h3{font-size:17px;margin:0 0 8px;font-weight:600}.ccup ul{margin:6px 0 12px 20px;padding:0}.ccup-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}'
			. '.ccup input[type=text],.ccup input[type=email],.ccup input[type=password],.ccup select{height:36px;padding:4px 10px;border:1px solid #c5ccd6;border-radius:6px;font-size:16px;max-width:100%;box-sizing:border-box;background:#fff}'
			. '.ccup-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px 20px}.ccup-grid label{display:block;font-weight:600;margin-bottom:4px}.ccup-grid input,.ccup-grid select{width:100%}'
			. '.ccup-help{color:#6b7280;font-size:12px;margin-top:2px}.ccup-btn{display:inline-block;height:36px;padding:0 14px;border-radius:6px;border:1px solid #2563eb;background:#fff;color:#2563eb;font-size:14px;cursor:pointer;line-height:34px;text-decoration:none}'
			. '.ccup-btn:hover{text-decoration:none}.ccup-btn--primary{background:#2563eb;color:#fff}.ccup-btn--danger{border-color:#b91c1c;color:#b91c1c}.ccup-btn[disabled]{opacity:.6}'
			. '.ccup-badge{display:inline-block;padding:2px 8px;border-radius:10px;background:#eef0f3;font-size:12px;vertical-align:middle}.ccup-badge--pro{background:#e7f6ec;color:#047857}'
			. '.ccup-msg{font-weight:600;margin:8px 0 0;min-height:1em}.ccup-msg.is-error{color:#b91c1c}.ccup-msg.is-ok{color:#047857}.ccup-gaps{color:#92400e}'
			. '.ccup-table{width:100%;border-collapse:collapse}.ccup-table th,.ccup-table td{border-bottom:1px solid #e5e7eb;padding:8px 6px;text-align:left;vertical-align:top}.ccup-table th{font-size:12px;color:#6b7280;text-transform:uppercase}'
			. '.ccup-table .ccup-actions{display:flex;flex-wrap:wrap;gap:4px}.ccup-err{color:#b91c1c;font-size:12px}.ccup-wrap{overflow-x:auto}'
			. '@media(max-width:700px){.ccup-table thead{display:none}.ccup-table tr{display:block;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px;padding:6px}.ccup-table td{display:flex;gap:8px;border:0;padding:4px 6px}.ccup-table td:before{content:attr(data-l);font-weight:600;min-width:110px;color:#6b7280}}</style>';
	}

	private function js($endpoint) {
		$cfg = json_encode(array('url' => $endpoint, 'working' => $this->t('working'), 'network' => $this->t('network'), 'confirm' => $this->t('confirm_delete'), 'select' => $this->t('select_some')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		return '<script>(function(){var C=' . $cfg . ';'
			. 'function say(b,t,e){if(!b)return;b.textContent=t||"";b.className="ccup-msg "+(e?"is-error":"is-ok");}'
			. 'function box(el){var s=el.closest("[data-ccup-scope]");return s?s.querySelector(".ccup-msg"):document.querySelector(".ccup-msg");}'
			. 'function ids(){var a=[];document.querySelectorAll("input.ccup-cb:checked").forEach(function(c){a.push(c.value);});return a.join(",");}'
			. 'document.addEventListener("click",function(e){var b=e.target.closest("[data-ccup]");if(!b)return;e.preventDefault();var op=b.getAttribute("data-ccup");'
			. 'var f=new FormData();f.append("op",op);var scope=b.closest("[data-ccup-scope]")||document;'
			. 'if(op==="save"){scope.querySelectorAll("[data-pro]").forEach(function(i){f.append(i.name,i.type==="checkbox"?(i.checked?"1":"0"):i.value);});}'
			. 'if(op==="lic_activate"){f.append("key",(scope.querySelector("#ccup-key")||{}).value||"");}'
			. 'if(op==="lic_trial"){f.append("email",(scope.querySelector("#ccup-email")||{}).value||"");}'
			. 'var one=b.getAttribute("data-id");if(one){f.append("ids",one);}else if(b.hasAttribute("data-bulk")){var v=ids();if(!v){say(box(b),C.select,true);return;}f.append("ids",v);'
			. 'if(op==="labels"||op==="registry"){window.open(b.getAttribute("data-href")+"&ids="+encodeURIComponent(v),"_blank");return;}}'
			. 'if(op==="delete"&&!window.confirm(C.confirm))return;var m=box(b);b.disabled=true;say(m,C.working,false);'
			. 'fetch(C.url,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(r){b.disabled=false;say(m,r.message,!r.ok);if(r.ok&&r.reload){setTimeout(function(){location.reload();},1300);}})'
			. '.catch(function(){b.disabled=false;say(m,C.network,true);});});'
			. 'var all=document.getElementById("ccup-all");if(all){all.addEventListener("change",function(){document.querySelectorAll("input.ccup-cb").forEach(function(c){c.checked=all.checked;});});}'
			. '})();</script>';
	}

	private function licStatusLine() {
		$state = CcUpLicense::state($this->config);
		$text  = $state === 'trial' ? $this->t('st_trial', CcUpLicense::trialDaysLeft($this->config)) : $this->t('st_' . $state);
		return $text;
	}

	/**
	 * Licence panel + Pro settings for the module settings page.
	 *
	 * @param array $u endpoint (POST JSON), shipments (page), cron (catalog URL), statuses (id => name)
	 */
	public function renderSettings(array $u) {
		$pro = $this->isPro();
		$h   = $this->css() . '<div class="ccup" id="ccup-pro"><div class="ccup-card" data-ccup-scope>';
		$h  .= '<h3>' . self::h($this->t('pro_title')) . ' <span class="ccup-badge' . ($pro ? ' ccup-badge--pro' : '') . '">' . self::h($this->t($pro ? 'badge_pro' : 'badge_free')) . '</span></h3>';
		if (CcUpLicense::hasKey($this->config)) {
			$h .= '<p>' . self::h($this->t('licence')) . ' <strong>' . self::h($this->licStatusLine()) . '</strong></p>';
			if (CcUpLicense::state($this->config) === 'trial_ended') {
				$h .= '<p>' . self::h($this->t('trial_ended_note')) . '</p>';
			}
		} else {
			$h .= '<p>' . self::h($this->t('features_intro')) . '</p><ul>';
			foreach (array('f_shipment', 'f_cod', 'f_labels', 'f_bulk', 'f_registry', 'f_tracking', 'f_customer', 'f_delete') as $f) {
				$h .= '<li>' . self::h($this->t($f)) . '</li>';
			}
			$h .= '</ul>';
		}
		$h .= '<div class="ccup-row"><input type="text" id="ccup-key" placeholder="' . self::h($this->t('key_ph')) . '" autocomplete="off" style="min-width:240px">'
			. '<button type="button" class="ccup-btn ccup-btn--primary" data-ccup="lic_activate">' . self::h($this->t('btn_activate')) . '</button>';
		if (CcUpLicense::hasKey($this->config)) {
			$h .= '<button type="button" class="ccup-btn" data-ccup="lic_verify">' . self::h($this->t('btn_verify')) . '</button>'
				. '<button type="button" class="ccup-btn" data-ccup="lic_deactivate">' . self::h($this->t('btn_release')) . '</button>';
		}
		$h .= '</div>';
		if (CcUpLicense::trialAvailable($this->config)) {
			$h .= '<div class="ccup-row"><input type="email" id="ccup-email" placeholder="' . self::h($this->t('trial_email_ph')) . '" value="' . self::h((string)$this->config->get('config_email')) . '" style="min-width:240px">'
				. '<button type="button" class="ccup-btn" data-ccup="lic_trial">' . self::h($this->t('btn_trial')) . '</button></div>';
		}
		$h .= '<p><a href="' . self::h(CcUpLicense::MODULE_URL) . '" target="_blank" rel="noopener">' . self::h($this->t('buy')) . '</a>';
		if ($pro) {
			$h .= ' · <a href="' . self::h($u['shipments']) . '">' . self::h($this->t('btn_shipments')) . '</a>';
		}
		$h .= '</p>';
		if (!$pro) {
			$h .= '<p class="ccup-help">' . self::h($this->t('pro_off_note')) . '</p>';
		}
		$h .= '<p class="ccup-msg" role="status"></p></div>';

		if ($pro) {
			$h .= '<div class="ccup-card" data-ccup-scope><h3>' . self::h($this->t('h_settings')) . '</h3><p class="ccup-help">' . self::h($this->t('h_settings_help')) . '</p>';
			if ($g = $this->gaps()) {
				$h .= '<p class="ccup-gaps"><strong>' . self::h($this->t('gaps')) . '</strong> ' . self::h(implode(' ', $g)) . '</p>';
			}
			$h .= '<div class="ccup-grid">';
			$h .= $this->field('token', 'password', null, 'token_help', $this->secret('token') !== '');
			$h .= $this->field('tracking_bearer', 'password', null, 'tracking_bearer_help', $this->secret('tracking_bearer') !== '');
			$h .= $this->field('sender_type', 'select', array('INDIVIDUAL' => $this->t('type_individual'), 'PRIVATE_ENTREPRENEUR' => $this->t('type_fop'), 'COMPANY' => $this->t('type_company')));
			$h .= $this->field('sender_last_name', 'text', null, '', false, 'sender_last');
			$h .= $this->field('sender_first_name', 'text', null, '', false, 'sender_first');
			$h .= $this->field('sender_middle_name', 'text', null, 'sender_middle_help', false, 'sender_middle');
			$h .= $this->field('sender_company', 'text');
			$h .= $this->field('sender_code', 'text', null, 'sender_code_help');
			$h .= $this->field('sender_phone', 'text');
			$h .= $this->field('sender_iban', 'text', null, 'sender_iban_help');
			$h .= $this->field('sender_uuid', 'text', null, 'sender_uuid_help');
			$h .= $this->field('pro_paid_by_recipient', 'select', array('1' => $this->t('paid_recipient'), '0' => $this->t('paid_shop')), '', false, 'paid_by');
			$h .= $this->field('pro_cod', 'select', array('1' => $this->t('yes'), '0' => $this->t('no')), '', false, 'cod');
			$h .= $this->field('pro_cod_payments', 'text', null, 'cod_payments_help', false, 'cod_payments');
			$h .= $this->field('pro_cod_amount', 'select', array('total' => $this->t('cod_total'), 'goods' => $this->t('cod_goods')), '', false, 'cod_amount');
			$h .= $this->field('pro_cod_fee_recipient', 'select', array('1' => $this->t('fee_recipient'), '0' => $this->t('fee_shop')), '', false, 'cod_fee');
			$h .= $this->field('default_weight', 'text');
			$h .= '<div><label>' . self::h($this->t('dims')) . '</label><div class="ccup-row" style="margin:0">'
				. '<input type="text" data-pro name="default_length" value="' . self::h($this->opt('default_length')) . '" style="width:30%">'
				. '<input type="text" data-pro name="default_width" value="' . self::h($this->opt('default_width')) . '" style="width:30%">'
				. '<input type="text" data-pro name="default_height" value="' . self::h($this->opt('default_height')) . '" style="width:30%"></div></div>';
			$h .= $this->field('pro_on_fail', 'select', array('RETURN' => $this->t('fail_return'), 'RETURN_AFTER_7_DAYS' => $this->t('fail_return7'), 'PROCESS_AS_REFUSAL' => $this->t('fail_refusal')), '', false, 'on_fail');
			$h .= $this->field('pro_sms', 'select', array('0' => $this->t('no'), '1' => $this->t('yes')), '', false, 'sms');
			$h .= $this->field('pro_label_size', 'select', array('' => $this->t('size_100'), 'SIZE_A4' => $this->t('size_a4'), 'SIZE_A5' => $this->t('size_a5')), '', false, 'label_size');
			$st = array('0' => $this->t('no_change')) + (isset($u['statuses']) ? $u['statuses'] : array());
			$h .= $this->field('pro_status_delivered', 'select', $st, '', false, 'status_delivered');
			$h .= $this->field('pro_status_returned', 'select', $st, '', false, 'status_returned');
			$h .= $this->field('pro_customer_tracking', 'select', array('1' => $this->t('yes'), '0' => $this->t('no')), '', false, 'customer_tracking');
			$h .= $this->field('pro_customer_mail', 'select', array('1' => $this->t('yes'), '0' => $this->t('no')), '', false, 'customer_mail');
			$h .= '</div><div class="ccup-row"><button type="button" class="ccup-btn ccup-btn--primary" data-ccup="save">' . self::h($this->t('btn_save_pro')) . '</button>'
				. '<button type="button" class="ccup-btn" data-ccup="sender">' . self::h($this->t('btn_sender')) . '</button>'
				. '<a class="ccup-btn" href="' . self::h($u['shipments']) . '">' . self::h($this->t('btn_shipments')) . '</a></div>';
			if (!empty($u['cron'])) {
				$key = (string)$this->config->get(CcUpLicense::PREFIX . 'cron_key');
				if ($key !== '') {
					$h .= '<p><strong>' . self::h($this->t('cron_url')) . ':</strong> <code style="word-break:break-all">' . self::h($u['cron'] . $key) . '</code><br><span class="ccup-help">' . self::h($this->t('cron_help')) . '</span></p>';
				}
			}
			$h .= '<p class="ccup-msg" role="status"></p></div>';
		}
		return $h . '</div>' . $this->js($u['endpoint']);
	}

	private function field($key, $type, $options = null, $help = '', $hasSecret = false, $labelKey = '') {
		$label = $this->t($labelKey !== '' ? $labelKey : $key);
		$out   = '<div><label for="ccup-' . $key . '">' . self::h($label) . '</label>';
		if ($type === 'select') {
			$cur  = $this->opt($key);
			$out .= '<select id="ccup-' . $key . '" data-pro name="' . $key . '">';
			foreach ((array)$options as $v => $l) {
				$out .= '<option value="' . self::h($v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . self::h($l) . '</option>';
			}
			$out .= '</select>';
		} elseif ($type === 'password') {
			$out .= '<input type="password" id="ccup-' . $key . '" data-pro name="' . $key . '" value="" autocomplete="new-password" placeholder="' . ($hasSecret ? '•••••••• ' . self::h($this->t('saved')) : '') . '">';
		} else {
			$out .= '<input type="text" id="ccup-' . $key . '" data-pro name="' . $key . '" value="' . self::h($this->opt($key)) . '">';
		}
		if ($help !== '') {
			$out .= '<div class="ccup-help">' . self::h($this->t($help)) . '</div>';
		}
		return $out . '</div>';
	}

	/**
	 * Shipments page body.
	 *
	 * @param array $u endpoint, pdf (URL ending with ...&what=), settings, order (URL ending with order_id=)
	 */
	public function renderShipments(array $u, $page = 1) {
		$this->ensureSchema();
		$h = $this->css() . '<div class="ccup" data-ccup-scope>';
		if (!$this->isPro()) {
			$h .= '<div class="ccup-card"><p>' . self::h($this->t('e_not_pro')) . '</p><p><a class="ccup-btn ccup-btn--primary" href="' . self::h($u['settings']) . '">' . self::h($this->t('back_settings')) . '</a></p></div></div>';
			return $h;
		}
		$this->trackDue();
		$per  = 50;
		$page = max(1, (int)$page);
		$rows = $this->db->query("SELECT s.*, o.firstname, o.lastname, o.shipping_firstname, o.shipping_lastname, o.date_added, o.total, o.order_status_id, o.currency_code FROM `" . DB_PREFIX . "up_shipment` s JOIN `" . DB_PREFIX . "order` o ON o.order_id = s.order_id WHERE o.order_status_id > 0 ORDER BY s.order_id DESC LIMIT " . (($page - 1) * $per) . ", " . ($per + 1))->rows;
		$more = count($rows) > $per;
		$rows = array_slice($rows, 0, $per);
		$h .= '<div class="ccup-card"><p>' . self::h($this->t('sh_intro')) . '</p>';
		if ($g = $this->gaps()) {
			$h .= '<p class="ccup-gaps"><strong>' . self::h($this->t('gaps')) . '</strong> ' . self::h(implode(' ', $g)) . ' <a href="' . self::h($u['settings']) . '">' . self::h($this->t('back_settings')) . '</a></p>';
		}
		$h .= '<div class="ccup-row"><button type="button" class="ccup-btn ccup-btn--primary" data-ccup="create" data-bulk>' . self::h($this->t('bulk_create')) . '</button>'
			. '<button type="button" class="ccup-btn" data-ccup="labels" data-bulk data-href="' . self::h($u['pdf'] . 'labels') . '">' . self::h($this->t('bulk_labels')) . '</button>'
			. '<button type="button" class="ccup-btn" data-ccup="registry" data-bulk data-href="' . self::h($u['pdf'] . 'registry') . '">' . self::h($this->t('bulk_registry')) . '</button>'
			. '<button type="button" class="ccup-btn" data-ccup="track" data-bulk>' . self::h($this->t('bulk_track')) . '</button>'
			. '<a class="ccup-btn" href="' . self::h($u['settings']) . '">' . self::h($this->t('back_settings')) . '</a></div><p class="ccup-msg" role="status"></p>';
		if (!$rows) {
			return $h . '<p>' . self::h($this->t('no_rows')) . '</p></div></div>' . $this->js($u['endpoint']);
		}
		$h .= '<div class="ccup-wrap"><table class="ccup-table"><thead><tr><th><input type="checkbox" id="ccup-all"></th><th>' . self::h($this->t('col_order')) . '</th><th>' . self::h($this->t('col_date')) . '</th><th>' . self::h($this->t('col_customer')) . '</th><th>'
			. self::h($this->t('col_office')) . '</th><th>' . self::h($this->t('col_cod')) . '</th><th>' . self::h($this->t('col_barcode')) . '</th><th>' . self::h($this->t('col_status')) . '</th><th>' . self::h($this->t('col_actions')) . '</th></tr></thead><tbody>';
		foreach ($rows as $r) {
			$id   = (int)$r['order_id'];
			$name = trim(($r['shipping_firstname'] ?: $r['firstname']) . ' ' . ($r['shipping_lastname'] ?: $r['lastname']));
			$bc   = (string)$r['barcode'];
			$o    = $this->order($id);
			$cod  = $bc !== '' ? (float)$r['cod_amount'] : ($o ? $this->draft($o)['cod'] : 0);
			$h .= '<tr><td data-l=""><input type="checkbox" class="ccup-cb" value="' . $id . '"></td>'
				. '<td data-l="' . self::h($this->t('col_order')) . '"><a href="' . self::h($u['order'] . $id) . '">#' . $id . '</a></td>'
				. '<td data-l="' . self::h($this->t('col_date')) . '">' . self::h(date('d.m.Y', strtotime($r['date_added']))) . '</td>'
				. '<td data-l="' . self::h($this->t('col_customer')) . '">' . self::h($name) . '</td>'
				. '<td data-l="' . self::h($this->t('col_office')) . '">' . self::h(trim($r['recipient_postindex'] . ' ' . $r['recipient_office_name'])) . '</td>'
				. '<td data-l="' . self::h($this->t('col_cod')) . '">' . ($cod > 0 ? self::h(number_format($cod, 2, '.', ' ')) . ' грн' : '—') . '</td>'
				. '<td data-l="' . self::h($this->t('col_barcode')) . '">' . ($bc !== '' ? '<a href="' . self::h(self::TRACK_URL . rawurlencode($bc)) . '" target="_blank" rel="noopener">' . self::h($bc) . '</a>' : '<span class="ccup-help">' . self::h($this->t('no_shipment_yet')) . '</span>') . '</td>'
				. '<td data-l="' . self::h($this->t('col_status')) . '">' . ($bc !== '' ? self::h($r['status_text'] ?: $this->t('ls_' . ($r['lifecycle'] ?: 'CREATED'))) : '') . (!empty($r['last_error']) && $bc === '' ? '<div class="ccup-err">' . self::h($r['last_error']) . '</div>' : '') . '</td>'
				. '<td data-l="' . self::h($this->t('col_actions')) . '"><div class="ccup-actions">';
			if ($bc === '') {
				$h .= '<button type="button" class="ccup-btn ccup-btn--primary" data-ccup="create" data-id="' . $id . '">' . self::h($this->t('btn_create')) . '</button>';
			} else {
				$h .= '<a class="ccup-btn" href="' . self::h($u['pdf'] . 'labels&ids=' . $id) . '" target="_blank" rel="noopener">' . self::h($this->t('btn_label')) . '</a>'
					. '<button type="button" class="ccup-btn" data-ccup="track" data-id="' . $id . '">' . self::h($this->t('btn_track')) . '</button>';
				if ((string)$r['lifecycle'] === '' || $r['lifecycle'] === 'CREATED') {
					$h .= '<button type="button" class="ccup-btn ccup-btn--danger" data-ccup="delete" data-id="' . $id . '">' . self::h($this->t('btn_delete')) . '</button>';
				}
			}
			$h .= '</div></td></tr>';
		}
		$h .= '</tbody></table></div><div class="ccup-row">';
		if ($page > 1) {
			$h .= '<a class="ccup-btn" href="' . self::h($u['page'] . ($page - 1)) . '">' . self::h($this->t('prev')) . '</a>';
		}
		if ($more) {
			$h .= '<a class="ccup-btn" href="' . self::h($u['page'] . ($page + 1)) . '">' . self::h($this->t('next')) . '</a>';
		}
		return $h . '</div></div></div>' . $this->js($u['endpoint']);
	}

	/** PDF download (labels / registry) or an explanation page. */
	public function pdfResponse($what, $idsRaw) {
		$this->ensureSchema();
		$ids = array_slice(array_filter(array_map('intval', explode(',', (string)$idsRaw))), 0, 100);
		return $what === 'registry' ? $this->registry($ids) : $this->labels($ids);
	}
}
