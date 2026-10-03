<?php
/** Google OIDC authorization-code flow. Never accepts an email from the browser as proof. */
final class Decka_Identity {
    public static function init():void {
        add_action('template_redirect',function(){ $path=untrailingslashit(wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH));foreach(['/konto/google/start/'=>'start','/konto/google/powrot/'=>'callback','/konto/google/potwierdz/'=>'confirm'] as $slug=>$method)if($path===untrailingslashit(wp_parse_url(home_url($slug),PHP_URL_PATH)))self::$method(); },-1);
        foreach(['','nopriv_'] as $prefix){add_action('admin_post_'.$prefix.'decka_google_start',[self::class,'start']);add_action('admin_post_'.$prefix.'decka_google_callback',[self::class,'callback']);}
    }
    public static function callback_url():string {return home_url('/konto/google/powrot/');}
    public static function authoritative(array $claims):bool {return ($claims['email_verified']??false)===true&&(str_ends_with(strtolower($claims['email']??''),'@gmail.com')||!empty($claims['hd']));}
    public static function secret(string $name):string {return Decka_Stripe::decrypt((string)(Decka_DB::settings()[$name]??''));}
    private static function cookie(string $value,int $expiry):void {setcookie('decka_google_state',$value,['expires'=>$expiry,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);}
    public static function start():void {
        if(!wp_verify_nonce($_GET['_wpnonce']??'','decka_auth'))wp_die('Odśwież stronę logowania.',403);
        $s=Decka_DB::settings();if(empty($s['google_client_id'])||!self::secret('google_client_secret'))wp_die('Logowanie Google nie jest jeszcze skonfigurowane.');
        $state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        set_transient('decka_oidc_'.hash('sha256',$state),['nonce'=>$nonce,'verifier'=>$verifier,'uid'=>get_current_user_id()],600);self::cookie($state,time()+600);
        $params=['client_id'=>$s['google_client_id'],'redirect_uri'=>self::callback_url(),'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account'];
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
            $s=Decka_DB::settings();$response=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>15,'redirection'=>0,'body'=>['code'=>sanitize_text_field($_GET['code']??''),'client_id'=>$s['google_client_id'],'client_secret'=>self::secret('google_client_secret'),'redirect_uri'=>self::callback_url(),'grant_type'=>'authorization_code','code_verifier'=>$session['verifier']]]);
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Google nie potwierdził logowania.');$tokens=json_decode(wp_remote_retrieve_body($response),true);
            $certs=get_transient('decka_google_certs');if(!$certs){$r=wp_safe_remote_get('https://www.googleapis.com/oauth2/v1/certs',['timeout'=>10,'limit_response_size'=>65536]);if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Nie można pobrać certyfikatów Google.');$certs=json_decode(wp_remote_retrieve_body($r),true);set_transient('decka_google_certs',$certs,900);}
            $claims=self::verify($tokens['id_token']??'',$s['google_client_id'],$session['nonce'],$certs,time());$email=sanitize_email($claims['email']??'');if(!is_email($email))throw new RuntimeException('Brak potwierdzonego e-maila.');
            $uid=self::resolve($claims,(int)$session['uid']);self::login($uid);
        }catch(Throwable $e){wp_die(esc_html($e->getMessage()).' <a href="'.esc_url(home_url('/bilety/')).'">Wróć do biletów</a>','Logowanie Google',['response'=>400]);}
    }
    public static function resolve(array $claims,int $uid=0):int {
        $map='decka_google_user_'.hash('sha256',$claims['sub']);$linked=(int)get_option($map,0);$email=strtolower(sanitize_email($claims['email']??''));
        if($uid){if($linked&&$linked!==$uid)throw new RuntimeException('To konto Google jest już połączone z innym kontem.');}
        elseif($linked)$uid=$linked;
        elseif($existing=email_exists($email)){
            if(!self::authoritative($claims)){self::challenge($claims,(int)$existing);}
            $uid=(int)$existing;
        }else{
            if(empty(Decka_DB::settings()['registration']))throw new RuntimeException('Rejestracja jest wyłączona.');
            $uid=wp_insert_user(['user_login'=>'google_'.substr(hash('sha256',$claims['sub']),0,32),'user_email'=>$email,'user_pass'=>wp_generate_password(40,true),'display_name'=>sanitize_text_field($claims['name']??'Kibic'),'role'=>'subscriber','meta_input'=>['decka_profile_required'=>1,'decka_first_name'=>sanitize_text_field($claims['given_name']??''),'decka_last_name'=>sanitize_text_field($claims['family_name']??'')]]);
            if(is_wp_error($uid))throw new RuntimeException('Nie można utworzyć konta. Spróbuj ponownie.');
        }
        if(!get_user_by('id',$uid))throw new RuntimeException('Konto nie istnieje.');
        if(!$linked&&!add_option($map,$uid,'','no')&&(int)get_option($map)!==$uid)throw new RuntimeException('Nie można połączyć konta Google.');return $uid;
    }
    private static function login(int $uid):void {wp_set_current_user($uid);wp_set_auth_cookie($uid,true,is_ssl());Decka_DB::audit('google_login',$uid);wp_safe_redirect(home_url('/bilety/'));exit;}
    private static function challenge(array $claims,int $uid):void {
        $rate='decka_google_mail_'.$uid;if(get_transient($rate))throw new RuntimeException('Link potwierdzający został już wysłany. Sprawdź e-mail lub spróbuj ponownie za minutę.');
        $token=bin2hex(random_bytes(32));$browser=bin2hex(random_bytes(32));self::cookie($browser,time()+600);
        set_transient('decka_google_confirm_'.hash('sha256',$token),['claims'=>$claims,'uid'=>$uid,'browser'=>hash('sha256',$browser),'email'=>strtolower($claims['email'])],600);set_transient($rate,1,60);
        $url=add_query_arg('token',$token,home_url('/konto/google/potwierdz/'));
        if(!wp_mail($claims['email'],'Decka Pelplin — potwierdź logowanie Google',"Potwierdź jednorazowe połączenie konta Google, otwierając ten link w tej samej przeglądarce. Link wygasa za 10 minut.\n\n".$url))throw new RuntimeException('Nie udało się wysłać potwierdzenia. Spróbuj ponownie.');
        wp_die('Wysłaliśmy link potwierdzający na Twój adres e-mail. Otwórz go w tej samej przeglądarce. Kolejne logowania Google nie będą wymagały tego kroku.','Potwierdź adres e-mail',['response'=>200]);exit;
    }
    public static function confirm():void {
        try{$token=(string)($_GET['token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('Nieprawidłowy link.');$key='decka_google_confirm_'.hash('sha256',$token);$p=get_transient($key);
            if(!$p||!hash_equals($p['browser'],hash('sha256',$_COOKIE['decka_google_state']??'')))throw new RuntimeException('Link wygasł lub otwarto go w innej przeglądarce. Rozpocznij logowanie ponownie.');
            $u=get_user_by('id',(int)$p['uid']);if(!$u||strtolower($u->user_email)!==$p['email'])throw new RuntimeException('Adres konta uległ zmianie. Rozpocznij ponownie.');
            delete_transient($key);self::cookie('',time()-3600);self::login(self::resolve($p['claims'],(int)$p['uid']));
        }catch(Throwable $e){wp_die(esc_html($e->getMessage()).' <a href="'.esc_url(home_url('/bilety/')).'">Wróć do biletów</a>','Logowanie Google',['response'=>400]);}
    }

}
