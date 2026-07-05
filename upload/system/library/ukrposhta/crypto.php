<?php
namespace Opencart\System\Library\Ukrposhta;

/**
 * At-rest obfuscation for stored secrets (Bearer, token, license key).
 * NOT cryptographic-grade — defense in depth against casual DB-dump leaks.
 * XOR against a deterministic per-install secret derived from PHP path
 * constants + DB name (identical across admin/catalog/cron/cli contexts).
 */
class Crypto {
	private const PREFIX = 'up$';

	private static function secret(): string {
		$material = DIR_OPENCART . (defined('DB_DATABASE') ? DB_DATABASE : '');
		return hash('sha256', 'UkrposhtaShipping|' . $material, true);
	}

	public static function encrypt(string $plain): string {
		if ($plain === '') {
			return '';
		}
		$secret = self::secret();
		$bytes = '';
		for ($i = 0, $n = strlen($plain); $i < $n; $i++) {
			$bytes .= chr(ord($plain[$i]) ^ ord($secret[$i % strlen($secret)]));
		}
		return self::PREFIX . base64_encode($bytes);
	}

	public static function decrypt(string $stored): string {
		if ($stored === '') {
			return '';
		}
		if (!str_starts_with($stored, self::PREFIX)) {
			return $stored; // legacy plaintext
		}
		$bytes = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if ($bytes === false) {
			return '';
		}
		$secret = self::secret();
		$out = '';
		for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
			$out .= chr(ord($bytes[$i]) ^ ord($secret[$i % strlen($secret)]));
		}
		return $out;
	}
}
