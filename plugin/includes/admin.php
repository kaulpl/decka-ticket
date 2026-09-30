<?php
final class Decka_Admin {
    public static function export():void {
        if(!current_user_can('decka_manage'))wp_die('Brak dostępu.',403);check_admin_referer('decka_stats');global $wpdb;$mode=Decka_DB::mode();$event=absint($_GET['event']??0);
        nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="Decka-statystyki-'.$mode.'-'.gmdate('Y-m-d').'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['ID meczu','Przeciwnik','Termin UTC','ID zamówienia','Środowisko','Status','E-mail','Sektor','Miejsce','Typ biletu','Kwota przypisana PLN','Data zakupu UTC','Data zapłaty UTC','Wejście UTC'],';','"','');$seats=array_column(Decka_DB::seats()['seats'],null,'id');
        for($offset=0;;$offset+=1000){$rows=$wpdb->get_results($wpdb->prepare('SELECT i.*,e.opponent,e.starts_at,o.mode,o.status,o.email,o.created_at,o.paid_at,t.used_at FROM '.Decka_DB::table('items').' i JOIN '.Decka_DB::table('orders').' o ON o.id=i.order_id JOIN '.Decka_DB::table('events').' e ON e.id=i.event_id LEFT JOIN '.Decka_DB::table('tickets').' t ON t.order_id=i.order_id AND t.event_id=i.event_id AND t.seat_id=i.seat_id WHERE o.mode=%s '.($event?'AND i.event_id='.(int)$event:'').' ORDER BY i.id LIMIT 1000 OFFSET %d',$mode,$offset));if(!$rows)break;
            foreach($rows as $r){$line=[$r->event_id,$r->opponent,$r->starts_at,$r->order_id,$r->mode,$r->status,$r->email,$seats[$r->seat_id]['sector']??'',$r->seat_id,$r->kind,number_format((int)$r->amount/100,2,',',''),$r->created_at,$r->paid_at,$r->used_at];$line=array_map(function($v){$s=(string)$v;return preg_match('/^[=+@\-\t\r\n]/',$s)?"'".$s:$s;},$line);fputcsv($out,$line,';','"','');}
        }fclose($out);exit;
    }
    public static function screens():array{return ['overview'=>'Pulpit','events'=>'Mecze','hall'=>'Plan hali','orders'=>'Zamówienia','tickets'=>'Bilety i vouchery','offers'=>'Mini-karnety','promos'=>'Promocje i kody','fans'=>'Kibice','gate'=>'Wejścia i bileterzy','reports'=>'Raporty','settings'=>'Ustawienia'];}
    public static function menu():void {
        add_menu_page('Decka Bilety','Decka Bilety','decka_manage','decka-bilety',[self::class,'page'],'dashicons-tickets-alt',30);
        foreach(self::screens() as $key=>$label)add_submenu_page('decka-bilety',$label.' · Decka Bilety',$label,'decka_manage',$key==='overview'?'decka-bilety':'decka-bilety-'.$key,[self::class,'page']);
    }
    public static function page():void {
        if(!current_user_can('decka_manage'))wp_die('Brak dostępu.',403);
        $screen=str_replace('decka-bilety-','',sanitize_key($_GET['page']??''));if(!isset(self::screens()[$screen]))$screen='overview';
        $url=add_query_arg(['action'=>'decka_app','view'=>'admin','screen'=>$screen],admin_url('admin-post.php'));
        echo '<style>#wpcontent{padding-left:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}.decka-admin-frame{width:100%;height:calc(100vh - 32px);min-height:720px;border:0;display:block}@media(max-width:782px){.decka-admin-frame{height:calc(100vh - 46px)}}</style><iframe class="decka-admin-frame" title="Panel administracyjny Decka Bilety" src="'.esc_url($url).'"></iframe>';
    }
}
