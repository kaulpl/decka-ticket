<?php
final class Decka_API {
    private static function route(string $path,string $method,callable $fn,callable $permission):void {
        register_rest_route('decka/v1',$path,['methods'=>$method,'permission_callback'=>$permission,'callback'=>function($r)use($fn,$path){try{if(!in_array($path??'',['/login','/register','/logout'],true)&&!Decka_DB::storage_ready())return new WP_Error('decka_maintenance','Sprzedaż biletów jest chwilowo niedostępna z powodu aktualizacji systemu. Spróbuj ponownie później.',['status'=>503]);return new WP_REST_Response($fn($r),200,['Cache-Control'=>'no-store, private']);}catch(Decka_Scan_Error $e){return new WP_REST_Response(['valid'=>false,'status'=>$e->status,'message'=>$e->getMessage()],200,['Cache-Control'=>'no-store, private']);}catch(Throwable $e){return new WP_Error('decka_error',$e->getMessage(),['status'=>400]);}}]);
    }
    public static function register():void {
        $public=fn()=>true;$user=fn()=>is_user_logged_in();$gate=fn()=>current_user_can('decka_scan');$admin=fn()=>current_user_can('decka_manage');
        self::route('/catalog','GET',fn()=>self::catalog(),$public);
        self::route('/availability','GET',fn($r)=>self::availability((string)$r->get_param('events')),$public);
        self::route('/login','POST',fn($r)=>self::auth($r,false),$public);
        self::route('/register','POST',fn($r)=>self::auth($r,true),$public);
        self::route('/profile','POST',function($r){Decka_Profile::save(get_current_user_id(),$r->get_json_params()?:[]);return self::config();},$user);
        self::route('/logout','POST',function(){wp_logout();Decka_Guest::clear();return ['ok'=>true];},$user);
        self::route('/quote','POST',fn($r)=>Decka_Service::quote($r->get_json_params()?:[]),$public);
        self::route('/checkout','POST',fn($r)=>Decka_Guest::checkout($r),$public);
        self::route('/orders','GET',fn()=>self::orders(),fn()=>is_user_logged_in()||(bool)Decka_Guest::hash());
        self::route('/orders/(?P<id>\d+)/download','POST',function($r){$id=(int)$r['id'];$o=Decka_Service::order($id);if(!$o||!Decka_Guest::owns($o)||!in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Bilet nie jest jeszcze dostępny.');return ['url'=>Decka_Tickets::download_link($id)];},fn()=>is_user_logged_in()||(bool)Decka_Guest::hash());
        self::route('/orders/(?P<id>\d+)/cancel','POST',function($r){$o=Decka_Service::order((int)$r['id']);if(!$o||!Decka_Guest::owns($o))throw new RuntimeException('Brak dostępu.');if($o->status==='pending'&&$o->session_id){if(Decka_Service::provider($o)==='payu'){Decka_Payu::refresh($o,true);return ['ok'=>true,'status'=>Decka_Service::order((int)$o->id)->status];}Decka_Stripe::request($o->mode,'POST','checkout/sessions/'.rawurlencode($o->session_id).'/expire');Decka_Service::settle(Decka_Stripe::request($o->mode,'GET','checkout/sessions/'.rawurlencode($o->session_id)),$o->mode);}return ['ok'=>true];},fn()=>is_user_logged_in()||(bool)Decka_Guest::hash());
        self::route('/gate/events','GET',fn()=>self::gate_events(),$gate);
        self::route('/gate/scan','POST',function($r){try{return Decka_Tickets::scan((string)$r->get_param('token'),(int)$r->get_param('event_id'));}catch(Throwable $e){Decka_DB::audit('scan_rejected',(int)$r->get_param('event_id'),wp_json_encode(['mode'=>Decka_DB::mode(),'reason'=>$e->getMessage()]));throw $e;}},$gate);
        self::route('/admin/voucher','POST',fn($r)=>Decka_Service::create($r->get_json_params()?:[],true),$admin);
        self::route('/payu/webhook/(?P<mode>test|live)','POST',fn($r)=>Decka_Payu::webhook($r['mode'],$r->get_body(),(string)($r->get_header('openpayu-signature')?:$r->get_header('x-openpayu-signature'))),$public);
        self::route('/webhook/(?P<mode>test|live)','POST',fn($r)=>self::webhook($r),$public);
    }
    public static function gate_events():array {
        global $wpdb;$now=gmdate('Y-m-d H:i:s');
        // Match chronology, never gate_open, determines the default. Include today's
        // ongoing admission and every dated future match, even without gate settings.
        $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/Warsaw')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $events=$wpdb->get_results($wpdb->prepare('SELECT id,opponent,starts_at,gate_open,gate_close FROM '.Decka_DB::table('events').' WHERE cancelled=0 AND starts_at IS NOT NULL AND (starts_at>=%s OR (starts_at>=%s AND gate_close>=%s)) ORDER BY starts_at,id',$now,$today,$now));
        foreach($events as $e){$counts=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) total,COALESCE(SUM(t.used_at IS NOT NULL),0) scanned FROM '.Decka_DB::table('tickets').' t JOIN '.Decka_DB::table('orders').' o ON o.id=t.order_id WHERE t.event_id=%d AND t.status="valid" AND o.mode=%s AND o.status IN ("paid","voucher","free")',$e->id,Decka_DB::mode()),ARRAY_A);$e->total=(int)$counts['total'];$e->scanned=(int)$counts['scanned'];$e->entry_open=(bool)($e->gate_open&&$e->gate_close&&$e->gate_open<=$now&&$e->gate_close>=$now);}
        return $events;
    }
    public static function catalog():array {
        global $wpdb;$s=Decka_DB::settings();$now=gmdate('Y-m-d H:i:s');
        $catalog=['mode'=>Decka_DB::mode(),'normal'=>(int)($s['normal']??2500),'reduced'=>(int)($s['reduced']??1500),'seats'=>Decka_DB::seats(),'events'=>$wpdb->get_results($wpdb->prepare('SELECT id,opponent,starts_at,date_label,venue,sale_open,normal_price,reduced_price,image_id FROM '.Decka_DB::table('events').' WHERE cancelled=0 AND (starts_at>%s OR starts_at IS NULL) ORDER BY starts_at',$now)),'offers'=>$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Decka_DB::table('offers').' WHERE active=1 AND (starts_at IS NULL OR starts_at<=%s) AND (ends_at IS NULL OR ends_at>=%s)',$now,$now)),'registration'=>!empty($s['registration']),'max_per_fan'=>(int)($s['max_per_fan']??10)];foreach($catalog['offers'] as $offer)$offer->promos_allowed=(bool)get_option('decka_offer_promos_'.$offer->id,[]);foreach($catalog['events'] as $event)$event->opponent_logo=Decka_League::logo((int)$event->id,$event->opponent);foreach($catalog['events'] as $event)$event->image_url=$event->image_id?wp_get_attachment_image_url((int)$event->image_id,'large'):null;return $catalog;
    }
    public static function availability(string $ids):array {
        global $wpdb;$events=array_values(array_unique(array_filter(array_map('absint',explode(',',$ids)))));if(!$events||count($events)>20)throw new RuntimeException('Niepoprawna lista meczów.');$in=implode(',',$events);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT event_id,seat_id,CASE WHEN state IN ("hidden","camera") THEN state ELSE "busy" END state FROM '.Decka_DB::table('inventory')." WHERE mode=%s AND event_id IN (0,$in)",Decka_DB::mode()),ARRAY_A);return ['seats'=>$rows,'updated_at'=>gmdate('c')];
    }
    private static function auth(WP_REST_Request $r,bool $register):array {
        $origin=$r->get_header('origin');$expected=wp_parse_url(home_url());$actual=wp_parse_url($origin);
        if(!$actual||strtolower($actual['host']??'')!==strtolower($expected['host']??'')||($actual['scheme']??'')!==($expected['scheme']??'')||($actual['port']??null)!==($expected['port']??null))throw new RuntimeException('Nieprawidłowe źródło logowania.');
        if(!wp_verify_nonce((string)$r->get_param('auth_nonce'),'decka_auth'))throw new RuntimeException('Odśwież stronę logowania.');
        $ip=$_SERVER['REMOTE_ADDR']??'';$key='decka_auth_'.hash_hmac('sha256',$ip,wp_salt());$attempts=(int)get_transient($key);if($attempts>=12)throw new RuntimeException('Zbyt wiele prób. Spróbuj ponownie za 15 minut.');set_transient($key,$attempts+1,900);
        $email=sanitize_email($r->get_param('email'));$pass=(string)$r->get_param('password');
        if($register){if(empty(Decka_DB::settings()['registration']))throw new RuntimeException('Rejestracja jest wyłączona.');if(!is_email($email)||mb_strlen($pass)<8)throw new RuntimeException('Podaj e-mail i hasło zawierające co najmniej 8 znaków.');if(email_exists($email))throw new RuntimeException('Nie można utworzyć konta. Spróbuj się zalogować lub odzyskać hasło.');$profile=Decka_Profile::validate($r->get_json_params()?:[]);$uid=wp_insert_user(['user_login'=>'kibic_'.bin2hex(random_bytes(10)),'user_email'=>$email,'user_pass'=>$pass,'role'=>'subscriber','display_name'=>sanitize_text_field($r->get_param('name')?:'Kibic')]);if(is_wp_error($uid))throw new RuntimeException('Nie można utworzyć konta.');Decka_Profile::save((int)$uid,$profile);}
        // The response nonce must use the new login session token, not the guest cookie.
        $capture=function($cookie){$_COOKIE[LOGGED_IN_COOKIE]=$cookie;};
        add_action('set_logged_in_cookie',$capture,10,1);
        try{$u=wp_signon(['user_login'=>$email,'user_password'=>$pass,'remember'=>true],is_ssl());}finally{remove_action('set_logged_in_cookie',$capture,10);}
        if(is_wp_error($u))throw new RuntimeException('Nieprawidłowy e-mail lub hasło.');wp_set_current_user($u->ID);delete_transient($key);return self::config();
    }
    public static function orders():array {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,request_key,status,mode,total,created_at,mail_sent_at,checkout_url,offer_id FROM '.Decka_DB::table('orders').' WHERE user_id=%d AND (user_id<>0 OR guest_hash=%s) ORDER BY id DESC LIMIT 100',get_current_user_id(),Decka_Guest::hash()),ARRAY_A);
        foreach($rows as &$o){if(in_array($o['status'],['creating','pending'],true)){try{$live=Decka_Service::order((int)$o['id']);if($live->session_id&&!get_transient('decka_payment_poll_'.$live->id)){set_transient('decka_payment_poll_'.$live->id,1,15);Decka_Service::refresh_payment($live);$o['status']=Decka_Service::order((int)$o['id'])->status;}}catch(Throwable $e){/* Keep pending on an ambiguous Stripe response. */}}$o['number']=Decka_Service::number((object)$o);unset($o['request_key']);$o['expires_at']=gmdate('c',strtotime($o['created_at'].' UTC')+600);$o['download']=in_array($o['status'],['paid','free','voucher'],true)?Decka_Tickets::download_link((int)$o['id']):null;$o['wallet']=$o['download']?Decka_Wallet::links((int)$o['id']):[];$o['items']=$wpdb->get_results($wpdb->prepare('SELECT i.seat_id,i.kind,e.opponent,e.starts_at FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('events').' e ON e.id=i.event_id WHERE i.order_id=%d',$o['id']),ARRAY_A);}return $rows;
    }
    public static function webhook(WP_REST_Request $r):array {
        $mode=$r['mode'];$raw=$r->get_body();if(!Decka_Domain::signature($raw,(string)$r->get_header('stripe-signature'),Decka_Stripe::secret($mode,'webhook'),time()))throw new RuntimeException('Nieprawidłowy podpis Stripe.');
        $event=json_decode($raw,true);if((bool)($event['livemode']??false)!==($mode==='live'))throw new RuntimeException('Nieprawidłowe środowisko Stripe.');update_option('decka_stripe_webhook_'.$mode,['time'=>gmdate('c'),'fingerprint'=>hash('sha256',Decka_Stripe::secret($mode,'webhook'))],false);$obj=$event['data']['object']??[];$type=$event['type']??'';
        if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired','checkout.session.async_payment_failed'],true)){$s=Decka_Stripe::request($mode,'GET','checkout/sessions/'.rawurlencode($obj['id']));Decka_Service::settle($s,$mode);}
        if($type==='charge.refunded'||$type==='charge.dispute.created'){
            $charge=$type==='charge.refunded'?$obj:Decka_Stripe::request($mode,'GET','charges/'.rawurlencode($obj['charge']));
            // Conservative: any refund/dispute revokes the whole order; do not resell automatically.
            if(!empty($charge['payment_intent'])){
                // Refund events can arrive before checkout.session.completed.
                $intent=Decka_Stripe::request($mode,'GET','payment_intents/'.rawurlencode($charge['payment_intent']));
                Decka_Service::revoke_payment($mode,$charge['payment_intent'],(int)($intent['metadata']['decka_order']??0));
            }
        }
        return ['received'=>true];
    }
    public static function config():array {return ['paymentProvider'=>Decka_DB::settings()['payment_provider']??'stripe','shopUrl'=>home_url('/bilety/'),'scannerUrl'=>home_url('/skaner/'),'api'=>rest_url('decka/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'authNonce'=>wp_create_nonce('decka_auth'),'user'=>is_user_logged_in()?['profileRequired'=>Decka_Profile::required(get_current_user_id()),'profile'=>Decka_Profile::read(get_current_user_id()),'name'=>wp_get_current_user()->display_name,'email'=>wp_get_current_user()->user_email,'gate'=>current_user_can('decka_scan'),'admin'=>current_user_can('decka_manage')]:null,'resetUrl'=>wp_lostpassword_url(),'privacyUrl'=>get_privacy_policy_url(),'termsUrl'=>Decka_DB::settings()['terms_url']??'','googleLogin'=>!empty(Decka_DB::settings()['google_client_id'])&&!empty(Decka_DB::settings()['google_client_secret'])?add_query_arg('_wpnonce',wp_create_nonce('decka_auth'),home_url('/konto/google/start/')):null];}
    public static function embed(string $view):string {
        $url=add_query_arg(['action'=>'decka_app','view'=>$view],admin_url('admin-post.php'));
        $id=wp_unique_id('decka-shop-');
        return '<div class="decka-embed"><iframe id="'.esc_attr($id).'" title="'.($view==='gate'?'Panel biletera':'Bilety Decka Pelplin').'" src="'.esc_url($url).'" style="display:block;width:100%;min-height:700px;height:1150px;border:0;border-radius:18px" allow="camera; payment" loading="eager"></iframe><p><a href="'.esc_url($url).'">Otwórz bilety na pełnym ekranie</a></p></div><script>(function(){var f=document.getElementById('.wp_json_encode($id).');window.addEventListener("message",function(e){if(e.source!==f.contentWindow||e.origin!==window.location.origin||!e.data||e.data.type!=="decka-height")return;var h=Number(e.data.height);if(Number.isFinite(h)&&h>=200&&h<=30000)f.style.height=Math.ceil(h)+"px";});})();</script>';

    }
    public static function app():void {
        Decka_Guest::init();$view=sanitize_key($_GET['view']??'shop');if($view==='admin'&&!current_user_can('decka_manage'))wp_die('Brak dostępu.',403);
        $file=DECKA_DIR.($view==='admin'?'assets/admin/index.html':'assets/index.html');if(!file_exists($file))wp_die('Brak zbudowanego interfejsu. Zainstaluj pełną paczkę ZIP.');
        status_header(200);nocache_headers();header('Content-Type: text/html; charset=UTF-8');header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: same-origin');
        $config=self::config();$config['view']=sanitize_key($_GET['view']??'shop');$config['order']=absint($_GET['order']??0);$config['adminScreen']=sanitize_key($_GET['screen']??'overview');
        $html=file_get_contents($file);
        // Use explicit asset URLs in embedded documents, independently of the host page path.
        $html=str_replace(['src="./_next/','href="./_next/'],['src="'.esc_url(DECKA_URL.'assets/_next/'),'href="'.esc_url(DECKA_URL.'assets/_next/')],$html);$inject='<base href="'.esc_url(DECKA_URL.'assets/').'" /><script>window.DECKA='.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
        echo str_replace('<head>','<head>'.$inject,$html);exit;
    }
}
