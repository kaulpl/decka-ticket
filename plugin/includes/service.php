<?php
final class Decka_Service {
    public static function order(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE id=%d',$id));}
    public static function create(array $input,bool $voucher=false):array {
        global $wpdb;Decka_DB::require_storage();$mode=Decka_DB::mode();$uid=get_current_user_id();
        if(!$uid)throw new RuntimeException('Zaloguj się, aby dokończyć zakup.');
        $key=sanitize_text_field($input['request_key']??'');
        if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$key))throw new RuntimeException('Niepoprawny identyfikator zamówienia.');
        $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Decka_DB::table('orders').' WHERE user_id=%d AND mode=%s AND request_key=%s',$uid,$mode,$key));
        if($existing)return self::checkout((int)$existing);
        $seats=(array)($input['seats']??[]); if(!$seats || count($seats)>10)throw new RuntimeException('Wybierz od 1 do 10 miejsc.');
        $catalog=array_column(Decka_DB::seats()['seats'],null,'id');$chosen=[];
        foreach($seats as $s){$id=(string)($s['id']??'');$kind=$s['kind']??'normal';if(!isset($catalog[$id])||isset($chosen[$id])||!in_array($kind,['normal','reduced'],true))throw new RuntimeException('Niepoprawne lub powtórzone miejsce.');$chosen[$id]=$kind;}
        ksort($chosen,SORT_NATURAL);
        $email=$voucher?sanitize_email($input['email']??''):wp_get_current_user()->user_email;
        if(!is_email($email))throw new RuntimeException('Podaj prawidłowy adres e-mail.');
        $offer_id=$voucher?0:absint($input['offer_id']??0);$event_id=absint($input['event_id']??0);
        $id=Decka_DB::tx(function()use($wpdb,$mode,$uid,$key,$chosen,$email,$voucher,$offer_id,$event_id,$input){
            $now=gmdate('Y-m-d H:i:s');$settings=Decka_DB::settings();$offer=null;$event_rows=[];
            if($offer_id){$offer=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('offers').' WHERE id=%d FOR UPDATE',$offer_id));if(!$offer || !$offer->active || ($offer->starts_at && $offer->starts_at>$now)||($offer->ends_at && $offer->ends_at<$now))throw new RuntimeException('Oferta nie jest już dostępna.');}
            $events=$offer?json_decode($offer->event_ids,true):[$event_id];$events=array_values(array_unique(array_map('intval',$events)));sort($events);
            if($offer && empty($input['package_ack']))throw new RuntimeException('Potwierdź zapoznanie się z zasadami terminów mini-karnetu.');
            if(!$events || in_array(0,$events,true))throw new RuntimeException('Wybierz mecz.');
            foreach($events as $eid){$ev=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('events').' WHERE id=%d FOR UPDATE',$eid));if(!$ev || $ev->cancelled || ($ev->starts_at && $ev->starts_at<=$now) || (!$offer && (!$ev->starts_at || (!$voucher && !$ev->sale_open))))throw new RuntimeException('Sprzedaż na jeden z meczów jest zamknięta.');$event_rows[$eid]=$ev;}
            $again=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Decka_DB::table('orders').' WHERE user_id=%d AND mode=%s AND request_key=%s',$uid,$mode,$key));if($again)return (int)$again;
            $limit=max(1,(int)($settings['max_per_fan']??10));
            if(!$voucher)foreach($events as $eid){$used=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('orders').' o ON o.id=i.order_id WHERE o.user_id=%d AND o.mode=%s AND i.event_id=%d AND o.status IN ("creating","pending","paid","free")',$uid,$mode,$eid));if($used+count($chosen)>$limit)throw new RuntimeException('Limit '.$limit.' biletów na kibica na mecz został przekroczony (wliczamy oczekujące płatności).');}
            $raw=[];$total=0;
            foreach($chosen as $seat=>$kind){$price=$voucher?0:(int)($offer?($kind==='normal'?$offer->normal_price:$offer->reduced_price):($event_rows[$event_id]->{$kind.'_price'}??$settings[$kind]??($kind==='normal'?2500:1500)));if($price<0)throw new RuntimeException('Nieprawidłowa cena.');$total+=$price;$amounts=Decka_Domain::allocate($price,count($events));foreach($events as $i=>$eid)$raw[]=['event_id'=>$eid,'seat_id'=>(string)$seat,'kind'=>$voucher?'voucher':$kind,'amount'=>$amounts[$i]];}
            $promo=null;$code=strtoupper(sanitize_text_field($input['promo']??''));$before=$total;
            if($code && !$voucher){
                $promo=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('promos').' WHERE code=%s FOR UPDATE',$code));
                if(!$promo || !$promo->active || ($promo->starts_at && $promo->starts_at>$now)||($promo->ends_at && $promo->ends_at<$now))throw new RuntimeException('Kod jest nieaktywny lub nieprawidłowy.');
                $allowed=json_decode($promo->event_ids,true)?:[];if($allowed && array_diff($events,array_map('intval',$allowed)))throw new RuntimeException('Kod nie obejmuje wszystkich wybranych meczów.');
                $used=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Decka_DB::table('orders')." WHERE mode=%s AND promo_id=%d AND status IN ('creating','pending','paid','free')",$mode,$promo->id));
                if($promo->max_uses && $used>=$promo->max_uses)throw new RuntimeException('Limit wykorzystania kodu został osiągnięty.');
                $total=Decka_Domain::discount($total,$promo->type,(int)$promo->value);
                $remaining=$total;foreach($raw as $i=>&$item){$item['amount']=$i===count($raw)-1?$remaining:(int)floor($before?$item['amount']*$total/$before:0);$remaining-=$item['amount'];}unset($item);
            }
            if(!$voucher && $total>0 && $total<200)throw new RuntimeException('Po rabacie zamówienie musi wynosić co najmniej 2 zł albo 0 zł.');
            if($total>0 && (!Decka_Stripe::secret($mode)||!Decka_Stripe::secret($mode,'webhook')))throw new RuntimeException('Płatności nie są jeszcze skonfigurowane. Skontaktuj się z klubem.');
            $oid=Decka_DB::insert('orders',['mode'=>$mode,'user_id'=>$uid,'request_key'=>$key,'email'=>$email,'status'=>'creating','session_id'=>null,'payment_id'=>null,'total'=>$total,'discount'=>$before-$total,'offer_id'=>$offer_id?:null,'promo_id'=>$promo?$promo->id:null,'created_at'=>$now,'package_ack'=>$offer?'schedule-v1':null]);
            foreach($raw as $item){
                $occupied=$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id=%d AND seat_id=%s',$mode,$item['event_id'],$item['seat_id']));
                if($occupied!==null)throw new RuntimeException('Miejsce '.$item['seat_id'].' jest już zajęte na jednym z meczów. Wybierz inne.');
                Decka_DB::insert('inventory',['mode'=>$mode,'event_id'=>$item['event_id'],'seat_id'=>$item['seat_id'],'order_id'=>$oid,'state'=>'held']);
                Decka_DB::insert('items',array_merge($item,['order_id'=>$oid]));
            }
            if($total===0){self::issue_locked($oid,$voucher?'voucher':'free');if($voucher)Decka_DB::audit('voucher',$oid,$email);return $oid;}
            $base=admin_url('admin-post.php?action=decka_app');
            $payload=['mode'=>'payment','locale'=>'pl','customer_email'=>$email,'client_reference_id'=>(string)$oid,'metadata'=>['decka_order'=>(string)$oid,'decka_mode'=>$mode], 'payment_intent_data'=>['metadata'=>['decka_order'=>(string)$oid]],'payment_method_types'=>['card','blik'],'success_url'=>$base.'&order='.$oid,'cancel_url'=>$base.'&order='.$oid.'&cancel=1','expires_at'=>time()+1860,'line_items'=>[['price_data'=>['currency'=>'pln','unit_amount'=>$total,'product_data'=>['name'=>'Decka Pelplin — '.($offer?$offer->name:'bilety').' ('.count($raw).' wejść)']],'quantity'=>1]]];
            Decka_DB::update('orders',['stripe_payload'=>wp_json_encode($payload)],['id'=>$oid]);
            return $oid;
        });
        return self::checkout($id);
    }
    public static function checkout(int $id):array {
        global $wpdb;Decka_DB::require_storage();$o=self::order($id);if(!$o)throw new RuntimeException('Nie znaleziono zamówienia.');
        if($o->status==='creating'){
            // A network timeout is ambiguous. Keep the seats and retry with the same Stripe key.
            $session=Decka_Stripe::create($o);
            if(($session['metadata']['decka_order']??'')!==(string)$id)throw new RuntimeException('Błąd przypisania płatności.');
            Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('orders')." SET session_id=%s,checkout_url=%s,status=IF(status='creating','pending',status) WHERE id=%d",$session['id'],$session['url']??'',$id),'orders');
            $o=self::order($id);
        }
        return ['order_id'=>$id,'status'=>$o->status,'total'=>(int)$o->total,'url'=>$o->status==='pending'?$o->checkout_url:null];
    }
    private static function issue_locked(int $id,string $status):void {
        global $wpdb;$o=self::order($id);$items=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Decka_DB::table('items').' WHERE order_id=%d',$id));
        foreach($items as $i)Decka_DB::insert('tickets',['order_id'=>$id,'event_id'=>$i->event_id,'seat_id'=>$i->seat_id,'kind'=>$i->kind,'nonce'=>bin2hex(random_bytes(24)),'status'=>'valid']);
        Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('orders').' SET status=%s,paid_at=%s WHERE id=%d',$status,gmdate('Y-m-d H:i:s'),$id));
        Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('inventory').' SET state=%s WHERE order_id=%d',$status==='paid'?'paid':($status==='voucher'?'voucher':'free'),$id));
    }
    public static function settle(array $s,string $mode):void {
        global $wpdb;$id=(int)($s['metadata']['decka_order']??0);if(!$id)return;
        Decka_DB::tx(function()use($wpdb,$s,$mode,$id){
            $o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE id=%d FOR UPDATE',$id));
            if(!$o || $o->mode!==$mode || (bool)($s['livemode']??false)!==($mode==='live') || ($o->session_id && $o->session_id!==$s['id']) || (string)($s['client_reference_id']??'')!==(string)$id || (int)($s['amount_total']??-1)!==(int)$o->total || ($s['currency']??'')!=='pln')throw new RuntimeException('Płatność nie pasuje do zamówienia.');
            if(!in_array($o->status,['creating','pending'],true))return;
            if(($s['payment_status']??'')==='paid'){
                Decka_DB::update('orders',['session_id'=>$s['id'],'payment_id'=>$s['payment_intent']??null],['id'=>$id]);
                self::issue_locked($id,'paid');
            }elseif(($s['status']??'')==='expired'){
                Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('orders')." SET status='expired' WHERE id=%d",$id));
                Decka_DB::query($wpdb->prepare('DELETE FROM '.Decka_DB::table('inventory').' WHERE order_id=%d AND state=%s',$id,'held'));
            }
        });
    }
    public static function revoke_payment(string $mode,string $payment,int $order_id=0):void {
        global $wpdb;Decka_DB::tx(function()use($wpdb,$mode,$payment,$order_id){$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE mode=%s AND (payment_id=%s OR id=%d) FOR UPDATE',$mode,$payment,$order_id));if(!$o)return;if($o->payment_id && $o->payment_id!==$payment)throw new RuntimeException('Zwrot nie pasuje do płatności.');Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('orders')." SET status='refunded',payment_id=%s WHERE id=%d",$payment,$o->id));Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('tickets')." SET status='revoked' WHERE order_id=%d",$o->id));Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('inventory')." SET state='blocked' WHERE order_id=%d",$o->id));});
    }
    public static function maintenance():void {
        global $wpdb;Decka_DB::require_storage();$lock='decka_maintenance_'.substr(md5($wpdb->prefix),0,12);if(!(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return;
        try{
            $orders=$wpdb->get_results('SELECT * FROM '.Decka_DB::table('orders')." WHERE status IN ('creating','pending') ORDER BY id LIMIT 50");
            foreach($orders as $o){try{
                if($o->status==='creating'){
                    if(strtotime($o->created_at.' UTC')<time()-23*3600){Decka_DB::update('orders',['last_error'=>'Wymagana ręczna weryfikacja w Stripe; blokada miejsc pozostaje.'],['id'=>$o->id]);continue;}
                    self::checkout((int)$o->id);$o=self::order((int)$o->id);
                }
                if($o->session_id)self::settle(Decka_Stripe::request($o->mode,'GET','checkout/sessions/'.rawurlencode($o->session_id)),$o->mode);
            }catch(Throwable $e){Decka_DB::update('orders',['last_error'=>$e->getMessage()],['id'=>$o->id]);}}
            $mail=$wpdb->get_col('SELECT id FROM '.Decka_DB::table('orders')." WHERE status IN ('paid','free','voucher') AND mail_sent_at IS NULL AND mail_attempts<10 ORDER BY id LIMIT 15");
            foreach($mail as $id)try{Decka_Tickets::email((int)$id);}catch(Throwable $e){Decka_DB::update('orders',['last_error'=>$e->getMessage()],['id'=>$id]);}
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
