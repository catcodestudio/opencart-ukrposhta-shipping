<?php
namespace Opencart\Catalog\Model\Extension\Ukrposhta\Shipping;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';

class Ukrposhta extends \Opencart\System\Engine\Model {
	public function getQuote(array $address): array {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');
		$this->load->model('localisation/geo_zone');

		$results = $this->model_localisation_geo_zone->getGeoZone(
			(int)$this->config->get('shipping_ukrposhta_geo_zone_id'),
			(int)$address['country_id'],
			(int)$address['zone_id']
		);

		$status = !$this->config->get('shipping_ukrposhta_geo_zone_id') || (bool)$results;
		if (!$status) {
			return [];
		}

		$defaultCost = (float)$this->config->get('shipping_ukrposhta_default_cost');
		$cost        = $defaultCost;

		$bearer         = $this->secret('shipping_ukrposhta_bearer');
		$senderPostcode = (int)preg_replace('/\D/', '', (string)$this->config->get('shipping_ukrposhta_sender_postcode'));
		$recipPostcode  = (int)preg_replace('/\D/', '', (string)($this->session->data['up_office_postindex'] ?? ''));

		if ($bearer !== '' && $senderPostcode > 0 && $recipPostcode > 0) {
			$sandbox = (bool)$this->config->get('shipping_ukrposhta_sandbox');
			// Read-only tariff: Bearer only, no token.
			$client  = new \Opencart\System\Library\Ukrposhta\Client($bearer, '', $sandbox);
			$weightG = $this->cartWeightGrams();
			$value   = $this->cartValue();
			$type    = (string)($this->config->get('shipping_ukrposhta_service_type') ?: 'STANDARD');
			$resp = $client->deliveryPrice($senderPostcode, $recipPostcode, $weightG, [], $type, 'W2W', $value);
			if (!empty($resp['success']) && is_array($resp['data'] ?? null)) {
				$live = $resp['data']['deliveryPrice'] ?? ($resp['data']['deliveryPriceGriven'] ?? null);
				if ($live !== null && (float)$live > 0) {
					$cost = (float)$live;
					// Add postpay fee if the tariff response provides one.
					if (!empty($resp['data']['postPayDeliveryPrice'])) {
						$cost += (float)$resp['data']['postPayDeliveryPrice'];
					}
				}
			}
		}

		$tax_class_id = (int)$this->config->get('shipping_ukrposhta_tax_class_id');
		$quote_data['ukrposhta'] = [
			'code'         => 'ukrposhta.ukrposhta',
			'name'         => $this->language->get('text_description'),
			'cost'         => $cost,
			'tax_class_id' => $tax_class_id,
			'text'         => $this->currency->format(
				$this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')),
				$this->session->data['currency']
			),
		];

		return [
			'code'       => 'ukrposhta',
			'name'       => $this->language->get('heading_title'),
			'quote'      => $quote_data,
			'sort_order' => $this->config->get('shipping_ukrposhta_sort_order'),
			'error'      => false,
		];
	}

	private function secret(string $key): string {
		$raw = (string)$this->config->get($key);
		return $raw === '' ? '' : \Opencart\System\Library\Ukrposhta\Crypto::decrypt($raw);
	}

	private function cartWeightGrams(): int {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 1000;
		}
		try {
			$this->load->model('localisation/weight_class');
			$w = (float)$this->cart->getWeight();
			if ($w <= 0) return 1000;
			// getWeight() is in the store's default weight unit. Convert to grams
			// via the weight class unit value against the base kg (unit=1000 g).
			$unit = (int)$this->config->get('config_weight_class_id');
			$info = $this->model_localisation_weight_class->getWeightClass($unit);
			$title = strtolower((string)($info['unit'] ?? ($info['title'] ?? '')));
			$grams = match (true) {
				str_contains($title, 'g') || str_contains($title, 'г') => $w,        // already grams
				default => $w * 1000,                                                // kg → g
			};
			return (int)max(round($grams), 1);
		} catch (\Throwable $e) {
			return 1000;
		}
	}

	private function cartValue(): float {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 100.0;
		}
		try {
			$total = (float)$this->cart->getSubTotal();
			return $total > 0 ? $total : 100.0;
		} catch (\Throwable $e) {
			return 100.0;
		}
	}
}
