<?php
namespace Opencart\Admin\Controller\Extension\Ukrposhta\Shipping;

require_once dirname(__DIR__, 3) . '/system/library/cc_keep.php';

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/cache.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/pro/pro.php';

class Ukrposhta extends \Opencart\System\Engine\Controller {
	public function __construct(\Opencart\System\Engine\Registry $registry) {
		parent::__construct($registry);

		// Any request to this screen may change the settings, and the core wipes them on uninstall.
		if (strpos((string)($this->request->get['route'] ?? ''), 'extension/ukrposhta/shipping/ukrposhta') === 0) {
			\CcSettingsKeep::mirrorAfter($this->db, 'shipping_ukrposhta');
		}
	}

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
		\CcSettingsKeep::restoreAfter($this->db, 'shipping_ukrposhta');

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
		// `ukrposhta_order_added_slash` is not registered any more — it is listed
		// so an upgrade from 1.0.12 removes the row that build left behind.
		foreach (['ukrposhta_order_added', 'ukrposhta_order_added_slash', 'ukrposhta_order_history_added', 'ukrposhta_footer_inject'] as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Throwable $e) {}
		}
		// OC4 strips the `catalog/` prefix (startup/event.php), and the model-event
		// method separator differs by minor version — OC 4.1.x builds
		// `order.addOrder`, OC 4.0.2.x builds `order/addOrder` (see the proxy in
		// system/engine/loader.php). A trigger with the wrong separator fires
		// nothing, silently.
		//
		// ONE row covers both: Event::trigger() turns `*` into `.*` and matches
		// the trigger as a regex, so the wildcard spans either separator. The
		// previous build registered the dot and the slash as two separate events
		// instead — which also works, but leaves a second row that has to stay in
		// sync and would double-fire the handler if a future build ever matched
		// both.
		$this->model_setting_event->addEvent([
			'code'        => 'ukrposhta_order_added',
			'description' => 'Ukrposhta — capture office selection on order create',
			'trigger'     => 'catalog/model/checkout/order*addOrder/after',
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
		foreach (['ukrposhta_poll', 'ukrposhta_sync_regions', 'ukrposhta_track'] as $code) {
			try { $this->model_setting_cron->deleteCronByCode($code); } catch (\Throwable $e) {}
		}
		$this->model_setting_cron->addCron('ukrposhta_sync_regions', 'Ukrposhta — weekly region classifier sync', 'week', 'extension/ukrposhta/cron.syncRegions', true);
		$this->model_setting_cron->addCron('ukrposhta_track', 'Ukrposhta Pro — delivery statuses and the licence check', 'hour', 'extension/ukrposhta/cron.track', true);
		(new \CcUpPro($this->db, $this->config, 'strval', 'strval', 'en'))->ensureSchema();

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
		foreach (['ukrposhta_order_added', 'ukrposhta_order_added_slash', 'ukrposhta_order_history_added', 'ukrposhta_footer_inject'] as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Throwable $e) {}
		}
		$this->load->model('setting/cron');
		foreach (['ukrposhta_poll', 'ukrposhta_sync_regions', 'ukrposhta_track'] as $code) {
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

		// The "first run — press Install" hint must disappear once the tables
		// exist, otherwise every settings screen tells the shop to install an
		// extension that is already running.
		$installed = (bool)$this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "up_regions'")->num_rows;
		$regions   = 0;
		if ($installed) {
			$regions = (int)$this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "up_regions`")->row['total'];
		}
		$data['is_setup']        = $installed;
		$data['text_setup_done'] = $regions
			? sprintf($this->language->get('text_setup_done'), $regions)
			: $this->language->get('text_setup_offline');

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
			'shipping_ukrposhta_intl_status'        => 0,
			'shipping_ukrposhta_intl_transport'     => 'AVIA',
			'shipping_ukrposhta_intl_package'       => 'PARCEL',
			'shipping_ukrposhta_intl_category'      => 'SALE_OF_GOODS',
			'shipping_ukrposhta_intl_currency'      => 'USD',
			'shipping_ukrposhta_intl_default_cost'  => '',
		];
		foreach ($fields as $key => $default) {
			$val = $this->config->get($key);
			$data[$key] = ($val === null || $val === '') ? $default : $val;
		}

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['pro_html'] = $this->proSettingsHtml();

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
			foreach (['shipping_ukrposhta_status', 'shipping_ukrposhta_sandbox', 'shipping_ukrposhta_intl_status'] as $cb) {
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
			// Licence rows are written by the licence calls and the Pro settings by the Pro
			// panel (with its own licence check), never by this form.
			$fromPanel = \CcUpLicense::serverOwned();
			foreach (array_keys(\CcUpPro::$fields) as $proKey) {
				$fromPanel[] = $proKey;
			}
			$fromPanel[] = 'cron_key';
			foreach ($fromPanel as $panelKey) {
				unset($post[\CcUpLicense::PREFIX . $panelKey]);
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
					// When the international leg is on, probe it too: a domestic-only
					// check passes even if the country tariff is misconfigured, and
					// the merchant finds out from a customer instead.
					if ($this->config->get('shipping_ukrposhta_intl_status')) {
						$intl = $client->internationalDeliveryPrice(
							'PL',
							1000,
							[],
							[
								'transportType' => (string)($this->config->get('shipping_ukrposhta_intl_transport') ?: 'AVIA'),
								'packageType'   => (string)($this->config->get('shipping_ukrposhta_intl_package') ?: 'PARCEL'),
								'categoryType'  => (string)($this->config->get('shipping_ukrposhta_intl_category') ?: 'SALE_OF_GOODS'),
								'currencyCode'  => (string)($this->config->get('shipping_ukrposhta_intl_currency') ?: 'USD'),
								'declaredPrice' => 500,
							]
						);
						$intlCost = $intl['data']['deliveryPrice'] ?? null;
						if (!empty($intl['success']) && $intlCost !== null && (float)$intlCost > 0) {
							$json['success'] .= ' ' . sprintf($this->language->get('text_quote_intl_ok'), (float)$intlCost);
						} else {
							$reason = trim((string)($intl['data']['message'] ?? '')) ?: implode('; ', (array)($intl['errors'] ?? []));
							$json['success'] .= ' ' . $this->language->get('text_quote_intl_fail') . ' ' . $reason;
						}
					}
				} else {
					$json['error'] = $this->language->get('text_quote_fail') . ' ' . implode('; ', $resp['errors'] ?? []);
				}
			}
		}
		$this->jsonResponse($json);
	}

	// ------------------------------------------------------------------ Pro

	private function link(string $method, string $args = ''): string {
		$route = 'extension/ukrposhta/shipping/ukrposhta' . ($method !== '' ? '.' . $method : '');
		return html_entity_decode($this->url->link($route, 'user_token=' . $this->session->data['user_token'] . $args), ENT_QUOTES, 'UTF-8');
	}

	private function proCore(): \CcUpPro {
		$core = new \CcUpPro(
			$this->db,
			$this->config,
			static function ($v) { return \Opencart\System\Library\Ukrposhta\Crypto::encrypt((string)$v); },
			static function ($v) { return \Opencart\System\Library\Ukrposhta\Crypto::decrypt((string)$v); },
			(string)$this->language->get('code')
		);
		$config = $this->config;
		$core->mailer = static function ($to, $subject, $text) use ($config) {
			try {
				$option = [
					'parameter'     => $config->get('config_mail_parameter'),
					'smtp_hostname' => $config->get('config_mail_smtp_hostname'),
					'smtp_username' => $config->get('config_mail_smtp_username'),
					'smtp_password' => html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
					'smtp_port'     => $config->get('config_mail_smtp_port'),
					'smtp_timeout'  => $config->get('config_mail_smtp_timeout'),
				];
				$mail = new \Opencart\System\Library\Mail((string)$config->get('config_mail_engine'), $option);
				$mail->setTo($to);
				$mail->setFrom((string)$config->get('config_email'));
				$mail->setSender(html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8'));
				$mail->setSubject($subject);
				$mail->setText($text);
				$mail->send();
				return true;
			} catch (\Throwable $e) {
				return false;
			}
		};
		return $core;
	}

	private function proStatuses(): array {
		$this->load->model('localisation/order_status');
		$out = [];
		foreach ($this->model_localisation_order_status->getOrderStatuses() as $s) {
			$out[(string)$s['order_status_id']] = $s['name'];
		}
		return $out;
	}

	/** Licence panel and Pro settings under the core form. */
	private function proSettingsHtml(): string {
		\CcUpLicense::maybeVerify($this->config, $this->db);
		return $this->proCore()->renderSettings([
			'endpoint'  => $this->link('pro'),
			'shipments' => $this->link('shipments'),
			'cron'      => '',
			'statuses'  => $this->proStatuses(),
		]);
	}

	/** JSON endpoint of the Pro panel and the shipments page. */
	public function pro(): void {
		$core = $this->proCore();
		if (!$this->user->hasPermission('modify', 'extension/ukrposhta/shipping/ukrposhta')) {
			$this->jsonResponse(['ok' => false, 'message' => $core->t('e_permission')]);
			return;
		}
		$post = [];
		foreach ((array)$this->request->post as $k => $v) {
			$post[$k] = is_scalar($v) ? html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8') : '';
		}
		$this->jsonResponse($core->handle((string)($post['op'] ?? ''), $post));
	}

	/** Labels / form 103a as PDF. */
	public function proPdf(): void {
		$core = $this->proCore();
		if (!$this->user->hasPermission('access', 'extension/ukrposhta/shipping/ukrposhta')) {
			$this->response->setOutput($core->t('e_permission'));
			return;
		}
		$r = $core->pdfResponse((string)($this->request->get['what'] ?? ''), (string)($this->request->get['ids'] ?? ''));
		if (empty($r['ok'])) {
			$this->response->addHeader('Content-Type: text/html; charset=utf-8');
			$this->response->setOutput('<p style="font:16px sans-serif;margin:24px">' . htmlspecialchars((string)$r['message'], ENT_QUOTES, 'UTF-8') . '</p>');
			return;
		}
		$this->response->addHeader('Content-Type: application/pdf');
		$this->response->addHeader('Content-Disposition: inline; filename="ukrposhta-' . date('Ymd-His') . '.pdf"');
		$this->response->setOutput($r['pdf']);
	}

	/** Shipments page: every Ukrposhta order with its shipment, bulk actions. */
	public function shipments(): void {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		\CcUpLicense::maybeVerify($this->config, $this->db);
		$core  = $this->proCore();
		$title = $core->t('sh_title');
		$this->document->setTitle($title);
		$data['heading_title'] = $title;
		$data['breadcrumbs']   = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/ukrposhta/shipping/ukrposhta', 'user_token=' . $this->session->data['user_token'])],
			['text' => $title, 'href' => $this->url->link('extension/ukrposhta/shipping/ukrposhta.shipments', 'user_token=' . $this->session->data['user_token'])],
		];
		$data['pro_html'] = $core->renderShipments([
			'endpoint' => $this->link('pro'),
			'pdf'      => $this->link('proPdf', '&what='),
			'settings' => $this->link(''),
			'order'    => html_entity_decode($this->url->link('sale/order.info', 'user_token=' . $this->session->data['user_token'] . '&order_id='), ENT_QUOTES, 'UTF-8'),
			'page'     => $this->link('shipments', '&page='),
		], (int)($this->request->get['page'] ?? 1));
		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/ukrposhta/shipping/ukrposhta_shipments', $data));
	}
}
