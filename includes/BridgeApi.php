<?php

namespace BridgeApi;

use BridgeApi\Exceptions\BridgeApi as BridgeApiError;
use BridgeApi\Exceptions\ConnectionError;
use BridgeApi\Exceptions\Exception;

class BridgeApi
{
	/**
	 * Bridge Payment Gateway instance
	 *
	 * @var BridgeGateway
	 */
	public $bridgeGateway;
	/**
	 * Class instance
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Admin instance
	 *
	 * @var Admin
	 */
	private $admin;

	/**
	 * Ajax instance
	 *
	 * @var Ajax
	 */
	private $ajax;

	private function __construct()
	{
		$this->admin = new Admin($this);
		$this->ajax = new Ajax($this);

		$this->hooks();
		$this->admin->hooks();
		$this->ajax->hooks();
	}

	/**
	 * Main plugin parent class
	 */
	public static function instance(): self
	{
		if (!self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Instantiate BridgeGateway to be re-used through the plugin
	 */
	public function loadBridgeGateway(): void
	{
		$this->bridgeGateway = new BridgeGateway();
	}

	/**
	 * Expose payment gateway class to WC
	 *
	 * @param array<mixed> $methods
	 *
	 * @return array<mixed> $methods
	 */
	public function addPaymentMethod(array $methods): array
	{
		$methods[] = $this->bridgeGateway;
		return $methods;
	}

	/**
	 * Load WP translations
	 */
	public function loadTranslations()
	{
		load_plugin_textdomain('bridgeapi-io', false, basename(BRIDGEAPI_IO_DIR) . '/languages/');
	}

	/**
	 * Force to use local translations
	 * See - https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#plugins-on-wordpress-org
	 */
	public function forceToUseTranslations($mofile, $domain): string
	{
		if ($domain === 'bridgeapi-io' && strpos($mofile, WP_LANG_DIR . '/plugins/') !== false) {
			$locale = apply_filters('plugin_locale', determine_locale(), $domain);
			$mofile = BRIDGEAPI_IO_DIR . '/languages/' . $domain . '-' . $locale . '.mo';
		}

		return $mofile;
	}

	/**
	 * Checks for missing dependencies
	 */
	public function checkDeps(): void
	{
		// Show all pending notices
		$this->admin->showNotices();

		if (is_admin() && current_user_can('activate_plugins')) {
			if (!is_plugin_active('woocommerce/woocommerce.php')) {
				$this->admin->addNotice(
					__('BridgeAPI.io plugin depends on WooCommerce to be installed. Kindly setup WooCommerce', 'bridgeapi-io'),
					'notice-error',
					false
				);
			}

			if (!extension_loaded('openssl')) {
				$this->admin->addNotice(
					__('BridgeAPI.io plugin depends on OpenSSL PHP extension. Kindly enable this or contact your web host', 'bridgeapi-io'),
					'notice-error',
					false
				);
			}

			do_action('bridge-io/dependencies/check', $this);

			if ($this->admin->hasNotices()) {
				deactivate_plugins(BRIDGEAPI_IO_BASE_NAME);
				if (isset($_GET['activate'])) {
					unset($_GET['activate']);
				}

				$this->admin->showNotices();
			}
		}
	}

	
	public function getAdminInstance(): Admin
	{
		return $this->admin;
	}

	/**
	 * Activation hook to run on plugin activation
	 */
	public function activation()
	{
		Utils::generateKey();
		if (Utils::siteHasPermalinks()) {
			Utils::createWebhookEndpoint(true);
		}
	}

	/**
	 * Deactivation hook run on plugin deactivation
	 */
	public function deactivation()
	{
		remove_action('init', ['\BridgeApi\Utils', 'createWebhookEndpoint']);
		flush_rewrite_rules();
	}

	/**
	 * Embed scripts needed in checkout page
	 */
	public function checkoutScripts()
	{
		if (is_checkout()) {
			wp_enqueue_script('bridge-io-checkout', sprintf('%s/js/checkout.js', BRIDGEAPI_IO_ASSETS_URI), ['jquery'], null, false);
			wp_enqueue_style('bridge-io-checkout', sprintf('%s/css/checkout.css', BRIDGEAPI_IO_ASSETS_URI));

			wp_localize_script('bridge-io-checkout', 'BRIDGE_IO', [
				'ajax' => admin_url('admin-ajax.php'),
				'pay_for_order' => isset($_GET['pay_for_order']) && $_GET['pay_for_order'] === 'true',
				'nonces' => [
					'get_banks' => wp_create_nonce('bridge-io/ajax/get_banks'),
				],
			]);
		}
	}

	/**
	 * Hide metabox of payment_link_id meta from admin
	 *
	 * @param array<string> array of meta_ids to hide
	 *
	 * @return array<string> array of meta_ids
	 */
	public function hidePaymentLinkIdMeta(array $meta_ids): array
	{
		$meta_ids[] = 'bridge_payment_link_id';

		return $meta_ids;
	}

	public function protectPaymentLinkIdMeta(bool $protected, string $key, string $type): bool
	{
		return ($key === 'bridge_payment_link_id' && $type === 'post' ? true : $protected);
	}

	/**
	 * Watch for a successful webhook event
	 */
	public function webhookSuccess(string $type, \StdClass $payload, ?\WC_Order $wcOrder, bool $isSandbox)
	{
		Utils::configureWebhook($isSandbox);
	}

	/**
	 * Tell WordPress to watch this URL parameter
	 *
	 * @param array<string> $vars
	 *
	 * @return array<string>
	 */
	public function addQueryVar(array $vars): array
	{
		$vars[] = 'bridge-status';
		$vars[] = 'sandbox';

		return $vars;
	}

	/**
	 * Load webhook template when called
	 */
	public function loadWebhookTemplate(string $template): string
	{
		global $wp_query;

		$sandbox = get_query_var('sandbox', -1) !== -1;

		if (Utils::isWebhookRequest($sandbox)) {
			$rawInput = file_get_contents('php://input');

			if ($rawInput && Utils::isValidWebhook($rawInput, $sandbox)) {
				$decoded = json_decode($rawInput);
				if ($decoded && isset($decoded->type)) {
					if ($this->processWebHook($decoded, $sandbox)) {
						status_header(200);
						die;
					}
				}
			}

			if (Utils::siteHasPermalinks()) {
				$template = get_404_template();
				$wp_query->set_404();
				status_header(404);
			}
			// else act as normal as a useless url param
		}

		return $template;
	}

	/**
	 * Process payment after returning from Bridge. Before thank you page is displayed
	 *
	 * @return int $orderId to continue the filter journey
	 */
	public function processPayment(int $orderId)
	{
		$wcOrder = wc_get_order($orderId);
		if ($wcOrder) {
			try {
				$paymentId = Utils::getOrderPaymentRequestId($orderId);
				if ($paymentId) {
					$paymentRequest = $this->bridgeGateway->bridge->payment_requests->get($paymentId);
					Utils::updateOrderStatus($paymentRequest, $wcOrder);
				}
			} catch (ConnectionError $e) {
				$wcOrder->update_status('failed', $e->getMessage() . "\n");
			} catch (BridgeApiError $e) {
				$wcOrder->update_status('failed', $e->getMessage() . "\n");
			} catch (Exception $e) {
				$wcOrder->update_status(
					'failed',
					__('Error while fetching payment request ID:', 'bridgeapi-io') . $e->getMessage() . "\n"
				);
			}
		}

		return $orderId;
	}

	/**
	 * Fire all hooks required by this class
	 */
	private function hooks(): void
	{
		add_action('admin_init', [$this, 'checkDeps']);
		add_filter('woocommerce_payment_gateways', [$this, 'addPaymentMethod']);
		add_action('wp_enqueue_scripts', [$this, 'checkoutScripts']);

		// Delay this action to the last of them, we need to make sure loadTranslations() is done
		add_action('woocommerce_init', [$this, 'loadBridgeGateway'], 50);

		add_filter('woocommerce_thankyou_order_id', [$this, 'processPayment']);
		add_filter('query_vars', [$this, 'addQueryVar']);
		add_filter('template_include', [$this, 'loadWebhookTemplate']);
		add_action('init', ['\BridgeApi\Utils', 'createWebhookEndpoint']);
		add_action('bridgeapi-io/webhook/success', [$this, 'webhookSuccess'], 10, 4);

		// Could be init, but then plugin_loaded is called before init, makes it unable to translate texts in BridgeGateway.php because it is called in plugins_loaded, before init
		add_action('plugins_loaded', [$this, 'loadTranslations']);

		add_filter('load_textdomain_mofile', [$this, 'forceToUseTranslations'], 10, 2);
		add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hidePaymentLinkIdMeta']);
		add_filter('is_protected_meta', [$this, 'protectPaymentLinkIdMeta'], 10, 3);
	}

	/**
	 * Process Webhook request received
	 *
	 * @param \StdClass request payload
	 */
	private function processWebHook(\StdClass $payload, bool $isSandbox): bool
	{
		$result = false;
		do_action('bridgeapi-io/webhook/before', $payload);

		switch ($payload->type) {
			case Bridge::WBH_PAY_TRANS_UPDTD:
				if (isset($payload->content, $payload->content->end_to_end_id, $payload->content->payment_request_id)) {
					$wcOrder = wc_get_order($payload->content->end_to_end_id);

					if ($wcOrder) {
						try {
							$wcOrder->add_order_note(__('Webhook received', 'bridgeapi-io'));
							$paymentId = Utils::getOrderPaymentRequestId($wcOrder->get_id());
							if ($paymentId === $payload->content->payment_request_id) {
								// Verify from Bridge
								$paymentRequest = $this->bridgeGateway->bridge->payment_requests->get(
									$payload->content->payment_request_id
								);

								// Set with payment status gotten from Bridge
								Utils::updateOrderStatus($paymentRequest, $wcOrder);

								$wcOrder->add_order_note(__('Order updated through webhook', 'bridgeapi-io'));
								print('OK');
								do_action('bridgeapi-io/webhook/success', $payload->type, $payload, $wcOrder, $isSandbox);
								$result = true;
							}
						} catch (BridgeApiError $e) {
							$wcOrder->add_order_note(
								sprintf(__('Webhook verification failed: %s', 'bridgeapi-io'), $e->getMessage())
							);
						} catch (ConnectionError $e) {
							$wcOrder->add_order_note(
								sprintf(__('Webhook verification connection error: %s', 'bridgeapi-io'), $e->getMessage())
							);
						} catch (Exception $e) {
							$wcOrder->add_order_note(
								sprintf(__('Webhook failed: %s', 'bridgeapi-io'), $e->getMessage())
							);
						}
					}
				}
				break;
			case Bridge::WBH_TEST_EVENT:
				print('TESTOK');
				do_action('bridgeapi-io/webhook/success', $payload->type, $payload, null, $isSandbox);
				$result = true;
				break;
			default:
				$result = false;
		}

		do_action('bridgeapi-io/webhook/after', $payload);

		return $result;
	}
}
