<?php
namespace Intelink;
defined('ABSPATH') || exit;
/** Dedicated, scoped customer account. Existing guest bookings require proof of the private token before linking. */
final class CustomerPortal {
    public static function url(): string { return add_query_arg('ib_account','1',home_url('/')); }
    public static function init(): void {
        add_shortcode('intelink_customer_dashboard',[self::class,'shortcode']);
        add_action('template_redirect',[self::class,'render'],1);
        add_action('admin_init',function() { if(!wp_doing_ajax() && !current_user_can('manage_intelink_booking') && is_user_logged_in()) wp_safe_redirect(self::url()); });
        add_filter('show_admin_bar',fn($show)=>current_user_can('manage_intelink_booking')?$show:false);
    }
    public static function render(): void {
        if(!isset($_GET['ib_account'])) return;
        nocache_headers();header('X-Robots-Tag: noindex, nofollow',true);
        get_header(); echo '<main class="ib-account-page">'.self::shortcode().'</main>';get_footer();exit;
    }
    private static function notice(string $value): void { set_transient('ib_account_notice_'.wp_get_session_token(),$value,60); }
    private static function redirect(): void { wp_safe_redirect(self::url());exit; }
    public static function shortcode(): string {
        wp_enqueue_style('ib-customer-portal',IB_URL.'assets/customer-portal.css',[],IB_VERSION);
        $error=''; $notice='';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ib_customer_action'])) {
            $action=sanitize_key(wp_unslash($_POST['ib_customer_action']));
            if(!isset($_POST['ib_customer_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ib_customer_nonce'])),'ib_customer_'.$action)) $error='Session expired. Reload the page and try again.';
            else try {
                if($action==='login') {
                    $login=sanitize_text_field(wp_unslash($_POST['login']??''));
                    if(is_email($login)) { $u=get_user_by('email',$login); if($u) $login=$u->user_login; }
                    $user=wp_signon(['user_login'=>$login,'user_password'=>(string)wp_unslash($_POST['password']??''),'remember'=>!empty($_POST['remember'])],is_ssl());
                    if(is_wp_error($user)) throw new \RuntimeException('Email/username or password was not accepted.');
                    self::redirect();
                }
                if($action==='register') {
                    if(!empty($_POST['website'])) throw new \RuntimeException('Unable to register.');
                    $key='ib_registration_'.md5($_SERVER['REMOTE_ADDR']??'');$tries=(int)get_transient($key);if($tries>=5)throw new \RuntimeException('Too many attempts. Try again later.');set_transient($key,$tries+1,HOUR_IN_SECONDS);
                    $email=sanitize_email(wp_unslash($_POST['email']??''));$password=(string)wp_unslash($_POST['password']??'');$name=sanitize_text_field(wp_unslash($_POST['full_name']??''));
                    if(!is_email($email) || email_exists($email) || strlen($password)<12 || strlen($name)<2) throw new \RuntimeException('Use a new valid email, your name and a password of at least 12 characters.');
                    $user=wp_insert_user(['user_login'=>'ib_'.bin2hex(random_bytes(8)),'user_pass'=>$password,'user_email'=>$email,'display_name'=>$name,'role'=>'subscriber']);
                    if(is_wp_error($user))throw new \RuntimeException('Account could not be created.');
                    wp_new_user_notification($user,null,'user');
                    wp_set_current_user($user);wp_set_auth_cookie($user,true,is_ssl());self::redirect();
                }
                if($action==='claim') {
                    if(!is_user_logged_in())throw new \RuntimeException('Sign in first.');
                    $reference=sanitize_text_field(wp_unslash($_POST['reference']??''));$token=sanitize_text_field(wp_unslash($_POST['token']??''));
                    $b=Booking::authorize($reference,$token);$owner=Store::all('appointment_meta','appointment_id=%d AND meta_key=%s',[(int)$b['id'],'account_user_id'])[0]??null;
                    if($owner && (int)$owner['meta_value']!==get_current_user_id())throw new \RuntimeException('This booking is already linked to another account.');
                    if(!$owner) Store::insert('appointment_meta',['appointment_id'=>(int)$b['id'],'meta_key'=>'account_user_id','meta_value'=>(string)get_current_user_id()]);
                    self::notice('Appointment linked to your account.');self::redirect();
                }
            } catch(\Throwable $e) { $error=$e->getMessage(); }
        }
        $notice=get_transient('ib_account_notice_'.wp_get_session_token());delete_transient('ib_account_notice_'.wp_get_session_token());
        $logo=Frontend::logo();$s=Store::settings();ob_start(); ?>
        <div class="ib-account"><header class="ib-account-top"><a href="<?php echo esc_url(home_url('/')); ?>"><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php else: echo esc_html(get_bloginfo('name'));endif; ?></a><span>My appointments</span><?php if(is_user_logged_in()): ?><a href="<?php echo esc_url(wp_logout_url(self::url())); ?>">Sign out</a><?php endif; ?></header>
        <?php if($error): ?><p class="ib-account-error" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?><?php if($notice): ?><p class="ib-account-success" role="status"><?php echo esc_html($notice); ?></p><?php endif; ?>
        <?php if(!is_user_logged_in()): ?>
          <div class="ib-account-auth"><section><h1>Welcome back</h1><p>Sign in to see your linked appointments and notifications.</p><form method="post"><?php wp_nonce_field('ib_customer_login','ib_customer_nonce'); ?><input type="hidden" name="ib_customer_action" value="login"><label>Email or username<input name="login" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><label class="ib-inline"><input type="checkbox" name="remember"> Remember me</label><button>Sign in</button></form><a href="<?php echo esc_url(wp_lostpassword_url(self::url())); ?>">Forgot password?</a></section>
          <section><h2>Create account</h2><p>Your appointments stay private. Previous guest bookings can be linked using their secure management token.</p><form method="post"><?php wp_nonce_field('ib_customer_register','ib_customer_nonce'); ?><input type="hidden" name="ib_customer_action" value="register"><div class="ib-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><label>Full name<input name="full_name" required autocomplete="name"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Password (12+ characters)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button>Create account</button></form></section></div>
        <?php else: $user=wp_get_current_user();global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT a.* FROM '.Store::table('appointments').' a INNER JOIN '.Store::table('appointment_meta').' m ON m.appointment_id=a.id AND m.meta_key=%s AND m.meta_value=%s ORDER BY a.starts_at DESC LIMIT 100','account_user_id',(string)$user->ID),ARRAY_A)?:[]; ?>
          <div class="ib-account-intro"><div><h1>Hello, <?php echo esc_html($user->display_name); ?></h1><p>Manage your bookings, review payment statuses and see notification history.</p></div><a class="ib-account-button" href="<?php echo esc_url(add_query_arg('ib_account',null,home_url('/'))); ?>">+ Book an appointment</a></div>
          <div class="ib-account-stats"><article><strong><?php echo count($rows); ?></strong><span>Total appointments</span></article><article><strong><?php echo count(array_filter($rows,fn($b)=>$b['status']==='confirmed')); ?></strong><span>Confirmed</span></article><article><strong><?php echo count(array_filter($rows,fn($b)=>strtotime($b['starts_at'].' UTC')>time() && in_array($b['status'],['confirmed','pending','awaiting_payment'],true))); ?></strong><span>Upcoming</span></article></div>
          <h2>My appointments</h2><div class="ib-account-list"><?php foreach($rows as $b): $snapshot=Store::json($b['snapshot']);$token=Booking::token((int)$b['id']);$link=add_query_arg('ib_manage',$b['reference'],home_url('/')).'#ib_token='.$token;?><article><div><small><?php echo esc_html($b['reference']); ?></small><h3><?php echo esc_html(implode(', ',array_column($snapshot['services']??[],'name'))); ?></h3><p><?php echo esc_html(wp_date('M j, Y · g:i a',strtotime($b['starts_at'].' UTC'),Store::tz())); ?></p></div><div><span class="ib-account-status"><?php echo esc_html(ucwords(str_replace('_',' ',$b['status']))); ?></span><p><?php echo esc_html(Store::money((int)$b['total'],$b['currency'])); ?> · <?php echo esc_html($b['payment_status']); ?></p><a href="<?php echo esc_url($link); ?>">Manage appointment →</a></div></article><?php endforeach; if(!$rows): ?><p>No appointments linked yet. Book while signed in, or link an earlier booking below.</p><?php endif; ?></div>
          <h2>My notifications</h2><div class="ib-account-list"><?php foreach(array_slice($rows,0,30) as $b):foreach(Store::all('notification_logs','appointment_id=%d ORDER BY id DESC LIMIT 10',[(int)$b['id']]) as $n):$template=Store::get('email_templates',(int)$n['template_id']);if(($template['audience']??'')!=='customer')continue; ?><article><div><strong><?php echo esc_html(ucwords(str_replace('_',' ',$template['event']))); ?></strong><p><?php echo esc_html($b['reference'].' · '.wp_date('M j, Y',strtotime($n['created_at'].' UTC'),Store::tz())); ?></p></div><span class="ib-account-status"><?php echo esc_html($n['status']); ?></span></article><?php endforeach;endforeach; ?></div>
          <section class="ib-account-claim"><h2>Link an earlier booking</h2><p>For privacy, guest appointments are not imported just because their email matches your account. Use the private reference and management token from your booking email.</p><form method="post"><?php wp_nonce_field('ib_customer_claim','ib_customer_nonce'); ?><input type="hidden" name="ib_customer_action" value="claim"><label>Booking reference<input name="reference" required placeholder="IB-..."></label><label>Private management token<input name="token" required minlength="64" maxlength="64" autocomplete="off"></label><button>Link appointment</button></form></section>
        <?php endif; ?></div>
        <?php return (string)ob_get_clean();
    }
}
