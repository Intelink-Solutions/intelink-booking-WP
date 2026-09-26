<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Booking {
    public static function quote(array $services,string $coupon=''): array {
        $subtotal=0;$sale=0;$deposit=0; $cfg=Store::settings();
        foreach($services as $s) { $price=$s['sale_price']!==null?min((int)$s['price'],(int)$s['sale_price']):(int)$s['price']; $subtotal+=(int)$s['price']; $sale+=(int)$s['price']-$price;
            $deposit+=!$s['payment_required']?0:($cfg['payment_mode']==='deposit' && $s['deposit_type']!=='full'?($s['deposit_type']==='percent'?(int)round($price*(float)$s['deposit_value']/100):min($price,Store::minor($s['deposit_value']))):$price);
        }
        $discount=$sale; $couponRow=null;
        if ($coupon!=='') { $couponRow=Store::all('coupons','code=%s AND enabled=1',[strtoupper($coupon)])[0]??null; if (!$couponRow || ($couponRow['expires_on'] && $couponRow['expires_on']<wp_date('Y-m-d',null,Store::tz())) || ($couponRow['max_uses'] && $couponRow['uses']>=$couponRow['max_uses'])) throw new \InvalidArgumentException('Coupon is invalid or expired.'); $discount+=min($subtotal-$sale,$couponRow['type']==='percent'?(int)round(($subtotal-$sale)*$couponRow['value']/100):Store::minor($couponRow['value'])); }
        $net=max(0,$subtotal-$discount);$tax=(int)round($net*(float)$cfg['tax_percent']/100);$total=$net+$tax;
        $due=$subtotal>$sale?(int)round($deposit/($subtotal-$sale)*$total):0;
        return ['subtotal'=>$subtotal,'discount'=>$discount,'tax'=>$tax,'total'=>$total,'due'=>min($total,max(0,$due)),'currency'=>$cfg['currency'],'coupon_id'=>$couponRow['id']??0];
    }
    public static function custom(array $values): array {
        $clean=[]; foreach(Store::all('custom_fields','enabled=1 ORDER BY sort_order,id') as $f) {
            $value=$values[$f['name']]??$f['default_value']; if (is_array($value)) $value=implode(', ',array_map('sanitize_text_field',$value)); $value=trim((string)$value);
            if ($f['required'] && ($value==='' || ($f['type']==='checkbox' && $value!=='1'))) throw new \InvalidArgumentException($f['label'].' is required.'); if ($value==='') { $clean[$f['name']]='';continue; }
            if (strlen($value)>10000) throw new \InvalidArgumentException($f['label'].' is too long.');
            if ($f['type']==='email' && !is_email($value)) throw new \InvalidArgumentException($f['label'].' must be an email address.');
            if ($f['type']==='number' && !is_numeric($value)) throw new \InvalidArgumentException($f['label'].' must be a number.');
            if ($f['type']==='date' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value) || date('Y-m-d',strtotime($value))!==$value)) throw new \InvalidArgumentException($f['label'].' must be a valid date.');
            if (in_array($f['type'],['dropdown','radio']) && !in_array($value,array_map('trim',explode("\n",$f['options'])),true)) throw new \InvalidArgumentException('Invalid '.$f['label']);
            if ($f['type']==='checkbox' && !in_array($value,['1','0'],true)) throw new \InvalidArgumentException('Invalid checkbox value.');
            $rules=Store::json($f['rules']); foreach(['min','max'] as $rule) if(isset($rules[$rule]) && $f['type']==='number' && ($rule==='min'?(float)$value<$rules[$rule]:(float)$value>$rules[$rule])) throw new \InvalidArgumentException($f['label'].' is out of range.');
            if(isset($rules['maxlength']) && (function_exists('mb_strlen')?mb_strlen($value):strlen($value))>(int)$rules['maxlength']) throw new \InvalidArgumentException($f['label'].' is too long.');
            if ($f['type']==='file') { $upload=get_transient('ib_upload_'.$value); if (!$upload || $upload['field']!==$f['name']) throw new \InvalidArgumentException('Upload expired. Please upload the file again.'); }
            $clean[$f['name']]=sanitize_textarea_field($value);
        } return $clean;
    }
    public static function create(array $data,bool $admin=false): array {
        $cfg=Store::settings(); if (!$admin && !$cfg['guest_booking'] && !is_user_logged_in()) throw new \RuntimeException('Sign in before booking.');
        $key=sanitize_text_field($data['request_key']??'');$token=(string)($data['token']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{20,64}$/',$key) || !preg_match('/^[a-f0-9]{64}$/',$token)) throw new \InvalidArgumentException('Invalid booking request. Refresh and try again.');
        return Store::atomic(function() use($data,$admin,$cfg,$key,$token) {
            $existing=Store::all('appointments','request_key=%s',[$key])[0]??null;
            if ($existing) { if(!hash_equals($existing['token_hash'],hash('sha256',$token))) throw new \RuntimeException('Invalid booking token.'); return self::view($existing); }
            $services=Schedule::services((array)($data['services']??[])); $quote=self::quote($services,sanitize_text_field($data['coupon']??''));
            $start=strtotime((string)($data['start']??'')); if (!$start) throw new \InvalidArgumentException('Choose an appointment time.');
            $staff=$admin || $cfg['staff_selection']?absint($data['staff_id']??0):0;
            $slot=Schedule::check($services,$start,$staff); if (!$slot) throw new \RuntimeException('This time is no longer available. Please select another slot.');
            $c=(array)($data['customer']??[]); $customer=[];
            foreach(['first_name','last_name','email','phone','country','city','address'] as $f) $customer[$f]=sanitize_text_field($c[$f]??'');
            foreach(['first_name','last_name','email','phone'] as $f) if(!$customer[$f]) throw new \InvalidArgumentException(ucwords(str_replace('_',' ',$f)).' is required.');
            if(!is_email($customer['email'])) throw new \InvalidArgumentException('Enter a valid email address.');
            if(!preg_match('/^[+0-9().\s-]{6,60}$/',$customer['phone'])) throw new \InvalidArgumentException('Enter a valid phone number.');
            if(empty($data['privacy'])) throw new \InvalidArgumentException('Please agree to the privacy policy.');
            $custom=self::custom((array)($data['custom']??[]));
            $found=Store::all('customers','email=%s',[$customer['email']])[0]??null;
            // Existing customer master records are not overwritten by unauthenticated requests.
            $customerId=$found?(int)$found['id']:Store::insert('customers',$customer+['created_at'=>Store::now()]);
            $snapshot=['services'=>$services,'customer'=>$customer,'custom'=>$custom,'location'=>$cfg['business_address'],'timezone'=>$cfg['timezone'],'staff_name'=>$slot['staff_id']?(Store::get('staff',$slot['staff_id'])['name']??''):'','privacy_at'=>Store::now(),'coupon_id'=>$quote['coupon_id']];
            unset($quote['coupon_id']);
            $status=$quote['due']>0?'awaiting_payment':($cfg['manual_approval']?'pending':'confirmed');
            $id=Store::insert('appointments',$slot+$quote+['reference'=>'IB-'.strtoupper(wp_generate_password(12,false,false)),'request_key'=>$key,'token_hash'=>hash('sha256',$token),'customer_id'=>$customerId,'status'=>$status,'payment_status'=>$quote['total']===0?'free':($quote['due']===0?'pay_later':'pending'),'paid'=>0,'expires_at'=>$quote['due']>0?gmdate('Y-m-d H:i:s',time()+max(5,(int)$cfg['reservation_minutes'])*60):null,'snapshot'=>wp_json_encode($snapshot),'notes'=>sanitize_textarea_field($data['notes']??''),'created_at'=>Store::now(),'updated_at'=>Store::now()]);
            if($quote['due']===0) Store::insert('payments',['reference'=>'IBP-'.bin2hex(random_bytes(16)),'appointment_id'=>$id,'provider'=>$quote['total']===0?'free':'pay_later','mode'=>$cfg['mode'],'amount'=>$quote['total'],'currency'=>$quote['currency'],'status'=>$quote['total']===0?'successful':'pending','external_id'=>'','checkout_url'=>'','created_at'=>Store::now(),'paid_at'=>$quote['total']===0?Store::now():null]);
            if ($snapshot['coupon_id']) { $coupon=Store::get('coupons',(int)$snapshot['coupon_id']); Store::update('coupons',(int)$coupon['id'],['uses'=>(int)$coupon['uses']+1]); }
            // Store encrypted capability separately so queued emails can contain management links.
            Store::insert('appointment_meta',['appointment_id'=>$id,'meta_key'=>'manage_token','meta_value'=>self::seal($token)]);
            if(!$admin && is_user_logged_in() && strcasecmp($customer['email'],wp_get_current_user()->user_email)===0) Store::insert('appointment_meta',['appointment_id'=>$id,'meta_key'=>'account_user_id','meta_value'=>(string)get_current_user_id()]);
            foreach(Store::all('custom_fields',"enabled=1 AND type='file'") as $field) { $uploadToken=$custom[$field['name']]??'';if(!$uploadToken)continue;$upload=get_transient('ib_upload_'.$uploadToken); if($upload) Store::insert('appointment_meta',['appointment_id'=>$id,'meta_key'=>'file_'.$field['name'],'meta_value'=>wp_json_encode($upload)]); }
            Store::history($id,'Booking created: '.$status);
            if ($status==='confirmed') Notifications::enqueue($id,'confirmed');
            return self::view(Store::get('appointments',$id));
        });
    }
    public static function seal(string $value): string { $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag); return base64_encode($iv.$tag.$cipher); }
    public static function unseal(string $value): string { $raw=base64_decode($value); return (string)openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)); }
    public static function token(int $id): string { return self::unseal(Store::all('appointment_meta','appointment_id=%d AND meta_key=%s',[$id,'manage_token'])[0]['meta_value']??''); }
    public static function authorize(string $reference,string $token): array { $b=Store::all('appointments','reference=%s',[$reference])[0]??null; if(!$b || !preg_match('/^[a-f0-9]{64}$/',$token) || !hash_equals($b['token_hash'],hash('sha256',$token))) throw new \RuntimeException('Booking link is invalid.'); return $b; }
    public static function view(array $b): array { $s=Store::json($b['snapshot']); unset($b['token_hash'],$b['request_key'],$b['snapshot']); $b['services']=array_map(fn($s)=>array_intersect_key($s,array_flip(['id','name','description','duration','program_unit','program_length','price','sale_price'])),$s['services']??[]); return $b+array_intersect_key($s,array_flip(['customer','custom','location','timezone','staff_name'])); }
    public static function change(int $id,string $action,array $data=[],bool $admin=false): array {
        return Store::atomic(function() use($id,$action,$data,$admin) {
            $b=Store::get('appointments',$id); if(!$b) throw new \RuntimeException('Booking not found.'); $cfg=Store::settings();
            if(!$admin && !in_array($action,['cancel','reschedule'])) throw new \RuntimeException('Action not permitted.');
            if(!$admin && !in_array($b['status'],['pending','awaiting_payment','confirmed'])) throw new \RuntimeException('This appointment can no longer be changed.');
            if(!$admin && strtotime($b['starts_at'].' UTC')-time()<(int)$cfg[$action==='cancel'?'cancellation_hours':'reschedule_hours']*3600) throw new \RuntimeException('The change deadline has passed. Please contact the business.');
            $update=['updated_at'=>Store::now()]; $event='';
            if($action==='reschedule') {
                if(empty($data['start'])) throw new \InvalidArgumentException('Choose a new appointment time.');
                if(in_array($b['status'],['cancelled','refunded','completed','no_show'])) throw new \RuntimeException('Create a new booking for a closed appointment.');
                $services=Schedule::services(array_column(Store::json($b['snapshot'])['services'],'id')); $newStart=(new \DateTimeImmutable($data['start']??'',Store::tz()))->getTimestamp(); $slot=Schedule::check($services,$newStart,(int)($data['staff_id']??$b['staff_id']),$id); if(!$slot) throw new \RuntimeException('The new time is unavailable.'); $update+=$slot; $snapshot=Store::json($b['snapshot']);$snapshot['staff_name']=$slot['staff_id']?(Store::get('staff',(int)$slot['staff_id'])['name']??''):'';$update['snapshot']=wp_json_encode($snapshot); $event='rescheduled';
            } else {
                $status=['confirm'=>'confirmed','cancel'=>'cancelled','complete'=>'completed','no_show'=>'no_show','pending'=>'pending'][$action]??'';
                if(!$status) throw new \InvalidArgumentException('Unknown action.');
                if($action==='confirm') { if($b['due']>$b['paid'] && $b['payment_status']!=='pay_later') throw new \RuntimeException('Record or verify the required payment first.'); $services=Schedule::services(array_column(Store::json($b['snapshot'])['services'],'id')); if(!Schedule::check($services,strtotime($b['starts_at'].' UTC'),(int)$b['staff_id'],$id,false)) throw new \RuntimeException('The appointment is no longer available.'); }
                $update['status']=$status; $update['expires_at']=null; $event=$action==='confirm'?'confirmed':($action==='cancel'?'cancelled':'');
            }
            Store::update('appointments',$id,$update); Store::history($id,$action); if($event) Notifications::enqueue($id,$event,$event==='confirmed'?'':wp_generate_uuid4()); return self::view(Store::get('appointments',$id));
        });
    }
    public static function editDetails(int $id,array $data): void {
        Store::atomic(function() use($id,$data) { $b=Store::get('appointments',$id);if(!$b)throw new \RuntimeException('Appointment not found.');$snapshot=Store::json($b['snapshot']);$customer=[];foreach(['first_name','last_name','email','phone','country','city','address'] as $key)$customer[$key]=sanitize_text_field($data['customer'][$key]??'');if(!$customer['first_name'] || !$customer['last_name'] || !is_email($customer['email']) || !preg_match('/^[+0-9().\s-]{6,60}$/',$customer['phone']))throw new \InvalidArgumentException('Name, valid email and valid phone are required.');$snapshot['customer']=$customer;Store::update('appointments',$id,['snapshot'=>wp_json_encode($snapshot),'notes'=>sanitize_textarea_field($data['notes']??''),'updated_at'=>Store::now()]);Store::history($id,'Administrator edited appointment contact details and notes.'); });
    }
    public static function expire(): void {
        Store::atomic(function() { foreach(Store::all('appointments',"status='awaiting_payment' AND expires_at IS NOT NULL AND expires_at<=%s",[Store::now()]) as $b) { Store::update('appointments',(int)$b['id'],['status'=>'cancelled','updated_at'=>Store::now()]); Store::history((int)$b['id'],'Unpaid reservation expired.'); Notifications::enqueue((int)$b['id'],'payment_failed','expired'); } });
    }
}
