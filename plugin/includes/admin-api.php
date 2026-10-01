<?php
/** All administrative reads and writes require the dedicated WordPress capability. */
final class Decka_Admin_API {
    public static function register():void {
        foreach([
            ['bootstrap','GET',fn($r)=>self::bootstrap()],
            ['orders','GET',fn($r)=>self::orders($r->get_params())],
            ['orders/(?P<id>\d+)','GET',fn($r)=>self::order((int)$r['id'])],
            ['tickets','GET',fn($r)=>self::tickets($r->get_params())],
            ['fans','GET',fn($r)=>self::fans($r->get_params())],
            ['gate','GET',fn($r)=>self::gate((int)$r->get_param('event'))],
            ['inventory','GET',fn($r)=>self::inventory((int)$r->get_param('event'))],
            ['media','POST',fn($r)=>self::media()],
            ['action','POST',fn($r)=>self::action($r->get_json_params()?:[])],
            ['updates/check','POST',fn($r)=>Decka_Updater::force()],
        ] as [$path,$method,$fn])register_rest_route('decka/v1','/admin/'.$path,['methods'=>$method,'permission_callback'=>fn()=>current_user_can('decka_manage'),'callback'=>function($r)use($fn){try{return new WP_REST_Response($fn($r),200,['Cache-Control'=>'no-store, private']);}catch(Throwable $e){return new WP_Error('decka_admin',$e->getMessage(),['status'=>400]);}}]);
    }
    public static function bootstrap():array {
        global $wpdb;$mode=Decka_DB::mode();$events=$wpdb->get_results('SELECT * FROM '.Decka_DB::table('events').' ORDER BY starts_at IS NULL, starts_at',ARRAY_A);
        $stats=$wpdb->get_results($wpdb->prepare('SELECT i.event_id,COUNT(*) positions_count,SUM(o.status="paid" AND i.kind="normal") normal,SUM(o.status="paid" AND i.kind="reduced") reduced,SUM(o.status="voucher") vouchers,SUM(o.status="free") free_count,SUM(t.used_at IS NOT NULL) entered,SUM(o.status IN ("creating","pending")) held,SUM(o.status="refunded") revoked,SUM(CASE WHEN o.status="paid" THEN i.amount ELSE 0 END) revenue FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('orders').' o ON o.id=i.order_id LEFT JOIN '.Decka_DB::table('tickets').' t ON t.order_id=i.order_id AND t.event_id=i.event_id AND t.seat_id=i.seat_id WHERE o.mode=%s GROUP BY i.event_id',$mode),ARRAY_A);
        $map=[];foreach($stats as $stat){foreach($stat as &$v)$v=(int)$v;unset($v);$map[$stat['event_id']]=$stat;}
        $occupied=$wpdb->get_results($wpdb->prepare('SELECT event_id,COUNT(*) occupied FROM '.Decka_DB::table('inventory').' WHERE mode=%s GROUP BY event_id',$mode),OBJECT_K);
        foreach($events as &$e){$e['image_url']=!empty($e['image_id'])?wp_get_attachment_image_url((int)$e['image_id'],'large'):null;$e['stats']=$map[$e['id']]??['normal'=>0,'reduced'=>0,'vouchers'=>0,'free_count'=>0,'entered'=>0,'held'=>0,'revoked'=>0,'revenue'=>0];$e['stats']['occupied']=(int)($occupied[$e['id']]->occupied??0);$e['stats']['available']=340-$e['stats']['occupied'];}unset($e);
        $promos=$wpdb->get_results('SELECT * FROM '.Decka_DB::table('promos').' ORDER BY id DESC',ARRAY_A);
        foreach($promos as &$p)$p['uses']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Decka_DB::table('orders').' WHERE promo_id=%d AND mode=%s AND status IN ("creating","pending","paid","free")',$p['id'],$mode));unset($p);
        $offers=$wpdb->get_results('SELECT * FROM '.Decka_DB::table('offers').' ORDER BY id DESC',ARRAY_A);
        foreach($offers as &$o)$o['sold']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Decka_DB::table('orders').' WHERE offer_id=%d AND mode=%s AND status IN ("paid","free")',$o['id'],$mode));unset($o);
        return ['mode'=>$mode,'version'=>DECKA_VERSION,'events'=>$events,'offers'=>$offers,'promos'=>$promos,'seats'=>Decka_DB::seats(),'settings'=>self::settings(),'updater'=>Decka_Updater::status(),'permissions'=>['updates'=>current_user_can('update_plugins'),'users'=>current_user_can('promote_users')],'links'=>['csv'=>add_query_arg('_wpnonce',wp_create_nonce('decka_stats'),admin_url('admin-post.php?action=decka_stats')),'users'=>admin_url('user-new.php'),'plugins'=>self_admin_url('plugins.php'),'gate'=>home_url('/bileter/')],'alerts'=>$wpdb->get_results($wpdb->prepare('SELECT id,status,last_error,mail_attempts FROM '.Decka_DB::table('orders').' WHERE mode=%s AND (last_error IS NOT NULL OR (mail_sent_at IS NULL AND mail_attempts>=10)) ORDER BY id DESC LIMIT 10',$mode),ARRAY_A),'updated_at'=>gmdate('c')];
    }
    public static function settings():array {
        $s=Decka_DB::settings();$out=[];
        foreach(['mode'=>'test','normal'=>2500,'reduced'=>1500,'registration'=>1,'league_url'=>'','team_id'=>'7625','terms_url'=>'','ticket_color'=>'#19569d','max_per_fan'=>10,'google_client_id'=>'','google_wallet_issuer'=>'','apple_pass_type'=>'','apple_team_id'=>'','ticket_footer'=>'W razie zmiany terminu sprawdź aktualny bilet na swoim koncie.','mail_subject'=>'Decka Pelplin — Twoje bilety #{order}','mail_body'=>'W załączniku przesyłamy bilety na mecz. Pokaż kod QR przy wejściu. Do zobaczenia w hali!'] as $k=>$default)$out[$k]=$s[$k]??$default;
        foreach(['test','live'] as $m){$out[$m.'_secret_set']=(bool)Decka_Stripe::secret($m);$out[$m.'_webhook_set']=(bool)Decka_Stripe::secret($m,'webhook');$out[$m.'_webhook_url']=rest_url('decka/v1/webhook/'.$m);}
        foreach(['google_client_secret','google_wallet_credentials','apple_certificate','apple_private_key','apple_wwdr','apple_key_password'] as $key)$out[$key.'_set']=!empty($s[$key]);$out['google_redirect_uri']=admin_url('admin-post.php?action=decka_google_callback');$out['wallet_zip']=class_exists('ZipArchive');
        $next=wp_next_scheduled('decka_maintenance');$out['cron_next']=$next?gmdate('c',$next):null;$out['https']=is_ssl();return $out;
    }
    private static function page(array $p):array{$page=max(1,(int)($p['page']??1));return [$page,25,($page-1)*25];}
    public static function orders(array $p):array {
        global $wpdb;[$page,$limit,$offset]=self::page($p);$where=$wpdb->prepare('o.mode=%s',Decka_DB::mode());
        if(!empty($p['event']))$where.=$wpdb->prepare(' AND EXISTS (SELECT 1 FROM '.Decka_DB::table('items').' x WHERE x.order_id=o.id AND x.event_id=%d)',absint($p['event']));
        if(!empty($p['user']))$where.=$wpdb->prepare(' AND o.user_id=%d',absint($p['user']));
        if(!empty($p['status']))$where.=$wpdb->prepare(' AND o.status=%s',sanitize_key($p['status']));
        if(!empty($p['q'])){$q=sanitize_text_field($p['q']);$where.=$wpdb->prepare(' AND (o.email LIKE %s OR o.id=%d)','%'.$wpdb->esc_like($q).'%',absint(ltrim($q,'#')));}
        $table=Decka_DB::table('orders');$sort=($p['sort']??'newest')==='oldest'?'ASC':'DESC';
        $rows=$wpdb->get_results("SELECT o.id,o.email,o.status,o.total,o.discount,o.created_at,o.paid_at,o.mail_sent_at,o.last_error,(SELECT COUNT(*) FROM ".Decka_DB::table('items')." i WHERE i.order_id=o.id) positions_count FROM $table o WHERE $where ORDER BY o.id $sort LIMIT $limit OFFSET $offset",ARRAY_A);
        return ['rows'=>$rows,'page'=>$page,'total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table o WHERE $where"),'page_size'=>$limit];
    }
    public static function order(int $id):array {
        global $wpdb;$o=Decka_Service::order($id);if(!$o||$o->mode!==Decka_DB::mode())throw new RuntimeException('Nie znaleziono zamówienia w tym środowisku.');
        $items=$wpdb->get_results($wpdb->prepare('SELECT i.*,e.opponent,e.starts_at,t.id ticket_id,t.status ticket_status,t.used_at FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('events').' e ON e.id=i.event_id LEFT JOIN '.Decka_DB::table('tickets').' t ON t.order_id=i.order_id AND t.event_id=i.event_id AND t.seat_id=i.seat_id WHERE i.order_id=%d ORDER BY i.id',$id),ARRAY_A);
        return ['id'=>$id,'email'=>$o->email,'status'=>$o->status,'total'=>(int)$o->total,'discount'=>(int)$o->discount,'created_at'=>$o->created_at,'paid_at'=>$o->paid_at,'mail_sent_at'=>$o->mail_sent_at,'last_error'=>$o->last_error,'items'=>$items,'pdf'=>in_array($o->status,['paid','voucher','free'],true)?add_query_arg('_wpnonce',wp_create_nonce('decka_pdf_'.$id),admin_url('admin-post.php?action=decka_pdf&order='.$id)):null,'stripe_url'=>$o->payment_id?'https://dashboard.stripe.com/'.($o->mode==='test'?'test/':'').'payments/'.rawurlencode($o->payment_id):null];
    }
    public static function tickets(array $p):array {
        global $wpdb;[$page,$limit,$offset]=self::page($p);$where=$wpdb->prepare('o.mode=%s',Decka_DB::mode());
        if(!empty($p['event']))$where.=$wpdb->prepare(' AND t.event_id=%d',absint($p['event']));
        if(!empty($p['kind']))$where.=$wpdb->prepare(' AND t.kind=%s',sanitize_key($p['kind']));
        if(($p['status']??'')==='used')$where.=' AND t.used_at IS NOT NULL';elseif(($p['status']??'')==='valid')$where.=' AND t.status="valid" AND t.used_at IS NULL';elseif(($p['status']??'')==='revoked')$where.=' AND t.status="revoked"';
        if(!empty($p['q']))$where.=$wpdb->prepare(' AND (o.email LIKE %s OR t.id=%d OR t.seat_id=%s)','%'.$wpdb->esc_like(sanitize_text_field($p['q'])).'%',absint($p['q']),sanitize_text_field($p['q']));
        $join=' FROM '.Decka_DB::table('tickets').' t JOIN '.Decka_DB::table('orders').' o ON o.id=t.order_id JOIN '.Decka_DB::table('events').' e ON e.id=t.event_id';
        return ['rows'=>$wpdb->get_results('SELECT t.id,t.order_id,t.event_id,t.seat_id,t.kind,t.status,t.used_at,o.email,e.opponent'.$join." WHERE $where ORDER BY t.id DESC LIMIT $limit OFFSET $offset",ARRAY_A),'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*)'.$join." WHERE $where"),'page_size'=>$limit];
    }
    public static function fans(array $p):array {
        global $wpdb;[$page,$limit,$offset]=self::page($p);$t=Decka_DB::table('orders');$mode=$wpdb->prepare('%s',Decka_DB::mode());$where="(u.user_login LIKE 'kibic_%' OR EXISTS (SELECT 1 FROM $t o WHERE o.user_id=u.ID))";
        if(!empty($p['q'])){$like='%'.$wpdb->esc_like(sanitize_text_field($p['q'])).'%';$where.=$wpdb->prepare(' AND (u.display_name LIKE %s OR u.user_email LIKE %s)',$like,$like);}
        return ['rows'=>$wpdb->get_results("SELECT u.ID id,u.display_name name,u.user_email email,u.user_registered created_at,(SELECT COUNT(*) FROM $t o WHERE o.user_id=u.ID AND o.mode=$mode) orders_count,(SELECT COALESCE(SUM(o.total),0) FROM $t o WHERE o.user_id=u.ID AND o.mode=$mode AND o.status='paid') spent FROM {$wpdb->users} u WHERE $where ORDER BY u.ID DESC LIMIT $limit OFFSET $offset",ARRAY_A),'page'=>$page,'total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u WHERE $where"),'page_size'=>$limit];
    }
    public static function gate(int $event):array {
        global $wpdb;$where='';if($event)$where=$wpdb->prepare(' AND ((a.action="scan_accepted" AND t.event_id=%d) OR (a.action="scan_rejected" AND a.object_id=%d))',$event,$event);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT a.id,a.action,a.object_id,a.detail,a.created_at,u.display_name actor,t.seat_id,e.opponent FROM '.Decka_DB::table('audit').' a LEFT JOIN '.$wpdb->users.' u ON u.ID=a.actor LEFT JOIN '.Decka_DB::table('tickets').' t ON a.action="scan_accepted" AND t.id=a.object_id LEFT JOIN '.Decka_DB::table('orders').' o ON o.id=t.order_id LEFT JOIN '.Decka_DB::table('events').' e ON e.id=t.event_id WHERE ((a.action="scan_accepted" AND o.mode=%s) OR (a.action="scan_rejected" AND a.detail LIKE %s))'.$where.' ORDER BY a.id DESC LIMIT 100',Decka_DB::mode(),'%"mode":"'.Decka_DB::mode().'"%'),ARRAY_A);
        $users=array_map(fn($u)=>['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'administrator'=>in_array('administrator',$u->roles,true)],get_users(['role__in'=>['decka_bileter','administrator'],'number'=>200]));return ['scans'=>$rows,'users'=>$users];
    }
    public static function inventory(int $event):array {
        global $wpdb;$where=$event?$wpdb->prepare(' AND event_id=%d',$event):'';
        return ['rows'=>$wpdb->get_results($wpdb->prepare('SELECT event_id,seat_id,order_id,state FROM '.Decka_DB::table('inventory').' WHERE mode=%s',Decka_DB::mode()).$where,ARRAY_A)];
    }
    public static function media():array {
        if(!current_user_can('upload_files'))throw new RuntimeException('Brak uprawnień do przesyłania grafiki.');
        $f=$_FILES['image']??null;if(!$f||$f['error']||$f['size']>8*1024*1024)throw new RuntimeException('Wybierz JPG lub PNG do 8 MB.');
        $size=getimagesize($f['tmp_name']);if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||$size[0]>6000||$size[1]>6000)throw new RuntimeException('Grafika musi być JPG/PNG do 6000 × 6000 px.');
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
        $id=media_handle_upload('image',0,[],['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png']]);if(is_wp_error($id))throw new RuntimeException($id->get_error_message());return ['id'=>$id,'url'=>wp_get_attachment_image_url($id,'large')];
    }
    private static function date($value):?string {
        if(!$value)return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)$value,new DateTimeZone('Europe/Warsaw'));if(!$d||$d->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Niepoprawna data.');return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private static function cents($value):int{if(!is_numeric($value)||$value<0||$value>1000000||floor((float)$value)!=(float)$value)throw new RuntimeException('Niepoprawna kwota.');return (int)$value;}
    private static function write(string $table,array $row,int $id):int {global $wpdb;if($id){if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Decka_DB::table($table).' WHERE id=%d',$id)))throw new RuntimeException('Rekord nie istnieje.');if(false===$wpdb->update(Decka_DB::table($table),$row,['id'=>$id]))throw new RuntimeException('Nie można zapisać zmian.');return $id;}return Decka_DB::insert($table,$row);}
    public static function action(array $p):array {
        global $wpdb;$op=sanitize_key($p['operation']??'');$id=absint($p['id']??0);$message='Zapisano zmiany.';
        if($op==='event'){
            $row=['normal_price'=>self::cents($p['normal_price']??Decka_DB::settings()['normal']??2500),'reduced_price'=>self::cents($p['reduced_price']??Decka_DB::settings()['reduced']??1500),'image_id'=>absint($p['image_id']??0)?:null,'opponent'=>sanitize_text_field($p['opponent']??''),'starts_at'=>self::date($p['starts_at']??null),'venue'=>sanitize_text_field($p['venue']??''),'gate_open'=>self::date($p['gate_open']??null),'gate_close'=>self::date($p['gate_close']??null),'sale_open'=>empty($p['sale_open'])?0:1,'cancelled'=>empty($p['cancelled'])?0:1,'updated_at'=>gmdate('Y-m-d H:i:s')];
            if($row['image_id']&&!in_array(get_post_mime_type($row['image_id']),['image/jpeg','image/png'],true))throw new RuntimeException('Wybierz grafikę JPG lub PNG z biblioteki.');
            if(!$row['opponent']||!$row['venue'])throw new RuntimeException('Uzupełnij przeciwnika i halę.');if($row['sale_open']&&(!$row['starts_at']||$row['starts_at']<=gmdate('Y-m-d H:i:s')||$row['cancelled']))throw new RuntimeException('Sprzedaż wymaga przyszłego terminu i aktywnego meczu.');if(($row['gate_open']||$row['gate_close'])&&(!$row['gate_open']||!$row['gate_close']||$row['gate_open']>=$row['gate_close']))throw new RuntimeException('Podaj poprawne godziny wejścia od–do.');
            $id=Decka_DB::tx(function()use($wpdb,$id,$row){if($id)$wpdb->get_row($wpdb->prepare('SELECT id FROM '.Decka_DB::table('events').' WHERE id=%d FOR UPDATE',$id));return self::write('events',$row,$id);});
        }elseif($op==='offer'||$op==='promo'){
            $ids=array_values(array_unique(array_map('absint',(array)($p['event_ids']??[]))));sort($ids);foreach($ids as $eid)if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Decka_DB::table('events').' WHERE id=%d',$eid)))throw new RuntimeException('Wybrano nieistniejący mecz.');
            $row=['event_ids'=>wp_json_encode($ids),'active'=>empty($p['active'])?0:1,'starts_at'=>self::date($p['starts_at']??null),'ends_at'=>self::date($p['ends_at']??null)];if($row['starts_at']&&$row['ends_at']&&$row['starts_at']>=$row['ends_at'])throw new RuntimeException('Nieprawidłowy okres oferty.');
            if($op==='offer'){if(count($ids)<2||count($ids)>20)throw new RuntimeException('Wybierz od 2 do 20 meczów.');$row+=['name'=>sanitize_text_field($p['name']??''),'normal_price'=>self::cents($p['normal_price']??-1),'reduced_price'=>self::cents($p['reduced_price']??-1)];if(!$row['name'])throw new RuntimeException('Podaj nazwę oferty.');$table='offers';}
            else{$type=sanitize_key($p['type']??'');$value=self::cents($p['value']??-1);Decka_Domain::discount(10000,$type,$value);$code=strtoupper(sanitize_text_field($p['code']??''));if(!preg_match('/^[A-Z0-9_-]{2,64}$/',$code))throw new RuntimeException('Kod musi zawierać 2–64 znaki A–Z, 0–9, _ lub -.');$row+=['code'=>$code,'type'=>$type,'value'=>$value,'max_uses'=>absint($p['max_uses']??0)];$table='promos';}
            $id=self::write($table,$row,$id);
        }elseif($op==='settings'){
            $s=Decka_DB::settings();$previous=$s;$mode=$p['mode']??'test';if(!in_array($mode,['test','live'],true))throw new RuntimeException('Nieprawidłowy tryb.');
            foreach(['test','live'] as $env)foreach(['secret','webhook'] as $kind){$k=$env.'_'.$kind;$v=trim((string)($p[$k]??''));if($v){if($kind==='secret'&&!preg_match('/^(sk|rk)_'.$env.'_/',$v))throw new RuntimeException('Klucz nie pasuje do '.$env.'.');if($kind==='webhook'&&!str_starts_with($v,'whsec_'))throw new RuntimeException('Nieprawidłowy sekret webhook.');$s[$k]=Decka_Stripe::encrypt($v);}}
            $s['max_per_fan']=max(1,min(340,absint($p['max_per_fan']??10)));
            foreach(['google_client_id','google_wallet_issuer','apple_pass_type','apple_team_id'] as $key)$s[$key]=sanitize_text_field($p[$key]??'');
            foreach(['google_client_secret','google_wallet_credentials','apple_certificate','apple_private_key','apple_wwdr','apple_key_password'] as $key){$v=trim((string)($p[$key]??''));if($v)$s[$key]=Decka_Stripe::encrypt($v);}
            $s['mode']=$mode;$s['normal']=self::cents($p['normal']??2500);$s['reduced']=self::cents($p['reduced']??1500);$s['registration']=empty($p['registration'])?0:1;$s['team_id']=(string)absint($p['team_id']??7625);$s['league_url']=esc_url_raw($p['league_url']??'');$s['terms_url']=esc_url_raw($p['terms_url']??'');
            $color=sanitize_hex_color($p['ticket_color']??'#0c253a');if(!$color)throw new RuntimeException('Niepoprawny kolor biletu.');if(strlen($color)===4)$color='#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];$s['ticket_color']=$color;$s['ticket_footer']=mb_substr(sanitize_textarea_field($p['ticket_footer']??''),0,300);$s['mail_subject']=mb_substr(sanitize_text_field($p['mail_subject']??''),0,150);$s['mail_body']=mb_substr(sanitize_textarea_field($p['mail_body']??''),0,3000);Decka_DB::tx(function()use($wpdb,$s,$previous){if((int)$s['normal']!==(int)($previous['normal']??2500)||(int)$s['reduced']!==(int)($previous['reduced']??1500))Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('events').' SET normal_price=%d,reduced_price=%d',$s['normal'],$s['reduced']));update_option('decka_settings',$s,false);});
        }elseif($op==='voucher'){$r=Decka_Service::create($p,true);$id=$r['order_id'];$message='Voucher #'.$id.' dodano do kolejki wysyłki.';
        }elseif($op==='resend'){$o=Decka_Service::order($id);if(!$o||$o->mode!==Decka_DB::mode()||!in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Nie można wysłać tego zamówienia.');$wpdb->update(Decka_DB::table('orders'),['mail_sent_at'=>null,'mail_attempts'=>0],['id'=>$id]);$message='Bilety dodano do kolejki wysyłki.';
        }elseif($op==='revoke'){
            if(empty($p['confirmed']))throw new RuntimeException('Potwierdź unieważnienie biletu.');
            Decka_DB::tx(function()use($wpdb,$id){$t=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('tickets').' WHERE id=%d',$id));if(!$t)throw new RuntimeException('Nie znaleziono biletu.');$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE id=%d FOR UPDATE',$t->order_id));if(!$o||$o->mode!==Decka_DB::mode())throw new RuntimeException('Nieprawidłowe środowisko.');Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('tickets').' SET status=%s WHERE id=%d','revoked',$id));Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('inventory').' SET state=%s WHERE mode=%s AND event_id=%d AND seat_id=%s','blocked',$o->mode,$t->event_id,$t->seat_id));});$message='Bilet unieważniono. Miejsce pozostaje zablokowane; zwrot pieniędzy wykonaj w Stripe.';
        }elseif($op==='seat_block'||$op==='seat_release'){
            $event=absint($p['event_id']??0);$seats=array_values(array_unique(array_map('strval',(array)($p['seats']??[]))));$known=array_column(Decka_DB::seats()['seats'],'id');if(!$seats||count($seats)>340||array_diff($seats,$known))throw new RuntimeException('Wybierz prawidłowe miejsca.');
            $event_ids=$event?[$event]:array_map('intval',$wpdb->get_col('SELECT id FROM '.Decka_DB::table('events').' ORDER BY id'));
            if(!$event_ids)throw new RuntimeException('Nie ma meczów do zablokowania.');sort($event_ids);
            Decka_DB::tx(function()use($wpdb,$event_ids,$seats,$op){
                foreach($event_ids as $eid)if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Decka_DB::table('events').' WHERE id=%d FOR UPDATE',$eid)))throw new RuntimeException('Mecz nie istnieje.');
                foreach($event_ids as $eid)foreach($seats as $seat){$i=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id=%d AND seat_id=%s',Decka_DB::mode(),$eid,$seat));
                    if($op==='seat_block'){
                        if($i&&(int)$i->order_id!==0)throw new RuntimeException('Miejsce '.$seat.' jest zajęte na meczu #'.$eid.'. Nie zmieniono żadnego miejsca.');
                        if(!$i)Decka_DB::insert('inventory',['mode'=>Decka_DB::mode(),'event_id'=>$eid,'seat_id'=>$seat,'order_id'=>0,'state'=>'blocked']);
                    }else{
                        if($i&&(int)$i->order_id!==0)throw new RuntimeException('Miejsce '.$seat.' należy do zamówienia. Można zwolnić tylko blokady organizatora.');
                        Decka_DB::query($wpdb->prepare('DELETE FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id=%d AND seat_id=%s AND order_id=0',Decka_DB::mode(),$eid,$seat));
                    }
                }
            });$id=$event;$message=($op==='seat_block'?'Zablokowano miejsca':'Zwolniono ręczne blokady').' na '.count($event_ids).' meczach.';
        }elseif($op==='gate_access'){
            if(!current_user_can('promote_users'))throw new RuntimeException('Brak uprawnień do zarządzania rolami.');$u=get_user_by('email',sanitize_email($p['email']??''));if(!$u||!current_user_can('edit_user',$u->ID))throw new RuntimeException('Nie znaleziono dostępnego konta.');if(array_diff($u->roles,['subscriber','decka_bileter']))throw new RuntimeException('Nie zmieniaj tą operacją konta administratora lub pracownika z inną rolą.');if(!empty($p['enabled']))$u->add_role('decka_bileter');else{$u->remove_role('decka_bileter');if(!$u->roles)$u->add_role('subscriber');}$id=$u->ID;
        }elseif($op==='sync'){$message='Zaktualizowano '.Decka_League::sync().' meczów. Nowe spotkania mają zamkniętą sprzedaż.';
        }elseif($op==='maintenance'){Decka_Service::maintenance();$message='Uruchomiono sprawdzanie płatności i kolejki e-mail.';
        }else throw new RuntimeException('Nieznana operacja.');
        $detail=in_array($op,['seat_block','seat_release'],true)?wp_json_encode(['mode'=>Decka_DB::mode(),'seats'=>$p['seats'],'reason'=>sanitize_text_field($p['reason']??'')]):Decka_DB::mode();Decka_DB::audit('admin_'.$op,$id,$detail);return ['ok'=>true,'id'=>$id,'message'=>$message];
    }
}
