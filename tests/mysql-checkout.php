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
$next=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>'69','kind'=>'normal']]]);
decka_check($next['status']==='pending','checkout explicitly writes NULL independently of default');
$old=$wpdb->suppress_errors(true);$blocked=$wpdb->update($orders,['session_id'=>'cs_test_'.$o['order_id']],['id'=>$next['order_id']]);$wpdb->suppress_errors($old);
decka_check($blocked===false,'real Stripe session uniqueness still enforced');
Decka_DB::install();Decka_DB::install();decka_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before+1,'repair is repeatable and preserves orders');
