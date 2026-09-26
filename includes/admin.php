<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Admin {
    public static function init(): void {
        add_action('admin_menu',function() { add_menu_page('Intelink Booking','Intelink Booking','manage_intelink_booking','ib-dashboard',[self::class,'portalRedirect'],'dashicons-calendar-alt',26); foreach(['dashboard'=>'Dashboard','appointments'=>'Appointments','calendar'=>'Calendar','services'=>'Services','service_categories'=>'Service Categories','staff'=>'Staff','customers'=>'Customers','availability'=>'Availability','payments'=>'Payments','coupons'=>'Coupons','notifications'=>'Notifications','reports'=>'Reports','settings'=>'Settings','integrations'=>'Integrations','activity'=>'Recent Booking Popups'] as $key=>$label) add_submenu_page(null,$label,$label,'manage_intelink_booking','ib-'.$key,[self::class,'page']); });
        add_action('admin_enqueue_scripts',function($hook) { if(!str_contains($hook,'ib-')) return; wp_enqueue_media(); wp_enqueue_style('ib-admin',IB_URL.'assets/admin.css',[],IB_VERSION); wp_enqueue_script('ib-admin',IB_URL.'assets/admin.js',[],IB_VERSION,true); if(($_GET['page']??'')==='ib-activity') wp_enqueue_style('ib-activity-admin',IB_URL.'assets/activity.css',[],IB_VERSION); });
        add_action('admin_post_ib_admin',[self::class,'handle']);
        add_action('admin_init',function() { if(($_GET['page']??'')==='ib-dashboard' && empty($_GET['ib_embed']) && current_user_can('manage_intelink_booking')) { wp_safe_redirect(add_query_arg('ib_portal','1',home_url('/'))); exit; } },1);
        add_action('admin_head',function() { if(!empty($_GET['ib_embed']) && current_user_can('manage_intelink_booking') && str_starts_with(sanitize_key($_GET['page']??''),'ib-')) echo '<style>#adminmenumain,#wpadminbar,#wpfooter,.update-nag,.notice:not(.ib-admin .notice){display:none!important}html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}#wpbody-content{padding-bottom:0!important}body.wp-admin{background:#f5f8f9}.ib-admin{padding:14px 22px!important;margin:0!important;max-width:none!important}</style>'; });
        add_filter('admin_url',function($url) { if(!empty($_GET['ib_embed']) && str_contains($url,'admin.php?page=ib-') && !str_contains($url,'ib_embed=')) return add_query_arg('ib_embed','1',$url); return $url; });
    }
    public static function portalRedirect(): void { if(!empty($_GET['ib_embed'])) { self::page(); return; } wp_safe_redirect(add_query_arg('ib_portal','1',home_url('/'))); exit; }
    /** Field schema: label, input type, options. Shared by HTML and REST validation. */
    public static function schemas(): array {
        return [
            'services'=>['name'=>['Service Name','required'],'description'=>['Description','editor'],'image_id'=>['Featured Image','media'],'category_id'=>['Category','categories'],'price'=>['Price','money'],'sale_price'=>['Sale Price (blank for none)','optional_money'],'duration'=>['Session Duration (minutes)','positive'],'program_unit'=>['Programme Length Unit','select',['single','days','weeks','months']],'program_length'=>['Programme Length (number of units)','positive'],'buffer_before'=>['Buffer Before (minutes)','number'],'buffer_after'=>['Buffer After (minutes)','number'],'capacity'=>['Maximum Customers','positive'],'payment_required'=>['Payment Required','checkbox'],'deposit_type'=>['Deposit Type','select',['full','fixed','percent']],'deposit_value'=>['Deposit Amount / Percentage','decimal'],'status'=>['Status','select',['draft','active','inactive']],'staff_ids'=>['Assigned Staff','staff_multi']],
            'service_categories'=>['name'=>['Name','required'],'slug'=>['Slug','required']],
            'staff'=>['name'=>['Name','required'],'image_id'=>['Profile Image','media'],'email'=>['Email','email'],'phone'=>['Phone','text'],'capacity'=>['Appointment Capacity','positive'],'status'=>['Status','select',['active','inactive']],'service_ids'=>['Assigned Services','services_multi']],
            'availability'=>['name'=>['Schedule Name','required'],'scope'=>['Applies To','select',['business','service','staff']],'owner_id'=>['Service / Staff ID (0 for business)','number'],'weekday'=>['Day (Monday = 1, Sunday = 7)','positive'],'opens'=>['Opening Time','time'],'closes'=>['Closing Time','time'],'breaks'=>['Breaks JSON, e.g. [["12:00","13:00"]]','json'],'special_date'=>['Special Working Date (overrides weekly schedule)','date']],
            'blocked_dates'=>['name'=>['Holiday / Block Name','required'],'scope'=>['Applies To','select',['business','service','staff']],'owner_id'=>['Service / Staff ID (0 for business)','number'],'date_from'=>['From Date','date'],'date_to'=>['To Date','date']],
            'coupons'=>['code'=>['Coupon Code','required'],'type'=>['Discount Type','select',['percent','fixed']],'value'=>['Value','decimal'],'expires_on'=>['Expires On','date'],'max_uses'=>['Maximum Uses (0 = unlimited)','number'],'enabled'=>['Enabled','checkbox']],
            'custom_fields'=>['name'=>['Unique Field Key','required'],'label'=>['Label','required'],'type'=>['Type','select',['text','email','phone','number','textarea','dropdown','radio','checkbox','date','file']],'placeholder'=>['Placeholder','text'],'required'=>['Required','checkbox'],'sort_order'=>['Display Order','number'],'default_value'=>['Default Value','text'],'options'=>['Options (one per line)','textarea'],'rules'=>['Validation JSON: min, max, maxlength','json'],'enabled'=>['Enabled','checkbox']],
            'email_templates'=>['event'=>['Event','select',['confirmed','cancelled','rescheduled','payment_failed','payment_review','reminder']],'audience'=>['Audience','select',['customer','admin']],'enabled'=>['Enabled','checkbox'],'subject'=>['Subject','required'],'body'=>['Email Body','editor'],'recipient'=>['Override Recipient (optional)','email']],
            'customers'=>['first_name'=>['First Name','required'],'last_name'=>['Last Name','required'],'email'=>['Email','email'],'phone'=>['Phone','text'],'country'=>['Country','text'],'city'=>['City','text'],'address'=>['Address','textarea']],
        ];
    }
    public static function saveEntity(string $entity,array $data,int $id=0): array {
        $schema=self::schemas()[$entity]??null; if(!$schema) throw new \InvalidArgumentException('Unknown record type.');
        if($id && !Store::get($entity,$id)) throw new \InvalidArgumentException('Record not found.');
        $clean=[]; foreach($schema as $key=>$field) {
            if(in_array($key,['staff_ids','service_ids'])) continue; $v=$data[$key]??''; $type=$field[1]; if(is_array($v)) throw new \InvalidArgumentException('Invalid '.$field[0]);
            $clean[$key]=match($type) { 'checkbox'=>empty($v)?0:1, 'positive'=>max(1,absint($v)), 'number','media','categories'=>absint($v), 'money'=>Store::minor($v), 'optional_money'=>$v===''?null:Store::minor($v), 'decimal'=>max(0,(float)$v), 'editor'=>wp_kses_post($v), 'textarea'=>sanitize_textarea_field($v), 'email'=>sanitize_email($v), 'json'=>wp_json_encode(Store::json($v)), default=>sanitize_text_field($v) };
            if($type==='required' && !$clean[$key]) throw new \InvalidArgumentException($field[0].' is required.');
            if($type==='email' && $v!=='' && !is_email($v)) throw new \InvalidArgumentException('Invalid email.');
            if($type==='select' && !in_array($clean[$key],$field[2],true)) throw new \InvalidArgumentException('Invalid '.$field[0]);
            if(in_array($type,['money','optional_money','decimal']) && $v!=='' && (!is_numeric($v) || (float)$v<0)) throw new \InvalidArgumentException($field[0].' must be a nonnegative number.');
            if($type==='json' && $v!=='' && (!is_array(json_decode($v,true)) || json_last_error()!==JSON_ERROR_NONE)) throw new \InvalidArgumentException($field[0].' must contain valid JSON.');
            if($type==='date' && $v!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v) || gmdate('Y-m-d',strtotime($v))!==$v)) throw new \InvalidArgumentException('Invalid date.');
        }
        if($entity==='services') { $clean['config']=wp_json_encode(['program_unit'=>$clean['program_unit'],'program_length'=>$clean['program_length']]); unset($clean['program_unit'],$clean['program_length']); if((int)($data['program_length']??1)>365) throw new \InvalidArgumentException('Programme length must not exceed 365 units.'); if($clean['duration']>1440 || $clean['buffer_before']>1440 || $clean['buffer_after']>1440) throw new \InvalidArgumentException('Durations and buffers must not exceed one day.'); if($clean['sale_price']!==null && $clean['sale_price']>$clean['price']) throw new \InvalidArgumentException('Sale price cannot exceed price.'); if($clean['deposit_type']==='percent' && $clean['deposit_value']>100) throw new \InvalidArgumentException('Deposit percent cannot exceed 100.'); }
        if($entity==='staff') $clean['config']='{}';
        if($entity==='service_categories') $clean['slug']=sanitize_title($clean['slug']);
        if($entity==='custom_fields') { $clean['name']=sanitize_key($clean['name']); if(!$clean['name']) throw new \InvalidArgumentException('Field key is required.'); $rules=Store::json($clean['rules']); if(array_diff(array_keys($rules),['min','max','maxlength'])) throw new \InvalidArgumentException('Supported rules: min, max, maxlength.'); foreach($rules as $v) if(!is_numeric($v)) throw new \InvalidArgumentException('Validation limits must be numeric.'); }
        if(in_array($entity,['availability','blocked_dates'])) {
            if($clean['scope']==='business') $clean['owner_id']=0;
            elseif(!Store::get($clean['scope']==='staff'?'staff':'services',$clean['owner_id'])) throw new \InvalidArgumentException('Choose an existing service or staff ID.');
            if($entity==='availability') { if($clean['weekday']>7 || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$clean['opens']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$clean['closes']) || $clean['opens']>=$clean['closes']) throw new \InvalidArgumentException('Enter valid working hours within one day.'); foreach(Store::json($clean['breaks']) as $break) if(!is_array($break) || count($break)!==2 || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)$break[0]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)$break[1]) || $break[0]>=$break[1]) throw new \InvalidArgumentException('Breaks must be pairs of opening and closing times.'); }
            elseif(!$clean['date_from'] || !$clean['date_to'] || $clean['date_from']>$clean['date_to']) throw new \InvalidArgumentException('Enter a valid blocked date range.');
        }
        if($entity==='coupons') { $clean['code']=strtoupper($clean['code']); if($clean['type']==='percent' && $clean['value']>100) throw new \InvalidArgumentException('Discount cannot exceed 100%.'); }
        if($entity==='customers') { if(!$clean['email']) throw new \InvalidArgumentException('Email is required.'); if(!$id) $clean['created_at']=Store::now(); }
        $scheduleRows=null;
        if(in_array($entity,['services','staff']) && !empty($data['schedule_apply'])) {
            $scheduleRows=[];
            if(!empty($data['schedule_override'])) {
                $days=API::ids($data['schedule_days']??[]);$opens=sanitize_text_field($data['schedule_opens']??'');$closes=sanitize_text_field($data['schedule_closes']??'');$breaks=Store::json($data['schedule_breaks']??'[]');
                if(!$days || array_diff($days,[1,2,3,4,5,6,7]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$opens) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$closes) || $opens>=$closes) throw new \InvalidArgumentException('Select working days and valid opening/closing times.');
                foreach($breaks as $break) if(!is_array($break) || count($break)!==2 || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)$break[0]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)$break[1]) || $break[0]>=$break[1]) throw new \InvalidArgumentException('Invalid break period.');
                foreach($days as $day) $scheduleRows[]=['name'=>$clean['name'].' hours','scope'=>$entity==='services'?'service':'staff','weekday'=>$day,'opens'=>$opens,'closes'=>$closes,'breaks'=>wp_json_encode($breaks),'special_date'=>''];
            }
        }
        return Store::atomic(function() use($entity,$id,$clean,$data,$scheduleRows) { global $wpdb; if($id) Store::update($entity,$id,$clean); else $id=Store::insert($entity,$clean);
            if(in_array($entity,['services','staff'])) {
                $key=$entity==='services'?'staff_ids':'service_ids'; $owner=$entity==='services'?'service_id':'staff_id'; $other=$entity==='services'?'staff_id':'service_id';
                $wpdb->delete(Store::table('staff_services'),[$owner=>$id]); foreach(API::ids($data[$key]??[]) as $otherId) { if(!Store::get($entity==='services'?'staff':'services',$otherId)) throw new \InvalidArgumentException('Invalid assignment.'); Store::insert('staff_services',[$owner=>$id,$other=>$otherId]); }
            } if($scheduleRows!==null) { $wpdb->query($wpdb->prepare('DELETE FROM '.Store::table('availability')." WHERE scope=%s AND owner_id=%d AND special_date=''",$entity==='services'?'service':'staff',$id));foreach($scheduleRows as $schedule) Store::insert('availability',$schedule+['owner_id'=>$id]); } return Store::get($entity,$id);
        });
    }
    public static function deleteEntity(string $entity,int $id): array {
        if(!isset(self::schemas()[$entity])) throw new \RuntimeException('Record cannot be deleted.');
        return Store::atomic(function() use($entity,$id) { global $wpdb;
            if($entity==='customers' && Store::all('appointments','customer_id=%d',[$id])) throw new \RuntimeException('This customer has appointments. Retain the record for booking history.');
            if($entity==='services' || $entity==='staff') { $wpdb->delete(Store::table('staff_services'),[$entity==='services'?'service_id':'staff_id'=>$id]); $wpdb->delete(Store::table('availability'),['scope'=>$entity==='services'?'service':'staff','owner_id'=>$id]); }
            if($entity==='service_categories') $wpdb->update(Store::table('services'),['category_id'=>0],['category_id'=>$id]);
            $wpdb->delete(Store::table($entity),['id'=>$id]); return ['deleted'=>true];
        });
    }
    public static function settingsSchema(): array {
        $s=['Business'=>['business_name'=>'text','business_email'=>'email','business_phone'=>'text','help_hours'=>'text','business_address'=>'textarea','logo_id'=>'media','primary'=>'color','accent'=>'color','timezone'=>'timezone','currency'=>'currency','date_format'=>'text','time_format'=>'text'],
        'Booking'=>['multiple_services'=>'checkbox','staff_selection'=>'checkbox','guest_booking'=>'checkbox','manual_approval'=>'checkbox','slot_interval'=>'positive','min_notice'=>'number','max_days'=>'positive','capacity'=>'positive','cancellation_hours'=>'number','reschedule_hours'=>'number','reservation_minutes'=>'positive','reminder_hours'=>'positive'],
        'Payments'=>['mode'=>['test','live'],'payment_mode'=>['full','deposit'],'tax_percent'=>'decimal','enabled_methods'=>'methods','bank_name'=>'text','bank_account'=>'text','bank_number'=>'text','payment_instructions'=>'textarea'],
        'Email'=>['sender_name'=>'text','sender_email'=>'email']];
        foreach(['paystack','flutterwave','stripe'] as $provider) foreach(['test','live'] as $mode) foreach(['public','secret','webhook'] as $key) $s[ucfirst($provider)][$provider.'_'.$mode.'_'.$key]=$key==='public'?'text':'password';
        return $s;
    }
    public static function saveSettings(array $data): void {
        $s=Store::settings(); foreach(self::settingsSchema() as $fields) foreach($fields as $key=>$type) {
            if(!array_key_exists($key,$data)) continue; $v=$data[$key];
            if(is_array($type)) { if(!in_array($v,$type,true)) throw new \InvalidArgumentException('Invalid '.$key); $s[$key]=$v; }
            elseif($type==='methods') { $s[$key]=array_values(array_intersect((array)$v,['paystack','flutterwave','stripe','woocommerce','bank','pay_later'])); }
            elseif($type==='password') { if($v!=='') $s[$key]=sanitize_text_field($v); }
            elseif($type==='checkbox') $s[$key]=empty($v)?0:1;
            elseif(in_array($type,['number','positive','media'])) $s[$key]=max($type==='positive'?1:0,absint($v));
            elseif($type==='decimal') { if(!is_numeric($v) || $v<0 || $v>100) throw new \InvalidArgumentException('Tax must be between 0 and 100.'); $s[$key]=(float)$v; }
            elseif($type==='email') { if(!is_email($v)) throw new \InvalidArgumentException('Invalid email address.'); $s[$key]=sanitize_email($v); }
            elseif($type==='color') { $color=sanitize_hex_color($v); if(!$color) throw new \InvalidArgumentException('Invalid color.'); $s[$key]=$color; }
            elseif($type==='timezone') { try { new \DateTimeZone($v); } catch(\Throwable $e) { throw new \InvalidArgumentException('Use a valid timezone, for example Africa/Lagos.'); } $s[$key]=$v; }
            elseif($type==='currency') { if(!preg_match('/^[A-Z]{3}$/',strtoupper($v))) throw new \InvalidArgumentException('Use a three-letter currency code.'); $s[$key]=strtoupper($v); }
            else $s[$key]=$type==='textarea'?sanitize_textarea_field($v):sanitize_text_field($v);
        }
        if($s['max_days']>730 || $s['slot_interval']>1440) throw new \InvalidArgumentException('Maximum advance period is 730 days; interval must not exceed one day.');
        $oldCurrency=Store::settings()['currency'];
        if($s['currency']!==$oldCurrency) {
            $services=Store::all('services');
            if($services && empty($data['confirm_currency_reprice'])) throw new \RuntimeException('Currency not changed: tick the repricing confirmation. Existing service price numbers will be retained (for example 100 NGN becomes 100 USD); historical bookings and payments keep their original currencies. This does NOT apply a foreign exchange rate.');
            // This change affects future quotes only. Historical appointment/payment rows retain their stored currency and integer amounts.
            // Re-encode service price fields using the new currency's minor-unit scale, preserving their displayed major-unit numbers.
            Store::atomic(function() use($services,$oldCurrency,$s) {
                $from=10**Store::digits($oldCurrency); $to=10**Store::digits($s['currency']);
                foreach($services as $service) {
                    $fields=['price'=>(int)round((int)$service['price']/$from*$to)];
                    if($service['sale_price']!==null) $fields['sale_price']=(int)round((int)$service['sale_price']/$from*$to);
                    Store::update('services',(int)$service['id'],$fields);
                }
                if(!update_option('ib_settings',$s,false)) {
                    // WordPress returns false for unchanged options; a changed currency must change this option.
                    throw new \RuntimeException('Unable to save booking currency. Existing service prices were not changed.');
                }
            });
            return;
        }
        update_option('ib_settings',$s,false);
    }
    private static function startForm(string $action,array $hidden=[]): void { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="ib-admin-form">'; wp_nonce_field('ib_admin'); echo '<input type="hidden" name="action" value="ib_admin"><input type="hidden" name="ib_action" value="'.esc_attr($action).'">'; if(!empty($_GET['ib_embed'])) echo '<input type="hidden" name="ib_embed" value="1">'; foreach($hidden as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">'; }
    public static function field(string $key,array $field,$value=''): void {
        [$label,$type]=$field; echo '<div class="ib-admin-field"><label for="ib-'.esc_attr($key).'">'.esc_html($label).'</label>'; $attr=' id="ib-'.esc_attr($key).'" name="'.esc_attr($key).'"';
        if($type==='editor') wp_editor((string)$value,'ib-editor-'.sanitize_key($key),['textarea_name'=>$key,'textarea_rows'=>7,'media_buttons'=>false]);
        elseif(in_array($type,['textarea','json'])) echo '<textarea'.$attr.' rows="4">'.esc_textarea($value).'</textarea>';
        elseif($type==='checkbox') echo '<input type="hidden" name="'.esc_attr($key).'" value="0"><input type="checkbox"'.$attr.' value="1" '.checked($value,1,false).'>';
        elseif($type==='currency') {
            $codes=['USD'=>'US Dollar (USD)','GHS'=>'Ghana Cedi (GHS)','NGN'=>'Nigerian Naira (NGN)','GBP'=>'British Pound (GBP)','EUR'=>'Euro (EUR)','CAD'=>'Canadian Dollar (CAD)','AUD'=>'Australian Dollar (AUD)','KES'=>'Kenyan Shilling (KES)','ZAR'=>'South African Rand (ZAR)','JPY'=>'Japanese Yen (JPY)'];
            if(!isset($codes[$value])) $codes[$value]=$value;
            echo '<select'.$attr.'>';foreach($codes as $code=>$label) echo '<option value="'.esc_attr($code).'" '.selected($value,$code,false).'>'.esc_html($label).'</option>';echo '</select>';
        }
        elseif(in_array($type,['select','categories','staff_multi','services_multi','methods','weekdays'])) {
            $multi=in_array($type,['staff_multi','services_multi','methods','weekdays']); $options=[];
            if($type==='select') foreach($field[2] as $v) $options[$v]=ucwords(str_replace('_',' ',$v));
            elseif($type==='weekdays') $options=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
            elseif($type==='methods') $options=['paystack'=>'Paystack Direct','flutterwave'=>'Flutterwave Direct','stripe'=>'Stripe Direct','woocommerce'=>'WooCommerce','bank'=>'Manual Bank Transfer','pay_later'=>'Pay at Appointment'];
            else { $table=match($type){'categories'=>'service_categories','staff_multi'=>'staff',default=>'services'}; if(!$multi) $options[0]='None'; foreach(Store::all($table,'1=1 ORDER BY name') as $row) $options[$row['id']]=$row['name'].' (#'.$row['id'].')'; }
            if($multi) echo '<input type="hidden" name="'.esc_attr($key).'[]" value="">';
            echo '<select id="ib-'.esc_attr($key).'" name="'.esc_attr($key).($multi?'[]':'').'"'.($multi?' multiple size="5"':'').'>'; foreach($options as $v=>$label) echo '<option value="'.esc_attr($v).'" '.($multi?(in_array((string)$v,array_map('strval',(array)$value),true)?'selected':''):selected($value,$v,false)).'>'.esc_html($label).'</option>'; echo '</select>';
        } else {
            $inputType=match($type) {'email'=>'email','password'=>'password','time'=>'time','date'=>'date','color'=>'color','positive','number','money','optional_money','decimal','media'=>'number',default=>'text'};
            $display=in_array($type,['money','optional_money']) && $value!=='' && $value!==null?$value/10**Store::digits(Store::settings()['currency']):$value;
            echo '<input type="'.$inputType.'"'.$attr.' value="'.esc_attr($type==='password'?'':(string)$display).'"'.($type==='required'?' required':'').($inputType==='number'?' min="0" step="'.(in_array($type,['money','optional_money','decimal'])?'any':'1').'"':'').($type==='password'?' autocomplete="new-password" placeholder="Leave blank to retain saved value"':'').'>';
            if($type==='media') echo ' <button type="button" class="button ib-media" data-target="ib-'.esc_attr($key).'">Choose image</button>';
        } echo '</div>';
    }
    public static function handle(): void {
        if(!current_user_can('manage_intelink_booking')) wp_die('Access denied.',403); check_admin_referer('ib_admin'); $data=wp_unslash($_POST); $action=sanitize_key($data['ib_action']??''); $page=sanitize_key($data['page']??'dashboard');
        try {
            switch($action) {
                case 'save': self::saveEntity(sanitize_key($data['entity']),$data,absint($data['id']??0)); break;
                case 'delete': self::deleteEntity(sanitize_key($data['entity']),absint($data['id'])); break;
                case 'settings': self::saveSettings($data); break;
                case 'edit_details': Booking::editDetails(absint($data['id']),$data);break;
                case 'appointment': Booking::change(absint($data['id']),sanitize_key($data['operation']),$data,true); break;
                case 'manual': $data['request_key']=wp_generate_uuid4();$data['token']=bin2hex(random_bytes(32));$data['privacy']=1;$data['services']=API::ids($data['services']??[]);$data['start']=(new \DateTimeImmutable($data['start'],Store::tz()))->format(DATE_ATOM); $b=Booking::create($data,true); if($b['due']>0) Payments::initialize(Store::get('appointments',(int)$b['id']),'pay_later',home_url('/')); break;
                case 'record_payment': Payments::manual(absint($data['id'])); break;
                case 'verify_payment': $p=Store::get('payments',absint($data['id'])); if(!$p) throw new \RuntimeException('Payment not found.'); Payments::verify($p); break;
                case 'reconcile_refund': Payments::reconcileRefund(absint($data['id']),sanitize_text_field($data['provider_id']??'')); break;
                case 'refund': Payments::refund(absint($data['id']),Store::minor($data['amount']),!empty($data['offline'])); break;
                case 'test_email': $t=Store::get('email_templates',absint($data['id'])); if(!$t) throw new \RuntimeException('Template not found.'); [$subject,$body]=Notifications::render($t,['customer_name'=>'Test Customer','business_name'=>Store::settings()['business_name'],'booking_reference'=>'PREVIEW']); if(!wp_mail(wp_get_current_user()->user_email,$subject,$body,Notifications::headers())) throw new \RuntimeException('WordPress could not send the test email.'); break;
                case 'retry_email': Store::update('notification_logs',absint($data['id']),['status'=>'queued','attempts'=>0]); break;
                default: throw new \RuntimeException('Unknown action.');
            } $notice='Changes saved.';
        } catch(\Throwable $e) { $notice=$e->getMessage(); }
        set_transient('ib_notice_'.get_current_user_id(),$notice,60); wp_safe_redirect(!empty($data['ib_embed'])?add_query_arg('ib_embed','1',admin_url('admin.php?page=ib-'.$page)):admin_url('admin.php?page=ib-'.$page)); exit;
    }
    public static function page(): void {
        if(!current_user_can('manage_intelink_booking')) return;
        $page=str_replace('ib-','',sanitize_key($_GET['page']??'dashboard'));
        echo '<div class="wrap ib-admin"><div class="ib-admin-header"><div><span>INTELINK BOOKING</span><h1>'.esc_html(ucwords(str_replace('_',' ',$page))).'</h1></div></div>';
        $notice=get_transient('ib_notice_'.get_current_user_id()); if($notice) { echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; delete_transient('ib_notice_'.get_current_user_id()); }
        if(in_array($page,['dashboard','reports'])) self::dashboard($page);
        elseif($page==='activity') Activity::page();
        elseif($page==='settings' || $page==='integrations') self::settingsPage($page);
        elseif($page==='appointments') self::appointments();
        elseif($page==='calendar') self::calendar();
        elseif($page==='payments') self::paymentsPage();
        elseif($page==='notifications') { self::entityPage('email_templates','notifications'); self::logs(); }
        elseif($page==='availability') { echo '<p>Business schedules are required. Service and staff schedules intersect with business hours. All times use '.esc_html(Store::settings()['timezone']).'. Breaks use pairs of 24-hour times. Use special dates for exceptional opening hours.</p>'; $entity=($_GET['section']??'')==='blocked_dates'?'blocked_dates':'availability'; echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=ib-availability')).'">Working hours</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=ib-availability&section=blocked_dates')).'">Holidays & blocked dates</a></p>'; self::entityPage($entity,$page); }
        elseif(isset(self::schemas()[$page])) self::entityPage($page,$page);
        echo '</div>';
    }
    /** Dedicated two-column editor: defaults new services to publicly visible. */
    private static function serviceForm(array $row,int $id,string $page): void {
        $schema=self::schemas()['services']; $s=Store::settings();
        $defaults=['name'=>'','description'=>'','image_id'=>0,'category_id'=>0,'price'=>0,'sale_price'=>null,'duration'=>30,'program_unit'=>'single','program_length'=>1,'buffer_before'=>0,'buffer_after'=>0,'capacity'=>1,'payment_required'=>1,'deposit_type'=>'full','deposit_value'=>0,'status'=>'active','staff_ids'=>[]];
        $v=array_merge($defaults,$row);
        $hours=$id?Store::all('availability',"scope=%s AND owner_id=%d AND special_date='' ORDER BY weekday",['service',$id]):[];
        $active=(int)count(Store::all('services',"status='active'"));
        echo '<div class="ib-editor-top"><div><p class="ib-eyebrow">SERVICES / '.($id?'EDIT':'NEW').'</p><h2>'.($id?'Edit service':'Add New Service').'</h2><p>Configure the information, price and schedule that customers will see on your booking page.</p></div><a class="button" href="'.esc_url(add_query_arg(['page'=>'ib-services'],admin_url('admin.php'))).'">Back to services</a></div>';
        echo '<div class="ib-catalog-health"><strong>Public catalogue: '.(int)$active.' active service'.($active===1?'':'s').'.</strong> Only services with status <b>Active</b> appear in the booking form. A category or service filter in your shortcode may also limit which services appear. <a href="'.esc_url(rest_url('intelink-booking/v1/catalog')).'" target="_blank" rel="noopener">Check public catalogue ↗</a></div>';
        self::startForm('save',['entity'=>'services','id'=>$id,'page'=>$page]);
        echo '<div class="ib-service-editor"><div class="ib-editor-main">';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>01</span><div><h3>Basic information</h3><p>What will customers book?</p></div></div>';
        self::field('name',$schema['name'],$v['name']);
        echo '<div class="ib-admin-field"><label for="ib-service-description">Service description</label><textarea id="ib-service-description" name="description" rows="6" placeholder="Describe what customers will receive, who the service is for, and what to expect.">'.esc_textarea($v['description']).'</textarea><small>Shown on the booking service card. Basic HTML entered here is sanitized when saved.</small></div>';
        echo '</section>';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>02</span><div><h3>Pricing & payment</h3><p>Customers will see the currently configured '.esc_html($s['currency']).' currency.</p></div></div><div class="ib-form-grid">';
        self::field('price',['Regular Price ('.$s['currency'].')','money'],$v['price']);self::field('sale_price',['Sale Price (optional)','optional_money'],$v['sale_price']);
        echo '</div><div class="ib-form-grid">';self::field('payment_required',$schema['payment_required'],$v['payment_required']);self::field('deposit_type',$schema['deposit_type'],$v['deposit_type']);self::field('deposit_value',$schema['deposit_value'],$v['deposit_value']);echo '</div><p class="ib-field-help">Use Full payment for the entire amount, Fixed for a fixed deposit, or Percent for a percentage deposit. The public booking form uses the price saved here.</p></section>';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>03</span><div><h3>Appointment & programme duration</h3><p>Session duration controls the bookable slot. Programme length describes the overall period.</p></div></div><div class="ib-form-grid ib-three">';
        self::field('duration',$schema['duration'],$v['duration']);self::field('buffer_before',$schema['buffer_before'],$v['buffer_before']);self::field('buffer_after',$schema['buffer_after'],$v['buffer_after']);echo '</div><div class="ib-form-grid">';self::field('program_unit',['Programme type','select',['single','days','weeks','months']],$v['program_unit']);self::field('program_length',['Programme length (units)','positive'],$v['program_length']);echo '</div><p class="ib-field-help">Programmes do not yet automatically create recurring bookings. Each session is reserved individually.</p></section>';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>04</span><div><h3>Availability & capacity</h3><p>Set where and when this service can be booked.</p></div></div>';
        self::field('capacity',$schema['capacity'],$v['capacity']);self::field('staff_ids',$schema['staff_ids'],$v['staff_ids']);
        echo '<div class="ib-schedule-box">';self::field('schedule_apply',['Update schedule when saving','checkbox'],$id?0:1);self::field('schedule_override',['Use service-specific hours instead of business hours','checkbox'],$hours?1:0);
        echo '<div class="ib-schedule-fields">';self::field('schedule_days',['Available days','weekdays'],array_column($hours,'weekday')?:[1,2,3,4,5]);echo '<div class="ib-form-grid">';self::field('schedule_opens',['Opening time','time'],$hours[0]['opens']??'09:00');self::field('schedule_closes',['Closing time','time'],$hours[0]['closes']??'17:00');echo '</div>';self::field('schedule_breaks',['Breaks (JSON, optional)','json'],$hours[0]['breaks']??'[]');echo '</div><p class="ib-field-help">If override is off, the business working hours apply. If override is on, choose at least one working day and valid times. Edit special dates and holidays in Availability.</p></div></section></div>';
        echo '<aside class="ib-editor-side">';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>05</span><div><h3>Publish service</h3><p>Control customer visibility.</p></div></div>';
        self::field('status',['Visibility','select',['active','draft','inactive']],$v['status']);echo '<div class="ib-visibility-note">Active = appears on customer booking page. Draft / Inactive = hidden from public booking.</div><button type="submit" class="button button-primary ib-save-service">'.($id?'Save Changes':'Save & Publish Service').'</button></section>';
        echo '<section class="ib-edit-card"><div class="ib-card-title"><span>06</span><div><h3>Image & category</h3><p>Help customers recognize the service.</p></div></div>';
        $img=(int)$v['image_id']?wp_get_attachment_image_url((int)$v['image_id'],'medium'):'';
        echo '<div class="ib-image-preview" id="ib-image-preview">'.($img?'<img src="'.esc_url($img).'" alt="Service image">':'<span>▧<small>Service image preview</small></span>').'</div>';
        self::field('image_id',$schema['image_id'],$v['image_id']);self::field('category_id',$schema['category_id'],$v['category_id']);echo '<p class="ib-field-help">Categories are optional. An unfiltered booking shortcode displays active services from every category.</p></section>';
        echo '<section class="ib-edit-card"><h3>Public booking check</h3><p>After saving, your service should be visible at:</p><code>[intelink_booking]</code><p class="ib-field-help">If the booking page uses <code>service=</code> or <code>category=</code>, remove the filter or update it to this service.</p></section></aside></div></form>';
    }

    public static function entityPage(string $entity,string $page): void {
        $schema=self::schemas()[$entity]; $id=absint($_GET['edit']??0);$row=$id?Store::get($entity,$id):[];
        if(!$row) $row=[];
        if($entity==='services') { $row=array_merge($row,Store::json($row['config']??'{}')); $row['program_unit']=$row['program_unit']??'single'; $row['program_length']=$row['program_length']??1; }
        if($entity==='services') $row['staff_ids']=array_column(Store::all('staff_services','service_id=%d',[$id]),'staff_id');
        if($entity==='staff') $row['service_ids']=array_column(Store::all('staff_services','staff_id=%d',[$id]),'service_id');
        if($entity==='services') {
            self::serviceForm($row,$id,$page);
        } else {
            echo '<details class="ib-panel" '.($id?'open':'').'><summary>'.($id?'Edit #'.$id:'Add new '.esc_html(str_replace('_',' ',rtrim($entity,'s')))).'</summary>';
            self::startForm('save',['entity'=>$entity,'id'=>$id,'page'=>$page]);
            foreach($schema as $key=>$field) self::field($key,$field,$row[$key]??match($key){'duration'=>30,'program_length'=>1,'program_unit'=>'single','capacity','weekday'=>1,'enabled','payment_required'=>1,'breaks'=>'[]','rules'=>'{}','opens'=>'09:00','closes'=>'17:00',default=>''});
            if($entity==='staff') {
                $hours=$id?Store::all('availability',"scope=%s AND owner_id=%d AND special_date='' ORDER BY weekday",['staff',$id]):[];
                echo '<h3>Working schedule</h3><p>Leave override disabled to inherit business hours. Configure special dates under Availability.</p>';
                self::field('schedule_apply',['Apply these schedule changes when saving','checkbox'],$id?0:1);
                self::field('schedule_override',['Override business schedule','checkbox'],$hours?1:0);
                self::field('schedule_days',['Working days','weekdays'],array_column($hours,'weekday')?:[1,2,3,4,5]);
                self::field('schedule_opens',['Opening time','time'],$hours[0]['opens']??'09:00');
                self::field('schedule_closes',['Closing time','time'],$hours[0]['closes']??'17:00');
                self::field('schedule_breaks',['Break periods JSON','json'],$hours[0]['breaks']??'[]');
            }
            echo '<button class="button button-primary">Save record</button></form></details>';
        }
        $rows=Store::all($entity,'1=1 ORDER BY id DESC LIMIT 500');
        $columns=array_slice(array_keys($schema),0,$entity==='services'?9:6); $columns=array_values(array_diff($columns,['description','body','config','staff_ids','service_ids']));
        echo '<div class="ib-table"><table class="widefat striped"><thead><tr><th>ID</th>';foreach($columns as $c) echo '<th>'.esc_html($schema[$c][0]).'</th>'; echo '<th>Actions</th></tr></thead><tbody>';
        foreach($rows as $r) { echo '<tr><td>'.(int)$r['id'].'</td>'; foreach($columns as $c) { $v=$r[$c]??''; echo '<td>'; if($c==='image_id' && $v) echo wp_get_attachment_image((int)$v,[48,48]); elseif(in_array($schema[$c][1],['money','optional_money'])) echo $v!==null?esc_html(Store::money((int)$v)):'—'; else echo esc_html(wp_trim_words(wp_strip_all_tags((string)$v),12)); echo '</td>'; }
            echo '<td><a class="button button-small" href="'.esc_url(add_query_arg(['page'=>'ib-'.$page,'edit'=>$r['id'],'section'=>$entity],admin_url('admin.php'))).'">Edit</a> '; self::startForm('delete',['entity'=>$entity,'id'=>$r['id'],'page'=>$page]); echo '<button class="button button-small ib-confirm" data-confirm="Delete this record? Historical appointments retain their snapshots.">Delete</button></form>';
            if($entity==='email_templates') { self::startForm('test_email',['id'=>$r['id'],'page'=>$page]);echo '<button class="button button-small">Send test to me</button></form><details><summary>Preview</summary>'; [$subject,$body]=Notifications::render($r,['customer_name'=>'Example Customer','booking_reference'=>'IB-PREVIEW','business_name'=>Store::settings()['business_name']]); echo '<strong>'.esc_html($subject).'</strong><iframe title="Email preview" sandbox srcdoc="'.esc_attr($body).'"></iframe></details>'; } echo '</td></tr>';
        } if(!$rows) echo '<tr><td colspan="12">No records yet.</td></tr>'; echo '</tbody></table></div>';
    }
    public static function settingsPage(string $page): void {
        echo '<p>Add <code>[intelink_booking]</code> to any WordPress page. The site logo is used automatically unless a logo override is set. Booking notice is in minutes; deadlines and reminders are in hours.</p>';
        if($page==='settings' && ($_GET['section']??'')==='fields') { self::entityPage('custom_fields','settings'); return; }
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=ib-settings&section=fields')).'">Custom booking fields</a></p>';
        self::startForm('settings',['page'=>$page]);$s=Store::settings(); foreach(self::settingsSchema() as $title=>$fields) {
            if($page==='integrations' && !in_array($title,['Payments','Paystack','Flutterwave','Stripe'])) continue;
            echo '<details class="ib-panel"'.($title==='Business' || $title==='Payments'?' open':'').'><summary>'.esc_html($title).'</summary>';
            foreach($fields as $key=>$type) { $field=[ucwords(str_replace('_',' ',$key)),is_array($type)?'select':$type]; if(is_array($type)) $field[]=$type; self::field($key,$field,$s[$key]??''); }
            if($title==='Business') echo '<div class="ib-currency-warning"><p><strong>Changing currency:</strong> Existing service price numbers will stay the same, but become denominated in the new currency (e.g. 100 NGN → 100 USD). No exchange-rate conversion is performed. Existing appointments and payments retain their original currencies. Review all service prices before accepting new bookings.</p><label><input type="checkbox" name="confirm_currency_reprice" value="1"> I understand and authorize repricing existing services if the currency changes.</label></div>';
            if(in_array($title,['Paystack','Flutterwave','Stripe'])) echo '<p>Webhook URL: <code>'.esc_html(add_query_arg('mode',Store::settings()['mode'],rest_url('intelink-booking/v1/webhook/'.strtolower($title)))).'</code></p>';
            echo '</details>';
        } echo '<button class="button button-primary">Save settings</button></form>';
    }
    public static function report(string $from,string $to): array {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) || $from>$to) throw new \InvalidArgumentException('Invalid report range.');
        $a=(new \DateTimeImmutable($from.' 00:00',Store::tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $b=(new \DateTimeImmutable($to.' 23:59:59',Store::tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows=Store::all('appointments','starts_at BETWEEN %s AND %s',[$a,$b]); $payments=Store::all('payments',"paid_at BETWEEN %s AND %s AND status IN ('successful','partially_refunded','refunded')",[$a,$b]);
        $statuses=[];$popular=[];$daily=[];$revenue=[];$revenueCurrencies=[];$revenueDailyCurrencies=[];
        foreach($rows as $r) { $statuses[$r['status']]=($statuses[$r['status']]??0)+1; $day=wp_date('Y-m-d',strtotime($r['starts_at'].' UTC'),Store::tz()); $daily[$day]=($daily[$day]??0)+1; foreach(Store::json($r['snapshot'])['services']??[] as $s) $popular[$s['name']]=($popular[$s['name']]??0)+1; }
        foreach($payments as $p) {
            $day=wp_date('Y-m-d',strtotime($p['paid_at'].' UTC'),Store::tz());$currency=strtoupper($p['currency']);$net=(int)$p['amount']-(int)$p['refunded'];
            $revenueCurrencies[$currency]=($revenueCurrencies[$currency]??0)+$net;
            $revenueDailyCurrencies[$currency][$day]=($revenueDailyCurrencies[$currency][$day]??0)+$net;
            if($currency===Store::settings()['currency']) $revenue[$day]=($revenue[$day]??0)+$net;
        }
        arsort($popular);ksort($daily);ksort($revenue);ksort($revenueCurrencies);
        foreach($revenueDailyCurrencies as &$byDay) ksort($byDay);unset($byDay);
        return ['count'=>count($rows),'statuses'=>$statuses,'popular'=>$popular,'appointments_by_day'=>$daily,'revenue_by_day'=>$revenue,'revenue'=>array_sum($revenue),'revenue_currencies'=>$revenueCurrencies,'revenue_daily_currencies'=>$revenueDailyCurrencies,'from'=>$from,'to'=>$to];
    }
    public static function dashboard(string $page): void {
        global $wpdb; $today=wp_date('Y-m-d',null,Store::tz());$from=sanitize_text_field($_GET['from']??wp_date('Y-m-01',null,Store::tz()));$to=sanitize_text_field($_GET['to']??$today);
        try { $r=self::report($from,$to);$td=self::report($today,$today);$month=self::report(wp_date('Y-m-01',null,Store::tz()),$today); } catch(\Throwable $e) { echo '<p>'.esc_html($e->getMessage()).'</p>'; return; }
        echo '<form method="get" class="ib-filter"><input type="hidden" name="page" value="ib-'.esc_attr($page).'"><label>From <input type="date" name="from" value="'.esc_attr($from).'" required></label><label>To <input type="date" name="to" value="'.esc_attr($to).'" required></label><button class="button">Filter</button>';
        foreach(['Today'=>$today,'This Week'=>wp_date('Y-m-d',strtotime('monday this week'),Store::tz()),'This Month'=>wp_date('Y-m-01',null,Store::tz())] as $label=>$start) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'ib-'.$page,'from'=>$start,'to'=>$today],admin_url('admin.php'))).'">'.esc_html($label).'</a>'; echo '</form>';
        $cards=["Today's Appointments"=>$td['count'],'Upcoming Appointments'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('appointments')." WHERE starts_at>%s AND status IN ('confirmed','pending','awaiting_payment')",Store::now())),'Completed Appointments'=>$r['statuses']['completed']??0,'Cancelled Appointments'=>$r['statuses']['cancelled']??0,'Pending Appointments'=>($r['statuses']['pending']??0)+($r['statuses']['awaiting_payment']??0),'Total Customers'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Store::table('customers')),'Revenue Today'=>self::moneyBreakdown($td['revenue_currencies']),'Revenue This Month'=>self::moneyBreakdown($month['revenue_currencies']),'Revenue in Range'=>self::moneyBreakdown($r['revenue_currencies'])];
        echo '<div class="ib-stats">'; foreach($cards as $label=>$value) echo '<article><span>'.esc_html($label).'</span><strong>'.esc_html((string)$value).'</strong></article>'; echo '</div><div class="ib-admin-grid">';
        self::chart('Appointment trends',$r['appointments_by_day']);foreach($r['revenue_daily_currencies'] as $currency=>$days) self::chart('Verified revenue by payment date — '.$currency.' (net of refunds)',$days,true,$currency);self::chart('Appointment status distribution',$r['statuses']);self::chart('Most booked services',$r['popular']);echo '</div>';
        echo '<h2>Recent appointments</h2>';self::appointmentTable(Store::all('appointments','1=1 ORDER BY id DESC LIMIT 8'));
        echo '<h2>Upcoming appointments</h2>';self::appointmentTable(Store::all('appointments',"starts_at>%s AND status IN ('confirmed','pending','awaiting_payment') ORDER BY starts_at LIMIT 8",[Store::now()]));
        echo '<h2>Recent payments</h2>';self::paymentTable(Store::all('payments','1=1 ORDER BY id DESC LIMIT 8'),false);
    }
    private static function moneyBreakdown(array $currencies): string { if(!$currencies)return Store::money(0);return implode(' / ',array_map(fn($code,$amount)=>Store::money((int)$amount,(string)$code),array_keys($currencies),array_values($currencies))); }
    private static function chart(string $title,array $values,bool $money=false,?string $currency=null): void { echo '<section class="ib-panel"><h2>'.esc_html($title).'</h2>'; $max=$values?max(1,max($values)):1; if(!$values) echo '<p>No data in this range.</p>'; foreach(array_slice($values,0,31,true) as $label=>$v) echo '<div class="ib-bar"><span>'.esc_html($label).'</span><meter min="0" max="'.esc_attr($max).'" value="'.esc_attr($v).'">'.esc_html($v).'</meter><b>'.esc_html($money?Store::money($v,$currency):(string)$v).'</b></div>';echo '</section>'; }
    public static function appointmentTable(array $rows): void {
        echo '<div class="ib-table"><table class="widefat striped"><thead><tr>';foreach(['Reference','Customer','Service','Staff','Date & Time','Duration','Amount','Payment','Status','Actions'] as $c) echo '<th>'.esc_html($c).'</th>';echo '</tr></thead><tbody>';
        foreach($rows as $b) { $v=Booking::view($b);echo '<tr>';foreach([$b['reference'],$v['customer']['first_name'].' '.$v['customer']['last_name'],implode(', ',array_column($v['services'],'name')),$v['staff_name'],wp_date('Y-m-d H:i',strtotime($b['starts_at'].' UTC'),Store::tz()),array_sum(array_column($v['services'],'duration')).' min',Store::money((int)$b['total'],$b['currency']),$b['payment_status'],$b['status']] as $value) echo '<td>'.esc_html((string)$value).'</td>';echo '<td><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=ib-appointments&view='.$b['id'])).'">View / Edit</a></td></tr>'; } if(!$rows) echo '<tr><td colspan="10">No appointments.</td></tr>';echo '</tbody></table></div>';
    }
    public static function appointments(): void {
        $id=absint($_GET['view']??0);$b=$id?Store::get('appointments',$id):null;
        if($b) { $v=Booking::view($b);echo '<section class="ib-panel"><h2>'.esc_html($b['reference']).'</h2><dl class="ib-details">'; foreach(['status','payment_status','starts_at','ends_at','staff_name','timezone','location','notes'] as $key) echo '<dt>'.esc_html(ucwords(str_replace('_',' ',$key))).'</dt><dd>'.esc_html((string)($v[$key]??'')).'</dd>';foreach($v['customer'] as $key=>$value) echo '<dt>'.esc_html(ucwords(str_replace('_',' ',$key))).'</dt><dd>'.esc_html($value).'</dd>';foreach($v['custom'] as $key=>$value) { echo '<dt>'.esc_html($key).'</dt><dd>'; $file=Store::all('appointment_meta','appointment_id=%d AND meta_key=%s',[$id,'file_'.$key])[0]??null; if($file) echo '<a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ib_download&file='.$file['id']),'ib_file')).'">Download attachment</a>';else echo esc_html($value);echo '</dd>'; }echo '</dl><p>Total: '.esc_html(Store::money((int)$b['total'],$b['currency'])).' · Paid: '.esc_html(Store::money((int)$b['paid'],$b['currency'])).' · Balance: '.esc_html(Store::money((int)$b['total']-(int)$b['paid'],$b['currency'])).'</p>';
            echo '<details><summary>Edit customer details and notes</summary>';self::startForm('edit_details',['page'=>'appointments','id'=>$id]);foreach($v['customer'] as $key=>$value) self::field('customer['.$key.']',[ucwords(str_replace('_',' ',$key)),$key==='email'?'email':'text'],$value);self::field('notes',['Notes','textarea'],$b['notes']);echo '<button class="button button-primary">Save appointment details</button></form></details>';self::startForm('appointment',['page'=>'appointments','id'=>$id]);self::field('operation',['Action','select',['confirm','pending','reschedule','cancel','complete','no_show']]);echo '<label>New appointment time ('.esc_html(Store::settings()['timezone']).') <input type="datetime-local" name="start"></label>';self::field('staff_id',['Staff ID (0 = automatic)','number'],$b['staff_id']);echo '<button class="button button-primary">Apply action</button></form><h3>History</h3><ul>';foreach(Store::all('appointment_meta','appointment_id=%d AND meta_key=%s ORDER BY id DESC',[$id,'history']) as $h) { $entry=Store::json($h['meta_value']);echo '<li>'.esc_html(($entry['at']??'').' — '.($entry['message']??'')).'</li>'; }echo '</ul></section>';self::paymentTable(Store::all('payments','appointment_id=%d',[$id]),true);
        }
        echo '<details class="ib-panel"><summary>Create manual appointment</summary>';self::startForm('manual',['page'=>'appointments']);self::field('services',['Services','services_multi']);echo '<div class="ib-admin-field"><label>Date & Time ('.esc_html(Store::settings()['timezone']).')</label><input type="datetime-local" name="start" required></div>';self::field('staff_id',['Staff ID (0 = automatic)','number'],0);foreach(['first_name','last_name','email','phone','country','city','address'] as $f) self::field('customer['.$f.']',[ucwords(str_replace('_',' ',$f)),in_array($f,['first_name','last_name','phone'])?'required':($f==='email'?'email':'text')]);self::field('notes',['Notes','textarea']);foreach(Store::all('custom_fields','enabled=1') as $f) self::field('custom['.$f['name'].']',[$f['label'],$f['required']?'required':'text'],$f['default_value']);echo '<p>Manual appointments use pay at appointment; enable that method in Payments settings.</p><button class="button button-primary">Create appointment</button></form></details>';
        $search=sanitize_text_field($_GET['search']??'');echo '<form method="get"><input type="hidden" name="page" value="ib-appointments"><input name="search" placeholder="Booking reference" value="'.esc_attr($search).'"><button class="button">Search</button></form>';
        global $wpdb;self::appointmentTable(Store::all('appointments',$search?'reference LIKE %s ORDER BY starts_at DESC LIMIT 500':'1=1 ORDER BY starts_at DESC LIMIT 500',$search?['%'.$wpdb->esc_like($search).'%']:[]));
    }
    public static function calendar(): void {
        $month=sanitize_text_field($_GET['month']??wp_date('Y-m',null,Store::tz()));if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=wp_date('Y-m');$base=new \DateTimeImmutable($month.'-01',Store::tz());
        echo '<form><input type="hidden" name="page" value="ib-calendar"><input type="month" name="month" value="'.esc_attr($month).'"><button class="button">Show month</button></form><div class="ib-admin-calendar">';foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day) echo '<strong>'.esc_html($day).'</strong>';for($i=1;$i<(int)$base->format('N');$i++) echo '<div></div>';
        $a=$base->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');$z=$base->modify('+1 month')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');$rows=Store::all('appointments','starts_at>=%s AND starts_at<%s ORDER BY starts_at',[$a,$z]);$days=[];foreach($rows as $r) $days[wp_date('j',strtotime($r['starts_at'].' UTC'),Store::tz())][]=$r;
        for($d=1;$d<=(int)$base->format('t');$d++) { echo '<section><b>'.$d.'</b>';foreach($days[$d]??[] as $b) echo '<a href="'.esc_url(admin_url('admin.php?page=ib-appointments&view='.$b['id'])).'">'.esc_html(wp_date('H:i',strtotime($b['starts_at'].' UTC'),Store::tz()).' '.$b['reference'].' · '.$b['status']).'</a>';echo '</section>'; }echo '</div>';
    }
    public static function paymentTable(array $rows,bool $actions=true): void {
        echo '<div class="ib-table"><table class="widefat striped"><thead><tr><th>Transaction</th><th>Booking / Customer</th><th>Provider</th><th>Amount</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach($rows as $p) { $b=Store::get('appointments',(int)$p['appointment_id']);$c=$b?Store::json($b['snapshot'])['customer']:[];echo '<tr><td>'.esc_html($p['reference']).'</td><td><a href="'.esc_url(admin_url('admin.php?page=ib-appointments&view='.$p['appointment_id'])).'">'.esc_html($b['reference']??'').'</a><br>'.esc_html(($c['first_name']??'').' '.($c['last_name']??'')).'</td><td>'.esc_html($p['provider']).'</td><td>'.esc_html(Store::money((int)$p['amount'],$p['currency'])).'</td><td>'.esc_html($p['paid_at']?:$p['created_at']).' UTC</td><td>'.esc_html($p['status']).'</td><td>';
            if($actions && $p['status']==='pending') { self::startForm(in_array($p['provider'],['bank','pay_later'])?'record_payment':'verify_payment',['id'=>$p['id'],'page'=>'payments']);echo '<button class="button button-small">'.(in_array($p['provider'],['bank','pay_later'])?'Record received payment':'Verify with gateway').'</button></form>'; }
            if($actions && in_array($p['status'],['successful','partially_refunded'])) { self::startForm('refund',['id'=>$p['id'],'page'=>'payments','offline'=>in_array($p['provider'],['bank','pay_later'])?1:0]);echo '<label>Refund amount <input type="number" name="amount" min="0.01" step="any" required></label><button class="button ib-confirm" data-confirm="Refund this amount? For offline methods, confirm you have already returned the funds.">Refund</button></form>'; }echo '</td></tr>';
        }if(!$rows) echo '<tr><td colspan="7">No payments.</td></tr>';echo '</tbody></table></div>';
    }
    public static function paymentsPage(): void { echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=ib-payments&section=settings')).'">Payment settings</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=ib-payments')).'">Transactions</a></p>'; if(($_GET['section']??'')==='settings') self::settingsPage('integrations'); else { self::paymentTable(Store::all('payments','1=1 ORDER BY id DESC LIMIT 500')); $pending=Store::all('payment_events',"event_type IN ('refund_pending','refund_requested','refund_uncertain') ORDER BY id DESC"); if($pending) { echo '<h2>Refunds awaiting provider completion</h2><p>These funds are not counted as refunded until completion is verified.</p><pre>'.esc_html(wp_json_encode($pending,JSON_PRETTY_PRINT)).'</pre>'; foreach($pending as $event) { self::startForm('reconcile_refund',['page'=>'payments','id'=>$event['id']]); if($event['event_type']!=='refund_pending') echo '<label>Refund ID from provider dashboard <input name="provider_id" required></label>'; echo '<button class="button">Verify refund #'.(int)$event['id'].' with provider</button></form>'; } } } }
    public static function logs(): void {
        echo '<h2>Notification delivery log</h2><p>Sent means accepted by wp_mail; delivery depends on the site mail transport. A sending record left by an interrupted worker requires review before retrying.</p><div class="ib-table"><table class="widefat"><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Error</th><th>Action</th></tr>';foreach(Store::all('notification_logs','1=1 ORDER BY id DESC LIMIT 100') as $log) {echo '<tr><td>'.esc_html($log['event_key']).'</td><td>'.esc_html($log['status']).'</td><td>'.(int)$log['attempts'].'</td><td>'.esc_html($log['last_error']).'</td><td>';if(in_array($log['status'],['failed','sending'])) {self::startForm('retry_email',['page'=>'notifications','id'=>$log['id']]);echo '<button class="button ib-confirm" data-confirm="Check that this email was not already delivered before retrying.">Retry</button></form>';}echo '</td></tr>';}echo '</table></div><p>Template variables: <code>{{customer_name}} {{customer_email}} {{customer_phone}} {{booking_reference}} {{service_name}} {{appointment_date}} {{appointment_time}} {{appointment_duration}} {{appointment_location}} {{payment_amount}} {{payment_method}} {{payment_status}} {{booking_status}} {{cancel_booking_url}} {{reschedule_booking_url}} {{business_name}} {{business_logo}}</code></p>';
    }
}
