<?php

namespace BridgeApi;

defined('ABSPATH') || exit;

use BridgeApi\Exceptions\BridgeApi;
use BridgeApi\Exceptions\ConnectionError;
use BridgeApi\Exceptions\Exception;
use BridgeApi\Utils;

class Ajax
{
	/**
	 * Plugin main instance
	 *
	 * @var BridgeApi
	 */
	private $bridgeapi;

	public function __construct(\BridgeApi\BridgeApi $bridgeapi)
	{
		$this->bridgeapi = $bridgeapi;
	}

	/**
	 * Hooks for Ajax actions
	 */
	public function hooks()
	{
		add_action('wp_ajax_bridge-io/ajax/get_banks', [$this, 'getBanks']);
		add_action('wp_ajax_nopriv_bridge-io/ajax/get_banks', [$this, 'getBanks']);
		add_action('wp_ajax_bridge-io/ajax/credentials/test', [$this, 'testCredentials']);
		add_action('wp_ajax_bridge-io/ajax/webhook/test', [$this, 'testWebhook']);
	}

	/**
	 * GET request for getting list of banks
	 */
	public function getBanks()
	{
		if (!empty($_POST['__nonce']) && wp_verify_nonce($_POST['__nonce'], 'bridge-io/ajax/get_banks')) {
			try {
				$params = apply_filters('bridge-io/ajax/bank/params', [
					'limit' => 250,
					'capabilities' => 'single_payment',
				]);

				$response = $this->bridgeapi->bridgeGateway->bridge->banks->list($params);
				$banks = [];
				if (!empty($response->resources)) {
					$banks = array_map(['\BridgeApi\Utils', 'mapBankResponse'], $response->resources);
				}

				unset($response);

				while (($response = $this->bridgeapi->bridgeGateway->bridge->banks->next())) {
					if (!empty($response->resources)) {
						$list = array_map(['\BridgeApi\Utils', 'mapBankResponse'], $response->resources);
						$banks = array_merge($banks, $list);
					}
				}

				$banks = array_filter($banks, function ($bank) {
					return Utils::bankIsValid($bank['id']);
				});

				// Reset indexes
				$banks = array_values($banks);
				usort($banks, ['\BridgeApi\Utils', 'banksSortFunction']);

				wp_send_json_success($banks);
			} catch (ConnectionError $e) {
				wp_send_json_error(sprintf(__('Error connecting to API server: %s', 'bridgeapi-io'), $e->getMessage()), 500);
			} catch (BridgeApi $e) {
				wp_send_json_error(sprintf(__('API Error: %s', 'bridgeapi-io'), $e->getMessage()), 500);
			}
		}
	}
	/**
	 * Test credentials
	 */
	public function testCredentials()
	{
		if ($this->userCan()) {
			if (!empty($_POST['app_id']) && !empty($_POST['app_secret'])) {
				try {
					$id = sanitize_text_field($_POST['app_id']);
					$secret = sanitize_text_field($_POST['app_secret']);

					$bridge = new Bridge($id, $secret);
					$bridge->banks->list([
						'limit' => 1,
					]);

					wp_send_json_success();
				} catch (Exception $e) {
					wp_send_json_error($e->getMessage(), 500);
				}
			}
		}
	}

	/**
	 * Endpoint for testing webhook
	 */
	public function testWebhook()
	{
		if ($this->userCan()) {
			if (isset($_POST['isSandbox'])) {
				$isSandbox = (bool) $_POST['isSandbox'];
				$lastReceived = Utils::webhookConfigured($isSandbox);
				if ($lastReceived) {
					wp_send_json_success($lastReceived);
				}
			}

			wp_send_json_error();
		}
	}

	/**
	 * Checks if current request user can perform an action
	 */
	private function userCan(): bool
	{
		return check_admin_referer('bridgeapi-io/admin/nonce', '__nonce') && current_user_can('manage_options');
	}
}
