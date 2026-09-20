<?php
namespace Opencart\System\Library\Ukrposhta;

require_once __DIR__ . '/polyfill.php';

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
		if (!function_exists('openssl_encrypt')) {
			return self::PREFIX . base64_encode($plain ^ str_pad('', strlen($plain), $secret));
		}
		$iv  = openssl_random_pseudo_bytes(16);
		$ct  = openssl_encrypt($plain, 'aes-256-cbc', hash_hmac('sha256', 'enc', $secret, true), OPENSSL_RAW_DATA, $iv);
		$mac = hash_hmac('sha256', $iv . $ct, hash_hmac('sha256', 'mac', $secret, true), true);
		return self::PREFIX . base64_encode("\xCC" . 'cc2' . $iv . $mac . $ct);
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
		if (strncmp($bytes, "\xCC" . 'cc2', 4) === 0 && strlen($bytes) > 52 && function_exists('openssl_decrypt')) {
			$iv  = substr($bytes, 4, 16);
			$mac = substr($bytes, 20, 32);
			$ct  = substr($bytes, 52);
			if (!hash_equals(hash_hmac('sha256', $iv . $ct, hash_hmac('sha256', 'mac', $secret, true), true), $mac)) {
				return '';
			}
			$out = openssl_decrypt($ct, 'aes-256-cbc', hash_hmac('sha256', 'enc', $secret, true), OPENSSL_RAW_DATA, $iv);
			return $out === false ? '' : $out;
		}
		return $bytes ^ str_pad('', strlen($bytes), $secret); // written before the switch to AES
	}
}
