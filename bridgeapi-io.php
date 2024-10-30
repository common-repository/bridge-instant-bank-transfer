<?php
/**
 * Plugin Name:		Bridge – Payer par Virement Immédiat
 * Author:		Bridge
 * Author URI:		https://bridgeapi.io/
 * Description:		Enable your customer to shop in 1 click: simple, 100% secured and without amount limit
 * Version:		1.0.22
 * Text Domain:		bridgeapi-io
 * Requires PHP:	7.4
 * Requires at least:	5.1
 * Domain Path:         /languages
 */

defined('ABSPATH') || exit;

defined('BRIDGEAPI_IO_DIR')			|| define('BRIDGEAPI_IO_DIR', __DIR__ );
defined('BRIDGEAPI_IO_CLASS_PATH')		|| define('BRIDGEAPI_IO_CLASS_PATH', sprintf('%s/includes', BRIDGEAPI_IO_DIR));
defined('BRIDGEAPI_IO_TEMPLATE_PATH')		|| define('BRIDGEAPI_IO_TEMPLATE_PATH', sprintf('%s/templates', BRIDGEAPI_IO_DIR));
defined('BRIDGEAPI_IO_BASE_URI')		|| define('BRIDGEAPI_IO_BASE_URI', plugins_url( '', __FILE__));
defined('BRIDGEAPI_IO_ASSETS_URI')              || define('BRIDGEAPI_IO_ASSETS_URI', sprintf('%s/assets', BRIDGEAPI_IO_BASE_URI));
defined('BRIDGEAPI_IO_BASE_NAME')		|| define('BRIDGEAPI_IO_BASE_NAME', plugin_basename(__FILE__));
defined('BRIDGEAPI_BASE_URL')                   || define('BRIDGEAPI_IO_BASE_URL', 'https://api.bridgeapi.io/v2');
defined('BRIDGEAPI_VERSION')                    || define('BRIDGEAPI_VERSION', '2021-06-01');
defined('BRIDGEAPI_SUPPORTED_CURRENCIES')       || define('BRIDGEAPI_SUPPORTED_CURRENCIES', ['EUR']);
defined('BRIDGEAPI_WBH_IPS')                    || define('BRIDGEAPI_WBH_IPS', ['63.32.31.5', '52.215.247.62', '34.249.92.209']);

$uploads_dir = wp_get_upload_dir();
if (empty($uploads_dir['error'])) {
        defined('BRIDGEAPI_IO_CRYPT_DIR')       || define('BRIDGEAPI_IO_CRYPT_DIR', sprintf('%s/bridge-crypt', $uploads_dir['basedir']));
} else {
        defined('BRIDGEAPI_IO_CRYPT_DIR')       || define('BRIDGEAPI_IO_CRYPT_DIR', sprintf('%s/crypt', BRIDGEAPI_IO_DIR));
}

defined('BRIDGEAPI_IO_CIPHER')                  || define('BRIDGEAPI_IO_CIPHER', 'aes-256-cbc');
defined('BRIDGEAPI_IO_KEYFILE')                 || define('BRIDGEAPI_IO_KEYFILE', sprintf('%s/key', BRIDGEAPI_IO_CRYPT_DIR));

require_once 'vendor/autoload.php';

if (empty($GLOBALS['bridgeapi'])) {
	$GLOBALS['bridgeapi'] = \BridgeApi\BridgeApi::instance();
        register_activation_hook(__FILE__, [$GLOBALS['bridgeapi'], 'activation']);
        register_deactivation_hook(__FILE__, [$GLOBALS['bridgeapi'], 'deactivation']);
}