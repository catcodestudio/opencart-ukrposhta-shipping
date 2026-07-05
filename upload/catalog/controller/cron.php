<?php
namespace Opencart\Catalog\Controller\Extension\Ukrposhta;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/cache.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';

class Cron extends \Opencart\System\Engine\Controller {
	/** Weekly refresh of the region classifier cache. */
	public function syncRegions(): void {
		$rawB = (string)$this->config->get('shipping_ukrposhta_bearer');
		if ($rawB === '') { $this->response->setOutput('no bearer'); return; }
		$bearer  = \Opencart\System\Library\Ukrposhta\Crypto::decrypt($rawB);
		$sandbox = (bool)$this->config->get('shipping_ukrposhta_sandbox');
		$client  = new \Opencart\System\Library\Ukrposhta\Client($bearer, '', $sandbox);
		$regions = \Opencart\System\Library\Ukrposhta\Cache::syncRegions($this->db, $client);
		$this->response->setOutput('regions ' . count($regions));
	}
}
