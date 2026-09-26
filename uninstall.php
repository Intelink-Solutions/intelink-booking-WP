<?php
/** Data is retained on uninstall to avoid destroying appointment/payment history. */
defined('WP_UNINSTALL_PLUGIN') || exit;
wp_clear_scheduled_hook('ib_maintenance');
