<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Notifications {
    private static bool $shutdown=false;
    public static function seed(): void {
        foreach(['confirmed','cancelled','rescheduled','payment_failed','payment_review','reminder'] as $event) foreach(['customer','admin'] as $audience) {
            if(Store::all('email_templates','event=%s AND audience=%s',[$event,$audience])) continue;
            $title=match($event) {'confirmed'=>$audience==='admin'?'New Appointment Received':'Appointment Confirmed','cancelled'=>'Appointment Cancelled','rescheduled'=>'Appointment Rescheduled','payment_failed'=>'Appointment Payment Incomplete','payment_review'=>'Payment Received — Appointment Requires Review',default=>'Appointment Reminder'};
            Store::insert('email_templates',['event'=>$event,'audience'=>$audience,'enabled'=>1,'subject'=>$title.' — {{booking_reference}}','recipient'=>'','body'=>'<h1>'.$title.'</h1><p>Hello {{customer_name}},</p><p>Booking <strong>{{booking_reference}}</strong></p><p>{{service_name}}<br>{{appointment_date}} at {{appointment_time}}<br>Duration: {{appointment_duration}} minutes<br>{{appointment_location}}</p><p>Amount paid: {{payment_amount}}<br>Payment method: {{payment_method}}<br>Payment status: {{payment_status}}<br>Appointment status: {{booking_status}}</p><p>{{customer_email}} · {{customer_phone}}</p><p><a href="{{cancel_booking_url}}">Manage or cancel appointment</a> · <a href="{{reschedule_booking_url}}">Reschedule</a></p><p>{{business_name}}</p>']);
        }
    }
    public static function enqueue(int $id,string $event,string $version=''): void {
        foreach(Store::all('email_templates','event=%s AND enabled=1',[$event]) as $t) { $key=$id.':'.$event.':'.$t['id'].':'.$version; if(!Store::all('notification_logs','event_key=%s',[$key])) Store::insert('notification_logs',['event_key'=>$key,'appointment_id'=>$id,'template_id'=>$t['id'],'status'=>'queued','attempts'=>0,'last_error'=>'','created_at'=>Store::now()]); }
        if(!self::$shutdown && !wp_doing_cron()) { self::$shutdown=true; add_action('shutdown',[self::class,'work']); }
        if(!wp_next_scheduled('ib_maintenance')) wp_schedule_single_event(time()+1,'ib_maintenance');
    }
    public static function variables(array $b): array {
        $s=Store::settings(); $view=Booking::view($b); $c=$view['customer']; $link=add_query_arg(['ib_manage'=>$b['reference']],home_url('/')).'#ib_token='.Booking::token((int)$b['id']);
        $p=Store::all('payments','appointment_id=%d ORDER BY id DESC',[$b['id']])[0]??[];
        return ['customer_name'=>$c['first_name'].' '.$c['last_name'],'customer_email'=>$c['email'],'customer_phone'=>$c['phone'],'booking_reference'=>$b['reference'],'service_name'=>implode(', ',array_column($view['services'],'name')),'appointment_date'=>wp_date($s['date_format'],strtotime($b['starts_at'].' UTC'),Store::tz()),'appointment_time'=>wp_date($s['time_format'],strtotime($b['starts_at'].' UTC'),Store::tz()).' '.$s['timezone'],'appointment_duration'=>array_sum(array_column($view['services'],'duration')),'appointment_location'=>$view['location'],'payment_amount'=>Store::money((int)$b['paid'],$b['currency']),'payment_method'=>$p['provider']??'free','payment_status'=>$b['payment_status'],'booking_status'=>$b['status'],'cancel_booking_url'=>$link,'reschedule_booking_url'=>$link,'business_name'=>$s['business_name'],'business_logo'=>Frontend::logo()];
    }
    public static function render(array $t,array $vars): array {
        $plain=[];$html=[];foreach($vars as $key=>$v) { $plain['{{'.$key.'}}']=sanitize_text_field((string)$v); $html['{{'.$key.'}}']=esc_html((string)$v); }
        $body=strtr($t['body'],$html); return [strtr($t['subject'],$plain),'<html><body style="margin:0;background:#f4f7f6;padding:24px;font-family:Arial,sans-serif;color:#10213a"><div style="max-width:600px;margin:auto;background:white;padding:32px;border-radius:12px;border-top:5px solid #208563">'.wp_kses_post($body).'</div></body></html>'];
    }
    public static function work(): void {
        $s=Store::settings();
        Store::atomic(function() use($s) { foreach(Store::all('appointments',"status='confirmed' AND starts_at>%s AND starts_at<=%s",[Store::now(),gmdate('Y-m-d H:i:s',time()+(int)$s['reminder_hours']*3600)]) as $b) self::enqueue((int)$b['id'],'reminder',$b['starts_at']); });
        global $wpdb;
        foreach(Store::all('notification_logs',"status='queued' AND attempts<3 ORDER BY id LIMIT 20") as $log) {
            // Atomic claim: concurrent cron invocations cannot send the same outbox item.
            $claimed=$wpdb->query($wpdb->prepare('UPDATE '.Store::table('notification_logs')." SET status='sending', attempts=attempts+1 WHERE id=%d AND status='queued'",$log['id'])); if($claimed!==1) continue;
            $t=Store::get('email_templates',(int)$log['template_id']); $b=Store::get('appointments',(int)$log['appointment_id']);
            if(!$t || !$b || !$t['enabled']) { Store::update('notification_logs',(int)$log['id'],['status'=>'skipped']); continue; }
            [$subject,$body]=self::render($t,self::variables($b)); $c=Store::json($b['snapshot'])['customer'];
            if($t['audience']==='admin') $body.='<p><a href="'.esc_url(admin_url('admin.php?page=ib-appointments&view='.$b['id'])).'">Open appointment in WordPress</a></p>';
            $to=$t['recipient']?:($t['audience']==='admin'?$s['business_email']:$c['email']);
            $ok=wp_mail($to,$subject,$body,self::headers());
            Store::update('notification_logs',(int)$log['id'],['status'=>$ok?'sent':((int)$log['attempts']>=2?'failed':'queued'),'sent_at'=>$ok?Store::now():null,'last_error'=>$ok?'':'wp_mail did not accept the message.']);
        }
    }
    public static function headers(): array { $s=Store::settings(); return ['Content-Type: text/html; charset=UTF-8','From: '.sanitize_text_field($s['sender_name']).' <'.sanitize_email($s['sender_email']).'>']; }
}
