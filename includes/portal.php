<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Portal {
    public static function init(): void {
        add_action('template_redirect',[self::class,'render'],0);
        add_shortcode('intelink_booking_dashboard',[self::class,'shortcode']);
    }
    public static function shortcode(): string {
        if(!is_user_logged_in()) return '<p><a href="'.esc_url(add_query_arg('ib_portal','1',home_url('/'))).'">Sign in to the booking management portal</a></p>';
        if(!current_user_can('manage_intelink_booking')) return '<p>Access denied.</p>';
        return '<p><a class="button" href="'.esc_url(add_query_arg('ib_portal','1',home_url('/'))).'">Open the standalone booking dashboard →</a></p>';
    }
    private static function login(): void {
        $error=''; if($_SERVER['REQUEST_METHOD']==='POST') {
            if(!isset($_POST['ib_admin_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ib_admin_nonce'])),'ib_admin_signin')) $error='Please refresh and try again.';
            else { $login=sanitize_text_field(wp_unslash($_POST['login']??'')); $password=(string)wp_unslash($_POST['password']??''); $user=wp_signon(['user_login'=>$login,'user_password'=>$password,'remember'=>!empty($_POST['remember'])],is_ssl()); if(is_wp_error($user)) $error='Invalid credentials.'; elseif(!user_can($user,'manage_intelink_booking')) { wp_logout(); $error='Booking administration access is required.'; } else { wp_safe_redirect(add_query_arg('ib_portal','1',home_url('/')));exit; } }
        }
        $logo=Frontend::logo(); nocache_headers(); echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Booking management sign in</title><link rel="stylesheet" href="'.esc_url(IB_URL.'assets/portal.css?ver='.IB_VERSION).'"></head><body class="ib-auth"><main class="ib-auth-card">'; if($logo) echo '<img class="ib-auth-logo" src="'.esc_url($logo).'" alt="">'; echo '<h1>Booking Management</h1><p>Sign in to your administration workspace.</p>';if($error) echo '<p role="alert" class="ib-auth-error">'.esc_html($error).'</p>';echo '<form method="post">';wp_nonce_field('ib_admin_signin','ib_admin_nonce');echo '<label>Username or email<input name="login" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><label class="ib-auth-remember"><input type="checkbox" name="remember"> Remember me</label><button type="submit">Sign in →</button></form><a href="'.esc_url(wp_lostpassword_url()).'">Forgot password?</a></main></body></html>';
    }
    public static function render(): void {
        if(!isset($_GET['ib_portal'])) return;
        nocache_headers();header('X-Robots-Tag: noindex, nofollow',true);
        if(!is_user_logged_in()) { self::login(); exit; }
        if(!current_user_can('manage_intelink_booking')) wp_die('You do not have permission to access Intelink Booking.', 'Access denied', ['response'=>403]);
        $labels=['dashboard'=>'Overview','appointments'=>'Appointments','calendar'=>'Calendar','services'=>'Services','service_categories'=>'Categories','staff'=>'Staff','customers'=>'Customers','availability'=>'Availability','payments'=>'Payments','coupons'=>'Coupons','notifications'=>'Notifications','reports'=>'Reports','settings'=>'Settings','integrations'=>'Integrations','activity'=>'Recent Booking Popups'];
        $s=Store::settings();$logo=Frontend::logo();$initial=add_query_arg(['page'=>'ib-dashboard','ib_embed'=>'1'],admin_url('admin.php'));
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Intelink Booking Dashboard — <?php bloginfo('name'); ?></title><link rel="stylesheet" href="<?php echo esc_url(IB_URL.'assets/portal.css?ver='.IB_VERSION); ?>"></head><body class="ib-portal"><div class="ib-portal-shell"><aside class="ib-portal-sidebar"><a class="ib-portal-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php endif; ?><span><?php echo esc_html($s['business_name']); ?><small>Booking Management</small></span></a><nav aria-label="Booking administration"><?php foreach($labels as $key=>$label): ?><a target="ib-workspace" data-page="<?php echo esc_attr($key); ?>" href="<?php echo esc_url(add_query_arg(['page'=>'ib-'.$key,'ib_embed'=>'1'],admin_url('admin.php'))); ?>" <?php echo $key==='dashboard'?'aria-current="page"':''; ?>><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><div class="ib-portal-bottom"><a href="<?php echo esc_url(home_url('/')); ?>">← View website</a><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign out</a></div></aside><div class="ib-portal-main"><header class="ib-portal-top"><button class="ib-portal-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">☰</button><strong id="ib-portal-title">Overview</strong><span><?php echo esc_html(wp_get_current_user()->display_name); ?></span></header><iframe name="ib-workspace" id="ib-workspace" title="Booking administration workspace" src="<?php echo esc_url($initial); ?>"></iframe></div></div><script src="<?php echo esc_url(IB_URL.'assets/portal.js?ver='.IB_VERSION); ?>" defer></script></body></html><?php exit;
    }
}
