<?php
/**
 * Plugin Name:       Miran — Shop & Blog
 * Plugin URI:        https://mirangiftshop.ir/
 * Description:       افزونه یکپارچه میران (فروشگاه، محصول، وبلاگ، پست و...)
 * Version:           1.0.0
 * Author:            Miran
 * Author URI:        https://mirangiftshop.ir/
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       miran
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MIRAN_VERSION', '1.0.0');
define('MIRAN_PATH', plugin_dir_path(__FILE__));
define('MIRAN_URL', plugin_dir_url(__FILE__));

/**
 * Detect one of the former standalone plugins.
 *
 * The unified modules deliberately use different internal function names, so
 * activating this plugin first cannot produce a fatal error. When a legacy
 * plugin is still active, the matching unified module stays dormant and the
 * legacy one keeps serving the page until the site owner switches it off.
 */
function miran_suite_is_legacy_plugin_active($plugin_basename) {
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

$miran_suite_legacy = array();

if (!miran_suite_is_legacy_plugin_active('miran-shop/miran-shop.php')) {
    require_once MIRAN_PATH . 'modules/shop.php';
} else {
    $miran_suite_legacy[] = 'Miran Shop';
}

if (!miran_suite_is_legacy_plugin_active('miran-blog/miran-blog.php')) {
    require_once MIRAN_PATH . 'modules/blog.php';
} else {
    $miran_suite_legacy[] = 'Miran Blog';
}

// The single-product and single-post modules have no legacy standalone
// counterparts, so they are always available
// (shortcodes [miran_product] and [miran_post]).
require_once MIRAN_PATH . 'modules/product.php';
require_once MIRAN_PATH . 'modules/post.php';

if ($miran_suite_legacy) {
    add_action('admin_notices', function () use ($miran_suite_legacy) {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Miran — Shop & Blog:</strong> ';
        echo 'افزونه قدیمی ';
        echo esc_html(implode(' و ', $miran_suite_legacy));
        echo ' هنوز فعال است؛ برای جلوگیری از تداخل، ماژول مشابه در نسخه یکپارچه فعلاً استندبای مانده است. ';
        echo 'کافی است افزونه قدیمی را غیرفعال کنید تا نسخه یکپارچه همان لحظه جایگزین شود. شورت‌کدها تغییری نمی‌کنند و تنظیمی هم از دست نمی‌رود.';
        echo '</p></div>';
    });
}
