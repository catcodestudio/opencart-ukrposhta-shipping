<?php
namespace Opencart\Catalog\Controller\Extension\Ukrposhta;

require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/cache.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/crypto.php';
require_once DIR_EXTENSION . 'ukrposhta/system/library/ukrposhta/pro/pro.php';

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

	/** Hourly (Pro): delivery statuses of open shipments; once a day the licence. */
	public function track(): void {
		// Runs from the OpenCart cron only, not as a public URL.
		if ((string)($this->request->get['route'] ?? '') !== '' && strpos((string)$this->request->get['route'], 'extension/ukrposhta/cron') === 0) {
			return;
		}
		\CcUpLicense::maybeVerify($this->config, $this->db);
		$core = new \CcUpPro(
			$this->db,
			$this->config,
			static function ($v) { return \Opencart\System\Library\Ukrposhta\Crypto::encrypt((string)$v); },
			static function ($v) { return \Opencart\System\Library\Ukrposhta\Crypto::decrypt((string)$v); },
			'uk'
		);
		$this->response->setOutput('tracked ' . $core->trackDue());
	}
}
