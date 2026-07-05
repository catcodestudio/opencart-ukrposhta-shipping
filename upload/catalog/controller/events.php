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
		$postindex = (string)($this->session->data['up_office_postindex'] ?? '');
		if ($postindex === '') {
			return; // Different shipping method chosen.
		}
		$cityName   = (string)($this->session->data['up_city_name'] ?? '');
		$officeName = (string)($this->session->data['up_office_name'] ?? '');
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
