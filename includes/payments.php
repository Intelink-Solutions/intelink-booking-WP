<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Payments {
    public static function provider(string $name,?string $mode=null): Provider { return match($name) { 'paystack'=>new Paystack($mode), 'flutterwave'=>new Flutterwave($mode), 'stripe'=>new Stripe($mode), 'woocommerce'=>new WooCommerce(), 'bank'=>new BankTransfer(), 'pay_later'=>new PayLater(), 'free'=>new FreeBooking(), default=>throw new \InvalidArgumentException('Invalid payment method.') }; }
    public static function methods(): array { $s=Store::settings(); return array_values(array_filter((array)$s['enabled_methods'],function($name) use($s) { if($name==='woocommerce') return function_exists('wc_create_order'); if(in_array($name,['paystack','flutterwave','stripe'])) return !empty($s[$name.'_'.$s['mode'].'_secret']); return in_array($name,['bank','pay_later']); })); }
    public static function hooks(): void {
        add_action('woocommerce_payment_complete',function($id) { $order=function_exists('wc_get_order')?wc_get_order($id):false; if(!$order)return; $ref=$order->get_meta('_ib_reference'); if($ref) { $p=Store::all('payments','reference=%s',[$ref])[0]??null; if($p) try { self::verify($p); } catch(\Throwable $e) { $order->add_order_note('Intelink reconciliation required: '.$e->getMessage()); } } });
        add_action('woocommerce_order_status_changed',function($id,$old,$new) { if(!in_array($new,['processing','completed'],true))return;$o=function_exists('wc_get_order')?wc_get_order($id):false;if(!$o)return;$ref=$o->get_meta('_ib_reference');$p=$ref?(Store::all('payments','reference=%s',[$ref])[0]??null):null;if($p)try {self::verify($p);}catch(\Throwable $e){$o->add_order_note('Intelink reconciliation required: '.$e->getMessage());} },10,3);
        add_action('woocommerce_order_refunded',function($id) { add_action('shutdown',function() use($id) { Store::atomic(function() use($id) { $o=wc_get_order($id);if(!$o)return;$p=Store::all('payments','reference=%s',[$o->get_meta('_ib_reference')])[0]??null;if(!$p || !in_array($p['status'],['successful','partially_refunded','refunded'],true))return;$total=(int)round((float)$o->get_total_refunded()*10**Store::digits($p['currency']));$delta=$total-(int)$p['refunded'];if($delta>0 && $total<=(int)$p['amount']) { self::applyRefund($p,$delta);Store::insert('payment_events',['event_key'=>'woo-refund-total:'.$p['reference'].':'.$total,'payment_id'=>$p['id'],'event_type'=>'refund_completed','payload'=>wp_json_encode(['amount'=>$delta,'source'=>'woocommerce']),'created_at'=>Store::now()]); } }); }); });
        add_filter('woocommerce_get_return_url',function($url,$order) { return $order && $order->get_meta('_ib_return') ? $order->get_meta('_ib_return') : $url; },10,2);
    }
    public static function initialize(array $booking,string $method,string $return): array {
        if(!in_array($method,self::methods(),true) && !($method==='free' && (int)$booking['total']===0)) throw new \RuntimeException('Payment method is unavailable.');
        $return=wp_validate_redirect($return,home_url('/')); $p=Store::atomic(function() use($booking,$method) {
            $b=Store::get('appointments',(int)$booking['id']);
            if(in_array($b['status'],['cancelled','refunded','completed','no_show'])) throw new \RuntimeException('This reservation is no longer active.');
            if($b['expires_at'] && $b['expires_at']<=Store::now()) throw new \RuntimeException('Reservation expired. Please book again.');
            $existing=Store::all('payments','appointment_id=%d ORDER BY id DESC',[$b['id']])[0]??null;
            if($existing) { if($existing['provider']!==$method) throw new \RuntimeException('A payment method has already been selected for this booking.'); return $existing; }
            $amount=(int)$b['due'];
            if($amount<=0) throw new \RuntimeException('This appointment has no outstanding online payment.');
            if($method==='pay_later') { Store::update('appointments',(int)$b['id'],['status'=>Store::settings()['manual_approval']?'pending':'confirmed','payment_status'=>'pay_later','expires_at'=>null]); if(!Store::settings()['manual_approval']) Notifications::enqueue((int)$b['id'],'confirmed'); }
            if($method==='bank') Store::update('appointments',(int)$b['id'],['status'=>'awaiting_payment']);
            $id=Store::insert('payments',['reference'=>'IBP-'.bin2hex(random_bytes(16)),'appointment_id'=>$b['id'],'provider'=>$method,'mode'=>Store::settings()['mode'],'amount'=>$amount,'currency'=>$b['currency'],'status'=>$method==='free'?'successful':'pending','external_id'=>'','checkout_url'=>'','created_at'=>Store::now(),'paid_at'=>$method==='free'?Store::now():null]);
            return Store::get('payments',$id);
        });
        if(!$p['checkout_url'] && !in_array($method,['bank','pay_later','free']) && $p['status']==='pending') {
            // A short provider-specific lock prevents duplicate checkout initialization.
            global $wpdb; $lock='ibpay_'.md5($p['reference']); if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock))!==1) throw new \RuntimeException('Payment is initializing. Please retry.');
            try { $p=Store::get('payments',(int)$p['id']); if(!$p['checkout_url']) { $result=self::provider($method,$p['mode'])->initialize($p,Booking::view(Store::get('appointments',(int)$p['appointment_id'])),$return); if(!self::checkoutUrlValid($method,(string)($result['checkout_url']??''))) throw new \RuntimeException('Provider did not return an approved secure checkout URL.'); Store::update('payments',(int)$p['id'],$result); $p=Store::get('payments',(int)$p['id']); } }
            finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        }
        return ['reference'=>$p['reference'],'checkout_url'=>$p['checkout_url'],'status'=>$p['status'],'booking'=>API::bookingView(Store::get('appointments',(int)$p['appointment_id']))];
    }
    // Only redirect to the selected gateway's HTTPS checkout or this site's WooCommerce pay page.
    public static function checkoutUrlValid(string $method,string $url): bool {
        $parts=wp_parse_url($url);
        if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host=strtolower(rtrim($parts['host'],'.'));
        if($method==='woocommerce') return $host===strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $hosts=['paystack'=>['checkout.paystack.com','standard.paystack.co'], 'flutterwave'=>['checkout.flutterwave.com'], 'stripe'=>['checkout.stripe.com']];
        return in_array($host,$hosts[$method]??[],true);
    }
    public static function verify(array $p,string $hint=''): array {
        if($p['status']==='successful') return API::bookingView(Store::get('appointments',(int)$p['appointment_id']));
        $result=self::provider($p['provider'],$p['mode'])->verify($p,$hint);
        if(empty($result['success'])) return API::bookingView(Store::get('appointments',(int)$p['appointment_id']));
        if(($result['reference']??'')!==$p['reference'] || (int)($result['amount']??-1)!==(int)$p['amount'] || strtoupper($result['currency']??'')!==$p['currency']) throw new \RuntimeException('Verified payment does not match this reservation.');
        return self::settle($p,$result['external_id']??$p['external_id']);
    }
    private static function settle(array $payment,string $external): array {
        return Store::atomic(function() use($payment,$external) {
            $p=Store::get('payments',(int)$payment['id']); $b=Store::get('appointments',(int)$p['appointment_id']); if($p['status']==='successful') return API::bookingView($b);
            Store::update('payments',(int)$p['id'],['status'=>'successful','external_id'=>$external,'paid_at'=>Store::now()]);
            $paid=(int)$b['paid']+(int)$p['amount']; $status=$b['status'];
            if(in_array($status,['awaiting_payment','pending'])) {
                // Expired holds must compete for capacity again. Late paid cancellations stay cancelled for administrator review.
                $services=Store::json($b['snapshot'])['services'];
                if(Schedule::check($services,strtotime($b['starts_at'].' UTC'),(int)$b['staff_id'],(int)$b['id'],false)) $status=Store::settings()['manual_approval']?'pending':'confirmed'; else $status='cancelled';
            }
            Store::update('appointments',(int)$b['id'],['paid'=>$paid,'payment_status'=>$paid<(int)$b['total']?'deposit_paid':'paid','status'=>$status,'expires_at'=>null,'updated_at'=>Store::now()]);
            Store::history((int)$b['id'],$status==='cancelled'?'Payment received after availability was lost. Refund or reschedule required.':'Payment verified.');
            if($status==='confirmed') Notifications::enqueue((int)$b['id'],'confirmed');
            if($status==='cancelled') Notifications::enqueue((int)$b['id'],'payment_review');
            return API::bookingView(Store::get('appointments',(int)$b['id']));
        });
    }
    public static function manual(int $id): array { $p=Store::get('payments',$id); if(!$p || !in_array($p['provider'],['bank','pay_later'])) throw new \RuntimeException('Only offline payments can be recorded manually.'); return self::settle($p,$p['reference']); }
    public static function webhook(string $name,string $body,array $headers,?string $mode=null): void {
        $mode=$mode ?: Store::settings()['mode']; if(!in_array($mode,['test','live'],true)) throw new \RuntimeException('Invalid payment mode.');
        $event=self::provider($name,$mode)->webhook($body,$headers);
        // A signed refund, failure or unrelated provider event must never confirm a booking.
        $successEvents=['paystack'=>['charge.success'],'flutterwave'=>['charge.completed','charge.success'],'stripe'=>['checkout.session.completed','checkout.session.async_payment_succeeded']];
        if(!in_array($event['event']??'', $successEvents[$name]??[], true) || empty($event['reference'])) return;
        $key=$name.':'.$event['key']; if(Store::all('payment_events','event_key=%s',[$key])) return;
        $p=Store::all('payments','reference=%s AND provider=%s',[$event['reference'],$name])[0]??null; if(!$p || $p['mode']!==$mode) return;
        // Signed events must refer to the Checkout Session we actually created.
        if($name==='stripe' && ($event['session_id']??'')!==$p['external_id']) return;
        self::verify($p,$event['hint']);
        if(Store::get('payments',(int)$p['id'])['status']==='pending' && in_array($event['event'],['charge.success','charge.completed','checkout.session.completed','checkout.session.async_payment_succeeded'],true)) throw new \RuntimeException('Provider verification is pending. Retry this event.');
        Store::atomic(function() use($key,$p,$event) { if(!Store::all('payment_events','event_key=%s',[$key])) Store::insert('payment_events',['event_key'=>$key,'payment_id'=>$p['id'],'event_type'=>substr($event['event'],0,50),'payload'=>wp_json_encode(['reference'=>$event['reference']]),'created_at'=>Store::now()]); });
    }
    public static function refund(int $id,int $amount,bool $offline=false): void {
        // Commit an intent before contacting a provider: a timeout must never trigger a blind second refund.
        [$p,$eventId]=Store::atomic(function() use($id,$amount,$offline) {
            $p=Store::get('payments',$id); if(!$p || !in_array($p['status'],['successful','partially_refunded']) || $amount<1 || $amount>$p['amount']-$p['refunded']) throw new \RuntimeException('Invalid refundable amount.');
            if(Store::all('payment_events',"payment_id=%d AND event_type IN ('refund_requested','refund_pending','refund_uncertain')",[$id])) throw new \RuntimeException('A refund is already in progress or requires reconciliation.');
            if($offline && !in_array($p['provider'],['bank','pay_later'])) throw new \RuntimeException('Use the gateway refund action for online payments.');
            $eventId=Store::insert('payment_events',['event_key'=>'refund:'.$p['reference'].':'.wp_generate_uuid4(),'payment_id'=>$id,'event_type'=>'refund_requested','payload'=>wp_json_encode(['amount'=>$amount,'provider_id'=>'']),'created_at'=>Store::now()]);
            return [$p,$eventId];
        });
        try { $r=$offline?['complete'=>true,'id'=>wp_generate_uuid4()]:self::provider($p['provider'],$p['mode'])->refund($p,$amount); }
        catch(\Throwable $e) { Store::update('payment_events',$eventId,['event_type'=>'refund_uncertain']); throw new \RuntimeException('Refund response was not conclusive. Check the provider dashboard before reconciliation; no automatic retry was made.'); }
        Store::atomic(function() use($p,$eventId,$r,$amount) { Store::update('payment_events',$eventId,['event_type'=>$r['complete']?'refund_completed':'refund_pending','payload'=>wp_json_encode(['amount'=>$amount,'provider_id'=>$r['id']])]); if($r['complete']) self::applyRefund(Store::get('payments',(int)$p['id']),$amount); });
    }
    public static function reconcileRefund(int $eventId,string $providerId=''): void {
        Store::atomic(function() use($eventId,$providerId) {
            $event=Store::get('payment_events',$eventId); if(!$event || !in_array($event['event_type'],['refund_pending','refund_uncertain','refund_requested'],true)) throw new \RuntimeException('No pending refund found.');
            $p=Store::get('payments',(int)$event['payment_id']); $payload=Store::json($event['payload']);if(empty($payload['provider_id'])) $payload['provider_id']=sanitize_text_field($providerId);if(!$payload['provider_id']) throw new \RuntimeException('Enter the refund ID from the provider dashboard.');
            if(!Payments::provider($p['provider'],$p['mode'])->refundStatus($p,(string)$payload['provider_id'],(int)$payload['amount'])) throw new \RuntimeException('Provider has not confirmed completion yet.');
            self::applyRefund($p,(int)$payload['amount']);Store::update('payment_events',$eventId,['event_type'=>'refund_completed','payload'=>wp_json_encode($payload)]);
        });
    }
    public static function applyRefund(array $p,int $amount): void {
        $refunded=(int)$p['refunded']+$amount; Store::update('payments',(int)$p['id'],['refunded'=>$refunded,'status'=>$refunded===(int)$p['amount']?'refunded':'partially_refunded']);
        $b=Store::get('appointments',(int)$p['appointment_id']); $paid=max(0,(int)$b['paid']-$amount); $update=['paid'=>$paid,'payment_status'=>$paid?'partially_refunded':'refunded']; if(!$paid) $update['status']='refunded'; Store::update('appointments',(int)$b['id'],$update); Store::history((int)$b['id'],'Refund recorded: '.Store::money($amount,$p['currency']));
    }
}
