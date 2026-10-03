<?php
/** Isolated Stripe health checks: no credentials or network. */
require __DIR__.'/../plugin/includes/stripe.php';
class Decka_DB {static array $settings=[];static function mode(){return 'test';}static function settings(){return self::$settings;}}
function wp_salt($x=''){return 'health-fixture';}function wp_parse_url($u){return parse_url($u);}function rest_url($p){return 'https://example.test/wp-json/'.$p;}function get_option($k,$d=[]){return $d;}function update_option(...$x){}function is_wp_error($r){return false;}function wp_remote_retrieve_response_code($r){return 200;}function wp_remote_retrieve_body($r){return json_encode($r);}
$endpoints=[];$second=[];
function wp_remote_request($url,$args){global $endpoints,$second;if(str_contains($url,'/balance'))return ['livemode'=>false];return ['data'=>str_contains($url,'starting_after=')?$second:$endpoints,'has_more'=>!str_contains($url,'starting_after=')&&(bool)$second];}
Decka_DB::$settings=['test_secret'=>Decka_Stripe::encrypt('sk_test_fixture'),'test_webhook'=>Decka_Stripe::encrypt('whsec_fixture')];
$e=['id'=>'we_1','url'=>rest_url('decka/v1/webhook/test'),'status'=>'enabled','enabled_events'=>Decka_Stripe::webhook_events()];
function health_check($yes,$name){if(!$yes)throw new Exception($name);echo "PASS $name\n";}
$endpoints=[$e];health_check(Decka_Stripe::health()['ok'],'exact configured endpoint');
$endpoints[0]['url'].='/';health_check(Decka_Stripe::health()['ok'],'trailing slash accepted');
$endpoints[0]['enabled_events']=['checkout.session.completed'];$h=Decka_Stripe::health();health_check(!$h['ok']&&str_contains($h['message'],'charge.refunded')&&!str_contains($h['message'],'checkout.session.completed'),'exact missing event list');
$endpoints[0]['status']='disabled';health_check(str_contains(Decka_Stripe::health()['message'],'wyłączony'),'disabled endpoint distinguished');
$endpoints=[array_merge($e,['url'=>'https://wrong.test/webhook'])];health_check(str_contains(Decka_Stripe::health()['message'],rest_url('decka/v1/webhook/test')),'missing endpoint reports expected URL');
$second=[$e];health_check(Decka_Stripe::health()['ok'],'matching endpoint on second page');$second=[];
$endpoints=[array_merge($e,['enabled_events'=>['*']])];health_check(Decka_Stripe::health()['ok'],'all events wildcard');
$endpoints=[array_merge($e,['enabled_events'=>array_slice($e['enabled_events'],0,3)]),array_merge($e,['id'=>'we_2','enabled_events'=>array_slice($e['enabled_events'],3)])];health_check(!Decka_Stripe::health()['ok'],'separate signing secrets cannot be treated as one complete endpoint');
