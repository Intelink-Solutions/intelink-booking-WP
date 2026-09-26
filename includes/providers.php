<?php
namespace Intelink;
defined('ABSPATH') || exit;
interface Provider {
    public function initialize(array $payment,array $booking,string $return): array;
    public function verify(array $payment, string $hint=''): array;
    public function webhook(string $body,array $headers): array;
    public function refund(array $payment,int $amount): array;
    public function refundStatus(array $payment,string $refundId,int $amount): bool;
}
abstract class RemoteProvider implements Provider {
    protected string $name;
    protected string $mode;
    public function __construct(?string $mode=null) { $this->mode=$mode ?: Store::settings()['mode']; }
    protected function key(string $suffix='secret'): string { $s=Store::settings(); return trim((string)($s[$this->name.'_'.$this->mode.'_'.$suffix]??'')); }
    protected function request(string $url,array $data=[],string $method='POST',bool $form=false,array $extra=[]): array {
        $args=['method'=>$method,'timeout'=>30,'redirection'=>0,'sslverify'=>true,'headers'=>array_merge(['Authorization'=>'Bearer '.$this->key(),'Content-Type'=>$form?'application/x-www-form-urlencoded':'application/json'],$extra)];
        if ($method!=='GET') $args['body']=$form?http_build_query($data):wp_json_encode($data);
        $r=wp_remote_request($url,$args); if (is_wp_error($r)) throw new \RuntimeException('Payment provider could not be reached. Retry shortly.');
        $d=json_decode(wp_remote_retrieve_body($r),true); if (wp_remote_retrieve_response_code($r)<200 || wp_remote_retrieve_response_code($r)>=300 || !is_array($d)) throw new \RuntimeException('Payment provider rejected this request. Check gateway configuration.'); return $d;
    }
    protected function major(array $p): float { return $p['amount']/10**Store::digits($p['currency']); }
    public function refundStatus(array $p,string $refundId,int $amount): bool { throw new \RuntimeException('Refund status lookup is unavailable.'); }
    public function refund(array $p,int $amount): array { throw new \RuntimeException('Refund this transaction in the provider dashboard and reconcile it here.'); }
}
final class Paystack extends RemoteProvider {
    public function refundStatus(array $p,string $refundId,int $amount): bool { $r=$this->request('https://api.paystack.co/refund/'.rawurlencode($refundId),[],'GET'); $d=$r['data']??[];$transaction=is_array($d['transaction']??null)?($d['transaction']['id']??''):($d['transaction']??''); return ($d['status']??'')==='processed' && (string)$transaction===$p['external_id'] && (int)($d['amount']??-1)===$amount; }
    protected string $name='paystack';
    public function initialize(array $p,array $b,string $return): array {
        $r=$this->request('https://api.paystack.co/transaction/initialize',['email'=>$b['customer']['email'],'amount'=>$p['amount'],'currency'=>$p['currency'],'reference'=>$p['reference'],'callback_url'=>$return]);
        if (empty($r['status']) || empty($r['data']['authorization_url'])) throw new \RuntimeException('Paystack initialization failed.');
        return ['external_id'=>$p['reference'],'checkout_url'=>$r['data']['authorization_url']];
    }
    public function verify(array $p,string $hint=''): array {
        $r=$this->request('https://api.paystack.co/transaction/verify/'.rawurlencode($p['reference']),[],'GET')['data']??[];
        return ['success'=>($r['status']??'')==='success' && ($r['domain']??$this->mode)===$this->mode,'state'=>in_array($r['status']??'',['failed','abandoned'],true)?'failed':'pending','reference'=>$r['reference']??'','amount'=>(int)($r['amount']??0),'currency'=>$r['currency']??'','external_id'=>(string)($r['id']??'')];
    }
    public function webhook(string $body,array $h): array {
        if (!$this->key() || !hash_equals(hash_hmac('sha512',$body,$this->key()),$h['x-paystack-signature']??'')) throw new \RuntimeException('Invalid signature.');
        $d=json_decode($body,true); if(!is_array($d)) throw new \RuntimeException('Invalid webhook payload.'); return ['event'=>(string)($d['event']??''),'reference'=>$d['data']['reference']??'','hint'=>(string)($d['data']['id']??''),'key'=>hash('sha256',$body)];
    }
    public function refund(array $p,int $amount): array { $r=$this->request('https://api.paystack.co/refund',['transaction'=>$p['external_id'],'amount'=>$amount]); return ['complete'=>($r['data']['status']??'')==='processed','id'=>(string)($r['data']['id']??'')]; }
}
final class Flutterwave extends RemoteProvider {
    public function refundStatus(array $p,string $refundId,int $amount): bool { $r=$this->request('https://api.flutterwave.com/v3/refunds/'.rawurlencode($refundId),[],'GET'); $d=$r['data']??[]; return in_array($d['status']??'',['completed-bank-transfer','completed-momo','completed-mpgs','completed-offline','completed-preauth'],true) && (string)($d['tx_id']??$d['TransactionId']??'')===$p['external_id'] && (int)round((float)($d['amount_refunded']??$d['AmountRefunded']??-1)*10**Store::digits($p['currency']))===$amount; }
    protected string $name='flutterwave';
    public function initialize(array $p,array $b,string $return): array {
        $r=$this->request('https://api.flutterwave.com/v3/payments',['tx_ref'=>$p['reference'],'amount'=>$this->major($p),'currency'=>$p['currency'],'redirect_url'=>$return,'customer'=>['email'=>$b['customer']['email'],'name'=>$b['customer']['first_name'].' '.$b['customer']['last_name']],'customizations'=>['title'=>Store::settings()['business_name']]]);
        if (empty($r['data']['link'])) throw new \RuntimeException('Flutterwave initialization failed.'); return ['external_id'=>$p['reference'],'checkout_url'=>$r['data']['link']];
    }
    public function verify(array $p,string $hint=''): array {
        $url=$hint && ctype_digit($hint)?'https://api.flutterwave.com/v3/transactions/'.$hint.'/verify':'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref='.rawurlencode($p['reference']);
        $r=$this->request($url,[],'GET')['data']??[]; return ['success'=>($r['status']??'')==='successful','state'=>($r['status']??'')==='failed'?'failed':'pending','reference'=>$r['tx_ref']??'','amount'=>(int)round((float)($r['amount']??0)*10**Store::digits($p['currency'])),'currency'=>$r['currency']??'','external_id'=>(string)($r['id']??'')];
    }
    public function webhook(string $body,array $h): array {
        $secret=$this->key('webhook'); $expected=base64_encode(hash_hmac('sha256',$body,$secret,true));
        if (!$secret || !(hash_equals($secret,$h['verif-hash']??'') || hash_equals($expected,$h['flutterwave-signature']??''))) throw new \RuntimeException('Invalid signature.');
        $d=json_decode($body,true); if(!is_array($d)) throw new \RuntimeException('Invalid webhook payload.'); return ['event'=>$d['event']??$d['type']??'','reference'=>$d['data']['tx_ref']??'','hint'=>(string)($d['data']['id']??''),'key'=>hash('sha256',$body)];
    }
    public function refund(array $p,int $amount): array { $r=$this->request('https://api.flutterwave.com/v3/transactions/'.rawurlencode($p['external_id']).'/refund',['amount'=>$amount/10**Store::digits($p['currency'])]); return ['complete'=>in_array($r['data']['status']??'',['completed-bank-transfer','completed-momo','completed-mpgs','completed-offline','completed-preauth'],true),'id'=>(string)($r['data']['id']??'')]; }
}
final class Stripe extends RemoteProvider {
    public function refundStatus(array $p,string $refundId,int $amount): bool { $r=$this->request('https://api.stripe.com/v1/refunds/'.rawurlencode($refundId),[],'GET'); $session=$this->request('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($p['external_id']),[],'GET');return ($r['status']??'')==='succeeded' && ($r['payment_intent']??'')===($session['payment_intent']??null) && (int)($r['amount']??-1)===$amount; }
    protected string $name='stripe';
    public function initialize(array $p,array $b,string $return): array {
        $r=$this->request('https://api.stripe.com/v1/checkout/sessions',['mode'=>'payment','success_url'=>$return,'cancel_url'=>$return,'customer_email'=>$b['customer']['email'],'client_reference_id'=>$p['reference'],'metadata'=>['ib_reference'=>$p['reference']],'line_items'=>[['price_data'=>['currency'=>strtolower($p['currency']),'unit_amount'=>$p['amount'],'product_data'=>['name'=>'Appointment '.$b['reference']]],'quantity'=>1]]], 'POST',true,['Idempotency-Key'=>$p['reference']]);
        return ['external_id'=>$r['id'],'checkout_url'=>$r['url']];
    }
    public function verify(array $p,string $hint=''): array {
        if (!str_starts_with($p['external_id'],'cs_')) return ['success'=>false];
        $r=$this->request('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($p['external_id']),[],'GET');
        // Re-fetch from Stripe instead of trusting redirects or webhook payloads.
        // A test-mode Session must never settle a live-mode reservation (or vice versa).
        $modeMatches=isset($r['livemode']) && (bool)$r['livemode']===($p['mode']==='live');
        $sessionMatches=($r['id']??'')===$p['external_id']
            && ($r['client_reference_id']??'')===$p['reference']
            && ($r['metadata']['ib_reference']??'')===$p['reference']
            && ($r['mode']??'')==='payment';
        return ['success'=>$modeMatches && $sessionMatches && ($r['payment_status']??'')==='paid',
            'state'=>($r['status']??'')==='expired'?'cancelled':'pending',
            'reference'=>$r['client_reference_id']??'',
            'amount'=>(int)($r['amount_total']??0),
            'currency'=>strtoupper($r['currency']??''),
            'external_id'=>$p['external_id']];
    }
    public function webhook(string $body,array $h): array {
        $parts=[]; foreach(explode(',',$h['stripe-signature']??'') as $part) { $kv=explode('=',$part,2); if(count($kv)===2) $parts[$kv[0]][]=$kv[1]; }
        $time=(int)($parts['t'][0]??0); $secret=$this->key('webhook'); $expected=hash_hmac('sha256',$time.'.'.$body,$secret); $ok=false; foreach($parts['v1']??[] as $sig) if(hash_equals($expected,$sig)) $ok=true;
        if (!$secret || abs(time()-$time)>300 || !$ok) throw new \RuntimeException('Invalid signature.');
        $d=json_decode($body,true); if(!is_array($d)) throw new \RuntimeException('Invalid webhook payload.');
        $session=$d['data']['object']??[];
        // Only Checkout Session events can affect a booking; settlement still requires
        // a fresh server-side lookup of our stored Session, amount, and currency.
        $validSession=is_array($session) && ($session['object']??'')==='checkout.session'
            && str_starts_with((string)($session['id']??''),'cs_');
        return ['event'=>$d['type']??'',
            'reference'=>$validSession?($session['client_reference_id']??''):'',
            'hint'=>'','key'=>$d['id']??hash('sha256',$body),
            'session_id'=>$validSession?($session['id']??''):''];
    }
    public function refund(array $p,int $amount): array {
        $s=$this->request('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($p['external_id']),[],'GET');
        $r=$this->request('https://api.stripe.com/v1/refunds',['payment_intent'=>$s['payment_intent'],'amount'=>$amount],'POST',true,['Idempotency-Key'=>'refund-'.$p['reference'].'-'.$p['refunded'].'-'.$amount]); return ['complete'=>($r['status']??'')==='succeeded','id'=>$r['id']??''];
    }
}
abstract class OfflineProvider implements Provider {
    public function refundStatus(array $p,string $refundId,int $amount): bool { throw new \RuntimeException('Offline refunds require administrator reconciliation.'); }
    public function initialize(array $p,array $b,string $return): array { return ['external_id'=>$p['reference'],'checkout_url'=>'']; }
    public function verify(array $p,string $hint=''): array { return ['success'=>false]; }
    public function webhook(string $body,array $headers): array { throw new \RuntimeException('This method has no webhook.'); }
    public function refund(array $p,int $amount): array { throw new \RuntimeException('Record the refund after returning funds outside the plugin.'); }
}
final class BankTransfer extends OfflineProvider {}
final class PayLater extends OfflineProvider {}
final class FreeBooking extends OfflineProvider {}
final class WooCommerce extends OfflineProvider {
    public function refundStatus(array $p,string $refundId,int $amount): bool { $r=function_exists('wc_get_order')?wc_get_order((int)$refundId):null;return $r instanceof \WC_Order_Refund && (int)$r->get_parent_id()===(int)$p['external_id'] && (int)round((float)$r->get_amount()*10**Store::digits($p['currency']))===$amount; }
    public function initialize(array $p,array $b,string $return): array {
        if (!function_exists('wc_create_order')) throw new \RuntimeException('WooCommerce is not active.');
        $existing=wc_get_orders(['limit'=>1,'meta_key'=>'_ib_reference','meta_value'=>$p['reference']]);
        $order=$existing ? $existing[0] : wc_create_order();
        if(is_wp_error($order) || !$order) throw new \RuntimeException('WooCommerce could not create the payment order.');
        if (!$existing) {
            $order->set_currency($p['currency']); $order->set_billing_email($b['customer']['email']); $order->set_billing_first_name($b['customer']['first_name']); $order->set_billing_last_name($b['customer']['last_name']);
            $item=new \WC_Order_Item_Fee(); $item->set_name('Appointment '.$b['reference']); $item->set_amount($p['amount']/10**Store::digits($p['currency'])); $item->set_total($p['amount']/10**Store::digits($p['currency'])); $item->set_tax_status('none'); $order->add_item($item);
            $order->update_meta_data('_ib_reference',$p['reference']); $order->update_meta_data('_ib_return',$return); $order->calculate_totals(false); $order->save();
        } return ['external_id'=>(string)$order->get_id(),'checkout_url'=>$order->get_checkout_payment_url()];
    }
    public function verify(array $p,string $hint=''): array { $o=function_exists('wc_get_order')?wc_get_order((int)$p['external_id']):null; return ['success'=>$o && $o->is_paid(),'reference'=>$o?$o->get_meta('_ib_reference'):'','currency'=>$o?$o->get_currency():'','amount'=>$o?(int)round((float)$o->get_total()*10**Store::digits($p['currency'])):0,'external_id'=>$p['external_id']]; }
    public function refund(array $p,int $amount): array { if (!function_exists('wc_create_refund')) throw new \RuntimeException('WooCommerce is unavailable.'); $r=wc_create_refund(['order_id'=>(int)$p['external_id'],'amount'=>$amount/10**Store::digits($p['currency']),'reason'=>'Appointment refund','refund_payment'=>true]); if(is_wp_error($r)) throw new \RuntimeException($r->get_error_message()); return ['complete'=>true,'id'=>(string)$r->get_id()]; }
}
