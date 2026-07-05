<?php
namespace Opencart\System\Library\Ukrposhta;

require_once __DIR__ . '/translit.php';

/**
 * Ukrposhta REST API client (eCom 0.0.1 + Address Classifier + StatusTracking).
 *
 * Auth model (Ukrposhta issues both after signing the contract):
 *   - Bearer  ($bearer)  → Authorization: Bearer {uuid}  header on every call.
 *   - Token   ($token)   → ?token={uuid} query param on eCom write endpoints.
 *   The Address Classifier uses the Bearer only (no token).
 *
 * Hosts:
 *   prod eCom      : https://www.ukrposhta.ua/ecom/0.0.1
 *   prod forms     : https://www.ukrposhta.ua/forms/ecom/0.0.1   (sticker PDFs)
 *   prod classifier: https://www.ukrposhta.ua/address-classifier-ws
 *   prod tracking  : https://www.ukrposhta.ua/status-tracking/0.0.1
 *   sandbox        : https://dev.ukrposhta.ua/... (same paths)
 */
class Client {
	private string $bearer;
	private string $token;
	private string $trackingBearer;
	private bool $sandbox;
	private int $timeout = 20;

	private string $ecom;
	private string $forms;
	private string $classifier;
	private string $tracking;

	public function __construct(string $bearer, string $token = '', bool $sandbox = false, string $trackingBearer = '') {
		$this->bearer         = trim($bearer);
		$this->token          = trim($token);
		$this->trackingBearer = trim($trackingBearer) ?: $this->bearer;
		$this->sandbox        = $sandbox;

		$root = $sandbox ? 'https://dev.ukrposhta.ua' : 'https://www.ukrposhta.ua';
		$this->ecom       = $root . '/ecom/0.0.1';
		$this->forms      = $root . '/forms/ecom/0.0.1';
		$this->classifier = $root . '/address-classifier-ws';
		$this->tracking   = $root . '/status-tracking/0.0.1';
	}

	// ---------------------------------------------------------------- transport

	/**
	 * Low-level HTTP call. Returns a normalized array:
	 *   ['success'=>bool, 'status'=>int, 'data'=>mixed, 'errors'=>string[], 'raw'=>string]
	 */
	private function request(string $method, string $url, ?array $body = null, string $bearer = ''): array {
		$bearer = $bearer !== '' ? $bearer : $this->bearer;

		$headers = [
			'Authorization: Bearer ' . $bearer,
			'Accept: application/json',
		];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}

		$ch = curl_init($url);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => strtoupper($method),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 6,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
		}
		curl_setopt_array($ch, $opts);

		$raw  = curl_exec($ch);
		$err  = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			return ['success' => false, 'status' => $http, 'data' => null, 'errors' => ['HTTP: ' . $err], 'raw' => ''];
		}

		$decoded = json_decode($raw, true);
		$ok = $http >= 200 && $http < 300;

		if (!$ok) {
			$errors = [];
			if (is_array($decoded)) {
				// Ukrposhta returns {"message":"...","errors":[...]} or {"error":"..."} on failures.
				if (!empty($decoded['message'])) { $errors[] = (string)$decoded['message']; }
				if (!empty($decoded['error']))   { $errors[] = (string)$decoded['error']; }
				if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
					foreach ($decoded['errors'] as $e) {
						$errors[] = is_array($e) ? (string)($e['message'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string)$e;
					}
				}
			}
			if (!$errors) { $errors[] = 'HTTP ' . $http; }
			return ['success' => false, 'status' => $http, 'data' => $decoded, 'errors' => $errors, 'raw' => $raw];
		}

		return ['success' => true, 'status' => $http, 'data' => $decoded, 'errors' => [], 'raw' => $raw];
	}

	private function ecomUrl(string $path, bool $withToken = true): string {
		$url = $this->ecom . $path;
		if ($withToken && $this->token !== '') {
			$url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($this->token);
		}
		return $url;
	}

	// --------------------------------------------------------- connection test

	/** Cheap authenticated call to validate credentials — pings the client list. */
	public function testConnection(): array {
		// GET /clients/phone requires a token; a malformed but authorized request
		// still proves the Bearer is accepted. We use the address classifier as a
		// lightweight, always-available probe of the Bearer.
		return $this->request('GET', $this->classifier . '/get_regions_by_region_ua?region_name=' . rawurlencode('Київ'));
	}

	// -------------------------------------------------------- address classifier

	public function getRegions(string $name = ''): array {
		$url = $this->classifier . '/get_regions_by_region_ua?region_name=' . rawurlencode($name);
		return $this->request('GET', $url);
	}

	public function getDistricts(string $regionId, string $name = ''): array {
		$url = $this->classifier . '/get_districts_by_region_id_and_district_ua?region_id=' . rawurlencode($regionId) . '&district_ua=' . rawurlencode($name);
		return $this->request('GET', $url);
	}

	public function getCities(string $regionId, string $cityName, string $districtId = ''): array {
		$url = $this->classifier . '/get_city_by_region_id_and_district_id_and_city_ua'
			. '?region_id=' . rawurlencode($regionId)
			. '&district_id=' . rawurlencode($districtId)
			. '&city_ua=' . rawurlencode($cityName);
		return $this->request('GET', $url);
	}

	public function getPostOfficesByCity(string $cityId, string $districtId = '', string $regionId = ''): array {
		$url = $this->classifier . '/get_postoffices_by_city_id'
			. '?city_id=' . rawurlencode($cityId)
			. '&district_id=' . rawurlencode($districtId)
			. '&region_id=' . rawurlencode($regionId);
		return $this->request('GET', $url);
	}

	public function getPostOfficesByIndex(string $postIndex): array {
		return $this->request('GET', $this->classifier . '/get_postoffices_by_postindex?pi=' . rawurlencode($postIndex));
	}

	/** Extract the Entries.Entry list from a classifier response (always an array). */
	public static function entries(array $resp): array {
		$data = $resp['data'] ?? null;
		if (!is_array($data)) { return []; }
		$entry = $data['Entries']['Entry'] ?? null;
		if ($entry === null) { return []; }
		// A single result comes back as an object, many as a list — normalize.
		return isset($entry[0]) ? $entry : [$entry];
	}

	// ---------------------------------------------------------------- delivery price

	/**
	 * Domestic tariff quote. Weight in grams, dimensions in cm.
	 * @return array normalized response; cost is in data['deliveryPrice'] on success.
	 */
	public function deliveryPrice(int $senderPostcode, int $recipientPostcode, int $weightG, array $dims, string $type = 'STANDARD', string $deliveryType = 'W2W', float $declaredPrice = 0, float $postPay = 0): array {
		$body = [
			'weight'      => max($weightG, 1),
			'length'      => max((int)($dims['length'] ?? 20), 1),
			'width'       => max((int)($dims['width'] ?? 20), 1),
			'height'      => max((int)($dims['height'] ?? 10), 1),
			'addressFrom' => ['postcode' => $senderPostcode],
			'addressTo'   => ['postcode' => $recipientPostcode],
			'type'        => $type,
			'deliveryType'=> $deliveryType,
		];
		if ($declaredPrice > 0) { $body['declaredPrice'] = $declaredPrice; }
		if ($postPay > 0)       { $body['postPay'] = $postPay; }
		return $this->request('POST', $this->ecom . '/domestic/delivery-price', $body, $this->bearer);
	}

	// ---------------------------------------------------------------- clients

	/** Create (or upsert) a client. Returns the created client uuid in data['uuid']. */
	public function createClient(array $fields): array {
		return $this->request('POST', $this->ecomUrl('/clients'), $fields, $this->bearer);
	}

	// ---------------------------------------------------------------- shipments

	/**
	 * Create a shipment (parcel). $args:
	 *   sender_uuid, recipient_* (name/phone/postcode), deliveryType (W2W/W2D/D2W/D2D),
	 *   weight (grams), dims{length,width,height}, declaredPrice, description,
	 *   cod (postPay amount, optional), paidByRecipient (bool).
	 *
	 * Two-step: create the recipient client (PRIVATE_PERSON) with the office
	 * postcode as its address, then POST /shipments referencing both uuids.
	 * Returns the shipment response (data['uuid'], data['barcode']).
	 */
	public function createShipment(array $args): array {
		$name  = Translit::cleanName((string)($args['recipient_name'] ?? ''));
		$parts = preg_split('/\s+/', trim($name)) ?: [];
		$firstName  = (string)($parts[0] ?? 'Отримувач');
		$lastName   = (string)($parts[1] ?? $firstName);
		$middleName = (string)($parts[2] ?? '');
		$phone      = Translit::normalizePhone((string)($args['recipient_phone'] ?? ''));
		$postcode   = preg_replace('/\D/', '', (string)($args['recipient_postcode'] ?? ''));

		// 1) Recipient client with the destination post office as its address.
		$recipient = $this->createClient([
			'name'        => trim("$lastName $firstName $middleName"),
			'firstName'   => $firstName,
			'lastName'    => $lastName,
			'middleName'  => $middleName,
			'phoneNumber' => $phone,
			'type'        => 'INDIVIDUAL',
			'addresses'   => [[
				'postcode' => $postcode,
				'country'  => 'UA',
				'main'     => true,
			]],
		]);
		if (empty($recipient['success']) || empty($recipient['data']['uuid'])) {
			return $recipient['success'] ? ['success' => false, 'errors' => ['recipient uuid missing'], 'data' => $recipient['data']] : $recipient;
		}
		$recipientUuid = (string)$recipient['data']['uuid'];

		// 2) Shipment.
		$dims = $args['dims'] ?? [];
		$parcel = [
			'weight' => max((int)($args['weight'] ?? 1000), 1),
			'length' => max((int)($dims['length'] ?? 20), 1),
			'width'  => max((int)($dims['width'] ?? 20), 1),
			'height' => max((int)($dims['height'] ?? 10), 1),
		];
		if (!empty($args['declaredPrice'])) {
			$parcel['declaredPrice'] = (float)$args['declaredPrice'];
		}
		if (!empty($args['description'])) {
			$parcel['description'] = mb_substr((string)$args['description'], 0, 120);
		}

		$body = [
			'sender'          => ['uuid' => (string)($args['sender_uuid'] ?? '')],
			'recipient'       => ['uuid' => $recipientUuid],
			'deliveryType'    => (string)($args['deliveryType'] ?? 'W2W'),
			'paidByRecipient' => (bool)($args['paidByRecipient'] ?? true),
			'type'            => (string)($args['type'] ?? 'STANDARD'),
			'parcels'         => [$parcel],
		];
		if (!empty($args['cod']) && (float)$args['cod'] > 0) {
			$body['postPay'] = (float)$args['cod'];
			$body['transferPostPayToCard'] = (bool)($args['cod_to_card'] ?? true);
		}

		return $this->request('POST', $this->ecomUrl('/shipments'), $body, $this->bearer);
	}

	public function deleteShipment(string $uuid): array {
		return $this->request('DELETE', $this->ecomUrl('/shipments/' . rawurlencode($uuid)), null, $this->bearer);
	}

	/** Public sticker PDF URL for a shipment (barcode or uuid). size: '', SIZE_A4, SIZE_A5. */
	public function stickerUrl(string $barcodeOrUuid, string $size = ''): string {
		$url = $this->forms . '/shipments/' . rawurlencode($barcodeOrUuid) . '/sticker';
		if ($this->token !== '') {
			$url .= '?token=' . rawurlencode($this->token);
		}
		if ($size !== '') {
			$url .= (str_contains($url, '?') ? '&' : '?') . 'size=' . rawurlencode($size);
		}
		return $url;
	}

	/** Fetch the sticker PDF as binary (server-side, so the Bearer header is applied). */
	public function stickerPdf(string $barcodeOrUuid, string $size = ''): array {
		$url = $this->forms . '/shipments/' . rawurlencode($barcodeOrUuid) . '/sticker';
		if ($this->token !== '') {
			$url .= '?token=' . rawurlencode($this->token);
		}
		if ($size !== '') {
			$url .= (str_contains($url, '?') ? '&' : '?') . 'size=' . rawurlencode($size);
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->bearer, 'Accept: application/pdf'],
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 6,
		]);
		$raw  = curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$ctype= (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$err  = curl_error($ch);
		curl_close($ch);
		if ($raw === false || $http >= 400) {
			return ['success' => false, 'errors' => ['sticker HTTP ' . $http . ' ' . $err], 'body' => (string)$raw];
		}
		return ['success' => true, 'body' => $raw, 'content_type' => $ctype ?: 'application/pdf'];
	}

	// ---------------------------------------------------------------- tracking

	/** Status by barcode. Uses the StatusTracking bearer. */
	public function trackStatus(string $barcode, string $lang = 'ua'): array {
		$url = $this->tracking . '/statuses?barcode=' . rawurlencode($barcode) . '&lang=' . rawurlencode($lang);
		return $this->request('GET', $url, null, $this->trackingBearer);
	}
}
