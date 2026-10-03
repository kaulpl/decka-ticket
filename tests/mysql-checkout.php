<?php
/** Run only in the disposable CI WordPress database, with Stripe entirely intercepted. */
if(getenv('DECKA_CI_MYSQL')!=='1'||DB_NAME!=='decka_test')throw new RuntimeException('This test requires the disposable CI database.');
global $wpdb;
function decka_check($ok,$label){global $wpdb;if(!$ok)throw new RuntimeException($label.' | '.$wpdb->last_error);echo "PASS $label\n";}
// Restore strict settings WordPress relaxes, matching stricter managed hosts.
$wpdb->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$settings=Decka_DB::settings();$settings['test_secret']=Decka_Stripe::encrypt('sk_test_ci_placeholder');$settings['test_webhook']=Decka_Stripe::encrypt('whsec_ci_placeholder');update_option('decka_settings',$settings);
add_filter('pre_http_request',function($pre,$args,$url){if(str_starts_with($url,'https://api.stripe.com/')){if($url!=='https://api.stripe.com/v1/checkout/sessions')throw new RuntimeException('Unexpected Stripe request');parse_str($args['body'],$body);return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['id'=>'cs_test_'.$body['client_reference_id'],'url'=>'https://checkout.stripe.com/test','metadata'=>$body['metadata']]),'cookies'=>[]];}return $pre;},10,3);
$uid=wp_insert_user(['user_login'=>'ci_fan','user_email'=>'ci@example.test','user_pass'=>wp_generate_password(),'role'=>'subscriber']);decka_check(!is_wp_error($uid),'fan created');wp_set_current_user($uid);
$event=Decka_DB::insert('events',['opponent'=>'CI match','starts_at'=>gmdate('Y-m-d H:i:s',time()+86400),'sale_open'=>1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
try{$o=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'66','kind'=>'normal']]]);}catch(Throwable $e){throw new RuntimeException($e->getMessage().' | '.$wpdb->last_error,0,$e);}
decka_check($o['status']==='pending'&&$o['total']===2500,'strict MySQL paid checkout reaches Stripe');
decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('inventory'))===1,'one inventory hold');
// Exercise upgrading an existing database without losing the existing order.
$wpdb->query('ALTER TABLE '.Decka_DB::table('orders').' DROP COLUMN package_ack');$wpdb->query('ALTER TABLE '.Decka_DB::table('events').' DROP COLUMN normal_price, DROP COLUMN reduced_price, DROP COLUMN image_id');
Decka_DB::install();
$wpdb->flush();$wpdb->col_meta=[];
$again=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'67','kind'=>'normal']]]);
decka_check($again['status']==='pending','paid checkout after schema upgrade');decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('orders'))===2,'migration preserves orders');

// Capture an actual failed INSERT, then prove rollback did not erase the diagnosis.
$wpdb->query('ALTER TABLE '.Decka_DB::table('orders').' ADD COLUMN legacy_required int NOT NULL');
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('orders'));$old=$wpdb->suppress_errors(true);$caught=null;
try{Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'68','kind'=>'normal']]]);}catch(Decka_DB_Error $e){$caught=$e;}
$wpdb->suppress_errors($old);decka_check($caught instanceof Decka_DB_Error,'failed checkout has safe reference');
$diagnostic=get_option('decka_db_diagnostic');decka_check($diagnostic['reason']==='required_field'&&$diagnostic['field']==='legacy_required','actual MySQL failure classified');decka_check($diagnostic['reference']===$caught->diagnostic['reference'],'diagnostic survives rollback');decka_check(!str_contains(wp_json_encode($diagnostic),'ci@example.test'),'diagnostic contains no buyer email');decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('orders'))===$before,'failed checkout leaves no order');
$wpdb->query('ALTER TABLE '.Decka_DB::table('orders').' DROP COLUMN legacy_required');
// A failed/no-op dbDelta must not falsely mark an incomplete schema current.
$wpdb->query('ALTER TABLE '.Decka_DB::table('orders').' DROP COLUMN package_ack');update_option('decka_schema_version','0.2.0');
$disable=fn($queries)=>[];add_filter('dbdelta_queries',$disable);$failed=false;try{Decka_DB::install();}catch(RuntimeException $e){$failed=true;}remove_filter('dbdelta_queries',$disable);
decka_check($failed&&get_option('decka_schema_version')==='0.2.0','incomplete migration is not marked successful');
Decka_DB::install();decka_check(get_option('decka_schema_version')===Decka_DB::SCHEMA_VERSION,'repair verifies schema');

// Reproduce the reported failure class: a legacy empty session ID default conflicts
// with the unique Stripe index even though no Stripe request has been made yet.
$orders=Decka_DB::table('orders');
$legacy=['mode'=>'test','user_id'=>$uid,'request_key'=>bin2hex(random_bytes(16)),'email'=>'ci@example.test','status'=>'cancelled','total'=>0,'created_at'=>gmdate('Y-m-d H:i:s'),'session_id'=>''];
$legacyId=Decka_DB::insert('orders',$legacy);
Decka_DB::query("ALTER TABLE $orders MODIFY COLUMN session_id varchar(190) NOT NULL DEFAULT ''");
$legacy['request_key']=bin2hex(random_bytes(16));unset($legacy['session_id']);
$old=$wpdb->suppress_errors(true);$caught=null;
try{Decka_DB::insert('orders',$legacy);}catch(Decka_DB_Error $e){$caught=$e;}
$wpdb->suppress_errors($old);
decka_check($caught&&$caught->diagnostic['reason']==='duplicate'&&$caught->diagnostic['index']==='stripe_session','legacy empty session reproduces exact orders insert duplicate class');
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders");
Decka_DB::install();$wpdb->flush();$wpdb->col_meta=[];
$column=$wpdb->get_row("SHOW COLUMNS FROM $orders LIKE 'session_id'");
decka_check($column->Null==='YES'&&$column->Default===null,'repair restores nullable session with NULL default');
decka_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before,'repair preserves all orders');
decka_check($wpdb->get_var("SELECT session_id FROM $orders WHERE id=$legacyId")===null,'only empty legacy session normalized');
decka_check($wpdb->get_var($wpdb->prepare("SELECT session_id FROM $orders WHERE id=%d",$o['order_id']))==='cs_test_'.$o['order_id'],'existing real session retained');
$key=bin2hex(random_bytes(16));$input=['request_key'=>$key,'event_id'=>$event,'seats'=>[['id'=>'68','kind'=>'normal']]];
$fixed=Decka_Service::create($input);decka_check($fixed['status']==='pending','checkout reaches Stripe after legacy session repair');
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders");
$retry=Decka_Service::create($input);decka_check($retry['order_id']===$fixed['order_id']&&(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before,'repeat click reuses order without duplicate insert');
// Explicit NULL also protects new checkouts if a nullable empty default reappears.
Decka_DB::query("ALTER TABLE $orders ALTER COLUMN session_id SET DEFAULT ''");
decka_check($wpdb->update($orders,['session_id'=>''],['id'=>$legacyId])===1,'empty default collision fixture restored');
$next=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'69','kind'=>'normal']]]);
decka_check($next['status']==='pending','checkout explicitly writes NULL independently of default');
$old=$wpdb->suppress_errors(true);$blocked=$wpdb->update($orders,['session_id'=>'cs_test_'.$o['order_id']],['id'=>$next['order_id']]);$wpdb->suppress_errors($old);
decka_check($blocked===false,'real Stripe session uniqueness still enforced');
Decka_DB::install();Decka_DB::install();decka_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before+1,'repair is repeatable and preserves orders');

$venue=$wpdb->get_row('SHOW COLUMNS FROM '.Decka_DB::table('events')." LIKE 'venue'");
decka_check($venue->Default==='Hala ZKiW nr 1, Sambora 5A, Pelplin','migration preserves commas in quoted venue default');

// Full checkout audit: paid settlement, issuance, package, voucher, and atomic failures.
$settings=Decka_DB::settings();$settings['max_per_fan']=340;update_option('decka_settings',$settings);
function decka_paid_session($id){$o=Decka_Service::order($id);return ['id'=>$o->session_id,'client_reference_id'=>(string)$id,'metadata'=>['decka_order'=>(string)$id],'livemode'=>false,'currency'=>'pln','amount_total'=>(int)$o->total,'payment_status'=>'paid','payment_intent'=>'pi_ci_'.$id];}
Decka_Service::settle(decka_paid_session($o['order_id']),'test');Decka_Service::settle(decka_paid_session($o['order_id']),'test');
decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('tickets').' WHERE order_id='.$o['order_id'])===1,'duplicate settlement issues exactly one ticket');
$ticket=$wpdb->get_row('SELECT * FROM '.Decka_DB::table('tickets').' WHERE order_id='.$o['order_id']);$originalQr=Decka_Domain::token($ticket,wp_salt('secure_auth'));
Decka_DB::update('events',['gate_open'=>gmdate('Y-m-d H:i:s',time()-3600),'gate_close'=>gmdate('Y-m-d H:i:s',time()+3600)],['id'=>$event]);
// Turn the populated installation into an older decka_ schema with foreign constraints.
$tables=['events','offers','promos','orders','items','inventory','tickets','audit'];$snapshots=[];
foreach($tables as $table){$current=Decka_DB::table($table);$snapshots[$table]=$wpdb->get_results("SELECT * FROM $current",ARRAY_A);Decka_DB::query("RENAME TABLE $current TO {$wpdb->prefix}decka_$table");}
$oldOrders=$wpdb->prefix.'decka_orders';
$wpdb->query("ALTER TABLE $oldOrders ADD COLUMN order_number varchar(50) NULL, ADD UNIQUE KEY order_number (order_number), ADD COLUMN legacy_required int NOT NULL");
$wpdb->query("UPDATE $oldOrders SET order_number=CONCAT('OLD-',id)");$wpdb->query("UPDATE $oldOrders SET order_number='' WHERE id=$legacyId");$wpdb->query("ALTER TABLE $oldOrders MODIFY COLUMN order_number varchar(50) NOT NULL DEFAULT ''");
$old=$wpdb->suppress_errors(true);$legacy['request_key']=bin2hex(random_bytes(16));$legacy['legacy_required']=0;
$duplicate=$wpdb->insert($oldOrders,$legacy);$diagnosis=Decka_DB::diagnose('insert','orders',$wpdb->last_error);$wpdb->suppress_errors($old);
decka_check($duplicate===false&&$diagnosis['index']==='order_number','reported legacy order_number conflict reproduced');
// Reproduce request_once collisions, including a source with case-sensitive keys.
$wpdb->query("ALTER TABLE $oldOrders DROP INDEX request_once, MODIFY request_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
$duplicateIds=[$again['order_id'],$legacyId];
$wpdb->update($oldOrders,['request_key'=>'Legacy-Duplicate'],['id'=>$o['order_id']]);
$wpdb->update($oldOrders,['request_key'=>'Legacy-Duplicate'],['id'=>$again['order_id']]);
$wpdb->update($oldOrders,['request_key'=>'legacy-duplicate'],['id'=>$legacyId]);
foreach($snapshots['orders'] as &$row){if((int)$row['id']===$o['order_id'])$row['request_key']='Legacy-Duplicate';elseif(in_array((int)$row['id'],$duplicateIds,true))$row['request_key']=(int)$row['id']===$legacyId?'legacy-duplicate':'Legacy-Duplicate';}unset($row);
$legacySnapshot=$wpdb->get_results("SELECT * FROM $oldOrders ORDER BY id",ARRAY_A);
$wpdb->query('DELETE FROM '.Decka_DB::table('state'));delete_option('decka_storage_namespace');update_option('decka_schema_version','0.3.2');
$wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=MyISAM");
$blocked=false;try{Decka_DB::require_storage();}catch(RuntimeException $e){$blocked=true;}decka_check($blocked,'checkout blocked before complete migration');
// Force a failure after events/orders copied, proving the entire migration rolls back.
$legacyItems=$wpdb->prefix.'decka_items';$firstItem=$snapshots['items'][0];
$wpdb->query("ALTER TABLE $legacyItems MODIFY COLUMN amount varchar(30) NOT NULL");
$wpdb->update($legacyItems,['amount'=>'invalid-number'],['id'=>$firstItem['id']]);
$old=$wpdb->suppress_errors(true);$migrationFailed=false;try{Decka_DB::install();}catch(RuntimeException $e){$migrationFailed=true;}$wpdb->suppress_errors($old);
decka_check($migrationFailed&&!Decka_DB::storage_ready(),'failed migration never switches active namespace');
$status=get_option('decka_migration_status');decka_check($status['state']==='failed'&&$status['step']==='copy'&&$status['table']==='items','specific failed migration stage and table persisted');
$inspection=Decka_Admin_API::action(['operation'=>'database_inspect']);decka_check($inspection['ok']&&is_array(get_option('decka_schema_audit')),'admin inspection works before migration without writing to blocked audit table');
foreach($tables as $t)decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table($t))===0,"failed migration rolls back $t");
decka_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $oldOrders")===count($snapshots['orders']),'failed migration leaves source intact');
$wpdb->update($legacyItems,['amount'=>(string)$firstItem['amount']],['id'=>$firstItem['id']]);$wpdb->query("ALTER TABLE $legacyItems MODIFY COLUMN amount int NOT NULL");
// Never merge into or replace an already-populated target without a committed marker.
$wpdb->insert(Decka_DB::table('audit'),['actor'=>0,'action'=>'collision_fixture','created_at'=>gmdate('Y-m-d H:i:s')]);
$refused=false;try{Decka_DB::install();}catch(RuntimeException $e){$refused=true;}
decka_check($refused&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('audit'))===1,'nonempty target preserved and migration refused');
$wpdb->query('DELETE FROM '.Decka_DB::table('audit'));
Decka_DB::install();$wpdb->flush();$wpdb->col_meta=[];
decka_check(get_option('decka_storage_namespace')==='dect','migration activates dect namespace with MyISAM WordPress options');
decka_check($wpdb->get_var('SELECT value FROM '.Decka_DB::table('state')." WHERE name='namespace'")==='dect','migration marker committed inside own InnoDB table');
delete_option('decka_storage_namespace');
decka_check(Decka_DB::storage_ready(),'committed marker keeps storage active when compatibility option is missing');
Decka_DB::install();
decka_check(get_option('decka_storage_namespace')==='dect','retry restores compatibility option without copying populated tables again');
$wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=InnoDB");
$migration=get_option('decka_storage_migration');decka_check($migration['adjustments']['request_keys']===2,'migration resolves exact and destination-collation request_once collisions');
decka_check($wpdb->get_results("SELECT * FROM $oldOrders ORDER BY id",ARRAY_A)===$legacySnapshot,'source orders remain byte-for-byte unchanged');
foreach($snapshots['orders'] as &$row)if(in_array((int)$row['id'],$duplicateIds,true))$row['request_key']=hash('sha256','dect-migration-request:v1:'.$wpdb->prefix.':'.$row['id']);unset($row);
decka_check($wpdb->get_var($wpdb->prepare('SELECT request_key FROM '.Decka_DB::table('orders').' WHERE id=%d',$o['order_id']))==='Legacy-Duplicate','oldest order preserves original retry key');
foreach($tables as $table){
    $rows=$wpdb->get_results('SELECT * FROM '.Decka_DB::table($table),ARRAY_A);
    // Row order is not part of the database contract.
    $sort=static function(&$rows){foreach($rows as &$r)ksort($r);unset($r);usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));};$sort($rows);$sort($snapshots[$table]);
    decka_check($rows===$snapshots[$table],"migration preserves every $table field and identifier");
}
decka_check($wpdb->get_row("SELECT order_number FROM $oldOrders WHERE id=$legacyId")->order_number===''&&(int)$wpdb->get_var("SELECT COUNT(*) FROM $oldOrders")===count($snapshots['orders']),'legacy tables and extra values preserved');
$report=Decka_DB::inspect_schema();decka_check($report['ok'],'all new columns and unique indexes match contract');decka_check(!str_contains(json_encode($report),'ci@example.test')&&!str_contains(json_encode($report),'whsec_'),'schema report excludes customer and payment secrets');
$columns=$wpdb->get_col('SHOW COLUMNS FROM '.Decka_DB::table('orders'));decka_check(!in_array('order_number',$columns,true)&&!in_array('legacy_required',$columns,true),'foreign columns and constraints not copied');
decka_check(Decka_Tickets::scan($originalQr,$event)['valid'],'previously issued QR works after migration');
$paid=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'70','kind'=>'reduced']]]);
decka_check($paid['status']==='pending'&&$paid['order_id']>max(array_column($snapshots['orders'],'id')),'new order works with preserved auto increment');
$voucher=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'email'=>'voucher@example.test','seats'=>[['id'=>'71','kind'=>'normal']]],true);
decka_check($voucher['status']==='voucher'&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('tickets').' WHERE order_id='.$voucher['order_id'])===1,'voucher writes order item inventory ticket and audit');
$event2=Decka_DB::insert('events',['opponent'=>'Package match','starts_at'=>gmdate('Y-m-d H:i:s',time()+172800),'sale_open'=>1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
$offer=Decka_DB::insert('offers',['name'=>'CI package','event_ids'=>json_encode([$event,$event2]),'normal_price'=>4500,'reduced_price'=>2500,'active'=>1]);
$package=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'offer_id'=>$offer,'package_ack'=>true,'seats'=>[['id'=>'72','kind'=>'normal']]]);
Decka_Service::settle(decka_paid_session($package['order_id']),'test');
decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('tickets').' WHERE order_id='.$package['order_id'])===2&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table('inventory').' WHERE order_id='.$package['order_id'])===2,'package writes a ticket and inventory for each match');
// A later write must not leave an order or a partial seat reservation behind.
$items=Decka_DB::table('items');$wpdb->query("ALTER TABLE $items ADD COLUMN unexpected_required int NOT NULL");
$counts=[];foreach(['orders','items','inventory'] as $t)$counts[$t]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table($t));
$old=$wpdb->suppress_errors(true);$caught=null;
try{Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'73','kind'=>'normal']]]);}catch(Decka_DB_Error $e){$caught=$e;}$wpdb->suppress_errors($old);
decka_check($caught&&$caught->diagnostic['table']==='items','later item write failure has correct diagnosis');
foreach($counts as $t=>$count)decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table($t))===$count,"item failure rolls back $t");
$report=Decka_DB::inspect_schema();decka_check(!$report['ok']&&str_contains(implode(' ',$report['issues']),'unexpected_required'),'audit detects foreign required field in any table');$wpdb->query("ALTER TABLE $items DROP COLUMN unexpected_required");
// An update failure on paid confirmation must not issue a ticket or acknowledge payment.
$tickets=Decka_DB::table('tickets');$wpdb->query("ALTER TABLE $tickets ADD COLUMN unexpected_required int NOT NULL");
$old=$wpdb->suppress_errors(true);$caught=null;try{Decka_Service::settle(decka_paid_session($paid['order_id']),'test');}catch(Decka_DB_Error $e){$caught=$e;}$wpdb->suppress_errors($old);
$after=Decka_Service::order($paid['order_id']);decka_check($caught&&$after->status==='pending'&&$after->payment_id===null,'ticket failure rolls back payment data and status');$wpdb->query("ALTER TABLE $tickets DROP COLUMN unexpected_required");
Decka_Service::settle(decka_paid_session($paid['order_id']),'test');decka_check(Decka_Service::order($paid['order_id'])->status==='paid','webhook retry completes after failed write');
Decka_DB::install();decka_check(Decka_Service::order($paid['order_id'])->status==='paid','repeat installation never recopies stale legacy data');

// Check failures in UPDATE as well as INSERT. Stripe has been called, so retain the
// same creating order and reservation for an idempotent retry; never start a new order.
$orders=Decka_DB::table('orders');$trigger=$wpdb->prefix.'dect_ci_update_failure';
$wpdb->query("CREATE TRIGGER $trigger BEFORE UPDATE ON $orders FOR EACH ROW BEGIN IF NEW.status='pending' AND OLD.status='creating' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI forced session update failure'; END IF; END");
$key=bin2hex(random_bytes(16));$retryInput=['request_key'=>$key,'event_id'=>$event,'seats'=>[['id'=>'74','kind'=>'normal']]];
$old=$wpdb->suppress_errors(true);$caught=null;try{Decka_Service::create($retryInput);}catch(Decka_DB_Error $e){$caught=$e;}$wpdb->suppress_errors($old);
$creating=$wpdb->get_row($wpdb->prepare("SELECT * FROM $orders WHERE request_key=%s",$key));
decka_check($caught&&$creating->status==='creating'&&$creating->session_id===null,'failed session update is reported and order remains recoverable');
$wpdb->query("DROP TRIGGER $trigger");$resumed=Decka_Service::create($retryInput);decka_check($resumed['order_id']===(int)$creating->id&&$resumed['status']==='pending','same order resumes after session persistence failure');
$wpdb->query("CREATE TRIGGER $trigger BEFORE UPDATE ON $orders FOR EACH ROW BEGIN IF NEW.payment_id IS NOT NULL AND OLD.payment_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI forced payment update failure'; END IF; END");
$old=$wpdb->suppress_errors(true);$caught=null;try{Decka_Service::settle(decka_paid_session($resumed['order_id']),'test');}catch(Decka_DB_Error $e){$caught=$e;}$wpdb->suppress_errors($old);
decka_check($caught&&$caught->diagnostic['operation']==='update'&&Decka_Service::order($resumed['order_id'])->status==='pending'&&(int)$wpdb->get_var("SELECT COUNT(*) FROM $tickets WHERE order_id=".$resumed['order_id'])===0,'failed payment UPDATE never issues tickets or marks paid');
$wpdb->query("DROP TRIGGER $trigger");Decka_Service::settle(decka_paid_session($resumed['order_id']),'test');decka_check(Decka_Service::order($resumed['order_id'])->status==='paid','payment confirmation retry succeeds');

$audit=Decka_DB::table('audit');$wpdb->query("ALTER TABLE $audit ADD COLUMN unexpected_required int NOT NULL");
$counts=[];foreach(['orders','items','inventory','tickets','audit'] as $t)$counts[$t]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table($t));
$old=$wpdb->suppress_errors(true);$caught=null;
try{Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'email'=>'voucher@example.test','seats'=>[['id'=>'75','kind'=>'normal']]],true);}catch(Decka_DB_Error $e){$caught=$e;}$wpdb->suppress_errors($old);
decka_check($caught&&$caught->diagnostic['table']==='audit','voucher audit failure is reported');
foreach($counts as $t=>$count)decka_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Decka_DB::table($t))===$count,"voucher audit failure rolls back $t");
$wpdb->query("ALTER TABLE $audit DROP COLUMN unexpected_required");

// Inspection must remain available even if the audit table itself is broken.
$wpdb->query("ALTER TABLE $audit ADD COLUMN unexpected_required int NOT NULL");
$inspection=Decka_Admin_API::action(['operation'=>'database_inspect']);
decka_check($inspection['ok']&&!get_option('decka_schema_audit')['ok'],'inspection reports broken audit table without trying to write into it');
$wpdb->query("ALTER TABLE $audit DROP COLUMN unexpected_required");

// Guest checkout: identity isolation, idempotency, payment gating and browser ownership.
wp_set_current_user(0);$_COOKIE['decka_guest']=str_repeat('a',64);$guestHash=Decka_Guest::hash();
$guestInput=['request_key'=>str_repeat('b',32),'event_id'=>$event,'seats'=>[['id'=>'250','kind'=>'normal']],'email'=>'guest-ci@example.test','first_name'=>'Jan','last_name'=>'Kowalski','phone'=>'+48 123 456 789'];
$guest=Decka_Service::create($guestInput,false,$guestHash);$guestOrder=Decka_Service::order($guest['order_id']);
decka_check((int)$guestOrder->user_id===0&&$guestOrder->first_name==='Jan','guest order stores buyer fields without creating an account');
decka_check(Decka_Guest::owns($guestOrder),'guest cookie grants access to own order');
decka_check(Decka_Service::create($guestInput,false,$guestHash)['order_id']===$guest['order_id'],'guest retry is idempotent');
$sentBefore=(int)$guestOrder->mail_attempts;Decka_Tickets::email($guest['order_id']);
decka_check((int)Decka_Service::order($guest['order_id'])->mail_attempts===$sentBefore&&!Decka_Service::order($guest['order_id'])->mail_sent_at,'pending payment never attempts ticket email');
$nonce=wp_create_nonce('decka_auth');$_COOKIE['decka_guest']=str_repeat('c',64);
decka_check(!Decka_Guest::owns($guestOrder)&&!wp_verify_nonce($nonce,'decka_auth'),'different guest cannot access order or reuse browser nonce');
$otherInput=$guestInput;$otherInput['seats']=[['id'=>'251','kind'=>'normal']];$other=Decka_Service::create($otherInput,false,Decka_Guest::hash());
decka_check($other['order_id']!==$guest['order_id'],'same client request key is isolated across guest sessions');
decka_check(count(Decka_API::orders())===1&&(int)Decka_API::orders()[0]['id']===$other['order_id'],'guest orders endpoint exposes only current browser orders');
$r=new WP_REST_Request('POST');$r->set_header('origin','https://attacker.test');$r->set_param('auth_nonce',wp_create_nonce('decka_auth'));$denied=false;try{Decka_Guest::checkout($r);}catch(RuntimeException $e){$denied=true;}decka_check($denied,'cross-origin guest checkout rejected');
$_COOKIE['decka_guest']=str_repeat('a',64);Decka_Service::settle(decka_paid_session($guest['order_id']),'test');
decka_check(Decka_Service::order($guest['order_id'])->status==='paid'&&count(Decka_Tickets::documents($guest['order_id']))===1,'guest ticket issued only after verified paid session');
wp_set_current_user($uid);

// Schedule sync sets a Warsaw-correct UTC entry window and keeps manual overrides.
$leagueHtml='<table><tr><td>1</td><td><a href="/d/7625/decka/">Decka</a></td><td><a href="/mecz/987654/">Mecz</a></td><td>CI Guest</td><td>10.10.2027 18:00</td></tr></table>';
$leagueFilter=function($pre,$args,$url)use(&$leagueHtml){if(str_contains($url,'rozgrywki.pzkosz.pl'))return ['response'=>['code'=>200],'body'=>$leagueHtml,'headers'=>[],'cookies'=>[]];return $pre;};
add_filter('pre_http_request',$leagueFilter,5,3);$settings=Decka_DB::settings();$settings['league_url']='https://rozgrywki.pzkosz.pl/liga/1';$settings['team_id']='7625';update_option('decka_settings',$settings);
Decka_League::sync();$synced=$wpdb->get_row('SELECT * FROM '.Decka_DB::table('events')." WHERE external_id='pzkosz-987654'");
decka_check($synced->starts_at==='2027-10-10 16:00:00'&&$synced->gate_open==='2027-10-10 14:00:00'&&$synced->gate_close==='2027-10-10 18:00:00','synced entry window is two hours either side in UTC');
$leagueHtml=str_replace('18:00','19:00',$leagueHtml);Decka_League::sync();$synced=$wpdb->get_row('SELECT * FROM '.Decka_DB::table('events').' WHERE id='.$synced->id);
decka_check($synced->gate_open==='2027-10-10 15:00:00','automatic gate window tracks changed schedule');
Decka_DB::update('events',['gate_manual'=>1,'gate_open'=>'2027-10-10 12:00:00','gate_close'=>'2027-10-10 23:00:00'],['id'=>$synced->id]);
$leagueHtml=str_replace('19:00','20:00',$leagueHtml);Decka_League::sync();$synced=$wpdb->get_row('SELECT * FROM '.Decka_DB::table('events').' WHERE id='.$synced->id);
decka_check($synced->gate_open==='2027-10-10 12:00:00'&&$synced->gate_close==='2027-10-10 23:00:00','manual entry window survives schedule sync');remove_filter('pre_http_request',$leagueFilter,5);

$healthFilter=function($pre,$args,$url){if(str_contains($url,'api.stripe.com/v1/balance'))return ['response'=>['code'=>200],'body'=>'{"livemode":false}','headers'=>[],'cookies'=>[]];if(str_contains($url,'api.stripe.com/v1/webhook_endpoints'))return ['response'=>['code'=>200],'body'=>wp_json_encode(['data'=>[['url'=>rest_url('decka/v1/webhook/test'),'status'=>'enabled','enabled_events'=>['*']]]]),'headers'=>[],'cookies'=>[]];return $pre;};
// The existing fake Stripe hook handles checkout only. Add health responses after it by replacing its branch guard.
remove_all_filters('pre_http_request');add_filter('pre_http_request',$healthFilter,10,3);
$health=Decka_Stripe::health();decka_check($health['ok']&&!$health['webhook_verified'],'Stripe health verifies saved mode and distinguishes unverified webhook signature');
$settings=Decka_DB::settings();$settings['apple_wallet_enabled']=0;$settings['google_wallet_enabled']=0;update_option('decka_settings',$settings);decka_check(!Decka_Wallet::enabled('apple')&&!Decka_Wallet::enabled('google'),'wallet off switches disable both providers');

// Full registration profile, Google account reuse and scanner permission removal.
$profile=['first_name'=>'Jan','last_name'=>'Kowalski','street'=>'Sambora','house_number'=>'5A','apartment'=>'','postcode'=>'83-130','city'=>'Pelplin','phone'=>'+48 123 456 789'];
$bad=$profile;$bad['city']='';$failed=false;try{Decka_Profile::validate($bad);}catch(RuntimeException $e){$failed=true;}decka_check($failed,'incomplete profile rejected before account creation');
$settings=Decka_DB::settings();$settings['registration']=1;update_option('decka_settings',$settings);
$google=['sub'=>'ci-google-new','email'=>'new-google@gmail.com','email_verified'=>true,'name'=>'Jan Kowalski','given_name'=>'Jan','family_name'=>'Kowalski'];
$newUid=Decka_Identity::resolve($google);decka_check(Decka_Profile::required($newUid),'new Google account requires contact form');
Decka_Profile::save($newUid,$profile);decka_check(!Decka_Profile::required($newUid)&&Decka_Profile::read($newUid)['city']==='Pelplin','profile persists and completion flag clears');
decka_check(Decka_Identity::resolve($google)===$newUid,'linked Google account signs into same user');
$existing=wp_insert_user(['user_login'=>'ci_google_existing','user_email'=>'existing-ci@gmail.com','user_pass'=>wp_generate_password(),'role'=>'subscriber']);
$google['sub']='ci-google-existing';$google['email']='existing-ci@gmail.com';decka_check(Decka_Identity::resolve($google)===$existing,'authoritative Google email reuses existing account without password');
$failed=false;try{Decka_Identity::resolve($google,$newUid);}catch(RuntimeException $e){$failed=true;}decka_check($failed,'Google identity cannot be attached to another user');
decka_check(!Decka_Identity::authoritative(['email'=>'thirdparty@example.test','email_verified'=>true]),'third-party email needs separate ownership confirmation');
decka_check(Decka_Identity::authoritative(['email'=>'fan@workspace.test','email_verified'=>true,'hd'=>'workspace.test']),'Workspace verified email is authoritative');
decka_check(!str_contains(Decka_Identity::callback_url(),'wp-admin'),'OAuth callback uses public route');
$detail=Decka_Admin_API::order($guest['order_id']);decka_check($detail['items'][0]['ticket_number']===Decka_Tickets::number((int)$detail['items'][0]['ticket_id'])&&$detail['items'][0]['seat']['sector'],'order detail includes stable number and full seat location');
Decka_DB::query('UPDATE '.Decka_DB::table('events')." SET gate_close='2000-01-01 00:00:00'");Decka_DB::update('events',['cancelled'=>0,'gate_open'=>gmdate('Y-m-d H:i:s',time()-3600),'gate_close'=>gmdate('Y-m-d H:i:s',time()+3600)],['id'=>$event]);
$gate=Decka_API::gate_events();decka_check(count($gate)===1&&(int)$gate[0]->id===$event&&$gate[0]->entry_open,'scanner selects only closest eligible match');$before=$gate[0]->scanned;
Decka_Tickets::scan(Decka_Tickets::documents($guest['order_id'])[0]['token'],$event);$gate=Decka_API::gate_events();decka_check($gate[0]->scanned===$before+1&&$gate[0]->total>=$gate[0]->scanned,'scanner progress counts valid admissions against issued tickets');
wp_set_current_user(1);$admin=get_user_by('id',1);Decka_Admin_API::action(['operation'=>'gate_access','email'=>$admin->user_email,'enabled'=>false]);
decka_check(current_user_can('manage_options')&&!current_user_can('decka_scan'),'remove scanner access from admin without removing administration');Decka_Admin_API::action(['operation'=>'gate_access','email'=>$admin->user_email,'enabled'=>true]);decka_check(current_user_can('decka_scan'),'administrator scanner access can be restored');

// Registration and profile completion use authenticated REST nonces and private metadata.
wp_set_current_user(0);$registration=new WP_REST_Request('POST','/decka/v1/register');$registration->set_header('origin',home_url());$registration->set_header('content-type','application/json');$registration->set_body(wp_json_encode($profile+['email'=>'registered-ci@example.test','password'=>'CI-Test-Password-12345','auth_nonce'=>wp_create_nonce('decka_auth')]));
$response=rest_do_request($registration);decka_check($response->get_status()===200,'full registration form succeeds through REST');$registeredUid=get_current_user_id();decka_check($registeredUid>0&&Decka_Profile::read($registeredUid)['house_number']==='5A','registration stores address under authenticated fan');
$public=Decka_API::catalog();decka_check(!str_contains(wp_json_encode($public),'registered-ci@example.test'),'public catalog does not expose profile details');
