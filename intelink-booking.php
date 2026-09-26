<?php
/**
 * Plugin Name: Intelink Booking
 * Description: Independent appointment scheduling, customer management and hosted payments.
 * Version: 1.5.0
 * Author: Intelink Solutions
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: intelink-booking
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('IB_VERSION', '1.5.0');
define('IB_PATH', plugin_dir_path(__FILE__));
define('IB_URL', plugin_dir_url(__FILE__));
foreach (['store', 'schedule', 'notifications', 'providers', 'payments', 'booking', 'api', 'admin', 'frontend', 'portal', 'customer-portal', 'activity'] as $file) require_once IB_PATH . 'includes/' . $file . '.php';
register_activation_hook(__FILE__, ['Intelink\Store', 'install']);
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('ib_maintenance'); });
add_filter('cron_schedules', function ($s) { $s['ib_minute'] = ['interval'=>60, 'display'=>'Intelink minute']; return $s; });
add_action('plugins_loaded', function () {
    if (get_option('ib_version') !== IB_VERSION) \Intelink\Store::install();
    \Intelink\API::init(); \Intelink\Admin::init(); \Intelink\Frontend::init(); \Intelink\Portal::init(); \Intelink\CustomerPortal::init(); \Intelink\Payments::hooks(); \Intelink\Activity::init();
    if (!wp_next_scheduled('ib_maintenance')) wp_schedule_event(time()+60, 'ib_minute', 'ib_maintenance');
});
add_action('ib_maintenance', function () { \Intelink\Booking::expire(); \Intelink\Notifications::work(); });
add_action('admin_post_ib_download', ['Intelink\Frontend', 'download']);
add_action('admin_post_nopriv_ib_download', ['Intelink\Frontend', 'download']);
