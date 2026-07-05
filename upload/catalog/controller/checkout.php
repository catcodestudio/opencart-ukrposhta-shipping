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
		$this->session->data['up_city_id']          = trim((string)($this->request->post['city_id'] ?? ''));
		$this->session->data['up_city_name']        = trim((string)($this->request->post['city_name'] ?? ''));
		$this->session->data['up_office_postindex'] = trim((string)($this->request->post['office_postindex'] ?? ''));
		$this->session->data['up_office_name']      = trim((string)($this->request->post['office_name'] ?? ''));
		$this->jsonResponse(['ok' => true]);
	}

	public function getSelection(): void {
		$this->jsonResponse([
			'region_id'        => (string)($this->session->data['up_region_id'] ?? ''),
			'city_id'          => (string)($this->session->data['up_city_id'] ?? ''),
			'city_name'        => (string)($this->session->data['up_city_name'] ?? ''),
			'office_postindex' => (string)($this->session->data['up_office_postindex'] ?? ''),
			'office_name'      => (string)($this->session->data['up_office_name'] ?? ''),
		]);
	}
}
