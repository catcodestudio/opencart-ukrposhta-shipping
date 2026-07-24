<?php
namespace Opencart\Catalog\Controller\Extension\Ukrposhta;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';

class Events extends \Opencart\System\Engine\Controller {
	/**
	 * Inject the checkout picker widget on every storefront footer. The widget
	 * self-activates only on the checkout shipping step.
	 */
	public function footerInject(string &$route, array &$args, mixed &$output): void {
		if (!is_string($output) || stripos($output, '</body>') === false) {
			return;
		}
		$this->seedShippingAddress();
		if (!(bool)$this->config->get('shipping_ukrposhta_status')) {
			return;
		}
		$baseUrl = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
		$accent = (string)($this->config->get('shipping_ukrposhta_accent_color') ?: '#374151');
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
			$accent = '#374151';
		}
		$radius = (int)$this->config->get('shipping_ukrposhta_radius');
		if ($radius < 0 || $radius > 28) {
			$radius = 14;
		}
		$theme = (string)$this->config->get('shipping_ukrposhta_theme');
		if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
			$theme = 'auto';
		}
		$cfg = [
			'regions'      => $baseUrl . 'index.php?route=extension/ukrposhta/checkout.regions',
			'searchCities' => $baseUrl . 'index.php?route=extension/ukrposhta/checkout.searchCities',
			'getOffices'   => $baseUrl . 'index.php?route=extension/ukrposhta/checkout.getOffices',
			'setSelection' => $baseUrl . 'index.php?route=extension/ukrposhta/checkout.setSelection',
			'getSelection' => $baseUrl . 'index.php?route=extension/ukrposhta/checkout.getSelection',
			'accentColor'  => $accent,
			'radius'       => $radius,
			'theme'        => $theme,
		];
		$json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$tag = '<script>window.__ukrposhta=' . $json . ';' . $this->pickerScript() . '</script>';
		$output = str_replace('</body>', $tag . '</body>', $output);
	}

	/**
	 * Fresh guest session on the checkout page: the core refuses to quote
	 * shipping methods until session['shipping_address'] exists, but the manual
	 * address form is replaced by the carrier picker — so the customer hits a
	 * dead «Потрібна адреса доставки!» before doing anything. Seed a minimal
	 * Ukraine address once (address_id=0, no zone) so the method list opens in
	 * the natural order; the picker/setSelection then writes the real address.
	 */
	private function seedShippingAddress(): void {
		if (((string)($this->request->get['route'] ?? '')) !== 'checkout/checkout') {
			return;
		}
		if (isset($this->session->data['shipping_address']['address_id'])) {
			return;
		}
		$this->load->model('localisation/country');
		$info = $this->model_localisation_country->getCountry((int)$this->config->get('config_country_id'));
		if (!$info || strtoupper((string)($info['iso_code_2'] ?? '')) !== 'UA') {
			$row = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = 'UA' AND status = 1")->row;
			if ($row) {
				$info = $this->model_localisation_country->getCountry((int)$row['country_id']);
			}
		}
		if (!$info) {
			return;
		}
		// The same core gate also demands session['customer'] before quoting.
		// Seed an empty guest stub (customer_id=0) — register.save overwrites it
		// with the real contact data. The picker JS keeps the confirm button
		// gated until that save happens, so no anonymous order can slip through.
		if (!isset($this->session->data['customer'])) {
			$this->session->data['customer'] = [
				'customer_id'       => 0,
				'customer_group_id' => (int)$this->config->get('config_customer_group_id'),
				'firstname'         => '',
				'lastname'          => '',
				'email'             => '',
				'telephone'         => '',
				'custom_field'      => [],
			];
		}
		$this->session->data['shipping_address'] = [
			'address_id'     => 0,
			'firstname'      => '',
			'lastname'       => '',
			'company'        => '',
			'address_1'      => '',
			'address_2'      => '',
			'city'           => '',
			'postcode'       => '',
			'zone_id'        => 0,
			'zone'           => '',
			'zone_code'      => '',
			'country_id'     => (int)$info['country_id'],
			'country'        => (string)($info['name'] ?? 'Ukraine'),
			'iso_code_2'     => (string)($info['iso_code_2'] ?? 'UA'),
			'iso_code_3'     => (string)($info['iso_code_3'] ?? 'UKR'),
			'address_format' => (string)($info['address_format'] ?? ''),
			'custom_field'   => [],
		];
	}

	private function pickerScript(): string {
		$file = DIR_EXTENSION . 'ukrposhta/catalog/view/javascript/ukrposhta/picker.js';
		return is_file($file) ? (string)file_get_contents($file) : '';
	}

	/**
	 * On order create — persist the chosen Ukrposhta office into a draft shipment row.
	 */
	public function orderAdded(string &$route, array &$args, mixed &$output): void {
		$order_id = (int)($output ?? 0);
		if ($order_id <= 0) {
			return;
		}
		// Only act on orders actually shipped by this carrier — a stale UP
		// selection in the session must not attach drafts to Nova Poshta orders.
		$method = (string)($this->session->data['shipping_method']['code'] ?? '');
		if (strpos($method, 'ukrposhta.') !== 0) {
			return;
		}
		$postindex = (string)($this->session->data['up_office_postindex'] ?? '');
		if ($postindex === '') {
			return; // Different shipping method chosen.
		}
		$cityName   = (string)($this->session->data['up_city_name'] ?? '');
		$officeName = (string)($this->session->data['up_office_name'] ?? '');
		// The order address must carry OUR data verbatim: classifier city, the
		// region as the customer picked it (Cyrillic) and the REAL branch index —
		// never a placeholder the hidden native form may have frozen in.
		$region = (string)($this->session->data['up_region_name'] ?? '');
		$sets = ["shipping_postcode = '" . $this->db->escape($postindex) . "'"];
		if ($cityName !== '') {
			$sets[] = "shipping_city = '" . $this->db->escape($cityName) . "'";
		}
		if ($officeName !== '') {
			$sets[] = "shipping_address_1 = '" . $this->db->escape($officeName) . "'";
		}
		if ($region !== '') {
			$sets[] = "shipping_zone = '" . $this->db->escape($region) . "'";
		}
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET " . implode(', ', $sets) . " WHERE order_id = " . $order_id);
		$this->db->query("INSERT INTO `" . DB_PREFIX . "up_shipment` SET
			order_id = " . $order_id . ",
			recipient_postindex = '" . $this->db->escape($postindex) . "',
			recipient_city_name = '" . $this->db->escape($cityName) . "',
			recipient_office_name = '" . $this->db->escape($officeName) . "',
			service_type = 'W2W',
			status_code = 0,
			status_text = 'Чернетка',
			created_at = NOW()");
	}

}
