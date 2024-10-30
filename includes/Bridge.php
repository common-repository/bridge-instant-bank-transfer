<?php

namespace BridgeApi;

defined('ABSPATH') || exit;

use BridgeApi\Exceptions\BridgeApi;
use BridgeApi\Exceptions\ConnectionError;

class Bridge
{
	public const WBH_PAY_TRANS_UPDTD = 'payment.transaction.updated';
	public const WBH_TEST_EVENT = 'TEST_EVENT';
	/**
	 * App ID key
	 *
	 * @var string
	 */
	private $id;

	/**
	 * App secret
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Current endpoint
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Next page params
	 *
	 * @var string
	 */
	private $nextPageParam;

	public function __construct(string $id, string $secret)
	{
		$this->id = $id;
		$this->secret = $secret;
	}

	public function __get($prop)
	{
		$this->endpoint = str_replace('_', '-', $prop);
		return $this;
	}

	public function __call($method, $args)
	{
		if ($this->endpoint) {
			switch ($method) {
				case 'list':
					return $this->request('GET', (!empty($args) ? $args[0] : []));
					break;
				case 'get':
					$this->endpoint .= '/' . $args[0];
					return $this->request('GET');
					break;
				case 'create':
					return $this->request('POST', [], (!empty($args) ? $args[0] : []));
					break;
			}
		}
	}

	/**
	 * Checks if response has next page
	 *
	 * @param StdClass $lastResponse
	 */
	public function hasNextPage(\StdClass $lastResponse): bool
	{
		return !empty($lastResponse->pagination->next_uri);
	}

	/**
	 * Requests for next page from last request
	 *
	 * @return mixed
	 */
	public function next()
	{
		if ($this->nextPageParam) {
			return $this->request('GET', $this->nextPageParam);
		}

		return null;
	}

	/**
	 * Make request to API server
	 *
	 * @param array<string> URL parameters
	 * @param array<mixed> request body
	 *
	 * @return mixed
	 */
	private function request(string $method = 'GET', array $params = [], array $body = [])
	{
		// I am assuming every request needs client_id and secret, so nothing happens without it
		if (empty($this->id) || empty($this->secret)) {
			throw new BridgeApi(__('Please finalize the plugin configuration', 'bridgeapi-io'));
		}

		$request = wp_remote_request(
			sprintf('%s/%s?%s', BRIDGEAPI_IO_BASE_URL, $this->endpoint, http_build_query($params)),
			[
				'method' => $method,
				'headers' => [
					'Client-Id' => $this->id,
					'Client-Secret' => $this->secret,
					'Bridge-Version' => BRIDGEAPI_VERSION,
					'Content-Type' => 'application/json',
				],
				'body' => $body ? json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : [],
			]
		);

		if (is_wp_error($request)) {
			throw new ConnectionError($request->get_error_message());
		}

		$body = wp_remote_retrieve_body($request);
		$code = wp_remote_retrieve_response_code($request);
		$decoded = json_decode($body);

		if ($code !== 200) {
			$errorMessage = '';
			if ($decoded) {
				if (isset($decoded->message)) {
					$errorMessage = sprintf(__('Bridge Error: %s', 'bridgeapi-io'), $decoded->message);
				} elseif (!empty($decoded->errors)) {
					// Choose first message first
					$errorMessage = sprintf(__('Bridge Error: %s', 'bridgeapi-io'), $decoded->errors[0]->message);
				} else {
					$errorMessage = __('Unknown Bridge API response', 'bridgeapi-io');
				}
			} else {
				$errorMessage = sprintf(__('Bridge Error: %s', 'bridgeapi-io'), $body);
			}

			throw new BridgeApi($errorMessage);
		}

		if (!$this->hasNextPage($decoded)) {
			$this->endpoint = null;
			$this->nextPageParam = null;
		} else {
			$params = substr($decoded->pagination->next_uri, strpos($decoded->pagination->next_uri, '?') + 1);
			parse_str($params, $this->nextPageParam);
		}

		return $decoded;
	}
}
