<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class API {
    public static function init(): void { add_action('rest_api_init',[self::class,'routes']); }
    public static function routes(): void {
        $public=fn()=>true; $admin=fn()=>current_user_can('manage_intelink_booking');
        $routes=[
            ['/catalog','GET',fn($r)=>self::catalog($r),$public],
            ['/services','GET',fn($r)=>self::catalog($r)['services'],$public],
            ['/categories','GET',fn()=>Store::all('service_categories'),$public],
            ['/staff','GET',function($r) { $eligible=Schedule::staff(Schedule::services(self::ids($r['services']))); return array_values(array_map(fn($s)=>array_intersect_key($s,array_flip(['id','name','image_id'])),array_filter(Store::all('staff',"status='active'"),fn($s)=>in_array((int)$s['id'],$eligible,true)))); },$public],
            ['/availability','GET',fn($r)=>Schedule::slots(self::ids($r['services']),sanitize_text_field($r['date']??''),Store::settings()['staff_selection']?absint($r['staff_id']??0):0),$public],
            ['/dates','GET',function($r) { self::limit('dates',30); return Schedule::dates(self::ids($r['services']),sanitize_text_field($r['month']??''),Store::settings()['staff_selection']?absint($r['staff_id']??0):0); },$public],
            ['/quote','POST',fn($r)=>Booking::quote(Schedule::services((array)$r['services']),sanitize_text_field($r['coupon']??'')),$public],
            ['/bookings','POST',function($r) { self::limit('booking',15); if(!empty($r['website'])) throw new \RuntimeException('Invalid request.'); return Booking::create($r->get_json_params()); },$public],
            ['/booking','POST',function($r) { self::limit('manage',60); $b=Booking::authorize((string)$r['reference'],(string)$r['token']); return self::bookingView($b); },$public],
            ['/booking/action','POST',function($r) { self::limit('manage',60); $b=Booking::authorize((string)$r['reference'],(string)$r['token']); Booking::change((int)$b['id'],sanitize_key($r['action']),$r->get_json_params()); return self::bookingView(Store::get('appointments',(int)$b['id'])); },$public],
            ['/booking/slots','POST',function($r) { $b=Booking::authorize((string)$r['reference'],(string)$r['token']); return Schedule::slots(array_column(Store::json($b['snapshot'])['services'],'id'),sanitize_text_field($r['date']),(int)$b['staff_id'],(int)$b['id']); },$public],
            ['/payments/initialize','POST',function($r) { self::limit('payment',30); $b=Booking::authorize((string)$r['reference'],(string)$r['token']); return Payments::initialize($b,sanitize_key($r['method']),(string)$r['return_url']); },$public],
            ['/payments/verify','POST',function($r) { self::limit('verify',30); $b=Booking::authorize((string)$r['reference'],(string)$r['token']); $p=Store::all('payments','appointment_id=%d ORDER BY id DESC',[$b['id']])[0]??null; return $p?Payments::verify($p,sanitize_text_field($r['transaction_id']??'')):Booking::view($b); },$public],
            ['/uploads','POST',fn($r)=>self::upload($r),$public],
            ['/admin/(?P<entity>[a-z_]+)','GET',fn($r)=>self::adminList($r),$admin],
            ['/admin/(?P<entity>[a-z_]+)','POST',fn($r)=>Admin::saveEntity($r['entity'],(array)$r->get_json_params(),absint($r['id']??0)),$admin],
            ['/admin/(?P<entity>[a-z_]+)/(?P<id>\d+)','DELETE',fn($r)=>Admin::deleteEntity($r['entity'],(int)$r['id']),$admin],
            ['/settings','GET',fn()=>self::safeSettings(),$admin],
            ['/settings','POST',function($r) { Admin::saveSettings($r->get_json_params()); return ['saved'=>true]; },$admin],
            ['/reports','GET',fn($r)=>Admin::report(sanitize_text_field($r['from']??wp_date('Y-m-01')),sanitize_text_field($r['to']??wp_date('Y-m-d'))),$admin],
            ['/appointments','GET',fn()=>array_map([Booking::class,'view'],Store::all('appointments','1=1 ORDER BY id DESC LIMIT 500')),$admin],
            ['/appointments','POST',fn($r)=>Booking::create($r->get_json_params(),true),$admin],
            ['/appointments/(?P<id>\d+)/action','POST',fn($r)=>Booking::change((int)$r['id'],sanitize_key($r['action']),$r->get_json_params(),true),$admin],
            ['/payments','GET',fn()=>Store::all('payments','1=1 ORDER BY id DESC LIMIT 500'),$admin],
            ['/customers','GET',fn()=>Store::all('customers','1=1 ORDER BY id DESC LIMIT 500'),$admin],
            ['/notifications','GET',fn()=>Store::all('notification_logs','1=1 ORDER BY id DESC LIMIT 100'),$admin],
        ];
        foreach($routes as [$path,$method,$callback,$permission]) register_rest_route('intelink-booking/v1',$path,['methods'=>$method,'permission_callback'=>$permission,'callback'=>fn($r)=>self::run(fn()=>$callback($r))]);
        register_rest_route('intelink-booking/v1','/webhook/(?P<provider>paystack|flutterwave|stripe)',['methods'=>'POST','permission_callback'=>$public,'callback'=>function($r) { try { $h=[]; foreach(['x-paystack-signature','verif-hash','flutterwave-signature','stripe-signature'] as $key) $h[$key]=(string)$r->get_header($key); Payments::webhook($r['provider'],$r->get_body(),$h,sanitize_key($r['mode']??Store::settings()['mode'])); return new \WP_REST_Response(['received'=>true],200); } catch(\Throwable $e) { error_log('Intelink Booking webhook rejected: '.sanitize_text_field($e->getMessage())); return new \WP_Error('ib_webhook','Webhook could not be processed.',['status'=>400]); } }]);
    }
    public static function run(callable $fn) { try { return $fn(); } catch(\Throwable $e) { return new \WP_Error('ib_request',$e->getMessage(),['status'=>400]); } }
    public static function ids($value): array { return array_values(array_filter(array_map('absint',is_array($value)?$value:explode(',',(string)$value)))); }
    public static function limit(string $action,int $max): void { $key='ib_rate_'.md5($action.($_SERVER['REMOTE_ADDR']??'').gmdate('YmdHi')); $n=(int)get_transient($key); if($n>=$max) throw new \RuntimeException('Too many requests. Please wait a minute.'); set_transient($key,$n+1,120); }
    public static function safeSettings(): array { return array_filter(Store::settings(),fn($key)=>!preg_match('/_(secret|webhook)$/',$key),ARRAY_FILTER_USE_KEY); }
    public static function catalog($r): array {
        $s=Store::settings(); $services=Store::all('services',"status='active' ORDER BY name"); $ids=self::ids(($r['services']??'') ?: ($r['service']??'')); $category=sanitize_text_field($r['category']??'');
        if($category) { $cat=Store::all('service_categories','slug=%s OR id=%d',[$category,absint($category)])[0]??[]; $services=array_filter($services,fn($row)=>(int)$row['category_id']===(int)($cat['id']??-1)); }
        if($ids) $services=array_filter($services,fn($row)=>in_array((int)$row['id'],$ids,true));
        $services=array_map(function($row) { $config=Store::json($row['config']??'{}'); $row=array_intersect_key($row,array_flip(['id','name','description','image_id','category_id','price','sale_price','duration','payment_required'])); $row['description']=wp_strip_all_tags($row['description']); $row['image']=wp_get_attachment_image_url((int)$row['image_id'],'medium')?:''; $row['program_unit']=$config['program_unit']??'single'; $row['program_length']=max(1,(int)($config['program_length']??1)); return $row; },array_values($services));
        return ['services'=>$services,'categories'=>Store::all('service_categories'),'fields'=>Store::all('custom_fields','enabled=1 ORDER BY sort_order,id'),'settings'=>array_intersect_key($s,array_flip(['business_name','business_phone','business_email','business_address','timezone','currency','multiple_services','staff_selection','guest_booking','max_days','date_format','time_format','bank_name','bank_account','bank_number','payment_instructions'])),'methods'=>Payments::methods(),'today'=>wp_date('Y-m-d',null,Store::tz()),'privacy_url'=>get_privacy_policy_url(),'logged_in'=>is_user_logged_in(),'login_url'=>wp_login_url(get_permalink())];
    }
    public static function bookingView(array $b): array {
        $v=Booking::view($b);
        $p=Store::all('payments','appointment_id=%d ORDER BY id DESC',[$b['id']])[0]??[];
        $v['payment_method']=$p['provider']??($b['total']==0?'free':'');
        $cfg=Store::settings(); $active=in_array($b['status'],['pending','awaiting_payment','confirmed'],true);
        $starts=strtotime($b['starts_at'].' UTC');
        $cancel=(int)$cfg['cancellation_hours']; $reschedule=(int)$cfg['reschedule_hours'];
        $v['management']=[
            'can_cancel'=>$active && $starts-time()>=$cancel*3600,
            'can_reschedule'=>$active && $starts-time()>=$reschedule*3600,
            'cancel_deadline'=>gmdate('Y-m-d\TH:i:s\Z',$starts-$cancel*3600),
            'reschedule_deadline'=>gmdate('Y-m-d\TH:i:s\Z',$starts-$reschedule*3600),
            'cancellation_hours'=>$cancel,'reschedule_hours'=>$reschedule,
            'contact_email'=>sanitize_email($cfg['business_email']),
            'contact_phone'=>sanitize_text_field($cfg['business_phone'])
        ];
        return $v;
    }
    public static function adminList($r): array { $entity=$r['entity']; if(!isset(Admin::schemas()[$entity]) && !in_array($entity,['appointments','payments','customers','notification_logs'])) throw new \RuntimeException('Unknown entity.'); return Store::all($entity,'1=1 ORDER BY id DESC LIMIT 500'); }
    public static function upload($r): array {
        self::limit('upload',5); $field=Store::all('custom_fields',"name=%s AND type='file' AND enabled=1",[sanitize_key($r['field'])])[0]??null; if(!$field) throw new \RuntimeException('Upload field is unavailable.');
        $files=$r->get_file_params();$file=$files['file']??null; if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>2*1024*1024 || !is_uploaded_file($file['tmp_name'])) throw new \RuntimeException('Upload a file no larger than 2 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if(!in_array($mime,['application/pdf','image/jpeg','image/png','text/plain'],true)) throw new \RuntimeException('Only PDF, JPG, PNG and text files are allowed.');
        // Content stays in a non-public database row. No executable file is written into uploads.
        $token=bin2hex(random_bytes(24)); set_transient('ib_upload_'.$token,['field'=>$field['name'],'name'=>sanitize_file_name($file['name']),'mime'=>$mime,'data'=>base64_encode(file_get_contents($file['tmp_name']))],DAY_IN_SECONDS);
        return ['token'=>$token,'name'=>sanitize_file_name($file['name'])];
    }
}
