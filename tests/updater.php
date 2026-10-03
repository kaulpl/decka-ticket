<?php
/** Deterministic updater tests; no external service or installation. */
define('DECKA_VERSION','0.2.0');define('DECKA_FILE','decka-bilety/decka-bilety.php');
define('ABSPATH',sys_get_temp_dir().'/decka-updater-test/');
@mkdir(ABSPATH.'wp-admin/includes',0777,true);file_put_contents(ABSPATH.'wp-admin/includes/plugin.php','<?php');file_put_contents(ABSPATH.'wp-admin/includes/file.php','<?php');
$cache=[];$calls=0;$allowed=true;$cleaned=false;$updated=false;$http=[];$download='correct archive';$count=0;
class WP_Error{function __construct(public $code,public $message){}}
class Decka_DB{static function storage_ready(){return true;}static function audit(...$a){}}
function wp_create_nonce($action){return 'test-nonce';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args,'','&',PHP_QUERY_RFC3986);}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=test-nonce';}
function plugin_basename($f){return $f;}function sanitize_textarea_field($s){return strip_tags($s);}function self_admin_url($s){return 'https://example.test/'.$s;}
function get_site_transient($k){global $cache;return $cache[$k]??false;}function set_site_transient($k,$v,$ttl=0){global $cache;$cache[$k]=$v;}function delete_site_transient($k){global $cache;unset($cache[$k]);}
function current_user_can($c){global $allowed;return $allowed;}function wp_clean_plugins_cache($clear){global $cleaned;$cleaned=$clear;delete_site_transient('update_plugins');}
function wp_update_plugins(){global $updated;$updated=true;set_site_transient('update_plugins',(object)['response'=>['other/other.php'=>(object)['new_version'=>'9.0.0']]]);}
function wp_safe_remote_get($u,$a){global $calls,$http;$calls++;return $http[$u]??['code'=>404,'body'=>'{}'];}function is_wp_error($x){return $x instanceof WP_Error;}
function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}function get_bloginfo($x){return '6.8';}
function download_url($u,$timeout){global $download;$f=tempnam(sys_get_temp_dir(),'decka');file_put_contents($f,$download);return $f;}
function ok($yes,$label){global $count;if(!$yes)throw new Exception('FAIL '.$label);$count++;echo 'PASS '.$label."\n";}
function rejects($fn,$label){try{$fn();}catch(Throwable $e){ok(true,$label);return;}ok(false,$label);}
require __DIR__.'/../plugin/includes/updater.php';
$tag='v0.3.0';$package='https://github.com/kaulpl/decka-ticket/releases/download/'.$tag.'/decka-bilety-0.3.0.zip';$manifestUrl='https://github.com/kaulpl/decka-ticket/releases/download/'.$tag.'/decka-bilety-update.json';
$release=['tag_name'=>$tag,'assets'=>[['name'=>'decka-bilety-0.3.0.zip','browser_download_url'=>$package],['name'=>'decka-bilety-update.json','browser_download_url'=>$manifestUrl]]];
$manifest=['slug'=>'decka-bilety','version'=>'0.3.0','asset'=>'decka-bilety-0.3.0.zip','sha256'=>hash('sha256',$download),'requires'=>'6.6','requires_php'=>'8.2'];
ok(Decka_Updater::status()['status']==='unchecked','initial state');
ok(Decka_Updater::check(true)['status']==='no_release','empty repo reports no release');
$api='https://api.github.com/repos/kaulpl/decka-ticket/releases/latest';$http[$api]=['code'=>200,'body'=>json_encode($release)];$http[$manifestUrl]=['code'=>200,'body'=>json_encode($manifest)];
ok(Decka_Updater::check(true)['status']==='available','new release available');$n=$calls;Decka_Updater::check();ok($calls===$n,'normal reads use release cache');
$cache['unrelated_page_cache']='keep';$r=Decka_Updater::force();ok($cleaned&&$updated&&$calls===$n+2,'manual check clears and refetches immediately');ok(isset($cache['update_plugins']->response[DECKA_FILE]),'WordPress receives update');ok(isset($cache['update_plugins']->response['other/other.php']),'other plugin update retained');ok($cache['unrelated_page_cache']==='keep','page cache unchanged');
ok(str_contains($r['install_url'],'upgrade-plugin')&&str_contains($r['install_url'],'_wpnonce='),'installation link uses native WordPress upgrader and nonce');
parse_str(parse_url($r['install_url'],PHP_URL_QUERY),$query);ok(($query['action']??'')==='upgrade-plugin'&&($query['plugin']??'')===DECKA_FILE&&($query['_wpnonce']??'')==='test-nonce'&&!str_contains($r['install_url'],'&amp;'),'JSON installation URL keeps real query separators');
$allowed=false;ok(Decka_Updater::status()['install_url']===null,'installation link hidden without permission');rejects(fn()=>Decka_Updater::force(),'requires update_plugins permission');$allowed=true;
rejects(fn()=>Decka_Updater::validate($release+['prerelease'=>true],$manifest),'rejects prerelease');rejects(fn()=>Decka_Updater::validate($release,array_merge($manifest,['version'=>'0.4.0'])),'rejects mismatch');rejects(fn()=>Decka_Updater::validate($release,array_merge($manifest,['sha256'=>'bad'])),'rejects missing checksum');rejects(fn()=>Decka_Updater::validate(array_merge($release,['assets'=>[]]),$manifest),'rejects source-only release');
$bad=$release;$bad['assets'][0]['browser_download_url']='https://evil.example/file.zip';rejects(fn()=>Decka_Updater::validate($bad,$manifest),'rejects foreign asset');
$f=Decka_Updater::download(false,$package,null,[]);ok(is_string($f)&&file_exists($f),'verified package accepted');unlink($f);$download='tampered';ok(Decka_Updater::download(false,$package,null,[]) instanceof WP_Error,'checksum mismatch blocks installation');ok(Decka_Updater::download(false,'https://example.test/other.zip',null,[])===false,'does not intercept unrelated plugins');
$http[$api]=['code'=>429,'body'=>'{}'];ok(Decka_Updater::check(true)['status']==='error','rate limit is error not current');$http[$api]=new WP_Error('network','offline');ok(Decka_Updater::check(true)['status']==='error','network failure is error');
$http[$api]=['code'=>200,'body'=>json_encode($release)];$http[$manifestUrl]=['code'=>200,'body'=>json_encode(array_merge($manifest,['requires_php'=>'99.0']))];Decka_Updater::check(true);ok(Decka_Updater::download(false,$package,null,[]) instanceof WP_Error,'incompatible PHP blocked');
echo "$count updater checks passed\n";

$http[$api]=['code'=>429,'body'=>'{}'];$http['https://github.com/kaulpl/decka-ticket/releases/latest/download/decka-bilety-update.json']=['code'=>200,'body'=>json_encode($manifest)];
ok(Decka_Updater::check(true)['status']==='available','public release manifest works when shared GitHub API quota is exhausted');
$http['https://github.com/kaulpl/decka-ticket/releases/latest/download/decka-bilety-update.json']['body']=json_encode(array_merge($manifest,['asset'=>'evil.zip']));
ok(Decka_Updater::check(true)['status']==='error','fallback still validates exact package and manifest');
