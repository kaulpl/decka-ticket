<?php
final class Decka_DB_Error extends RuntimeException {
    public function __construct(public array $diagnostic) {parent::__construct('Nie udało się zapisać zamówienia. Kod: '.$diagnostic['reference'].'. Przekaż ten kod obsłudze klubu.');}
}
final class Decka_Migration_Error extends RuntimeException {}
final class Decka_DB {
    public const SCHEMA_VERSION='0.3.6';
    private static int $transaction_depth=0;
    private static bool $installing=false;
    private static string $migration_step='start';
    private static string $migration_table='';
    public static function diagnose(string $operation,string $table,string $error):array {
        $reason='database';$field='';$index='';
        if(preg_match("/Unknown column '([a-zA-Z0-9_]+)'/i",$error,$m)){$reason='missing_column';$field=$m[1];}
        elseif(preg_match("/Field '([a-zA-Z0-9_]+)' doesn't have a default/i",$error,$m)){$reason='required_field';$field=$m[1];}
        elseif(preg_match("/(?:too long for column|Column) '([a-zA-Z0-9_]+)'/i",$error,$m)){$reason=str_contains(strtolower($error),'cannot be null')?'null_field':'value_length';$field=$m[1];}
        elseif(stripos($error,'Duplicate entry')!==false){
            $reason='duplicate';
            // Read only the final index identifier, never the duplicate value (which may be personal data).
            if(preg_match("/ for key ['`]([a-zA-Z0-9_]+\\.)?([a-zA-Z0-9_]{1,64})['`]\\s*$/i",$error,$m))$index=$m[2];
        }
        elseif(stripos($error,"doesn't exist")!==false)$reason='missing_table';
        elseif(stripos($error,'denied')!==false||stripos($error,'read only')!==false)$reason='permissions';
        elseif(stripos($error,'deadlock')!==false||stripos($error,'lock wait')!==false)$reason='busy';
        elseif(stripos($error,'incorrect string')!==false)$reason='encoding';
        elseif($error==='')$reason='validation';
        if(strlen($field)>64)$field='';
        $table=in_array($table,['orders','items','inventory','tickets','events','offers','promos','audit'],true)?$table:'query';
        return ['reference'=>'DB-'.strtoupper($table).'-'.strtoupper(bin2hex(random_bytes(3))),'time'=>gmdate('c'),'operation'=>$operation,'table'=>$table,'reason'=>$reason,'field'=>$field,'index'=>$index];
    }
    private static function remember(Decka_DB_Error $error):void {
        // Save after rollback: an option written inside the failed transaction would disappear.
        if(function_exists('update_option'))update_option('decka_db_diagnostic',$error->diagnostic,false);
        error_log('Decka database diagnostic: '.json_encode($error->diagnostic));
    }
    private static function fail(string $operation,string $table):never {
        global $wpdb;$error=new Decka_DB_Error(self::diagnose($operation,$table,(string)($wpdb->last_error??'')));
        if(self::$transaction_depth===0)self::remember($error);throw $error;
    }
    public static function maybe_upgrade():void {
        if(get_option('decka_schema_version')===self::SCHEMA_VERSION&&self::storage_ready())return;
        // A failed upgrade must not take down unrelated WordPress pages or run on every request.
        if(get_transient('decka_schema_retry'))return;
        set_transient('decka_schema_retry',1,300);
        try{self::install();delete_option('decka_schema_error');delete_transient('decka_schema_retry');}
        catch(Throwable $e){/* install() preserves the specific safe failure for the administrator. */}
    }

    public static function table(string $name): string {global $wpdb;return $wpdb->prefix.'dect_'.$name;}
    public static function query(string $sql,string $table='query'): int {global $wpdb;self::require_storage();$r=$wpdb->query($sql);if($r===false)self::fail('query',$table);return (int)$r;}
    public static function insert(string $table,array $data): int {global $wpdb;self::require_storage();if(false===$wpdb->insert(self::table($table),$data))self::fail('insert',$table);return (int)$wpdb->insert_id;}
    public static function update(string $table,array $data,array $where):int {
        global $wpdb;self::require_storage();$result=$wpdb->update(self::table($table),$data,$where);
        if($result===false)self::fail('update',$table);return (int)$result;
    }
    public static function require_storage():void {
        if(!self::$installing&&!self::storage_ready())
            throw new RuntimeException('Baza biletów nie została jeszcze przełączona na dect_. Administrator: Ustawienia → System → Ponów migrację.');
    }
    public static function tx(callable $fn) {
        global $wpdb;self::query('START TRANSACTION');self::$transaction_depth++;
        try{$r=$fn();self::query('COMMIT');self::$transaction_depth--;return $r;}
        catch(Throwable $e){$wpdb->query('ROLLBACK');self::$transaction_depth--;if($e instanceof Decka_DB_Error)self::remember($e);throw $e;}
    }
    private static function schemas():array {
        return [
        'events'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, external_id varchar(100) DEFAULT NULL, opponent varchar(190) NOT NULL, starts_at datetime DEFAULT NULL, date_label varchar(80) NOT NULL DEFAULT '', venue varchar(190) NOT NULL DEFAULT 'Hala ZKiW nr 1, Sambora 5A, Pelplin', normal_price int DEFAULT NULL, reduced_price int DEFAULT NULL, image_id bigint unsigned DEFAULT NULL, sale_open tinyint NOT NULL DEFAULT 0, gate_open datetime DEFAULT NULL, gate_close datetime DEFAULT NULL, cancelled tinyint NOT NULL DEFAULT 0, source_url text, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY external_id (external_id)",
        'offers'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, event_ids text NOT NULL, normal_price int NOT NULL, reduced_price int NOT NULL, active tinyint NOT NULL DEFAULT 0, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, PRIMARY KEY  (id)",
        'promos'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, code varchar(64) NOT NULL, type varchar(20) NOT NULL, value int NOT NULL, max_uses int NOT NULL DEFAULT 0, event_ids text NOT NULL, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, active tinyint NOT NULL DEFAULT 1, PRIMARY KEY  (id), UNIQUE KEY code (code)",
        'orders'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, user_id bigint unsigned NOT NULL, request_key varchar(64) NOT NULL, email varchar(190) NOT NULL, status varchar(24) NOT NULL, total int NOT NULL, discount int NOT NULL DEFAULT 0, promo_id bigint unsigned DEFAULT NULL, offer_id bigint unsigned DEFAULT NULL, session_id varchar(190) DEFAULT NULL, payment_id varchar(190) DEFAULT NULL, checkout_url text, stripe_payload longtext, package_ack varchar(40) DEFAULT NULL, created_at datetime NOT NULL, paid_at datetime DEFAULT NULL, mail_sent_at datetime DEFAULT NULL, mail_attempts int NOT NULL DEFAULT 0, last_error text, PRIMARY KEY  (id), UNIQUE KEY request_once (mode,user_id,request_key), UNIQUE KEY stripe_session (session_id), KEY status (status), KEY payment_id (payment_id)",
        'items'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, kind varchar(16) NOT NULL, amount int NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_seat (order_id,event_id,seat_id)",
        'inventory'=>"mode varchar(4) NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, order_id bigint unsigned NOT NULL, state varchar(16) NOT NULL, PRIMARY KEY  (mode,event_id,seat_id), KEY order_id (order_id)",
        'tickets'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL, seat_id varchar(16) NOT NULL, kind varchar(16) NOT NULL, nonce varchar(64) NOT NULL, status varchar(16) NOT NULL DEFAULT 'valid', used_at datetime DEFAULT NULL, used_by bigint unsigned DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY issued_once (order_id,event_id,seat_id)",
        'audit'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, actor bigint unsigned NOT NULL, action varchar(40) NOT NULL, object_id bigint unsigned NOT NULL DEFAULT 0, detail text, created_at datetime NOT NULL, PRIMARY KEY  (id)"
        ];
    }
    public static function inspect_schema():array {
        global $wpdb;$report=['checked_at'=>gmdate('c'),'tables'=>[],'issues'=>[]];
        foreach(self::schemas() as $name=>$schema){
            $table=self::table($name);$columns=$wpdb->get_results("SHOW COLUMNS FROM $table");
            if(!$columns){$report['issues'][]="$name: brak tabeli lub uprawnień odczytu struktury";continue;}
            preg_match_all('/(?:^|, )([a-z_]+) (?:bigint|varchar|datetime|int|tinyint|text|longtext)\b/',$schema,$matches);$expected=$matches[1];
            $seen=[];$safe=[];$definitions=[];
            foreach(preg_split("/'(?:''|[^'])*'(*SKIP)(*F)|, /",$schema) as $definition){if(preg_match('/^([a-z_]+) (.+)$/',$definition,$part))$definitions[$part[1]]=$part[2];}
            foreach($columns as $c){
                $seen[]=$c->Field;
                $known=in_array($c->Field,$expected,true);
                // Defaults can themselves contain private values; export only their category.
                $safe[]=['name'=>$c->Field,'type'=>$c->Type,'nullable'=>$c->Null==='YES','default'=>$c->Default===null?'NULL/none':($c->Default===''?'empty':'set'),'extra'=>$c->Extra,'expected'=>$known];
                if(!$known&&$c->Null!=='YES'&&$c->Default===null&&!preg_match('/auto_increment|generated/i',$c->Extra))$report['issues'][]="$name.$c->Field: dodatkowe wymagane pole bez wartości domyślnej";
                if($known){
                    $definition=$definitions[$c->Field];preg_match('/^([a-z]+(?:\(\d+\))?(?: unsigned)?)/',$definition,$type);
                    $normalize=fn($t)=>preg_replace('/\b(bigint|int|tinyint)\(\d+\)/','$1',strtolower($t));
                    if($normalize($c->Type)!==$normalize($type[1]))$report['issues'][]="$name.$c->Field: niezgodny typ lub długość pola";
                    if(($c->Null==='NO')!==str_contains($definition,'NOT NULL'))$report['issues'][]="$name.$c->Field: niezgodna obsługa NULL";
                    if(preg_match("/ DEFAULT ('(?:''|[^'])*'|NULL|[0-9]+)/",$definition,$default)){
                        $value=$default[1]==='NULL'?null:trim($default[1],"'");
                        if($c->Default!==null?(string)$c->Default!==$value:$value!==null)$report['issues'][]="$name.$c->Field: niezgodna wartość domyślna";
                    }
                }
                if($c->Field==='id'&&$known&&!str_contains(strtolower($c->Extra),'auto_increment'))$report['issues'][]="$name.id: brak AUTO_INCREMENT";
            }
            foreach(array_diff($expected,$seen) as $missing)$report['issues'][]="$name.$missing: brak wymaganej kolumny";
            $raw=$wpdb->get_results("SHOW INDEX FROM $table");$indices=[];
            foreach($raw?:[] as $i){$indices[$i->Key_name]['unique']=!(int)$i->Non_unique;$indices[$i->Key_name]['columns'][(int)$i->Seq_in_index]=['name'=>$i->Column_name,'prefix'=>$i->Sub_part];}
            preg_match_all('/(PRIMARY KEY  |UNIQUE KEY ([a-z_]+) )\(([^)]+)\)/',$schema,$keys,PREG_SET_ORDER);$expectedKeys=[];
            foreach($keys as $k){$key=$k[2]?:'PRIMARY';$expectedKeys[]=$key;$actual=$indices[$key]??null;$cols=$actual?array_values($actual['columns']):[];
                if(!$actual||!$actual['unique']||array_column($cols,'name')!==explode(',',$k[3])||array_filter(array_column($cols,'prefix')))$report['issues'][]="$name.$key: brak lub niezgodna definicja indeksu unikatowego";
            }
            foreach($indices as $key=>$index)if($index['unique']&&!in_array($key,$expectedKeys,true)){
                $cols=array_values($index['columns']);
                $report['issues'][]="$name.$key: dodatkowy indeks unikatowy — wymaga sprawdzenia";
            }
            $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table));
            if(!$engine||strtoupper($engine->Engine)!=='INNODB')$report['issues'][]="$name: wymagany silnik InnoDB";
            $report['tables'][$name]=['columns'=>$safe,'indexes'=>$indices,'engine'=>$engine->Engine??'unknown'];
        }
        $report['ok']=!$report['issues'];return $report;
    }
    public static function migration_inventory():array {
        global $wpdb;$out=['source_prefix'=>$wpdb->prefix.'decka_','target_prefix'=>$wpdb->prefix.'dect_','tables'=>[]];
        foreach(array_keys(self::schemas()) as $name){$row=['name'=>$name];
            foreach(['source'=>'decka_','target'=>'dect_'] as $side=>$prefix){
                $table=$wpdb->prefix.$prefix.$name;$exists=(bool)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
                $info=$exists?$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table)):null;
                $row[$side]=['exists'=>$exists,'rows'=>$exists?(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"):null,'engine'=>$info->Engine??null];
            }$out['tables'][]=$row;
        }return $out;
    }
    public static function storage_ready():bool {
        global $wpdb;
        if(get_option('decka_storage_namespace')==='dect')return true;
        $state=self::table('state');
        // Older versions have no state table. Suppress only this metadata lookup's missing-table message.
        $old=$wpdb->suppress_errors(true);$ready=$wpdb->get_var("SELECT value FROM $state WHERE name='namespace'")==='dect';$wpdb->suppress_errors($old);
        return $ready;
    }
    private static function migration_failure(Throwable $e):void {
        $detail=$e instanceof Decka_DB_Error?$e->diagnostic:null;
        $message=$e instanceof Decka_Migration_Error?$e->getMessage():($detail?'Baza odrzuciła operację. Kod: '.$detail['reference'].'. Sprawdź przyczynę, pole lub indeks w szczegółach poniżej.':'Nieoczekiwany błąd migracji. Sprawdź dziennik PHP hostingu dla podanego czasu.');
        $status=['state'=>'failed','time'=>gmdate('c'),'step'=>self::$migration_step,'table'=>self::$migration_table,'message'=>$message,'diagnostic'=>$detail];
        update_option('decka_migration_status',$status,false);update_option('decka_schema_error',$message,false);
    }
    public static function install():void {
        global $wpdb;$lock='dect_schema_'.substr(hash('sha256',$wpdb->prefix),0,24);$locked=false;
        self::$migration_step='lock';self::$migration_table='';
        try{
            $result=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock));
            if($result===null)throw new Decka_Migration_Error('Serwer nie udostępnił blokady migracji. Hosting powinien sprawdzić obsługę funkcji GET_LOCK i uprawnienia bazy.');
            if((int)$result!==1)throw new Decka_Migration_Error('Inne żądanie nadal aktualizuje bazę. Odczekaj minutę i ponów migrację.');
            $locked=true;self::$installing=true;
            update_option('decka_migration_status',['state'=>'running','time'=>gmdate('c'),'step'=>'schema','table'=>'','message'=>'Przygotowanie tabel i migracji.'],false);
            self::install_locked();delete_option('decka_schema_error');delete_transient('decka_schema_retry');
            update_option('decka_migration_status',['state'=>'complete','time'=>gmdate('c'),'step'=>'complete','table'=>'','message'=>'Nowe tabele są aktywne. Migracja została zatwierdzona.'],false);
        }catch(Throwable $e){self::migration_failure($e);throw $e;}
        finally{self::$installing=false;if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function install_locked():void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
        self::$migration_step='schema';self::$migration_table='';
        $schemas=self::schemas();
        dbDelta('CREATE TABLE '.self::table('state')." (name varchar(64) NOT NULL,\nvalue longtext NOT NULL,\nPRIMARY KEY  (name)) ENGINE=InnoDB $c;");
        $stateEngine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',self::table('state')));
        if(!$stateEngine||strtoupper($stateEngine->Engine)!=='INNODB')throw new Decka_Migration_Error('Nie można utworzyć tabeli stanu migracji dect_state w InnoDB. Sprawdź uprawnienia CREATE.');
        foreach($schemas as $name=>$schema) dbDelta('CREATE TABLE '.self::table($name).' ('.preg_replace("/'(?:''|[^'])*'(*SKIP)(*F)|, /",",\n",$schema).") ENGINE=InnoDB $c;");
        foreach(array_keys($schemas) as $name){$table=self::table($name);$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table));if(!$row || strtoupper($row->Engine)!=='INNODB')throw new Decka_Migration_Error('Decka Bilety wymaga tabel InnoDB.');}
        // dbDelta's result describes intended changes; verify the actual columns before marking success.
        foreach($schemas as $name=>$schema){
            $columns=$wpdb->get_col('SHOW COLUMNS FROM '.self::table($name));
            preg_match_all('/(?:^|, )([a-z_]+) (?:bigint|varchar|datetime|int|tinyint|text|longtext)\b/',$schema,$matches);
            if(!$columns||array_diff($matches[1],$columns))throw new Decka_Migration_Error('Brak wymaganych kolumn w tabeli biletowej '.$name.'. Sprawdź uprawnienia ALTER na hostingu.');
        }
        // dbDelta does not reliably change nullability or DEFAULT NULL on an existing varchar.
        // A legacy empty session default collides with stripe_session before Stripe is called.
        $orders=self::table('orders');
        $session=$wpdb->get_row("SHOW COLUMNS FROM $orders LIKE 'session_id'");
        if(!$session)throw new Decka_Migration_Error('Brak pola sesji płatności.');
        if($session->Null!=='YES'||$session->Default!==null){
            self::query("ALTER TABLE $orders MODIFY COLUMN session_id varchar(190) NULL DEFAULT NULL");
            $session=$wpdb->get_row("SHOW COLUMNS FROM $orders LIKE 'session_id'");
            if(!$session||$session->Null!=='YES'||$session->Default!==null)throw new Decka_Migration_Error('Nie ukończono naprawy pola sesji płatności.');
        }
        // Empty strings are not Stripe session IDs. Preserve every nonempty ID and its unique index.
        self::query("UPDATE $orders SET session_id=NULL WHERE session_id=''");
        self::$migration_step='validate_schema';
        $report=self::inspect_schema();update_option('decka_schema_audit',$report,false);
        if(!$report['ok'])throw new Decka_Migration_Error('Struktura bazy wymaga sprawdzenia: '.implode('; ',$report['issues']));
        self::migrate_legacy();
        add_role('decka_bileter','Bileter Decka',['read'=>true,'decka_scan'=>true]);
        if($a=get_role('administrator')){$a->add_cap('decka_scan');$a->add_cap('decka_manage');}
        add_option('decka_settings',['mode'=>'test','normal'=>2500,'reduced'=>1500,'league_url'=>'https://rozgrywki.pzkosz.pl/liga/1/druzyny/d/7625/decka-pelplin/terminarz.html','team_id'=>'7625','registration'=>1],'','no');
        update_option('decka_schema_version',self::SCHEMA_VERSION,false);
        if(!wp_next_scheduled('decka_maintenance'))wp_schedule_event(time()+60,'decka_minute','decka_maintenance');
    }
    /** Keep the first order's retry key; only disambiguate later legacy collisions. */
    private static function legacy_request_expression(string $source,string $target,array &$adjustments):string {
        global $wpdb;
        $columns=$wpdb->get_results("SHOW FULL COLUMNS FROM $target");$collations=[];
        foreach($columns as $column)if(in_array($column->Field,['mode','request_key'],true))$collations[$column->Field]=$column->Collation;
        $normalized=[];
        foreach(['mode','request_key'] as $field){
            $collation=$collations[$field]??'';$charset=explode('_',$collation)[0];
            if(!preg_match('/^[a-z0-9_]+$/i',$collation)||!preg_match('/^[a-z0-9]+$/i',$charset))throw new Decka_Migration_Error('Nie można ustalić reguł porównywania kluczy zamówień.');
            $normalized[$field]="CONVERT(a.`$field` USING $charset) COLLATE $collation";
        }
        $group="SELECT MIN(a.id) first_id,a.user_id,".$normalized['mode']." mode_key,".$normalized['request_key']." retry_key FROM $source a GROUP BY a.user_id,mode_key,retry_key HAVING COUNT(*)>1";
        $join=$normalized['mode']." = g.mode_key AND ".$normalized['request_key']." = g.retry_key AND a.user_id=g.user_id AND a.id<>g.first_id";
        // Compare with the destination collation, which can differ from the archive.
        $ids=$wpdb->get_col("SELECT a.id FROM $source a JOIN ($group) g ON $join ORDER BY a.id");
        if($wpdb->last_error)self::fail('query','orders');
        if(!$ids)return 's.`request_key`';
        $expression='CASE s.id';
        foreach($ids as $id){
            // Namespaced deterministic keys are stable across rolled-back retries.
            $key=hash('sha256','dect-migration-request:v1:'.$wpdb->prefix.':'.$id);
            $expression.=$wpdb->prepare(' WHEN %d THEN %s',(int)$id,$key);
        }
        $adjustments['request_keys']=count($ids);
        // Any unexpected hash collision is still rejected by the unique index, atomically.
        return $expression.' ELSE s.`request_key` END';
    }
    private static function migrate_legacy():void {
        global $wpdb;
        self::$migration_step='source_inventory';self::$migration_table='';
        $state=self::table('state');
        // The commit marker belongs to our InnoDB table, never to a potentially MyISAM wp_options.
        if(self::storage_ready()){
            self::query("INSERT INTO $state (name,value) VALUES ('namespace','dect') ON DUPLICATE KEY UPDATE value='dect'");
            update_option('decka_storage_namespace','dect',false);return;
        }
        $names=array_keys(self::schemas());$sources=[];
        foreach($names as $name){
            $source=$wpdb->prefix.'decka_'.$name;
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($source)));
            if($exists){
                $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$source));
                if(!$engine||strtoupper($engine->Engine)!=='INNODB')throw new Decka_Migration_Error('Stara tabela '.$name.' wymaga InnoDB przed migracją.');
                $sources[$name]=$source;
            }
        }
        if($sources&&count($sources)!==count($names))throw new Decka_Migration_Error('Brakuje starych tabel: '.implode(', ',array_diff($names,array_keys($sources))).'. Przywróć je z kopii bazy; niczego nie usunięto.');
        add_option('decka_storage_namespace','pending','','no');
        $counts=[];$adjustments=[];
        self::tx(function()use($wpdb,$names,$sources,$state,&$counts,&$adjustments){
            self::$migration_step='check_target';
            foreach($names as $name){
                self::$migration_table=$name;
                $target=self::table($name);
                if((int)$wpdb->get_var("SELECT COUNT(*) FROM $target"))throw new Decka_Migration_Error('Docelowa tabela '.$name.' nie jest pusta. Migracja nie nadpisuje istniejących danych.');
            }
            foreach($sources as $name=>$source){
                self::$migration_step='copy';self::$migration_table=$name;
                $target=self::table($name);$sourceColumns=$wpdb->get_col("SHOW COLUMNS FROM $source");$targetColumns=$wpdb->get_results("SHOW COLUMNS FROM $target");$common=[];
                foreach($targetColumns as $c){
                    if(in_array($c->Field,$sourceColumns,true))$common[]=$c->Field;
                    elseif($c->Field==='id'||($c->Null!=='YES'&&$c->Default===null))throw new Decka_Migration_Error('Brak wymaganej kolumny źródłowej '.$name.'.'.$c->Field.'.');
                }
                // Lock source rows until the complete copy commits. Copy data only, never legacy indexes/triggers.
                self::query("SELECT * FROM $source FOR UPDATE",$name);
                $quoted=implode(',',array_map(fn($c)=>'`'.$c.'`',$common));
                $expressions=array_combine($common,array_map(fn($c)=>"s.`$c`",$common));
                if($name==='orders'&&isset($expressions['request_key']))$expressions['request_key']=self::legacy_request_expression($source,$target,$adjustments);
                $projection=implode(',',$expressions);
                self::query("INSERT INTO $target ($quoted) SELECT $projection FROM $source s",$name);
                if($wpdb->get_results('SHOW WARNINGS'))throw new Decka_Migration_Error('Konwersja danych w tabeli '.$name.' wymaga sprawdzenia. Migracja została wycofana.');
                $primary=$name==='inventory'?['mode','event_id','seat_id']:['id'];
                $join=implode(' AND ',array_map(fn($c)=>"s.`$c`=t.`$c`",$primary));
                $same=implode(' AND ',array_map(fn($c)=>"(".$expressions[$c].") <=> t.`$c`",$common));
                if((int)$wpdb->get_var("SELECT COUNT(*) FROM $source s JOIN $target t ON $join WHERE NOT ($same)"))throw new Decka_Migration_Error('Niezgodność danych podczas migracji '.$name.'.');
                $sourceCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM $source");$targetCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM $target");
                if($sourceCount!==$targetCount)throw new Decka_Migration_Error('Niezgodna liczba rekordów podczas migracji '.$name.'.');
                $counts[$name]=$targetCount;
            }
            self::$migration_step='commit';self::$migration_table='state';
            self::query("INSERT INTO $state (name,value) VALUES ('namespace','dect') ON DUPLICATE KEY UPDATE value='dect'");
        });
        update_option('decka_storage_namespace','dect',false);
        wp_cache_delete('decka_storage_namespace','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
        update_option('decka_storage_migration',['completed_at'=>gmdate('c'),'source'=>'decka_','target'=>'dect_','rows'=>$counts,'adjustments'=>$adjustments,'legacy_preserved'=>true],false);
    }
    public static function audit(string $action,int $id,string $detail=''):void {self::insert('audit',['actor'=>get_current_user_id(),'action'=>$action,'object_id'=>$id,'detail'=>$detail,'created_at'=>gmdate('Y-m-d H:i:s')]);}
    public static function settings():array{return (array)get_option('decka_settings',[]);}
    public static function mode():string{return (self::settings()['mode']??'test')==='live'?'live':'test';}
    public static function seats():array{static $data;return $data??=json_decode(file_get_contents(DECKA_DIR.'data/seats.json'),true);}
}
