<?php
/** Google OIDC authorization-code flow. Never accepts an email from the browser as proof. */
final class Decka_Identity {
    public static function init():void {
        foreach(['','nopriv_'] as $prefix){add_action('admin_post_'.$prefix.'decka_google_start',[self::class,'start']);add_action('admin_post_'.$prefix.'decka_google_callback',[self::class,'callback']);}
    }
    public static function secret(string $name):string {return Decka_Stripe::decrypt((string)(Decka_DB::settings()[$name]??''));}
    private static function cookie(string $value,int $expiry):void {setcookie('decka_google_state',$value,['expires'=>$expiry,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);}
    public static function start():void {
        if(!wp_verify_nonce($_GET['_wpnonce']??'','decka_auth'))wp_die('Odśwież stronę logowania.',403);
        $s=Decka_DB::settings();if(empty($s['google_client_id'])||!self::secret('google_client_secret'))wp_die('Logowanie Google nie jest jeszcze skonfigurowane.');
        $state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        set_transient('decka_oidc_'.hash('sha256',$state),['nonce'=>$nonce,'verifier'=>$verifier,'uid'=>get_current_user_id()],600);self::cookie($state,time()+600);
        $params=['client_id'=>$s['google_client_id'],'redirect_uri'=>admin_url('admin-post.php?action=decka_google_callback'),'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account'];
        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));exit;
    }
    public static function verify(string $token,string $client,string $nonce,array $certs,int $now):array {
        $parts=explode('.',$token);if(count($parts)!==3)throw new RuntimeException('Nieprawidłowy token Google.');
        $decode=fn($s)=>base64_decode(strtr($s,'-_','+/'),true);$header=json_decode($decode($parts[0]),true);$claims=json_decode($decode($parts[1]),true);
        if(!is_array($header)||!is_array($claims)||($header['alg']??'')!=='RS256'||!isset($certs[$header['kid']??'']))throw new RuntimeException('Nieprawidłowy podpis Google.');
        if(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),$certs[$header['kid']],OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('Nieprawidłowy podpis Google.');
        if(($claims['aud']??'')!==$client||isset($claims['azp'])&&$claims['azp']!==$client||!in_array($claims['iss']??'',['accounts.google.com','https://accounts.google.com'],true)||($claims['exp']??0)<=$now||($claims['iat']??0)>$now+60||!hash_equals($nonce,(string)($claims['nonce']??''))||empty($claims['sub'])||($claims['email_verified']??false)!==true)throw new RuntimeException('Nie można potwierdzić tożsamości Google.');
        return $claims;
    }
    public static function callback():void {
        try{
            $state=(string)($_GET['state']??'');if(!preg_match('/^[a-f0-9]{64}$/',$state)||!hash_equals($_COOKIE['decka_google_state']??'',$state))throw new RuntimeException('Sesja logowania wygasła. Spróbuj ponownie.');
            $key='decka_oidc_'.hash('sha256',$state);$session=get_transient($key);delete_transient($key);self::cookie('',time()-3600);if(!$session||!empty($_GET['error']))throw new RuntimeException('Logowanie Google nie zostało zakończone.');
            if((int)$session['uid']!==get_current_user_id())throw new RuntimeException('Sesja konta zmieniła się. Rozpocznij ponownie.');
            $s=Decka_DB::settings();$response=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>15,'redirection'=>0,'body'=>['code'=>sanitize_text_field($_GET['code']??''),'client_id'=>$s['google_client_id'],'client_secret'=>self::secret('google_client_secret'),'redirect_uri'=>admin_url('admin-post.php?action=decka_google_callback'),'grant_type'=>'authorization_code','code_verifier'=>$session['verifier']]]);
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Google nie potwierdził logowania.');$tokens=json_decode(wp_remote_retrieve_body($response),true);
            $certs=get_transient('decka_google_certs');if(!$certs){$r=wp_safe_remote_get('https://www.googleapis.com/oauth2/v1/certs',['timeout'=>10,'limit_response_size'=>65536]);if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Nie można pobrać certyfikatów Google.');$certs=json_decode(wp_remote_retrieve_body($r),true);set_transient('decka_google_certs',$certs,900);}
            $claims=self::verify($tokens['id_token']??'',$s['google_client_id'],$session['nonce'],$certs,time());$email=sanitize_email($claims['email']??'');if(!is_email($email))throw new RuntimeException('Brak potwierdzonego e-maila.');
            $map='decka_google_user_'.hash('sha256',$claims['sub']);$linked=(int)get_option($map,0);$uid=(int)$session['uid'];
            if($uid){if($linked&&$linked!==$uid)throw new RuntimeException('To konto Google jest już połączone z innym kontem.');}
            elseif($linked)$uid=$linked;
            else{if(email_exists($email))throw new RuntimeException('Masz już konto. Zaloguj się hasłem i wybierz Połącz Google w Moje bilety.');if(empty($s['registration']))throw new RuntimeException('Rejestracja jest wyłączona.');$uid=wp_insert_user(['user_login'=>'google_'.substr(hash('sha256',$claims['sub']),0,32),'user_email'=>$email,'user_pass'=>wp_generate_password(40,true),'display_name'=>sanitize_text_field($claims['name']??'Kibic'),'role'=>'subscriber']);if(is_wp_error($uid))throw new RuntimeException('Nie można utworzyć konta. Spróbuj ponownie.');}
            if(!$linked&&!add_option($map,$uid,'','no')&&(int)get_option($map)!==$uid)throw new RuntimeException('Nie można połączyć konta Google.');
            if(!get_user_by('id',$uid))throw new RuntimeException('Konto nie istnieje.');wp_set_current_user($uid);wp_set_auth_cookie($uid,true,is_ssl());Decka_DB::audit('google_login',$uid);wp_safe_redirect(home_url('/bilety/'));exit;
        }catch(Throwable $e){wp_die(esc_html($e->getMessage()).' <a href="'.esc_url(home_url('/bilety/')).'">Wróć do biletów</a>','Logowanie Google',['response'=>400]);}
    }
}
