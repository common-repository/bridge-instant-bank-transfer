<?php

namespace BridgeApi;

use BridgeApi\Exceptions\ConnectionError;
use BridgeApi\Exceptions\DecodeError;
use BridgeApi\Exceptions\Exception as BaseException;
use BridgeApi\Exceptions\IO;
use BridgeApi\Exceptions\ItExists;
use BridgeApi\Exceptions\NotExists;
use BridgeApi\Exceptions\OpenSSL;

use function Safe\openssl_cipher_iv_length;
use function Safe\openssl_decrypt;
use function Safe\openssl_encrypt;

class Utils
{
	public const KEY_OPTION = 'bridge_key';

	/**
	 * Generate keys for this plugin
	 *
	 * @return bool if was successful
	 */
	public static function generateKey(): bool
	{
		global $bridgeapi;

		$adminInstance = $bridgeapi->getAdminInstance();
		$length = openssl_cipher_iv_length(BRIDGEAPI_IO_CIPHER);

		if ($length !== false) {
			try {
				$key = random_bytes($length);

				update_option(self::KEY_OPTION, base64_encode($key));

				return true;
			} catch (\Exception $e) {
				// Because this runs on activation, there is no method to display errors to the users. Unless some logging feature is in place. So log it to PHP logs
				error_log($e->getMessage(), 0);
				return false;
			}
		}

		error_log(__('Error determining key length', 'bridgeapi-io'), 0);

		return false;
	}

	
	public static function getKey(): string
	{
		// 76 is base64 max len per line
		$key = get_option(self::KEY_OPTION, '');
		$key = $key ? base64_decode($key) : $key;

		return apply_filters('bridge-io/key/get', $key);
	}

	/**
	 * Encrypt text
	 *
	 * @param string to encrypt
	 * @param string $key for retrieval
	 * @param bool overwrite key if it exists
	 * @param int $orderId if specified
	 *
	 * @return string encrypted string
	 */
	public static function encrypt(string $text, string $key, bool $overwrite = false, int $orderId = 0): string
	{
		$key = self::_maybePrepKey($key);

		if (!$overwrite && self::_keyExists($key, $orderId)) {
			throw new ItExists(__('Encryption key already exists', 'bridgeapi-io'));
		}

		$cipherLen = openssl_cipher_iv_length(BRIDGEAPI_IO_CIPHER);
		if ($cipherLen === false) {
			throw new OpenSSL(__('openssl_cipher_iv_length: Unable to determin cipher length', 'bridgeapi-io'));
		}

		try {
			$iv = random_bytes($cipherLen);
		} catch (\Exception $e) {
			throw new OpenSSL($e->getMessage(), $e->getCode(), $e);
		}

		$params = [
			'cipher' => BRIDGEAPI_IO_CIPHER,
			'iv' => $iv,
			'tag' => '',
			'key' => self::getKey(),
		];

		$params = apply_filters('bridge-io/encryption/params', $params, $text);
		$encrypted = openssl_encrypt($text, $params['cipher'], $params['key'], 0, $params['iv'], $params['tag']);
		if ($encrypted === false) {
			throw new OpenSSL(__('Error. Unable to encrypt data', 'bridgeapi-io'));
		}

		self::packToDB($params, $key, $orderId);

		return $encrypted;
	}

	public static function decrypt(string $encryption, string $key, int $orderId = 0): ?string
	{
		if (!self::_keyExists($key, $orderId)) {
			throw new NotExists(__('Encryption key does not exist', 'bridgeapi-io'));
		}

		$unpacked = self::UnpackFromDB($key, $orderId);

		$decrypted = openssl_decrypt($encryption, $unpacked['cipher'], $unpacked['key'], 0, $unpacked['iv'], $unpacked['tag']);
		if ($decrypted === false) {
			throw new OpenSSL(__('openssl_decrypt failed', 'bridgeapi-io'));
		}

		return $decrypted;
	}

	/**
	 * Return allowed bank data to the public
	 *
	 * @param array<string> bank data
	 *
	 * @return array<mixed> mapped out fields
	 */
	public static function mapBankResponse(\StdClass $bank): array
	{
		$allowedFields = apply_filters('bridgeapi-io/banks/fields/allowed', ['id', 'name', 'logo_url', 'country_code']);
		$ret = [];

		foreach ($allowedFields as $field) {
			$ret[$field] = isset($bank->{$field}) ? $bank->{$field} : null;
		}

		return $ret;
	}

	/**
	 * Get WC_Order payment request ID
	 *
	 * @param int WC_Order ID
	 */
	public static function getOrderPaymentRequestId(int $orderId): string
	{
		$wcOrder = wc_get_order($orderId);
		if ($wcOrder) {
			$id = $wcOrder->get_meta('bridge_payment_link_id', true);
			if ($id) {
				return self::decrypt($id, 'payment_request_id', $orderId);
			}
		}

		return '';
	}

	/**
	 * Add payment request ID to WC_Order
	 *
	 * @param string payment request ID
	 * @param WC_Order
	 */
	public static function linkPaymentRequestId(string $id, \WC_Order $wcOrder)
	{
		$encrypted = self::encrypt($id, 'payment_request_id', true, $wcOrder->get_id());
		$wcOrder->add_meta_data('bridge_payment_link_id', $encrypted, true);
		$wcOrder->save_meta_data();
	}

	/**
	 * Get current user IP
	 *
	 * @return string user IP
	 */
	public static function getUserIP()
	{
		if (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
			return $_SERVER['HTTP_FORWARDED_FOR'];
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED'])) {
			return $_SERVER['HTTP_X_FORWARDED'];
		}

		if (!empty($_SERVER['HTTP_FORWARDED'])) {
			return $_SERVER['HTTP_FORWARDED'];
		}

		if (!empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) {
			return $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
		}

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			return $_SERVER['HTTP_CLIENT_IP'];
		}

		return $_SERVER['REMOTE_ADDR'];
	}

	/**
	 * Create webhook endpoint
	 */
	public static function createWebhookEndpoint(bool $refresh = false): void
	{
		add_rewrite_rule('bridge-webhook/([a-z0-9-]+)[/]?$', 'index.php?bridge-status=$matches[1]', 'top');
		if ($refresh) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Checks if permalinks is enabled
	 */
	public static function siteHasPermalinks(): bool
	{
		return (bool) get_option('permalink_structure');
	}

	/**
	 * Get webhook URL
	 *
	 * @param bool production environment?
	 *
	 * @return string
	 */
	public static function getWebhookURL(bool $isSandbox = true)
	{
		if (self::siteHasPermalinks()) {
			if (!$isSandbox) {
				$url = site_url('/bridge-webhook/transaction-updated');
			} else {
				$url = site_url('/bridge-webhook/transaction-updated?sandbox');
			}
		} else {
			if (!$isSandbox) {
				$url = site_url('?bridge-status=transaction-updated');
			} else {
				$url = site_url('?bridge-status=transaction-updated&sandbox');
			}
		}

		return apply_filters('bridgeapi-io/webhook/url', $url);
	}

	/**
	 * Update order status from payment request status
	 *
	 * @param \StdClass payment request payload
	 * @param \WC_Order WC order class
	 */
	public static function updateOrderStatus(\StdClass $paymentRequest, \WC_Order $wcOrder): void
	{
		// Sometimes status_reason is not set on payment response. I have to set to avoid notice errors
		$paymentRequest->status_reason = empty($paymentRequest->status_reason) ? '' : $paymentRequest->status_reason;
		switch ($paymentRequest->status) {
			case 'CREA':
			case 'ACTC':
			case 'ACCP':
				$wcOrder->update_status('on-hold', __('Bridge payment initiated (Payment ID : {payment_id})', 'bridgeapi-io'));
				break;
			case 'PDNG':
			case 'ACSP':
				$wcOrder->update_status('on-hold', __('Bridge payment pending (Payment ID : {payment_id})', 'bridgeapi-io'));
				break;
			case 'ACSC':
				$wcOrder->update_status('processing', __('Bridge payment succeeded (Payment ID : {payment_id})', 'bridgeapi-io'));
				break;
			case 'CANC':
				$wcOrder->update_status('failed', __('Bridge payment canceled (Payment ID : {payment_id})', 'bridgeapi-io'));
				break;
			case 'RJCT':
				$wcOrder->update_status('failed', __('Bridge payment rejected (Payment ID : {payment_id})', 'bridgeapi-io'));
				break;
			default:
				$wcOrder->update_status('failed', __('Unknown payment status: %s', 'bridgeapi-io'));
		}
	}

	/**
	 * Checks if site is HTTPS
	 *
	 * @return bool
	 */
	public static function isHTTPS()
	{
		return !empty($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	}

	/**
	 * Checks if bank is to be ignored
	 *
	 * @param int bank ID
	 */
	public static function bankIsValid(int $id): bool
	{
		$ignore = apply_filters('bridgeapi-io/banks/ignored', [
			'152' => 1,
			'179' => 1,
		]);

		return !isset($ignore[$id]);
	}

	/**
	 * Checks if webhook is configured
	 *
	 * @return bool
	 */
	public static function webhookConfigured(bool $isSandbox): int
	{
		$option = $isSandbox ? 'bridge_sandbox_webhook_set' : 'bridge_webhook_set';
		return absint(get_option($option, 0));
	}

	/**
	 * Set webhook configuration status
	 */
	public static function configureWebhook(bool $isSandbox = false, bool $disable = false): void
	{
		$option = $isSandbox ? 'bridge_sandbox_webhook_set' : 'bridge_webhook_set';
		update_option($option, $disable ? false : time());
	}

	public static function removeWebhook(bool $isSandbox = false): void
	{
		self::configureWebhook($isSandbox, true);
	}

	/**
	 * usort function to sort banks by user's locale
	 *
	 * @param array bank A data
	 * @param array bank B data
	 *
	 * @return int See - https://www.php.net/manual/en/function.usort.php
	 */
	public static function banksSortFunction(array $bankA, array $bankB)
	{
		$locale = get_user_locale();
		$ret = strcmp($bankA['name'], $bankB['name']);

		if ($locale) {
			list($lang, $country) = explode('_', $locale);
			if ($country === 'ES' || $country === 'FR') {
				if ($country === $bankA['country_code']) {
					$ret = -1;
				} elseif ($country === $bankB['country_code']) {
					$ret = 1;
				}

				if ($bankB['country_code'] === $bankA['country_code']) {
					$ret = strcmp($bankA['name'], $bankB['name']);
				}
			} else {
				if ($bankA['country_code'] === 'FR') {
					$ret = -1;
				}

				if ($bankB['country_code'] === 'FR') {
					$ret = 1;
				}

				if ($bankB['country_code'] === $bankA['country_code']) {
					$ret = strcmp($bankA['name'], $bankB['name']);
				}
			}
		}

		return $ret;
	}

	/**
	 * Checks if WC currency is supported
	 */
	public static function currencySupported(): bool
	{
		return in_array(get_woocommerce_currency(), BRIDGEAPI_SUPPORTED_CURRENCIES);
	}

	/**
	 * Checks if production can be enabled
	 */
	public static function canEnableProd(array $settings): bool
	{
		global $bridgeapi;

		$result = false;

		if (!self::isHTTPS() || !self::webhookConfigured(true) || !self::webhookConfigured(false) || !self::currencySupported()) {
			return apply_filters('bridgeapi-io/canEnableProd', false);
		}

		if ($settings) {
			if (
				!empty($settings['app_id']) &&
				!empty($settings['app_secret']) &&
				!empty($settings['test_app_id']) &&
				!empty($settings['test_app_secret'])
			) {
				try {
					$liveBridge = new Bridge($settings['app_id'], $settings['app_secret']);
					$liveBridge->banks->list([
						'limit' => 1,
					]);

					$result = true;
				} catch (BaseException $e) {
					$result = false;
				}
			}
		}

		return apply_filters('bridgeapi-io/canEnableProd', $result);
	}

	/**
	 * Checks if request is valid webhook request
	 */
	public static function isWebhookRequest(bool $isSandbox): bool
	{
		global $bridgeapi;

		$bridgeStatus = get_query_var('bridge-status');
		$headers = array_change_key_case(getallheaders(), CASE_LOWER);

		/**
		 * CHECKS
		 *
		 * Request is POST
		 * Bridge webhook request sent is for transaction-updated, which is the only allowed currently
		 * Header parameter is not empty
		 * Bridge Gateway payment is also enabled on WC. Because we get the webhook secret from the instance
		 * If the environment webhook secret is set
		 * IP is from allowed list of IPs
		 */
		return (
			$_SERVER['REQUEST_METHOD'] === 'POST' &&
			$bridgeStatus === 'transaction-updated' &&
			!empty($headers['bridgeapi-signature']) &&
			!empty($bridgeapi->bridgeGateway) &&
			!empty(($isSandbox ? $bridgeapi->bridgeGateway->test_webhook_secret : $bridgeapi->bridgeGateway->webhook_secret))
		);
	}

	/**
	 * Checks webhook header signature. This function is assuming isWehbookRequest() passed.
	 */
	public static function isValidWebhook(string $requestBody, bool $isSandbox): bool
	{
		global $bridgeapi;

		$headers = array_change_key_case(getallheaders(), CASE_LOWER);
		$result = false;

		if (isset($headers['bridgeapi-signature'])) {
			$signatures = explode(',', $headers['bridgeapi-signature']);
			$hash = strtoupper(
				hash_hmac('sha256', $requestBody, ($isSandbox ? $bridgeapi->bridgeGateway->test_webhook_secret : $bridgeapi->bridgeGateway->webhook_secret), false)
			);

			// We cannot have more than 2
			for ($i = 0; $i < 2; $i++) {
				if (isset($signatures[$i])) { // could be less than 2
					parse_str($signatures[$i], $parsed);
					if (!empty($parsed['v1']) && $parsed['v1'] === $hash) {
						$result = true;
						break;
					}
				}
			}
		}

		return apply_filters('bridgeapi-io/webhook/isValid', $result, $requestBody, $isSandbox);
	}

	/**
	 * Remove specific char not accepted by Bridge API
	 */
	public static function removeForbiddenChar(string $string): string
	{
		$string = html_entity_decode($string);
		$string = str_replace(['#', '@', '[', ']', '|', '–'], '', $string);
		return preg_replace('/\s+/', ' ', $string);
	}

	/**
	 * Checks if encryption key exists
	 *
	 * @param int $orderId if available
	 */
	private static function _keyExists(string $key, int $orderId = 0): bool
	{
		$key = self::_maybePrepKey($key);

		if ($orderId) {
			$WCOrder = wc_get_order($orderId);
			if ($WCOrder) {
				return (bool) $WCOrder->get_meta($key, true);
			}

			return false;
		}
		return (bool) get_option($key, false);
	}

	private static function _maybePrepKey(string $key): string
	{
		if (strpos($key, '_bridge_key_') !== false) {
			return $key;
		}

		return '_bridge_key_' . $key;
	}

	/**
	 * Pack and write encryption data to file
	 *
	 * @param int $orderId if there is one
	 */
	private static function packToDB(array $params, string $key, int $orderId = 0): void
	{
		$pack = base64_encode(sprintf('%s:%s:%s:%s', $params['cipher'], base64_encode($params['iv']), $params['tag'], base64_encode($params['key'])));
		$key = self::_maybePrepKey($key);

		if ($orderId) {
			$wcOrder = wc_get_order($orderId);
			if (!$wcOrder) {
				throw new NotExists(__('Order ID does not exist', 'bridgeapi-io'));
			}

			$wcOrder->add_meta_data($key, $pack, true);
			$wcOrder->save_meta_data();
		} else {
			update_option($key, $pack);
		}
	}

	private static function UnpackFromDB(string $key, int $orderId = 0): array
	{
		$key = self::_maybePrepKey($key);

		if (!$orderId) {
			$content = get_option($key, '');
		} else {
			$wcOrder = wc_get_order($orderId);
			if (!$wcOrder) {
				throw new NotExists(__('Order ID does not exist', 'bridgeapi-io'));
			}

			$content = $wcOrder->get_meta($key, true);
		}

		$decoded = base64_decode($content);
		if ($decoded === false) {
			throw new DecodeError(__('Unable to decode encryption data'));
		}

		list($cipher, $iv, $tag, $key) = explode(':', $decoded);
		$iv = base64_decode($iv);
		$key = base64_decode($key);

		return compact('cipher', 'iv', 'tag', 'key');
	}
}
