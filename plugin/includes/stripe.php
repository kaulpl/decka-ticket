<?php
final class Decka_Stripe {
    public static function secret(string $mode,string $kind='secret'):string {
        $constant='DECKA_STRIPE_'.strtoupper($mode).'_'.strtoupper($kind);
        if(defined($constant))return constant($constant);
        $v=Decka_DB::settings()[$mode.'_'.$kind]??'';
        return self::decrypt($v);
    }
    public static function decrypt(string $v):string {
        if(!$v)return '';
        $bin=base64_decode($v,true);if($bin===false || strlen($bin)<29)return '';
        return openssl_decrypt(substr($bin,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($bin,0,12),substr($bin,12,16))?:'';
    }
    public static function encrypt(string $value):string {$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Nie można zaszyfrować klucza.');return base64_encode($iv.$tag.$cipher);}
    public static function request(string $mode,string $method,string $path,array $data=[],string $key=''):array {
        $secret=self::secret($mode);if(!$secret)throw new RuntimeException('Uzupełnij klucz Stripe dla środowiska '.$mode.'.');
        if(!str_starts_with($secret,'sk_'.$mode.'_') && !str_starts_with($secret,'rk_'.$mode.'_'))throw new RuntimeException('Klucz Stripe nie pasuje do środowiska.');
        $headers=['Authorization'=>'Bearer '.$secret,'Content-Type'=>'application/x-www-form-urlencoded'];
        if($key)$headers['Idempotency-Key']=$key;
        $args=['method'=>$method,'timeout'=>25,'redirection'=>0,'headers'=>$headers];
        if($data){if($method==='GET')$path.='?'.http_build_query($data,'','&');else $args['body']=http_build_query($data,'','&');}
        $r=wp_remote_request('https://api.stripe.com/v1/'.$path,$args);
        if(is_wp_error($r))throw new RuntimeException('Stripe chwilowo nie odpowiada. Miejsca pozostają zabezpieczone.');
        $body=json_decode(wp_remote_retrieve_body($r),true);
        if(wp_remote_retrieve_response_code($r)>=300 || !is_array($body))throw new RuntimeException('Stripe: nie udało się potwierdzić operacji. Sprawdź ustawienia i dziennik Stripe.');
        return $body;
    }
    public static function health():array {
        $mode=Decka_DB::mode();$result=['mode'=>$mode,'checked_at'=>gmdate('c'),'ok'=>false,'checks'=>[]];
        try{
            if(!self::secret($mode)||!str_starts_with(self::secret($mode,'webhook'),'whsec_'))throw new RuntimeException('Uzupełnij klucz API i sekret webhook w aktywnym środowisku.');
            $balance=self::request($mode,'GET','balance');if(!isset($balance['livemode'])||(bool)$balance['livemode']!==($mode==='live'))throw new RuntimeException('Klucz odpowiada innemu środowisku Stripe.');
            $result['checks'][]=['ok'=>true,'label'=>'Klucz API i środowisko'];
            $list=self::request($mode,'GET','webhook_endpoints',['limit'=>100]);$found=false;
            $required=['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired','charge.refunded','charge.dispute.created'];
            foreach($list['data']??[] as $endpoint)if(($endpoint['url']??'')===rest_url('decka/v1/webhook/'.$mode)&&($endpoint['status']??'')==='enabled'&&(in_array('*',$endpoint['enabled_events']??[],true)||!array_diff($required,$endpoint['enabled_events']??[])))$found=true;
            if(!$found)throw new RuntimeException('Nie znaleziono aktywnego webhooka z wymaganymi zdarzeniami. Sprawdź adres i zdarzenia w Stripe.');
            $result['checks'][]=['ok'=>true,'label'=>'Adres webhooka i wymagane zdarzenia'];
            $last=get_option('decka_stripe_webhook_'.$mode,[]);$verified=!empty($last['fingerprint'])&&hash_equals(hash('sha256',self::secret($mode,'webhook')),$last['fingerprint']);
            $result['ok']=true;$result['message']='Połączenie i konfiguracja poprawne.';$result['webhook_verified']=$verified;$result['webhook_message']=$verified?'Odebrano webhook z poprawnym podpisem: '.$last['time']:'Sekret podpisu czeka na potwierdzenie: wyślij zdarzenie testowe ze Stripe lub wykonaj płatność. Samo sprawdzenie nie tworzy płatności.';
        }catch(Throwable $e){$result['message']=$e->getMessage();}
        update_option('decka_stripe_health_'.$mode,$result,false);return $result;
    }
    public static function create(object $order):array {
        $payload=json_decode($order->stripe_payload,true);
        return self::request($order->mode,'POST','checkout/sessions',$payload,'decka-order-'.$order->mode.'-'.$order->id);
    }
}
