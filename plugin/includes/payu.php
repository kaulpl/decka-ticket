<?php
/** PayU Europe hosted checkout. Never trusts the browser return as proof of payment. */
final class Decka_Payu {
    public static function host(string $mode):string {if(!in_array($mode,['test','live'],true))throw new RuntimeException('Nieprawidłowy tryb PayU.');return $mode==='live'?'https://secure.payu.com':'https://secure.snd.payu.com';}
    public static function config(string $mode):array {$s=Decka_DB::settings();$out=[];foreach(['pos_id','client_id','client_secret','second_key'] as $key){$v=(string)($s['payu_'.$mode.'_'.$key]??'');$out[$key]=in_array($key,['client_secret','second_key'],true)?Decka_Stripe::decrypt($v):$v;}return $out;}
    public static function ready(string $mode):bool {return !in_array('',self::config($mode),true);}
    private static function token(string $mode):string {
        $c=self::config($mode);if(!self::ready($mode))throw new RuntimeException('Uzupełnij dane PayU dla wybranego środowiska.');$cache='decka_payu_token_'.hash('sha256',$mode.wp_json_encode($c));$saved=get_transient($cache);if($saved&&($token=Decka_Stripe::decrypt((string)$saved)))return $token;
        $r=wp_remote_request(self::host($mode).'/pl/standard/user/oauth/authorize',['method'=>'POST','timeout'=>20,'redirection'=>0,'headers'=>['Content-Type'=>'application/x-www-form-urlencoded'],'body'=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$c['client_id'],'client_secret'=>$c['client_secret']])]);
        if(is_wp_error($r))throw new RuntimeException('PayU nie odpowiada. Spróbuj ponownie.');$d=json_decode(wp_remote_retrieve_body($r),true);if(wp_remote_retrieve_response_code($r)!==200||empty($d['access_token']))throw new RuntimeException('PayU odrzuciło uwierzytelnienie. Sprawdź Client ID, Client secret i środowisko.');
        set_transient($cache,Decka_Stripe::encrypt($d['access_token']),max(1,min(3000,(int)($d['expires_in']??300)-30)));return $d['access_token'];
    }
    public static function request(string $mode,string $method,string $path,array $body=[]):array {
        $args=['method'=>$method,'timeout'=>25,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.self::token($mode),'Content-Type'=>'application/json']];if($body)$args['body']=wp_json_encode($body);
        $r=wp_remote_request(self::host($mode).'/api/v2_1/'.$path,$args);if(is_wp_error($r))throw new RuntimeException('PayU chwilowo nie odpowiada. Miejsca pozostają zabezpieczone.');$d=json_decode(wp_remote_retrieve_body($r),true);$code=wp_remote_retrieve_response_code($r);
        if(!is_array($d)||!in_array($code,[200,201,302],true)||isset($d['status']['statusCode'])&&!in_array($d['status']['statusCode'],['SUCCESS','WARNING_CONTINUE_REDIRECT'],true))throw new RuntimeException('PayU nie potwierdziło operacji (HTTP '.$code.'). Sprawdź zamówienie w panelu PayU.');return $d;
    }
    public static function payload(object $o,string $description):array {
        $c=self::config($o->mode);return ['provider'=>'payu','request'=>['notifyUrl'=>rest_url('decka/v1/payu/webhook/'.$o->mode),'continueUrl'=>home_url('/bilety/?order='.$o->id),'customerIp'=>filter_var($_SERVER['REMOTE_ADDR']??'',FILTER_VALIDATE_IP)?:'127.0.0.1','merchantPosId'=>$c['pos_id'],'description'=>$description,'currencyCode'=>'PLN','totalAmount'=>(string)$o->total,'extOrderId'=>'decka-'.$o->mode.'-'.$o->id.'-'.substr(hash('sha256',home_url().'|'.$o->request_key),0,24),'validityTime'=>'600','buyer'=>array_filter(['email'=>$o->email,'firstName'=>$o->first_name??'','lastName'=>$o->last_name??'','phone'=>$o->phone??'','language'=>'pl']),'products'=>[['name'=>$description,'unitPrice'=>(string)$o->total,'quantity'=>'1','virtual'=>true]]]];
    }
    public static function create(object $o):array {
        $payload=json_decode($o->stripe_payload,true);$payload['request']['validityTime']=(string)max(1,min(600,strtotime($o->created_at.' UTC')+600-time()));$d=self::request($o->mode,'POST','orders',$payload['request']);$id=(string)($d['orderId']??'');$url=(string)($d['redirectUri']??'');
        if(!$id||!preg_match('/^[A-Za-z0-9_-]{1,150}$/',$id)||wp_parse_url($url,PHP_URL_SCHEME)!=='https'||wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(self::host($o->mode),PHP_URL_HOST))throw new RuntimeException('PayU nie zwróciło poprawnego odnośnika do płatności.');
        return ['id'=>'payu_'.$id,'url'=>$url,'metadata'=>['decka_order'=>(string)$o->id]];
    }
    public static function normalize(object $o,array $payment):array {
        $payload=json_decode($o->stripe_payload,true);$expected=$payload['request']??[];$id=(string)($payment['orderId']??'');
        if(Decka_Service::provider($o)!=='payu'||!$id||($payment['extOrderId']??'')!==($expected['extOrderId']??null)||(string)($payment['merchantPosId']??'')!==(string)($expected['merchantPosId']??null)||($payment['currencyCode']??'')!=='PLN'||!preg_match('/^\d+$/',(string)($payment['totalAmount']??''))||(int)$payment['totalAmount']!==(int)$o->total||($o->session_id&&$o->session_id!=='payu_'.$id))throw new RuntimeException('Płatność PayU nie pasuje do zamówienia.');
        return ['id'=>'payu_'.$id,'metadata'=>['decka_order'=>(string)$o->id],'client_reference_id'=>(string)$o->id,'livemode'=>$o->mode==='live','amount_total'=>(int)$payment['totalAmount'],'currency'=>'pln','payment_intent'=>'payu_'.$id,'payment_status'=>($payment['status']??'')==='COMPLETED'?'paid':'unpaid','status'=>($payment['status']??'')==='CANCELED'?'expired':'open'];
    }
    public static function retrieve(object $o,string $id=''):array {$id=$id?:substr((string)$o->session_id,5);if(!preg_match('/^[A-Za-z0-9_-]{1,150}$/',$id))throw new RuntimeException('Nieprawidłowy identyfikator PayU.');$d=self::request($o->mode,'GET','orders/'.rawurlencode($id));$p=$d['orders'][0]??[];if(($p['orderId']??'')!==$id)throw new RuntimeException('PayU zwróciło inne zamówienie.');self::normalize($o,$p);return $p;}
    public static function refresh(object $o,bool $cancel=false):void {
        $p=self::retrieve($o);if(($cancel||strtotime($o->created_at.' UTC')+600<=time())&&in_array($p['status']??'',['NEW','PENDING','WAITING_FOR_CONFIRMATION'],true)){
            try{self::request($o->mode,'DELETE','orders/'.rawurlencode($p['orderId']));}catch(Throwable $e){$latest=self::retrieve($o);if(!in_array($latest['status']??'',['COMPLETED','CANCELED'],true))throw $e;}
            $p=self::retrieve($o);
        }
        Decka_Service::settle(self::normalize($o,$p),$o->mode,'payu');
    }
    public static function signature(string $raw,string $header,string $key):bool {
        if(!$key)return false;$parts=[];foreach(explode(';',$header) as $part){$v=explode('=',trim($part),2);if(count($v)===2){if(isset($parts[$v[0]]))return false;$parts[$v[0]]=$v[1];}}
        return ($parts['algorithm']??'')==='MD5'&&preg_match('/^[a-f0-9]{32}$/i',$parts['signature']??'')&&hash_equals(md5($raw.$key),strtolower($parts['signature']));
    }
    public static function webhook(string $mode,string $raw,string $signature):array {
        $c=self::config($mode);if(!self::signature($raw,$signature,$c['second_key']))throw new RuntimeException('Nieprawidłowy podpis PayU.');$body=json_decode($raw,true);$p=$body['order']??(!empty($body['refund'])?$body:[]);
        if(!preg_match('/^decka-(test|live)-(\d+)-[a-f0-9]{24}$/',$p['extOrderId']??'',$m)||$m[1]!==$mode)throw new RuntimeException('Nieprawidłowe przypisanie PayU.');$o=Decka_Service::order((int)$m[2]);if(!$o||$o->mode!==$mode)throw new RuntimeException('Nie znaleziono zamówienia PayU.');$expected=json_decode($o->stripe_payload,true)['request']??[];if(($p['extOrderId']??'')!==($expected['extOrderId']??null))throw new RuntimeException('Nieprawidłowy identyfikator powiadomienia PayU.');$current=self::retrieve($o,(string)($p['orderId']??''));
        // A finalized refund can precede a delayed COMPLETED notification. Revocation wins.
        if(!empty($body['refund']['refundId'])){$ref=self::request($mode,'GET','orders/'.rawurlencode($p['orderId']).'/refunds/'.rawurlencode((string)$body['refund']['refundId']));if(($ref['refundId']??'')!==(string)$body['refund']['refundId']||($ref['currencyCode']??'')!=='PLN')throw new RuntimeException('Zwrot PayU nie pasuje do powiadomienia.');if(($ref['status']??'')==='FINALIZED'&&(int)($ref['amount']??0)>0)Decka_Service::revoke_payment($mode,'payu_'.$p['orderId'],(int)$o->id,'payu');}
        Decka_Service::settle(self::normalize($o,$current),$mode,'payu');update_option('decka_payu_webhook_'.$mode,gmdate('c'),false);return ['received'=>true];
    }
    public static function health():array {$mode=Decka_DB::mode();try{self::token($mode);return ['ok'=>true,'message'=>'Połączenie OAuth z PayU działa. Podpis powiadomień i płatność sprawdź transakcją testową.','checked_at'=>gmdate('c'),'mode'=>$mode];}catch(Throwable $e){return ['ok'=>false,'message'=>$e->getMessage(),'checked_at'=>gmdate('c'),'mode'=>$mode];}}
}
