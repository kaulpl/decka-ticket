<?php
final class Decka_Stripe {
    public static function secret(string $mode,string $kind='secret'):string {
        $constant='DECKA_STRIPE_'.strtoupper($mode).'_'.strtoupper($kind);
        if(defined($constant))return constant($constant);
        $v=Decka_DB::settings()[$mode.'_'.$kind]??'';
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
        if($data)$args['body']=http_build_query($data,'','&');
        $r=wp_remote_request('https://api.stripe.com/v1/'.$path,$args);
        if(is_wp_error($r))throw new RuntimeException('Stripe chwilowo nie odpowiada. Miejsca pozostają zabezpieczone.');
        $body=json_decode(wp_remote_retrieve_body($r),true);
        if(wp_remote_retrieve_response_code($r)>=300 || !is_array($body))throw new RuntimeException('Stripe: nie udało się potwierdzić operacji. Sprawdź ustawienia i dziennik Stripe.');
        return $body;
    }
    public static function create(object $order):array {
        $payload=json_decode($order->stripe_payload,true);
        return self::request($order->mode,'POST','checkout/sessions',$payload,'decka-order-'.$order->mode.'-'.$order->id);
    }
}
