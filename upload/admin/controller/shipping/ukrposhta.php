<?php
namespace Opencart\Admin\Controller\Extension\Ukrposhta\Shipping;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/cache.php';

class Ukrposhta extends \Opencart\System\Engine\Controller {
	// Secret fields stored encrypted at rest. Read-only build needs only the
	// eCom Bearer (Address Classifier + tariff both authorize with it).
	private const SECRET_FIELDS = [
		'shipping_ukrposhta_bearer',
	];

	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) { ob_clean(); }
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function secret(string $key): string {
		$raw = (string)$this->config->get($key);
		return $raw === '' ? '' : \Opencart\System\Library\Ukrposhta\Crypto::decrypt($raw);
	}

	private function client(): ?\Opencart\System\Library\Ukrposhta\Client {
		$bearer = $this->secret('shipping_ukrposhta_bearer');
		if ($bearer === '') return null;
		return new \Opencart\System\Library\Ukrposhta\Client(
			$bearer,
			'',
			(bool)$this->config->get('shipping_ukrposhta_sandbox')
		);
	}

	public function install(): void {
		$prefix = DB_PREFIX;

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}up_shipment` (
			`shipment_id` int NOT NULL AUTO_INCREMENT,
			`order_id` int NOT NULL,
			`barcode` varchar(32) DEFAULT NULL,
			`shipment_uuid` varchar(64) DEFAULT NULL,
			`recipient_postindex` varchar(10) DEFAULT NULL,
			`recipient_city_name` varchar(255) DEFAULT NULL,
			`recipient_office_name` varchar(255) DEFAULT NULL,
			`recipient_name` varchar(255) DEFAULT NULL,
			`recipient_phone` varchar(32) DEFAULT NULL,
			`service_type` varchar(16) DEFAULT 'W2W',
			`weight` decimal(10,3) DEFAULT NULL,
			`declared_cost` decimal(15,2) DEFAULT NULL,
			`cod_amount` decimal(15,2) DEFAULT NULL,
			`status_code` int DEFAULT '0',
			`status_text` varchar(255) DEFAULT NULL,
			`created_at` datetime DEFAULT NULL,
			`last_polled_at` datetime DEFAULT NULL,
			PRIMARY KEY (`shipment_id`),
			KEY `order_id` (`order_id`),
			KEY `barcode` (`barcode`),
			KEY `status_code` (`status_code`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}up_regions` (
			`region_id` varchar(16) NOT NULL,
			`region_ua` varchar(255) DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`region_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}up_cities` (
			`city_id` varchar(16) NOT NULL,
			`region_id` varchar(16) NOT NULL,
			`district_id` varchar(16) DEFAULT NULL,
			`city_ua` varchar(255) DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`city_id`),
			KEY `region_id` (`region_id`),
			KEY `city_ua` (`city_ua`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}up_offices` (
			`office_id` int NOT NULL AUTO_INCREMENT,
			`city_id` varchar(16) NOT NULL,
			`postindex` varchar(10) NOT NULL,
			`name` varchar(512) DEFAULT NULL,
			`address` varchar(512) DEFAULT NULL,
			`is_postomat` tinyint(1) DEFAULT '0',
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`office_id`),
			UNIQUE KEY `city_pi` (`city_id`,`postindex`),
			KEY `updated_at` (`updated_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->load->model('setting/event');
		foreach (['ukrposhta_order_added', 'ukrposhta_order_history_added', 'ukrposhta_footer_inject'] as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Throwable $e) {}
		}
		$this->model_setting_event->addEvent([
			'code'        => 'ukrposhta_order_added',
			'description' => 'Ukrposhta — capture office selection on order create',
			'trigger'     => 'catalog/model/checkout/order.addOrder/after',
			'action'      => 'extension/ukrposhta/events.orderAdded',
			'status'      => 1,
			'sort_order'  => 10,
		]);
		$this->model_setting_event->addEvent([
			'code'        => 'ukrposhta_footer_inject',
			'description' => 'Ukrposhta — inject checkout picker on storefront footer',
			'trigger'     => 'catalog/view/common/footer/after',
			'action'      => 'extension/ukrposhta/events.footerInject',
			'status'      => 1,
			'sort_order'  => 10,
		]);

		$this->load->model('setting/cron');
		foreach (['ukrposhta_poll', 'ukrposhta_sync_regions'] as $code) {
			try { $this->model_setting_cron->deleteCronByCode($code); } catch (\Throwable $e) {}
		}
		$this->model_setting_cron->addCron('ukrposhta_sync_regions', 'Ukrposhta — weekly region classifier sync', 'week', 'extension/ukrposhta/cron.syncRegions', true);

		$this->load->model('user/user_group');
		foreach (['extension/ukrposhta/shipping/ukrposhta'] as $route) {
			try {
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'access', $route);
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'modify', $route);
			} catch (\Throwable $e) {}
		}
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		foreach (['ukrposhta_order_added', 'ukrposhta_order_history_added', 'ukrposhta_footer_inject'] as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Throwable $e) {}
		}
		$this->load->model('setting/cron');
		foreach (['ukrposhta_poll', 'ukrposhta_sync_regions'] as $code) {
			try { $this->model_setting_cron->deleteCronByCode($code); } catch (\Throwable $e) {}
		}
		// Tables preserved to avoid losing saved office selections.
	}

	public function setup(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			try {
				$this->install();
				$json['success'] = $this->language->get('text_setup_ok');
			} catch (\Throwable $e) {
				$json['error'] = 'Setup failed: ' . $e->getMessage();
			}
		}
		$this->jsonResponse($json);
	}

	public function index(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$this->document->setTitle($this->language->get('heading_title'));
		$ut = $this->session->data['user_token'];
		$data['user_token'] = $ut;

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/ukrposhta/shipping/ukrposhta', 'user_token=' . $ut)],
		];

		$data['save']          = $this->url->link('extension/ukrposhta/shipping/ukrposhta.save', 'user_token=' . $ut);
		$data['test']          = $this->url->link('extension/ukrposhta/shipping/ukrposhta.test', 'user_token=' . $ut);
		$data['quote_preview'] = $this->url->link('extension/ukrposhta/shipping/ukrposhta.quotePreview', 'user_token=' . $ut);
		$data['setup_url']     = $this->url->link('extension/ukrposhta/shipping/ukrposhta.setup', 'user_token=' . $ut);
		$data['sync_regions']  = $this->url->link('extension/ukrposhta/shipping/ukrposhta.syncRegions', 'user_token=' . $ut);
		$data['back']          = $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping');

		// Secret shown masked (present/absent), never round-tripped in plaintext.
		$data['has_bearer'] = $this->secret('shipping_ukrposhta_bearer') !== '';

		$fields = [
			'shipping_ukrposhta_sandbox'            => 0,
			'shipping_ukrposhta_sender_postcode'    => '',
			'shipping_ukrposhta_service_type'       => 'STANDARD',
			'shipping_ukrposhta_default_cost'       => '65',
			'shipping_ukrposhta_accent_color'       => '#374151',
			'shipping_ukrposhta_radius'             => 14,
			'shipping_ukrposhta_theme'              => 'auto',
			'shipping_ukrposhta_status'             => 0,
			'shipping_ukrposhta_sort_order'         => 0,
			'shipping_ukrposhta_tax_class_id'       => 0,
			'shipping_ukrposhta_geo_zone_id'        => 0,
		];
		foreach ($fields as $key => $default) {
			$val = $this->config->get($key);
			$data[$key] = ($val === null || $val === '') ? $default : $val;
		}

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/ukrposhta/shipping/ukrposhta', $data));
	}

	public function save(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$json['error'] = $this->language->get('error_permission');
		}
		if (!$json) {
			$post = $this->request->post;
			// Unchecked Bootstrap switches are absent from POST — coerce to 0 so the
			// replace-all merge doesn't preserve a stale "on" value.
			foreach (['shipping_ukrposhta_status', 'shipping_ukrposhta_sandbox'] as $cb) {
				$post[$cb] = isset($post[$cb]) && (string)$post[$cb] !== '0' ? 1 : 0;
			}
			$this->load->model('setting/setting');
			$current = $this->model_setting_setting->getSetting('shipping_ukrposhta');
			if (!is_array($current)) { $current = []; }

			// Encrypt secrets; empty incoming = keep existing (don't wipe).
			foreach (self::SECRET_FIELDS as $sf) {
				if (!isset($post[$sf]) || trim((string)$post[$sf]) === '') {
					unset($post[$sf]);
				} else {
					$post[$sf] = \Opencart\System\Library\Ukrposhta\Crypto::encrypt(trim((string)$post[$sf]));
				}
			}
			$merged = array_merge($current, $post);
			$this->model_setting_setting->editSetting('shipping_ukrposhta', $merged);
			$json['success'] = $this->language->get('text_success');
		}
		$this->jsonResponse($json);
	}

	public function test(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$bearer = trim((string)($this->request->post['shipping_ukrposhta_bearer'] ?? ''));
			if ($bearer === '') { $bearer = $this->secret('shipping_ukrposhta_bearer'); }
			if ($bearer === '') {
				$json['error'] = $this->language->get('error_bearer_empty');
			} else {
				$sandbox = (bool)($this->request->post['shipping_ukrposhta_sandbox'] ?? $this->config->get('shipping_ukrposhta_sandbox'));
				$client  = new \Opencart\System\Library\Ukrposhta\Client($bearer, '', $sandbox);
				$resp    = $client->testConnection();
				if (!empty($resp['success'])) {
					$json['success'] = $this->language->get('text_test_ok');
				} else {
					$json['error'] = $this->language->get('text_test_fail') . ' ' . implode('; ', $resp['errors'] ?? []);
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function syncRegions(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$client = $this->client();
			if (!$client) {
				$json['error'] = $this->language->get('error_bearer_empty');
			} else {
				$regions = \Opencart\System\Library\Ukrposhta\Cache::syncRegions($this->db, $client);
				$json['success'] = sprintf($this->language->get('text_sync_ok'), count($regions));
			}
		}
		$this->jsonResponse($json);
	}

	public function quotePreview(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$client   = $this->client();
			$sender   = (int)preg_replace('/\D/', '', (string)$this->config->get('shipping_ukrposhta_sender_postcode'));
			if (!$client) {
				$json['error'] = $this->language->get('error_bearer_empty');
			} elseif ($sender <= 0) {
				$json['error'] = $this->language->get('error_sender_postcode_empty');
			} else {
				$type = (string)($this->config->get('shipping_ukrposhta_service_type') ?: 'STANDARD');
				$resp = $client->deliveryPrice($sender, 1001, 1000, [], $type, 'W2W', 500);
				$cost = $resp['data']['deliveryPrice'] ?? null;
				if (!empty($resp['success']) && $cost !== null) {
					$json['success'] = sprintf($this->language->get('text_quote_ok'), (float)$cost);
				} else {
					$json['error'] = $this->language->get('text_quote_fail') . ' ' . implode('; ', $resp['errors'] ?? []);
				}
			}
		}
		$this->jsonResponse($json);
	}
}
