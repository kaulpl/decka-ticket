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
delete_option('decka_storage_namespace');update_option('decka_schema_version','0.3.2');
$blocked=false;try{Decka_DB::require_storage();}catch(RuntimeException $e){$blocked=true;}decka_check($blocked,'checkout blocked before complete migration');
Decka_DB::install();$wpdb->flush();$wpdb->col_meta=[];
decka_check(get_option('decka_storage_namespace')==='dect','migration activates dect namespace');
foreach($tables as $table){
    $rows=$wpdb->get_results('SELECT * FROM '.Decka_DB::table($table),ARRAY_A);
    // Row order is not part of the database contract.
    $sort=static function(&$rows){usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));};$sort($rows);$sort($snapshots[$table]);
    decka_check($rows===$snapshots[$table],"migration preserves every $table field and identifier");
}
decka_check($wpdb->get_var("SELECT order_number FROM $oldOrders WHERE id=$legacyId")===''&&(int)$wpdb->get_var("SELECT COUNT(*) FROM $oldOrders")===count($snapshots['orders']),'legacy tables and extra values preserved');
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
