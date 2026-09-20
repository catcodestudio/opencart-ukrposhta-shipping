<?php
namespace Opencart\Catalog\Model\Extension\Ukrposhta\Shipping;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/client.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';

class Ukrposhta extends \Opencart\System\Engine\Model {
	public function getQuote(array $address): array {
		$this->load->language('extension/ukrposhta/shipping/ukrposhta');

		// Geo-zone check via direct query — OpenCart 4.x has no catalog
		// `localisation/geo_zone` model, so loading it fatals the whole quote.
		$geo_zone_id = (int)$this->config->get('shipping_ukrposhta_geo_zone_id');
		if ($geo_zone_id) {
			$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$geo_zone_id . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");
			$status = (bool)$query->row['total'];
		} else {
			$status = true;
		}
		if (!$status) {
			return [];
		}

		// International destinations never touch the office picker: the tariff is
		// keyed by country + weight, so the branch splits before anything reads
		// the picked office out of the session.
		$iso2 = strtoupper(trim((string)($address['iso_code_2'] ?? '')));
		if ($iso2 !== '' && $iso2 !== 'UA') {
			return $this->internationalQuote($iso2);
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
					// 🔴 Ukrposhta always answers in UAH, but a quote `cost` must be
					// in the STORE's default currency — OpenCart multiplies it by the
					// display-currency rate afterwards. On a shop whose default is not
					// UAH the unconverted tariff is inflated by the whole rate.
					$cost = $this->toStoreCurrency($cost);
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

	/**
	 * UAH → the store's default currency. A store that has no UAH row (or whose
	 * default already is UAH) gets the amount back untouched, so nothing is
	 * scaled by a rate that does not exist.
	 */
	private function toStoreCurrency(float $uah): float {
		$default = (string)$this->config->get('config_currency');
		if ($default === '' || $default === 'UAH') {
			return $uah;
		}
		try {
			if (!$this->db->query("SELECT currency_id FROM `" . DB_PREFIX . "currency` WHERE code = 'UAH'")->num_rows) {
				return $uah;
			}
			return (float)$this->currency->convert($uah, 'UAH', $default);
		} catch (\Throwable $e) {
			return $uah;
		}
	}

	/**
	 * Cart weight in grams — the unit the Ukrposhta tariff endpoint expects.
	 *
	 * getWeight() answers in the store's default weight class, so the conversion
	 * goes through the core weight library, which divides by the class ratios in
	 * `weight_class.value`. That ratio is the only reliable source: sniffing the
	 * unit label instead made `kg` read as grams (strpos('kg','g') is truthy),
	 * i.e. a 2 kg parcel was quoted as 2 grams.
	 */
	private function cartWeightGrams(): int {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 1000;
		}
		try {
			$w = (float)$this->cart->getWeight();
			if ($w <= 0) return 1000;
			$from = (int)$this->config->get('config_weight_class_id');
			$gram = $this->gramWeightClassId();
			if ($gram > 0 && isset($this->weight) && is_object($this->weight)) {
				$grams = (float)$this->weight->convert($w, $from, $gram);
			} else {
				// No gram class in this store's localisation: assume the default
				// class is kilograms, which is what every UA shop uses.
				$grams = $w * 1000;
			}
			return (int)max(round($grams), 1);
		} catch (\Throwable $e) {
			return 1000;
		}
	}

	/**
	 * Weight class whose unit is grams, 0 when the store has none.
	 * ⚠ `unit` lives in weight_class_DESCRIPTION (it is language-specific), not
	 * in weight_class — the ratio table has only `value`.
	 */
	private function gramWeightClassId(): int {
		$row = $this->db->query("SELECT weight_class_id FROM `" . DB_PREFIX . "weight_class_description` WHERE LOWER(TRIM(unit)) IN ('g', 'г', 'gr') LIMIT 1")->row;
		return $row ? (int)$row['weight_class_id'] : 0;
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

	/**
	 * Quote for a destination outside Ukraine.
	 *
	 * Returns [] (method simply absent) when the merchant has not enabled the
	 * international leg — that is a configuration choice, not a failure. When it
	 * IS enabled but the tariff call fails, the method is shown with an `error`
	 * instead of a made-up price: a silent flat-rate fallback is how a broken
	 * quote stays invisible until the parcel is already sold at the wrong price.
	 */
	private function internationalQuote(string $iso2): array {
		if (!$this->config->get('shipping_ukrposhta_intl_status')) {
			return [];
		}

		$bearer = $this->secret('shipping_ukrposhta_bearer');
		$cost   = null;
		$error  = '';

		if ($bearer === '') {
			$error = $this->language->get('error_intl_unavailable');
		} else {
			$client = new \Opencart\System\Library\Ukrposhta\Client($bearer, '', (bool)$this->config->get('shipping_ukrposhta_sandbox'));
			$resp   = $client->internationalDeliveryPrice(
				$iso2,
				$this->cartWeightGrams(),
				[],
				[
					'transportType' => (string)($this->config->get('shipping_ukrposhta_intl_transport') ?: 'AVIA'),
					'packageType'   => (string)($this->config->get('shipping_ukrposhta_intl_package') ?: 'PARCEL'),
					'categoryType'  => (string)($this->config->get('shipping_ukrposhta_intl_category') ?: 'SALE_OF_GOODS'),
					'currencyCode'  => (string)($this->config->get('shipping_ukrposhta_intl_currency') ?: 'USD'),
					'declaredPrice' => $this->cartValue(),
				]
			);

			$live = $resp['data']['deliveryPrice'] ?? null;
			if (!empty($resp['success']) && $live !== null && (float)$live > 0) {
				$cost = $this->toStoreCurrency((float)$live);
			} else {
				// The API answers with a `message` for "this country cannot be served
				// with this package type", so an empty price is not always an HTTP
				// error — surface whatever it said.
				$error = trim((string)($resp['data']['message'] ?? ''));
				if ($error === '') { $error = implode('; ', (array)($resp['errors'] ?? [])); }
				if ($error === '') { $error = $this->language->get('error_intl_unavailable'); }
			}
		}

		if ($cost === null) {
			$fallback = (float)$this->config->get('shipping_ukrposhta_intl_default_cost');
			if ($fallback > 0) {
				$cost  = $fallback;
				$error = '';
			}
		}

		if ($cost === null) {
			return [
				'code'       => 'ukrposhta',
				'name'       => $this->language->get('heading_title'),
				'quote'      => [],
				'sort_order' => $this->config->get('shipping_ukrposhta_sort_order'),
				'error'      => $error,
			];
		}

		$tax_class_id = (int)$this->config->get('shipping_ukrposhta_tax_class_id');
		$quote_data = [];
		$quote_data['ukrposhta_intl'] = [
			'code'         => 'ukrposhta.ukrposhta_intl',
			'name'         => $this->language->get('text_description_intl'),
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
}
