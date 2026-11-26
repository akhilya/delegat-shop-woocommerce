<?php
/**
 * Plugin Name:       Delegat Bridge
 * Plugin URI:        https://delegat.pro
 * Description:       REST bridge for Delegat → WooCommerce catalog sync (categories, products, variations, prices, stocks).
 * Version:           1.3.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Delegat
 * Text Domain:       delegat-bridge
 * Domain Path:       /languages
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DELEGAT_BRIDGE_VERSION', '1.3.4');
define('DELEGAT_BRIDGE_FILE', __FILE__);
define('DELEGAT_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('DELEGAT_BRIDGE_OPTION_API_KEY', 'delegat_bridge_api_key');

require_once DELEGAT_BRIDGE_PATH . 'includes/class-delegat-auth.php';
require_once DELEGAT_BRIDGE_PATH . 'includes/class-delegat-images.php';
require_once DELEGAT_BRIDGE_PATH . 'includes/class-delegat-products.php';
require_once DELEGAT_BRIDGE_PATH . 'includes/class-delegat-commerce.php';
require_once DELEGAT_BRIDGE_PATH . 'includes/class-delegat-rest.php';
require_once DELEGAT_BRIDGE_PATH . 'admin/class-delegat-admin.php';

/**
 * Bootstrap after plugins loaded.
 */
function delegat_bridge_init(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Delegat Bridge requires WooCommerce to be active.', 'delegat-bridge') .
                '</p></div>';
        });
        return;
    }

    Delegat_Bridge_Images::register();
    Delegat_Bridge_REST::register();
    if (is_admin()) {
        Delegat_Bridge_Admin::register();
    }
}
add_action('plugins_loaded', 'delegat_bridge_init');

/**
 * Expose bridge version on every REST response for deploy verification.
 */
add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    if ($response instanceof WP_REST_Response
        && is_string($request->get_route())
        && str_starts_with($request->get_route(), '/' . Delegat_Bridge_REST::NAMESPACE)
    ) {
        $response->header('X-Delegat-Bridge-Version', DELEGAT_BRIDGE_VERSION);
    }
    return $response;
}, 10, 3);

/**
 * Generate an API key on activation if missing.
 */
function delegat_bridge_activate(): void
{
    if (!get_option(DELEGAT_BRIDGE_OPTION_API_KEY)) {
        update_option(DELEGAT_BRIDGE_OPTION_API_KEY, wp_generate_password(48, false, false));
    }
}
register_activation_hook(__FILE__, 'delegat_bridge_activate');
