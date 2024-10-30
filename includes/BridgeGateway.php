<?php
namespace BridgeApi;

use BridgeApi\Exceptions\BridgeApi;
use BridgeApi\Exceptions\ConnectionError;
use BridgeApi\Exceptions\Exception;
use BridgeApi\Utils;

class BridgeGateway extends \WC_Payment_Gateway
{
	public $bridge;
	public $webhook_secret = '';
	public $test_webhook_secret = '';
	/**
	 * Settings fields value
	 *
	 * @var array<mixed>
	 */
	private $fields = [];

	/**
	 * Hold fields that has been decryped to avoid decryption of already decryped which throws error
	 *
	 * @var array<string>
	 */
	private $decrypted = [];

	/**
	 * Hold fields that has been encrypted to avoid another encryption on encrypted
	 * This is because the actions fire twice, and I am sure why
	 *
	 * @var array<string>
	 */
	private $encrypted = [];

	/**
	 * List of fields to encrypt
	 *
	 * @var array<string>
	 */
	private $encrypted_fields = [
		'test_app_id' => 1,
		'test_app_secret' => 1,
		'app_secret' => 1,
		'app_id' => 1,
		'webhook_secret' => 1,
		'test_webhook_secret' => 1,
	];

	private $enable_logo = 'no';
	private $is_test = 'yes';
	private $can_enable_prod;
	private $app_id = '';
	private $app_secret = '';
	private $test_app_id = '';
	private $test_app_secret = '';


	public function __construct()
	{
		$this->id = 'bridgeapi-io';
		$this->icon = sprintf('%s/assets/images/bridge.png', BRIDGEAPI_IO_BASE_URI);
		$this->has_fields = true;
		$this->method_title = __('Pay by bank transfert', 'bridgeapi-io');
		$this->method_description = __('Bridge Instant Transfer Payment Plug-in for WooCommerce', 'bridgeapi-io');
		$this->title = __('Bank payment', 'bridgeapi-io');
		$this->description = __('Pay by bank transfer with Bridge. Settle your order by validating the transfer directly from your bank: simple, 100% secure and without credit card.', 'bridgeapi-io');
		$this->description .= sprintf('<p id="bridge-io-toggleCollapse">%s </p>', __('How does it works?', 'bridgeapi-io'));

		add_filter(sprintf('option_%s', $this->get_option_key()), [$this, 'decrypt']);
		add_filter(sprintf('pre_option_%s', $this->get_option_key()), [$this, 'decrypt']);
		add_filter(sprintf('woocommerce_settings_api_sanitized_fields_%s', $this->id), [$this, 'encrypt']);
		add_filter(sprintf('woocommerce_settings_api_sanitized_fields_%s', $this->id), [$this, 'sanitize_fields']);
		add_filter(sprintf('woocommerce_settings_api_sanitized_fields_%s', $this->id), [$this, 'validate_keys']);
		add_filter('woocommerce_get_order_note', [$this, 'decrypt_notes_payment_id'], 10, 2);

		$this->load_settings();
		if (!$this->enable_logo || $this->enable_logo === 'no') {
			$this->icon = '';
		}

		$this->can_enable_prod = Utils::canEnableProd($this->settings);
		if (!$this->can_enable_prod) {
			$this->is_test = 'yes';
			$this->settings['is_test'] = 'yes';
		}

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		if (empty($_POST)) {
			add_action('admin_notices', [$this, 'display_errors']);
		}

		$this->init_form_fields();

		if (!$this->is_test || $this->is_test === 'no') {
			$this->bridge = new Bridge($this->app_id, $this->app_secret);
		} else {
			$this->bridge = new Bridge($this->test_app_id, $this->test_app_secret);
		}
	}

	public function display_errors()
	{
		parent::display_errors();
		$this->errors = [];
	}

	/**
	 * Output the gateway settings screen.
	 */
	public function admin_options()
	{
		echo '<h2>' . esc_html($this->get_method_title());
		wc_back_link(__('Return to payments', 'woocommerce'), admin_url('admin.php?page=wc-settings&tab=checkout'));
		echo '</h2>';
		echo wp_kses_post(wpautop($this->get_method_description()));

		require_once BRIDGEAPI_IO_TEMPLATE_PATH . '/admin/partial/subheader.php';
		\WC_Settings_API::admin_options();
	}

	/**
	 * Return gateway title
	 */
	public function get_title(): string
	{
		if (is_admin()) {
			$this->title = __('Instant Transfer', 'bridgeapi-io');
		}

		return $this->title;
	}

	/**
	 * Validate keys on save
	 */
	public function validate_keys($option_values)
	{
		$has_credentials = true;
		// Check for test credentials if is in test mode
		// Check for live credentials if in live mode
		if (
			(
				!empty($option_values['is_test']) &&
				$option_values['is_test'] == 'yes' &&
				(
					empty($option_values['test_app_secret']) ||
					empty($option_values['test_app_id'])
				)
			) ||
			(
				(
					empty($option_values['is_test']) ||
					$option_values['is_test'] === 'no'
				)
				&&
				(
					empty($option_values['app_secret']) ||
					empty($option_values['app_id'])
				)
			)
		) {
			$this->add_error(__('Credentials invalid or not supplied', 'bridgeapi-io'));
			add_action('admin_notices', [$this, 'display_errors']);
			$has_credentials = false;
		}

		if (empty($option_values['app_secret']) || empty($option_values['app_id'])) {
			Utils::removeWebhook(false);
		}

		if (empty($option_values['test_app_secret']) || empty($option_values['test_app_id'])) {
			Utils::removeWebhook(true);
		}

		if ($has_credentials) {
			// Test
			try {
				if (!$option_values['is_test'] || $option_values['is_test'] === 'no') {
					$app_id = Utils::decrypt($option_values['app_id'], 'app_id');
					$secret = Utils::decrypt($option_values['app_secret'], 'app_secret');
				} else {
					$app_id = Utils::decrypt($option_values['test_app_id'], 'test_app_id');
					$secret = Utils::decrypt($option_values['test_app_secret'], 'test_app_secret');
				}

				$bridge = new Bridge($app_id, $secret);

				$bridge->banks->list([
					'limit' => 1,
				]);
			} catch (Exception $e) {
				Utils::removeWebhook($option_values['is_test'] && $option_values['is_test'] === 'yes');

				$this->add_error(sprintf(__('Credentials invalid or not supplied: %s', 'bridgeapi-io'), $e->getMessage()));
				add_action('admin_notices', [$this, 'display_errors']);
			}
		}

		return $option_values;
	}

	/**
	 * Always set to test mode if not HTTPS
	 *
	 * @param mixed
	 *
	 * @return mixed
	 */
	public function sanitize_fields($values)
	{
		if (!$this->can_enable_prod) {
			$values['is_test'] = 'yes';
		}

		return $values;
	}

	/**
	 * Decrypt and replace placeholder with payment_id
	 *
	 * @param array<mixed> $order_note
	 *
	 * @return array<mixed> $order_note
	 */
	public function decrypt_notes_payment_id(array $order_note, \WP_Comment $data): array
	{
		if (strpos($order_note['content'], '{payment_id}') !== false) {
			try {
				$paymentId = Utils::getOrderPaymentRequestId($data->comment_post_ID);
				$order_note['content'] = str_replace('{payment_id}', $paymentId, $order_note['content']);
			} catch (Exception $e) {
				$order_note['content'] = str_replace('{payment_id}', $e->getMessage(), $order_note['content']);
			}
		}

		return $order_note;
	}

	/**
	 * Encrypt keys
	 *
	 * @var mixed option values
	 *
	 * @return mixed option values
	 */
	public function encrypt($option_values)
	{
		if (is_array($option_values)) {
			foreach ($option_values as $key => $value) {
				$method = sprintf('encrypt_%s_field', $key);
				if (!isset($this->encrypted[$key]) && isset($this->encrypted_fields[$key])) {
					if (method_exists($this, $method)) {
						$option_values[$key] = $this->{$method}($value, $key);
					} else {
						$option_values[$key] = $this->encrypt_field($value, $key);
					}

					$this->encrypted[$key] = 1;
				}
			}
		}

		return $option_values;
	}

	/**
	 * Decrypt keys
	 *
	 * @var mixed option values
	 *
	 * @return mixed option values
	 */
	public function decrypt($option_values)
	{
		if (is_array($option_values)) {
			foreach ($option_values as $key => $value) {
				$method = sprintf('decrypt_%s_field', $key);
				if (!isset($this->decrypted[$key]) && isset($this->encrypted_fields[$key])) {
					if (method_exists($this, $method)) {
						$option_values[$key] = $this->{$method}($value, $key);
					} else {
						$option_values[$key] = $this->decrypt_field($value, $key);
					}

					$this->decrypted[$key] = 1;
				}
			}
		}

		return $option_values;
	}

	/**
	 * Generate Button Input HTML.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_button_html($key, $data)
	{
		$field_key = $this->get_field_key($key);
		$defaults = [
			'title' => '',
			'disabled' => false,
			'class' => '',
			'css' => '',
			'placeholder' => '',
			'type' => 'text',
			'desc_tip' => false,
			'description' => '',
			'custom_attributes' => [],
		];

		$data = wp_parse_args($data, $defaults);

		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); // WPCS: XSS ok.?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
					
					<button class="button <?php echo esc_attr($data['class']); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" <?php disabled($data['disabled'], true); ?> <?php echo $this->get_custom_attribute_html($data); // WPCS: XSS ok.?>>
						<?php echo esc_attr($data['default']); ?>
					</button>
                    <?php echo $this->get_description_html($data); // WPCS: XSS ok.?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate Title HTML.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 * @since  1.0.0
	 * @return string
	 */
	public function generate_title_html($key, $data)
	{
		$field_key = $this->get_field_key($key);
		$defaults = [
			'title' => '',
			'class' => '',
		];

		$data = wp_parse_args($data, $defaults);

		ob_start(); ?>
            </table>
            <h1 class="wc-settings-sub-title <?php echo esc_attr($data['class']); ?>" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h1>
            <?php if (!empty($data['description'])) : ?>
                <p><?php echo wp_kses_post($data['description']); ?></p>
            <?php endif; ?>
            <table class="form-table">
        <?php

		return ob_get_clean();
	}

	/**
	 * Init form fields to show on admin settings
	 */
	public function init_form_fields(): void
	{
		$webhookSandboxField = [
			'title' => __('Sandbox Webhook Status', 'bridgeapi-io'),
			'type' => 'button',
			'class' => 'button-secondary',
			'css' => 'margin-right: 5px;',
			'default' => __('Check the webhook configuration', 'bridgeapi-io'),
			'description' => sprintf(
				__('You must add this callback url %s to your Bridge Dashboard > Webhooks > Add a webhook > And select this one : %s. You can name the webhook as you wish.', 'bridgeapi-io'),
				'<code>' . Utils::getWebhookURL() . '</code>',
				'<strong><em>payment.transaction.updated</em></strong>'
			),
		];

		$webhookProdField = [
			'title' => __('Production Webhook Status', 'bridgeapi-io'),
			'type' => 'button',
			'class' => 'button-secondary',
			'css' => 'margin-right: 5px;',
			'default' => __('Check the webhook configuration', 'bridgeapi-io'),
			'description' => sprintf(
				__('You must add this callback url %s to your Bridge Dashboard > Webhooks > Add a webhook > And select this one : %s. You can name the webhook as you wish.', 'bridgeapi-io'),
				'<code>' . Utils::getWebhookURL(false) . '</code>',
				'<strong><em>payment.transaction.updated</em></strong>'
			),
		];

		$this->form_fields = [
			'enabled' => [
				'title' => __('Enable/Disable', 'bridgeapi-io'),
				'type' => 'checkbox',
				'label' => __('Enable this payment gateway', 'bridgeapi-io'),
				'description' => __('Enable this module to include the Bridge Instant Payment.', 'bridgeapi-io'),
				'default' => 'no',
			],
			'enable_logo' => [
				'title' => __('Bridge Logo', 'bridgeapi-io'),
				'type' => 'checkbox',
				'label' => __('Enable Bridge Logo', 'bridgeapi-io'),
				'default' => 'yes',
				'description' => __('Show Bridge logo at checkout', 'bridgeapi-io'),
			],
			'is_test' => [
				'title' => __('Environment', 'bridgeapi-io'),
				'type' => 'checkbox',
				'label' => __('Enable test mode (sandbox)', 'bridgeapi-io'),
				'description' => __('Switch to production mode to start accepting real payments. Conditions to switch to production mode: HTTPS,  production Client ID & Client Secret are valid, webhooks configured and tested. ', 'bridgeapi-io'),
				'default' => 'yes',
				'disabled' => !$this->can_enable_prod,
			],
			'sandbox_section' => [
				'type' => 'title',
				'title' => __('Sandbox environment', 'bridgeapi-io'),
			],
			'test_app_id' => [
				'title' => __('Sandbox (test) Client ID', 'bridgeapi-io'),
				'type' => 'text',
				'description' => __('This is the Client ID provided to you when creating an application on Bridge.', 'bridgeapi-io') . ' <a href="https://dashboard.bridgeapi.io/signup?utm_campaign=connector_woocommerce" target="_blank">' . __('Click here to create an account', 'bridgeapi-io') . '</a>',
			],
			'test_app_secret' => [
				'title' => __('Sandbox (test) Client Secret', 'bridgeapi-io'),
				'type' => 'password',
				'description' => __('This is the Client Secret provided to you when creating an application on Bridge.', 'bridgeapi-io') . ' <a href="https://dashboard.bridgeapi.io/signup?utm_campaign=connector_woocommerce" target="_blank">' . __('Click here to create an account', 'bridgeapi-io') . '</a>',
			],
			'test_webhook_secret' => [
				'title' => __('Sandbox Webhook Secret', 'bridgeapi-io'),
				'type' => 'password',
			],
			'check_test_btn' => [
				'title' => __('Check test (sandbox) credentials', 'bridgeapi-io'),
				'type' => 'button',
				'id' => 'check_test_btn',
				'class' => 'button-secondary',
				'default' => __('Check test (sandbox) credentials', 'bridgeapi-io'),
				'description' => '',
				'desc_tip' => __('Click to test sandbox credentials', 'bridgeapi-io'),
				'css' => 'margin-right: 5px;',
			],
			'check_sandbox_webhook' => $webhookSandboxField,
			'prod_section' => [
				'type' => 'title',
				'title' => __('Production environment', 'bridgeapi-io'),
			],
			'app_id' => [
				'title' => __('Production Client ID', 'bridgeapi-io'),
				'type' => 'text',
				'required' => true,
				'description' => __('This is the Client ID provided to you when creating an application on Bridge.', 'bridgeapi-io') . ' <a href="https://dashboard.bridgeapi.io/signup?utm_campaign=connector_woocommerce" target="_blank">' . __('Click here to create an account', 'bridgeapi-io') . '</a>',
			],
			'app_secret' => [
				'title' => __('Production Client Secret', 'bridgeapi-io'),
				'type' => 'password',
				'description' => __('This is the Client Secret provided to you when creating an application on Bridge.', 'bridgeapi-io') . ' <a href="https://dashboard.bridgeapi.io/signup?utm_campaign=connector_woocommerce" target="_blank">' . __('Click here to create an account', 'bridgeapi-io') . '</a>',
			],
			'webhook_secret' => [
				'title' => __('Production Webhook Secret', 'bridgeapi-io'),
				'type' => 'password',
			],
			'check_btn' => [
				'title' => __('Check production credentials', 'bridgeapi-io'),
				'type' => 'button',
				'class' => 'button-secondary',
				'desc_tip' => __('Click to test production credentials', 'bridgeapi-io'),
				'description' => '',
				'default' => __('Check production credentials', 'bridgeapi-io'),
				'css' => 'margin-right: 5px;',
			],
			'webook_btn' => $webhookProdField,
		];
	}

	/**
	 * Display banks on Checkout page
	 */
	public function payment_fields(): void
	{
		$description = $this->get_description();
		if ($description) {
			echo wpautop(wptexturize($description));
		}
		
		require_once BRIDGEAPI_IO_TEMPLATE_PATH . '/checkout/banks.php';
	}

	/**
	 * Validate user selection of banks before checkout
	 */
	public function validate_fields(): bool
	{
		$ret = false;
		if (empty($_POST['bridge-io-bank'])) {
			wc_add_notice(__('Payment error: Please select a bank to proceed', 'bridgeapi-io'), 'error');
		} else {
			try {
				$bankId = absint($_POST['bridge-io-bank']);
				if (!Utils::bankIsValid($bankId)) {
					throw new BridgeApi();
				}

				$this->bridge->banks->get($bankId);
				$ret = true;
			} catch (ConnectionError $e) {
				wc_add_notice(sprintf(__('Connection error: %s', 'bridgeapi-io'), $e->getMessage()), 'error');
			} catch (BridgeApi $e) {
				wc_add_notice(sprintf(__('Invalid bank selected: %s', 'bridgeapi-io'), $e->getMessage()), 'error');
			}
		}

		return apply_filters('bridgeapi-io/checkout/validate/fields', $ret, $this);
	}

	/**
	 * Finally, process payment
	 *
	 * @param int $order_id
	 *
	 * @return array<string>
	 */
	public function process_payment($order_id): array
	{
		$wc_order = wc_get_order($order_id);
		$bank_id = isset($_POST['bridge-io-bank']) ? absint($_POST['bridge-io-bank']) : 0;

		if ($bank_id && $wc_order) {
			$return_url = $this->get_return_url($wc_order);
			$params = [
				'successful_callback_url' => $return_url,
				'unsuccessful_callback_url' => $return_url,
				'transactions' => [
					[
						'currency' => get_woocommerce_currency(),
						'label' => Utils::removeForbiddenChar(get_bloginfo('name')),
						'amount' => (float) $wc_order->get_total(),
						'end_to_end_id' => (string) $order_id,
						'client_reference' => (string) $order_id,
					],
				],
				'user' => [
					'name' => sprintf('%s %s', $wc_order->get_billing_first_name(), $wc_order->get_billing_last_name()),
					'ip_address' => Utils::getUserIP(),
				],
				'bank_id' => $bank_id,
			];

			if (Utils::getOrderPaymentRequestId($order_id)) {
				$note = __('Last payment has not been finished, customer initiate a new one. Bridge payment initiated (Payment ID: {payment_id})', 'bridgeapi-io') . "\n";
			} else {
				$note = __('Bridge payment initiated (Payment ID: {payment_id})', 'bridgeapi-io') . "\n";
			}

			$params = apply_filters('bridgeapi-io/api/payment/params', $params, $this);

			$link_response = $this->bridge->payment_requests->create($params);

			Utils::linkPaymentRequestId($link_response->id, $wc_order);

			if ($wc_order->get_status() !== 'pending') {
				$wc_order->update_status('pending', $note);
			} else {
				$wc_order->add_order_note($note, false);
			}

			return [
				'result' => 'success',
				'redirect' => $link_response->consent_url,
			];
		}

		return [
			'result' => 'failure',
			'messages' => __('WC Order not found', 'bridgeapi-io'),
		];
	}

	/**
	 * Load existing settings
	 *
	 * @return BridgeGateway
	 */
	private function load_settings(): self
	{
		$this->init_settings();

		$this->enabled = $this->get_option('enabled');
		$this->enable_logo = $this->get_option('enable_logo');
		$this->is_test = $this->get_option('is_test');
		$this->app_id = $this->get_option('app_id');
		$this->app_secret = $this->get_option('app_secret');
		$this->webhook_secret = $this->get_option('webhook_secret');
		$this->test_app_id = $this->get_option('test_app_id');
		$this->test_app_secret = $this->get_option('test_app_secret');
		$this->test_webhook_secret = $this->get_option('test_webhook_secret');

		if (!Utils::currencySupported()) {
			$this->enabled = false;
			$this->add_error(__('Bridge payment only accepts Euro (EUR)', 'bridgeapi-io'));
		}


		if (!Utils::isHTTPS()) {
			$this->add_error(__('The website does not seem to be using HTTPS (SSL/TLS) encryption for communications, you must use it to enable Bridge Payment in production mode', 'bridgeapi-io'));
		}

		return $this;
	}

	/**
	 * Decrypt keys for a field
	 *
	 * @return ?string decrypted value
	 */
	private function decrypt_field(string $value, string $key): ?string
	{
		if ($value) {
			try {
				return Utils::decrypt($value, $key);
			} catch (Exception $e) {
				$this->add_error(sprintf(__('Error decrypting %s key: %s', 'bridgeapi-io'), $key, $e->getMessage()));
				add_action('admin_notices', [$this, 'display_errors']);
				return '';
			}
		}

		return $value;
	}

	/**
	 * Encrypt keys for a field
	 *
	 * @return ?string decrypted value
	 */
	private function encrypt_field(string $value, string $key): ?string
	{
		if ($value) {
			try {
				return Utils::encrypt($value, $key, true);
			} catch (Exception $e) {
				$this->add_error(sprintf(__('Error encrypting %s key: %s', 'bridgeapi-io'), $key, $e->getMessage()));
				add_action('admin_notices', [$this, 'display_errors']);
				return '';
			}
		}

		return $value;
	}
}
