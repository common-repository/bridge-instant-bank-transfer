<?php

namespace BridgeApi;

class Admin
{
	/**
	 * Enqueue notices to show
	 *
	 * @var array<mixed> [['class' => '', 'message' => ''], ...]
	 */
	private $notices = [];

	/**
	 * BrigeApi instance
	 *
	 * @var BridgeApi
	 */
	private $bridgeApi;


	public function __construct(BridgeApi $bridgeApi)
	{
		$this->bridgeApi = $bridgeApi;
	}

	/**
	 * Fire all hooks required by this class
	 */
	public function hooks(): void
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
	}

	/**
	 * Append new admin notice to display
	 *
	 * @param string $message Notice message
	 * @param string $class DOM class name
	 * @param bool $show Show message after appending
	 */
	public function addNotice(string $message, string $class = 'notice-info', bool $show = true): void
	{
		$this->notices[] = [
			'class' => $class,
			'message' => $message,
		];

		if ($show) {
			add_action('admin_notices', [$this, 'showNotices']);
		}
	}

	/**
	 * Enqueues admin scripts
	 */
	public function enqueue(): void
	{
		if (isset($_GET['page'], $_GET['tab'], $_GET['section']) && $_GET['section'] === 'bridgeapi-io') {
			// wp_register_script('bridge-fetch', sprintf('%s/js/fetch.min.js', BRIDGEAPI_IO_ASSETS_URI));
			wp_enqueue_script('bridge-settings', sprintf('%s/js/admin/settings.js', BRIDGEAPI_IO_ASSETS_URI), ['jquery']);
			wp_localize_script('bridge-settings', 'BRIDGE_SETTINGS', [
				'spinner' => admin_url('images/spinner.gif'),
				'ajax' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bridgeapi-io/admin/nonce'),
				'text' => [
					'provide_credentials' => __('Please provide credentials', 'bridgeapi-io'),
					'webhook_configured' => __('Webhook Configured: ', 'bridgeapi-io'),
				],
			]);
		}
	}

	/**
	 * Display and clear WP errors
	 */
	public function showNotices(): void
	{
		foreach ($this->notices as $index => $notice) {
			include sprintf('%s/admin/partial/notices.php', BRIDGEAPI_IO_TEMPLATE_PATH);
			unset($this->notices[ $index ]);
		}
	}

	/**
	 * Checks if has queued notices
	 */
	public function hasNotices(): bool
	{
		return !empty($this->notices);
	}
}
