<?php
/** Counter sales and seat exchanges. Every seat mutation runs under the hall lock. */
final class Decka_Cashier {
    private static function require_access():void {if(!current_user_can('decka_cashier'))throw new RuntimeException('Brak dostępu kasjera.');}
    public static function events():array {
        self::require_access();global $wpdb;$now=gmdate('Y-m-d H:i:s');
        return $wpdb->get_results($wpdb->prepare('SELECT id,opponent,starts_at,venue,normal_price,reduced_price FROM '.Decka_DB::table('events').' WHERE cancelled=0 AND starts_at IS NOT NULL AND starts_at>%s ORDER BY starts_at,id LIMIT 20',$now),ARRAY_A);
    }
    // Cashier selections remain protected until the cashier removes or sells them.
    // The inventory row is still written under the hall lock, so an online order
    // and a counter sale can never acquire the same seat concurrently.
    public static function expire():void {}
    private static function draft(int $event):object {
        global $wpdb;$uid=get_current_user_id();$mode=Decka_DB::mode();$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE mode=%s AND user_id=%d AND status="cashier_draft" AND offer_id IS NULL AND package_ack=%s ORDER BY id DESC LIMIT 1 FOR UPDATE',$mode,$uid,'cashier-event-'.$event));
        if($o)return $o;$user=wp_get_current_user();$id=Decka_DB::insert('orders',['mode'=>$mode,'user_id'=>$uid,'request_key'=>'cashier-'.bin2hex(random_bytes(20)),'email'=>$user->user_email,'first_name'=>$user->display_name,'status'=>'cashier_draft','total'=>0,'discount'=>0,'session_id'=>null,'payment_id'=>null,'package_ack'=>'cashier-event-'.$event,'created_at'=>gmdate('Y-m-d H:i:s')]);return Decka_Service::order($id);
    }
    public static function cart(int $event):array {
        self::require_access();global $wpdb;$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE mode=%s AND user_id=%d AND status="cashier_draft" AND package_ack=%s ORDER BY id DESC LIMIT 1',Decka_DB::mode(),get_current_user_id(),'cashier-event-'.$event));
        $items=$o?$wpdb->get_results($wpdb->prepare('SELECT i.*,s.opponent FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('events').' s ON s.id=i.event_id WHERE i.order_id=%d ORDER BY i.id',$o->id),ARRAY_A):[];return ['order_id'=>(int)($o->id??0),'items'=>$items,'total'=>array_sum(array_column($items,'amount'))];
    }
    public static function hold(array $p):array {
        self::require_access();$event=absint($p['event_id']??0);$seat=(string)($p['seat_id']??'');$kind=sanitize_key($p['kind']??'normal');if(!$event||!isset(array_column(Decka_DB::seats()['seats'],null,'id')[$seat])||!in_array($kind,['normal','reduced','free'],true))throw new RuntimeException('Nieprawidłowe miejsce lub rodzaj biletu.');
        Decka_DB::hall_tx(function()use($event,$seat,$kind){global $wpdb;$ev=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('events').' WHERE id=%d AND cancelled=0 AND starts_at>UTC_TIMESTAMP() FOR UPDATE',$event));if(!$ev)throw new RuntimeException('Mecz nie jest dostępny dla kasjera.');$o=self::draft($event);
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id IN (0,%d) AND seat_id=%s LIMIT 1 FOR UPDATE',Decka_DB::mode(),$event,$seat));if($existing&&(int)$existing->order_id!==(int)$o->id)throw new RuntimeException('Miejsce zostało właśnie zajęte. Odśwież plan hali.');
            $price=$kind==='free'?0:(int)($ev->{$kind.'_price'}??Decka_DB::settings()[$kind]??($kind==='normal'?2500:1500));
            if(!$existing)Decka_DB::insert('inventory',['mode'=>Decka_DB::mode(),'event_id'=>$event,'seat_id'=>$seat,'order_id'=>$o->id,'state'=>'cashier_hold']);
            $item=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.Decka_DB::table('items').' WHERE order_id=%d AND event_id=%d AND seat_id=%s',$o->id,$event,$seat));if($item)Decka_DB::update('items',['kind'=>$kind,'amount'=>$price],['id'=>$item->id]);else Decka_DB::insert('items',['order_id'=>$o->id,'event_id'=>$event,'seat_id'=>$seat,'kind'=>$kind,'amount'=>$price]);
            $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM '.Decka_DB::table('items').' WHERE order_id=%d',$o->id));Decka_DB::update('orders',['total'=>$total,'created_at'=>gmdate('Y-m-d H:i:s')],['id'=>$o->id]);Decka_DB::audit('cashier_hold',(int)$o->id,wp_json_encode(['event_id'=>$event,'seat_id'=>$seat,'kind'=>$kind]));
        });return self::cart($event);
    }
    public static function release(array $p):array {
        self::require_access();$event=absint($p['event_id']??0);$seat=(string)($p['seat_id']??'');Decka_DB::hall_tx(function()use($event,$seat){global $wpdb;$o=self::draft($event);Decka_DB::query($wpdb->prepare('DELETE FROM '.Decka_DB::table('inventory').' WHERE order_id=%d AND event_id=%d AND seat_id=%s AND state="cashier_hold"',$o->id,$event,$seat));Decka_DB::query($wpdb->prepare('DELETE FROM '.Decka_DB::table('items').' WHERE order_id=%d AND event_id=%d AND seat_id=%s',$o->id,$event,$seat));$total=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM '.Decka_DB::table('items').' WHERE order_id=%d',$o->id));Decka_DB::update('orders',['total'=>$total],['id'=>$o->id]);});return self::cart($event);
    }
    public static function confirm(array $p):array {
        self::require_access();$event=absint($p['event_id']??0);$id=Decka_DB::hall_tx(function()use($event){global $wpdb;$o=self::draft($event);$items=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Decka_DB::table('items').' WHERE order_id=%d FOR UPDATE',$o->id));if(!$items)throw new RuntimeException('Koszyk kasjera jest pusty.');foreach($items as $i)Decka_DB::insert('tickets',['order_id'=>$o->id,'event_id'=>$i->event_id,'seat_id'=>$i->seat_id,'kind'=>$i->kind,'nonce'=>bin2hex(random_bytes(24)),'status'=>'valid']);$status=(int)$o->total>0?'paid':'free';$user=wp_get_current_user();Decka_DB::update('orders',['email'=>$user->user_email,'status'=>$status,'payment_id'=>'cashier-'.bin2hex(random_bytes(8)),'stripe_payload'=>wp_json_encode(['provider'=>'cashier']),'paid_at'=>gmdate('Y-m-d H:i:s'),'mail_sent_at'=>gmdate('Y-m-d H:i:s')],['id'=>$o->id]);Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('inventory').' SET state="cashier" WHERE order_id=%d',$o->id));Decka_DB::audit('cashier_sale',(int)$o->id,wp_json_encode(['event_id'=>$event,'count'=>count($items),'total'=>(int)$o->total]));return (int)$o->id;});return ['order_id'=>$id,'number'=>Decka_Service::number(Decka_Service::order($id))];
    }
    public static function exchange(array $p):array {
        self::require_access();$event=absint($p['event_id']??0);$old=(string)($p['old_seat']??'');$new=(string)($p['new_seat']??'');$known=array_column(Decka_DB::seats()['seats'],'id');if(!$event||$old===$new||!in_array($old,$known,true)||!in_array($new,$known,true))throw new RuntimeException('Wybierz dwa różne miejsca z planu hali.');
        $order=Decka_DB::hall_tx(function()use($event,$old,$new){global $wpdb;
            $ticket=$wpdb->get_row($wpdb->prepare('SELECT t.*,o.email,o.offer_id,o.mode,o.status order_status FROM '.Decka_DB::table('tickets').' t JOIN '.Decka_DB::table('orders').' o ON o.id=t.order_id WHERE t.event_id=%d AND t.seat_id=%s AND t.status="valid" AND o.mode=%s AND o.status IN ("paid","free","voucher") FOR UPDATE',$event,$old,Decka_DB::mode()));if(!$ticket)throw new RuntimeException('Na starym miejscu nie ma ważnego biletu.');
            $tickets=$ticket->offer_id?$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Decka_DB::table('tickets').' WHERE order_id=%d AND seat_id=%s AND status="valid" ORDER BY event_id FOR UPDATE',$ticket->order_id,$old)):[$ticket];
            foreach($tickets as $row){$busy=$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id IN (0,%d) AND seat_id=%s LIMIT 1 FOR UPDATE',Decka_DB::mode(),$row->event_id,$new));if($busy!==null)throw new RuntimeException('Nowe miejsce jest niedostępne na jednym z meczów objętych biletem.');}
            foreach($tickets as $row){$state=$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id=%d AND seat_id=%s AND order_id=%d',Decka_DB::mode(),$row->event_id,$old,$ticket->order_id))?:'paid';Decka_DB::query($wpdb->prepare('DELETE FROM '.Decka_DB::table('inventory').' WHERE mode=%s AND event_id=%d AND seat_id=%s AND order_id=%d',Decka_DB::mode(),$row->event_id,$old,$ticket->order_id));Decka_DB::insert('inventory',['mode'=>Decka_DB::mode(),'event_id'=>$row->event_id,'seat_id'=>$new,'order_id'=>$ticket->order_id,'state'=>$state]);Decka_DB::update('items',['seat_id'=>$new],['order_id'=>$ticket->order_id,'event_id'=>$row->event_id,'seat_id'=>$old]);Decka_DB::update('tickets',['seat_id'=>$new,'nonce'=>bin2hex(random_bytes(24)),'used_at'=>null,'used_by'=>null],['id'=>$row->id]);}
            Decka_DB::update('orders',['mail_sent_at'=>null],['id'=>$ticket->order_id]);Decka_DB::audit('cashier_exchange',(int)$ticket->order_id,wp_json_encode(['event_id'=>$event,'events'=>array_map(fn($row)=>(int)$row->event_id,$tickets),'from'=>$old,'to'=>$new]));return (int)$ticket->order_id;
        });return ['order_id'=>$order,'message'=>'Miejsce zmienione. Stary kod QR przestał działać, a nowe miejsce zostało zapisane w systemie.'];
    }
}
