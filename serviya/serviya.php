<?php
/**
 * Plugin Name:       Serviya — Shop & Blog
 * Plugin URI:        https://serviya.ir/
 * Description:       افزونه یکپارچه سرویا (فروشگاه، محصول، وبلاگ، پست و...)
 * Version:           3.2.3
 * Author:            Serviya
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       serviya
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SERVIYA_VERSION', '3.2.3');
define('SERVIYA_PATH', plugin_dir_path(__FILE__));
define('SERVIYA_URL', plugin_dir_url(__FILE__));

/**
 * Detect one of the former standalone plugins.
 *
 * The unified modules deliberately use different internal function names, so
 * activating this plugin first cannot produce a fatal error. When a legacy
 * plugin is still active, the matching unified module stays dormant and the
 * legacy one keeps serving the page until the site owner switches it off.
 */
function serviya_suite_is_legacy_plugin_active($plugin_basename) {
    if (!function_exists('get_option')) {
        return false;
    }

    $active_plugins = (array) get_option('active_plugins', array());
    if (in_array($plugin_basename, $active_plugins, true)) {
        return true;
    }

    if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
        $network_plugins = (array) get_site_option('active_sitewide_plugins', array());
        return isset($network_plugins[$plugin_basename]);
    }

    return false;
}

$serviya_suite_legacy = array();

if (!serviya_suite_is_legacy_plugin_active('serviya-shop/serviya-shop.php')) {
    require_once SERVIYA_PATH . 'modules/shop.php';
} else {
    $serviya_suite_legacy[] = 'Serviya Shop';
}

if (!serviya_suite_is_legacy_plugin_active('serviya-blog/serviya-blog.php')) {
    require_once SERVIYA_PATH . 'modules/blog.php';
} else {
    $serviya_suite_legacy[] = 'Serviya Blog';
}

// The single-product and single-post modules have no legacy standalone
// counterparts, so they are always available
// (shortcodes [serviya_product] and [serviya_post]).
require_once SERVIYA_PATH . 'modules/product.php';
require_once SERVIYA_PATH . 'modules/post.php';

if ($serviya_suite_legacy) {
    add_action('admin_notices', function () use ($serviya_suite_legacy) {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Serviya — Shop & Blog:</strong> ';
        echo 'افزونه قدیمی ';
        echo esc_html(implode(' و ', $serviya_suite_legacy));
        echo ' هنوز فعال است؛ برای جلوگیری از تداخل، ماژول مشابه در نسخه یکپارچه فعلاً استندبای مانده است. ';
        echo 'کافی است افزونه قدیمی را غیرفعال کنید تا نسخه یکپارچه همان لحظه جایگزین شود. شورت‌کدها تغییری نمی‌کنند و تنظیمی هم از دست نمی‌رود.';
        echo '</p></div>';
    });
}
