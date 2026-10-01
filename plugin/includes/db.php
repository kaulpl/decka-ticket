<?php
final class Decka_DB {
    public static function table(string $name): string {global $wpdb;return $wpdb->prefix.'decka_'.$name;}
    public static function query(string $sql): int {global $wpdb;$r=$wpdb->query($sql);if($r===false) throw new RuntimeException('Błąd zapisu bazy danych.');return (int)$r;}
    public static function insert(string $table,array $data): int {global $wpdb;if(false===$wpdb->insert(self::table($table),$data))throw new RuntimeException('Błąd zapisu danych.');return (int)$wpdb->insert_id;}
    public static function tx(callable $fn) {self::query('START TRANSACTION');try{$r=$fn();self::query('COMMIT');return $r;}catch(Throwable $e){self::query('ROLLBACK');throw $e;}}
    public static function install(): void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
        $schemas=[
        'events'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, external_id varchar(100) DEFAULT NULL, opponent varchar(190) NOT NULL, starts_at datetime DEFAULT NULL, date_label varchar(80) NOT NULL DEFAULT '', venue varchar(190) NOT NULL DEFAULT 'Hala ZKiW nr 1, Sambora 5A, Pelplin', normal_price int DEFAULT NULL, reduced_price int DEFAULT NULL, image_id bigint unsigned DEFAULT NULL, sale_open tinyint NOT NULL DEFAULT 0, gate_open datetime DEFAULT NULL, gate_close datetime DEFAULT NULL, cancelled tinyint NOT NULL DEFAULT 0, source_url text, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY external_id (external_id)",
        'offers'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, event_ids text NOT NULL, normal_price int NOT NULL, reduced_price int NOT NULL, active tinyint NOT NULL DEFAULT 0, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, PRIMARY KEY  (id)",
        'promos'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, code varchar(64) NOT NULL, type varchar(20) NOT NULL, value int NOT NULL, max_uses int NOT NULL DEFAULT 0, event_ids text NOT NULL, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, active tinyint NOT NULL DEFAULT 1, PRIMARY KEY  (id), UNIQUE KEY code (code)",
        'orders'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, user_id bigint unsigned NOT NULL, request_key varchar(64) NOT NULL, email varchar(190) NOT NULL, status varchar(24) NOT NULL, total int NOT NULL, discount int NOT NULL DEFAULT 0, promo_id bigint unsigned DEFAULT NULL, offer_id bigint unsigned DEFAULT NULL, session_id varchar(190) DEFAULT NULL, payment_id varchar(190) DEFAULT NULL, checkout_url text, stripe_payload longtext, package_ack varchar(40) DEFAULT NULL, created_at datetime NOT NULL, paid_at datetime DEFAULT NULL, mail_sent_at datetime DEFAULT NULL, mail_attempts int NOT NULL DEFAULT 0, last_error text, PRIMARY KEY  (id), UNIQUE KEY request_once (mode,user_id,request_key), UNIQUE KEY stripe_session (session_id), KEY status (status), KEY payment_id (payment_id)",
        'items'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, kind varchar(16) NOT NULL, amount int NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_seat (order_id,event_id,seat_id)",
        'inventory'=>"mode varchar(4) NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, order_id bigint unsigned NOT NULL, state varchar(16) NOT NULL, PRIMARY KEY  (mode,event_id,seat_id), KEY order_id (order_id)",
        'tickets'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, kind varchar(16) NOT NULL, nonce varchar(64) NOT NULL, status varchar(16) NOT NULL DEFAULT 'valid', used_at datetime DEFAULT NULL, used_by bigint unsigned DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY issued_once (order_id,event_id,seat_id)",
        'audit'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, actor bigint unsigned NOT NULL, action varchar(40) NOT NULL, object_id bigint unsigned NOT NULL DEFAULT 0, detail text, created_at datetime NOT NULL, PRIMARY KEY  (id)"
        ];
        foreach($schemas as $name=>$schema) dbDelta('CREATE TABLE '.self::table($name).' ('.str_replace(', ',",\n",$schema).") ENGINE=InnoDB $c;");
        foreach(array_keys($schemas) as $name){$table=self::table($name);$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table));if(!$row || strtoupper($row->Engine)!=='INNODB')wp_die('Decka Bilety wymaga tabel InnoDB.');}
        add_role('decka_bileter','Bileter Decka',['read'=>true,'decka_scan'=>true]);
        if($a=get_role('administrator')){$a->add_cap('decka_scan');$a->add_cap('decka_manage');}
        add_option('decka_settings',['mode'=>'test','normal'=>2500,'reduced'=>1500,'league_url'=>'https://rozgrywki.pzkosz.pl/liga/1/druzyny/d/7625/decka-pelplin/terminarz.html','team_id'=>'7625','registration'=>1],'','no');
        update_option('decka_schema_version','0.3.0',false);
        if(!wp_next_scheduled('decka_maintenance'))wp_schedule_event(time()+60,'decka_minute','decka_maintenance');
    }
    public static function audit(string $action,int $id,string $detail=''):void {self::insert('audit',['actor'=>get_current_user_id(),'action'=>$action,'object_id'=>$id,'detail'=>$detail,'created_at'=>gmdate('Y-m-d H:i:s')]);}
    public static function settings():array{return (array)get_option('decka_settings',[]);}
    public static function mode():string{return (self::settings()['mode']??'test')==='live'?'live':'test';}
    public static function seats():array{static $data;return $data??=json_decode(file_get_contents(DECKA_DIR.'data/seats.json'),true);}
}
