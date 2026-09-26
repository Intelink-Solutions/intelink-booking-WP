<?php
namespace Intelink;
defined('ABSPATH') || exit;

/** Site activity notices backed by real, verified bookings; sample notices are admin-only. */
final class Activity {
    public static function defaults(): array {
        return ['enabled'=>0,'position'=>'bottom-left','delay'=>8,'interval'=>22,'duration'=>7,'max_items'=>5,'show_image'=>1,'booking_page'=>0];
    }
    public static function settings(): array {
        return array_merge(self::defaults(),(array)get_option('ib_activity_settings',[]));
    }
    public static function init(): void {
        add_action('wp_enqueue_scripts',[self::class,'enqueue']);
        add_action('admin_post_ib_activity_save',[self::class,'save']);
    }
    public static function save(): void {
        if(!current_user_can('manage_intelink_booking')) wp_die('Not authorized.',403);
        check_admin_referer('ib_activity_save');
        $data=wp_unslash($_POST);
        $pos=sanitize_key($data['position']??'bottom-left');
        if(!in_array($pos,['bottom-left','bottom-right','top-left','top-right'],true)) $pos='bottom-left';
        $next=['enabled'=>empty($data['enabled'])?0:1,'position'=>$pos,'delay'=>min(60,max(1,absint($data['delay']??8))),'interval'=>min(120,max(8,absint($data['interval']??22))),'duration'=>min(20,max(3,absint($data['duration']??7))),'max_items'=>min(12,max(1,absint($data['max_items']??5))),'show_image'=>empty($data['show_image'])?0:1,'booking_page'=>absint($data['booking_page']??0)];
        update_option('ib_activity_settings',$next,false);
        $url=admin_url('admin.php?page=ib-activity');
        if(!empty($data['ib_embed'])) $url=add_query_arg('ib_embed','1',$url);
        wp_safe_redirect(add_query_arg('updated','1',$url)); exit;
    }
    public static function items(int $max): array {
        global $wpdb;
        $p=Store::table('payments'); $a=Store::table('appointments');
        // Do not leak sandbox payments, customer identities, order references, or private appointment data.
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.snapshot,p.paid_at FROM $p p INNER JOIN $a a ON a.id=p.appointment_id WHERE p.status='successful' AND p.mode='live' AND a.status IN ('confirmed','completed') AND p.paid_at IS NOT NULL AND p.paid_at >= %s ORDER BY p.paid_at DESC LIMIT %d",gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS),max(1,min(50,$max*4))),ARRAY_A);
        $out=[];$seen=[];
        foreach((array)$rows as $row) {
            $snapshot=Store::json($row['snapshot']);
            foreach((array)($snapshot['services']??[]) as $service) {
                $id=absint($service['id']??0);
                if(!$id || isset($seen[$id])) continue;
                $current=Store::get('services',$id);
                if(!$current || $current['status']!=='active') continue;
                $seen[$id]=true;
                $out[]=['service'=>wp_strip_all_tags($current['name']),'image'=>wp_get_attachment_image_url((int)$current['image_id'],'thumbnail')?:'','ago'=>human_time_diff(strtotime($row['paid_at'].' UTC'),time()).' ago'];
                if(count($out)>=$max) break 2;
            }
        }
        return $out;
    }
    public static function enqueue(): void {
        if(is_admin() || is_feed() || is_robots() || is_preview() || is_user_logged_in() && current_user_can('manage_intelink_booking')) return;
        $s=self::settings(); if(!$s['enabled']) return;
        $items=self::items((int)$s['max_items']); if(!$items) return;
        wp_enqueue_style('ib-activity',IB_URL.'assets/activity.css',[],IB_VERSION);
        wp_enqueue_script('ib-activity',IB_URL.'assets/activity.js',[],IB_VERSION,true);
        $url=$s['booking_page']?get_permalink($s['booking_page']):'';
        wp_add_inline_script('ib-activity','window.IntelinkBookingActivity='.wp_json_encode(['items'=>$items,'position'=>$s['position'],'delay'=>$s['delay'],'interval'=>$s['interval'],'duration'=>$s['duration'],'showImage'=>(bool)$s['show_image'],'url'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    }
    public static function page(): void {
        if(!current_user_can('manage_intelink_booking')) return;
        $s=self::settings();
        if(isset($_GET['updated'])) echo '<div class="notice notice-success"><p>Activity settings saved.</p></div>';
        echo '<div class="ib-activity-wrap"><div class="ib-activity-panel"><h2>Recent booking notifications</h2><p>Display genuine recent bookings as compact popups. This feature is off by default. It never invents purchases, names, locations or timestamps. Only verified live payments for active services are eligible. No notices appear until matching bookings exist.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('ib_activity_save');
        echo '<input type="hidden" name="action" value="ib_activity_save">';if(!empty($_GET['ib_embed']))echo '<input type="hidden" name="ib_embed" value="1">';
        self::check('enabled','Enable real booking notifications',(int)$s['enabled']);
        echo '<div class="ib-activity-fields">';
        echo '<label>Popup position<select name="position">';foreach(['bottom-left'=>'Bottom left','bottom-right'=>'Bottom right','top-left'=>'Top left','top-right'=>'Top right'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($s['position'],$value,false).'>'.esc_html($label).'</option>';echo '</select></label>';
        foreach(['delay'=>['First popup delay (seconds)',1,60],'interval'=>['Time between popups (seconds)',8,120],'duration'=>['Visible for (seconds)',3,20],'max_items'=>['Maximum recent services',1,12]] as $key=>$info) echo '<label>'.esc_html($info[0]).'<input type="number" name="'.esc_attr($key).'" min="'.$info[1].'" max="'.$info[2].'" value="'.(int)$s[$key].'"></label>';
        echo '<label>Booking page (optional)<select name="booking_page"><option value="0">No link</option>';foreach(get_pages(['post_status'=>'publish']) as $page) echo '<option value="'.(int)$page->ID.'" '.selected($s['booking_page'],$page->ID,false).'>'.esc_html($page->post_title).'</option>';echo '</select></label></div>';
        self::check('show_image','Show service featured image',(int)$s['show_image']);
        echo '<p><button class="button button-primary">Save notification settings</button></p></form></div>';
        echo '<div class="ib-activity-panel"><h2>Design preview</h2><p>Admin-only sample. This is not shown to visitors and does not represent an actual purchase.</p><div class="ib-activity-sample"><span class="ib-activity-sample-image">✦</span><div><small>Sample activity — demo</small><strong>Consultation was booked</strong><span>Example only · 1 hour ago</span></div></div><h3>Eligible real activity</h3><p>'.count(self::items(12)).' active service(s) with recent verified live bookings.</p></div></div>';
    }
    private static function check(string $name,string $label,int $checked): void { echo '<label class="ib-activity-check"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($checked,1,false).'> '.esc_html($label).'</label>'; }
}
