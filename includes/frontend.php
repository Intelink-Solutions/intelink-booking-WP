<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Frontend {
    public static function init(): void {
        add_shortcode('intelink_booking',[self::class,'shortcode']);
        add_shortcode('intelink_manage_appointment',[self::class,'manageShortcode']);
        add_action('template_redirect',function() { if(!isset($_GET['ib_manage'])) return; nocache_headers(); header('Referrer-Policy: no-referrer'); get_header(); echo '<main class="ib-management-page">'.self::manageShortcode([]).'</main>'; get_footer(); exit; });
    }
    public static function logo(): string { $s=Store::settings();$id=(int)$s['logo_id']?:get_theme_mod('custom_logo');return $id?(wp_get_attachment_image_url($id,'full')?:''):(get_site_icon_url(128)?:''); }
    public static function shortcode($atts=[]): string {
        $atts=shortcode_atts(['service'=>'','services'=>'','category'=>''],(array)$atts,'intelink_booking');
        wp_enqueue_style('intelink-booking',IB_URL.'assets/booking.css',[],IB_VERSION);wp_enqueue_script('intelink-booking',IB_URL.'assets/booking.js',[],IB_VERSION,true);
        $s=Store::settings(); $logo=self::logo(); $config=['api'=>rest_url('intelink-booking/v1/'),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'','filter'=>$atts,'home'=>home_url('/'),'download'=>admin_url('admin-post.php'),'manage'=>sanitize_text_field($_GET['ib_manage']??''),'logo'=>$logo];
        ob_start(); ?>
        <div class="ib-host"><div class="ib-booking" style="--ib-green:<?php echo esc_attr($s['primary']); ?>;--ib-tint:<?php echo esc_attr($s['accent']); ?>" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <aside class="ib-sidebar">
                <a class="ib-logo" href="<?php echo esc_url(home_url('/')); ?>"><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($s['business_name']); ?>"><?php else: ?><strong><?php echo esc_html(get_bloginfo('name')); ?></strong><?php endif; ?></a>
                <nav class="ib-stages" aria-label="Booking steps"><?php foreach([['Services','Select your service'],['Date & Time','Choose date and time'],['Information','Your details'],['Confirmation','Review and pay']] as $index=>$step): ?><button type="button" class="ib-stage" data-step="<?php echo $index; ?>" disabled><span class="ib-stage-number"><?php echo $index+1; ?></span><span><strong><?php echo esc_html($step[0]); ?></strong><small><?php echo esc_html($step[1]); ?></small></span></button><?php endforeach; ?></nav>
                <div class="ib-help"><span class="ib-help-icon" aria-hidden="true">☎</span><div><span>Need Help?</span><?php if($s['business_phone']): ?><a href="tel:<?php echo esc_attr(preg_replace('/[^+0-9]/','',$s['business_phone'])); ?>"><?php echo esc_html($s['business_phone']); ?></a><?php endif; ?><?php if(!empty($s['help_hours'])): ?><small class="ib-help-hours"><?php echo esc_html($s['help_hours']); ?></small><?php endif; ?><?php if($s['business_email']): ?><a class="ib-help-email" href="mailto:<?php echo esc_attr($s['business_email']); ?>"><?php echo esc_html($s['business_email']); ?></a><?php endif; ?></div></div>
            </aside>
            <main class="ib-content"><header class="ib-top"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM3 22v-3a7 7 0 0 1 7-6h4a7 7 0 0 1 7 6v3Z"/></svg>Book an Appointment</span><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V6a6 6 0 0 1 12 0v4h2v13H4V10h2Zm3 0h6V6a3 3 0 0 0-6 0v4Zm2 5v4h2v-4h-2Z"/></svg>Secure Booking</span></header>
                <div class="ib-progress" aria-label="Booking progress"><span class="ib-progress-symbol" aria-hidden="true">⌕</span><div class="ib-progress-track"><i></i></div><div class="ib-progress-dots"><span>1</span><span>2</span><span>3</span><span>4</span></div></div>
                <div class="ib-alert" role="alert" hidden></div><div class="ib-screen" aria-live="polite"><p class="ib-loading">Loading services…</p></div>
                <footer class="ib-navigation"><button type="button" class="ib-back" hidden>← Back</button><button type="button" class="ib-next" disabled>Next <span aria-hidden="true">→</span></button></footer>
                <p class="ib-account-link"><a href="<?php echo esc_url(CustomerPortal::url()); ?>">My appointments &amp; notifications →</a></p>
            </main>
        </div></div>
        <?php return (string)ob_get_clean();
    }
    public static function manageShortcode($atts=[]): string {
        wp_enqueue_style('intelink-booking',IB_URL.'assets/booking.css',[],IB_VERSION);
        wp_enqueue_script('intelink-booking',IB_URL.'assets/booking.js',[],IB_VERSION,true);
        $s=Store::settings();$logo=self::logo();
        $reference=sanitize_text_field(wp_unslash($_GET['ib_manage']??''));
        $config=['api'=>rest_url('intelink-booking/v1/'),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'','filter'=>[],'home'=>home_url('/'),'download'=>admin_url('admin-post.php'),'manage'=>$reference,'logo'=>$logo];
        ob_start(); ?>
        <div class="ib-host ib-manage-host"><div class="ib-booking" style="--ib-green:<?php echo esc_attr($s['primary']); ?>;--ib-tint:<?php echo esc_attr($s['accent']); ?>" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
          <header class="ib-manage-header"><a href="<?php echo esc_url(home_url('/')); ?>"><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($s['business_name']); ?>"><?php else: ?><?php echo esc_html(get_bloginfo('name')); ?><?php endif; ?></a><span>Secure appointment management</span></header>
          <main class="ib-content"><div class="ib-alert" role="alert" hidden></div><div class="ib-screen" aria-live="polite"><p class="ib-loading">Loading appointment…</p></div><div class="ib-navigation" hidden><button type="button" class="ib-back" hidden>Back</button><button type="button" class="ib-next" disabled>Next</button></div><div class="ib-progress-track" hidden><i></i></div></main>
        </div></div>
        <?php return (string)ob_get_clean();
    }
    public static function download(): void {
        if(isset($_GET['file'])) { if(!current_user_can('manage_intelink_booking')) wp_die('Access denied.',403);check_admin_referer('ib_file');$meta=Store::get('appointment_meta',absint($_GET['file']));if(!$meta || !str_starts_with($meta['meta_key'],'file_')) wp_die('File not found.',404);$f=Store::json($meta['meta_value']);nocache_headers();header('X-Content-Type-Options: nosniff');header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.sanitize_file_name($f['name']).'"');echo base64_decode($f['data']);exit; }
        try { $b=Booking::authorize(sanitize_text_field(wp_unslash($_POST['reference']??'')),sanitize_text_field(wp_unslash($_POST['token']??''))); } catch(\Throwable $e) { wp_die('Invalid booking link.',403); }
        $v=Booking::view($b);nocache_headers();header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="'.$b['reference'].'.txt"');echo Store::settings()['business_name']."\nAppointment confirmation\n\n";foreach(['reference','status','payment_status','starts_at','ends_at','timezone','location','staff_name'] as $key) echo ucwords(str_replace('_',' ',$key)).': '.($v[$key]??'').(in_array($key,['starts_at','ends_at'])?' UTC':'')."\n";echo 'Services: '.implode(', ',array_column($v['services'],'name'))."\nCustomer: ".$v['customer']['first_name'].' '.$v['customer']['last_name']."\nPaid: ".Store::money((int)$b['paid'],$b['currency'])."\n";exit;
    }
}
