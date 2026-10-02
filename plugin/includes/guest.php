<?php
/** Guest access is bound to an opaque HttpOnly browser cookie, never to an email or order ID. */
final class Decka_Guest {
    private const COOKIE='decka_guest';
    public static function hash():string {$v=$_COOKIE[self::COOKIE]??'';return preg_match('/^[a-f0-9]{64}$/',$v)?hash_hmac('sha256',$v,wp_salt('auth')):'';}
    public static function init():void {if(self::hash()||is_user_logged_in())return;$v=bin2hex(random_bytes(32));setcookie(self::COOKIE,$v,['expires'=>time()+30*DAY_IN_SECONDS,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);$_COOKIE[self::COOKIE]=$v;}
    public static function clear():void {setcookie(self::COOKIE,'',['expires'=>time()-3600,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE[self::COOKIE]);}
    public static function owns(object $order):bool {return get_current_user_id()?(int)$order->user_id===get_current_user_id():((int)$order->user_id===0&&self::hash()&&!empty($order->guest_hash)&&hash_equals($order->guest_hash,self::hash()));}
    public static function checkout(WP_REST_Request $r):array {
        if(is_user_logged_in())return Decka_Service::create($r->get_json_params()?:[]);
        $a=wp_parse_url($r->get_header('origin'));$b=wp_parse_url(home_url());
        if(!$a||($a['scheme']??'')!==($b['scheme']??'')||strtolower($a['host']??'')!==strtolower($b['host']??'')||($a['port']??null)!==($b['port']??null)||!self::hash()||!wp_verify_nonce((string)$r->get_param('auth_nonce'),'decka_auth'))throw new RuntimeException('Odśwież stronę zakupu i spróbuj ponownie.');
        $key='decka_guest_rate_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'',wp_salt());$n=(int)get_transient($key);if($n>=30)throw new RuntimeException('Zbyt wiele prób zakupu. Spróbuj za 15 minut.');set_transient($key,$n+1,900);
        return Decka_Service::create($r->get_json_params()?:[],false,self::hash());
    }
}
