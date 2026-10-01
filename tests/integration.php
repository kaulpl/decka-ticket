<?php
/** Functional integration harness: real service methods, SQLite adapter, fake Stripe.
 * This does NOT prove MySQL row-level concurrency or a live Stripe connection.
 */
define('ARRAY_A','ARRAY_A');define('DECKA_DIR',__DIR__.'/../plugin/');
define('K_PATH_CACHE',__DIR__.'/artifacts/');@mkdir(K_PATH_CACHE,0777,true);
$settings=['mode'=>'test','normal'=>2500,'reduced'=>1500];$uid=7;$checks=0;
function get_option($name,$default=[]){global $settings;return $settings;}
function get_current_user_id(){global $uid;return $uid;}
function wp_get_current_user(){return (object)['user_email'=>'kibic@example.test'];}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){return trim((string)$v);}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v){return json_encode($v);}
function admin_url($v=''){return 'https://example.test/wp-admin/'.$v;}
function wp_salt($s=''){return 'test-secret-'.$s;}
function wp_date($format,$time,$zone=null){return (new DateTimeImmutable('@'.$time))->setTimezone($zone??new DateTimeZone('Europe/Warsaw'))->format($format);}
function check($condition,$label){global $checks;if(!$condition)throw new Exception('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function rejects($fn,$label){try{$fn();}catch(Throwable $e){check(true,$label.' ['.$e->getMessage().']');return;}check(false,$label);}
class DB {
 public $prefix='wp_',$insert_id=0;public PDO $pdo;
 function __construct(){$this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
 function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:$this->pdo->quote((string)$v);},$sql);}
 function rewrite($sql){$sql=str_replace(' FOR UPDATE','',$sql);return str_replace('START TRANSACTION','BEGIN TRANSACTION',$sql);}
 function query($sql){return $this->pdo->exec($this->rewrite($sql));}
 function get_row($sql,$mode=null){return $this->pdo->query($this->rewrite($sql))->fetch($mode===ARRAY_A?PDO::FETCH_ASSOC:PDO::FETCH_OBJ)?:null;}
 function get_results($sql,$mode=null){return $this->pdo->query($this->rewrite($sql))->fetchAll($mode===ARRAY_A?PDO::FETCH_ASSOC:PDO::FETCH_OBJ);}
 function get_var($sql){$value=$this->pdo->query($this->rewrite($sql))->fetchColumn();return $value===false?null:$value;}
 function insert($table,$data){$keys=array_keys($data);$s=$this->pdo->prepare("INSERT INTO $table (".implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');$s->execute(array_values($data));$this->insert_id=(int)$this->pdo->lastInsertId();return $s->rowCount();}
 function update($table,$data,$where){$keys=array_keys($data);$w=array_keys($where);$s=$this->pdo->prepare("UPDATE $table SET ".implode(',',array_map(fn($k)=>"$k=?",$keys)).' WHERE '.implode(' AND ',array_map(fn($k)=>"$k=?",$w)));$s->execute(array_merge(array_values($data),array_values($where)));return $s->rowCount();}
}
class Decka_Stripe {
 static function secret($mode,$kind='secret'){return 'local-test-only';}
 static array $sessions=[];
 static function create($o){$p=json_decode($o->stripe_payload,true);return self::$sessions[$o->id]??=['id'=>'cs_test_'.$o->id,'url'=>'https://checkout.stripe.com/test','metadata'=>$p['metadata'],'client_reference_id'=>(string)$o->id,'livemode'=>false,'amount_total'=>(int)$o->total,'currency'=>'pln','payment_status'=>'unpaid','status'=>'open','payment_intent'=>'pi_'.$o->id];}
}
$wpdb=new DB;
$schemas=[
'events'=>'id INTEGER PRIMARY KEY,opponent TEXT,starts_at TEXT,sale_open INT,cancelled INT DEFAULT 0,gate_open TEXT,gate_close TEXT,venue TEXT,normal_price INT,reduced_price INT,image_id INT',
'offers'=>'id INTEGER PRIMARY KEY,name TEXT,event_ids TEXT,normal_price INT,reduced_price INT,active INT,starts_at TEXT,ends_at TEXT',
'promos'=>'id INTEGER PRIMARY KEY,code TEXT,type TEXT,value INT,max_uses INT,event_ids TEXT,active INT,starts_at TEXT,ends_at TEXT',
'orders'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,mode TEXT,user_id INT,request_key TEXT,email TEXT,status TEXT,total INT,discount INT,offer_id INT,promo_id INT,session_id TEXT,payment_id TEXT,checkout_url TEXT,stripe_payload TEXT,package_ack TEXT,created_at TEXT,paid_at TEXT,mail_sent_at TEXT,mail_attempts INT DEFAULT 0,last_error TEXT,UNIQUE(mode,user_id,request_key)',
'items'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INT,event_id INT,seat_id TEXT,kind TEXT,amount INT,UNIQUE(order_id,event_id,seat_id)',
'inventory'=>'mode TEXT,event_id INT,seat_id TEXT,order_id INT,state TEXT,PRIMARY KEY(mode,event_id,seat_id)',
'tickets'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INT,event_id INT,seat_id TEXT,kind TEXT,nonce TEXT,status TEXT,used_at TEXT,used_by INT,UNIQUE(order_id,event_id,seat_id)',
'audit'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,actor INT,action TEXT,object_id INT,detail TEXT,created_at TEXT'];
foreach($schemas as $n=>$s)$wpdb->query("CREATE TABLE wp_decka_$n ($s)");
// SQLite equivalent only for the conditional state transition used by checkout().
$sqliteFn=method_exists($wpdb->pdo,'createFunction')?'createFunction':'sqliteCreateFunction';$wpdb->pdo->$sqliteFn('IF',fn($cond,$yes,$no)=>$cond?$yes:$no,3);
foreach(['domain','db','service','tickets'] as $p)require DECKA_DIR.'includes/'.$p.'.php';
for($i=1;$i<=3;$i++)Decka_DB::insert('events',['id'=>$i,'opponent'=>'Drużyna '.$i,'starts_at'=>gmdate('Y-m-d H:i:s',time()+86400*$i),'sale_open'=>1,'gate_open'=>gmdate('Y-m-d H:i:s',time()-3600),'gate_close'=>gmdate('Y-m-d H:i:s',time()+3600),'venue'=>'Hala testowa']);
function buy($seat,$event=1,$extra=[]){return Decka_Service::create(array_merge(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>$event,'seats'=>[['id'=>(string)$seat,'kind'=>'normal']]],$extra));}
$key='11111111-1111-1111-1111-111111111111';$a=buy(66,1,['request_key'=>$key]);
check($a['status']==='pending'&&$a['total']===2500,'nieopłacony zakup tworzy blokadę');
check($wpdb->get_var('SELECT state FROM wp_decka_inventory WHERE seat_id="66"')==='held','nieopłacone miejsce nie jest szare');
check(buy(66,1,['request_key'=>$key])['order_id']===$a['order_id'],'powtórzone kliknięcie zwraca to samo zamówienie');
rejects(fn()=>buy(66),'drugie zamówienie na to samo miejsce odrzucone');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_orders')===1,'nieudany zakup wycofuje cały zapis');
$s=Decka_Stripe::$sessions[$a['order_id']];Decka_Service::settle($s,'test');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_tickets')===0,'nieopłacona sesja nie wydaje biletu');
$s['payment_status']='paid';$s['status']='complete';$bad=$s;$bad['amount_total']=1500;rejects(fn()=>Decka_Service::settle($bad,'test'),'błędna kwota nie wydaje biletu');
rejects(fn()=>Decka_Service::settle($s,'live'),'inne środowisko nie zatwierdza płatności');
Decka_Service::settle($s,'test');Decka_Service::settle($s,'test');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_tickets')===1,'ponowiony webhook nie duplikuje biletu');
check($wpdb->get_var('SELECT state FROM wp_decka_inventory WHERE seat_id="66"')==='paid','dopiero zapłacone miejsce ma stan paid');
$t=$wpdb->get_row('SELECT * FROM wp_decka_tickets LIMIT 1');$qr=Decka_Domain::token($t,wp_salt('secure_auth'));
rejects(fn()=>Decka_Tickets::scan($qr,2),'bilet na inny mecz odrzucony');
rejects(fn()=>Decka_Tickets::scan(substr($qr,0,-1).'z',1),'sfałszowany QR odrzucony');
check(Decka_Tickets::scan($qr,1)['valid'],'pierwsze wejście przyjęte');
rejects(fn()=>Decka_Tickets::scan($qr,1),'drugie wejście odrzucone');
$b=buy(67);$expired=Decka_Stripe::$sessions[$b['order_id']];$expired['status']='expired';Decka_Service::settle($expired,'test');
check(!(bool)$wpdb->get_var('SELECT order_id FROM wp_decka_inventory WHERE seat_id="67"'),'potwierdzone wygaśnięcie zwalnia miejsce');
$retry=buy(67);check($retry['status']==='pending','miejsce można kupić po wygaśnięciu');
Decka_DB::insert('offers',['id'=>1,'name'=>'3 mecze','event_ids'=>'[1,2,3]','normal_price'=>6500,'reduced_price'=>3900,'active'=>1]);
$pack=buy(68,1,['offer_id'=>1,'package_ack'=>true]);check($pack['total']===6500,'cena pakietu za trzy mecze');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_inventory WHERE seat_id="68"')===3,'pakiet blokuje miejsce na wszystkich meczach');
check((int)$wpdb->get_var('SELECT SUM(amount) FROM wp_decka_items WHERE order_id='.$pack['order_id'])===6500,'kwota pakietu rozdzielona do statystyk');
buy(69,2);rejects(fn()=>buy(69,1,['offer_id'=>1,'package_ack'=>true]),'pakiet odrzucony gdy jedno spotkanie jest zajęte');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_inventory WHERE seat_id="69"')===1,'odrzucony pakiet nie zostawia częściowych blokad');
Decka_DB::insert('promos',['id'=>1,'code'=>'DECKA10','type'=>'percent','value'=>10,'max_uses'=>1,'event_ids'=>'[]','active'=>1]);
$discount=buy(70,1,['promo'=>'decka10']);check($discount['total']===2250,'rabat liczony po stronie serwera');
rejects(fn()=>buy(71,1,['promo'=>'DECKA10']),'limit kodu obejmuje oczekujące płatności');
$voucher=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>1,'email'=>'voucher@example.test','seats'=>[['id'=>'72','kind'=>'normal']]],true);
check($voucher['status']==='voucher'&&$voucher['total']===0,'voucher bez obciążenia Stripe');
rejects(fn()=>buy(72),'miejsce z voucherem nie jest dostępne');
Decka_Service::revoke_payment('test','pi_'.$a['order_id']);check($wpdb->get_var('SELECT status FROM wp_decka_tickets WHERE id='.$t->id)==='revoked','zwrot unieważnia bilet');
rejects(fn()=>Decka_Tickets::scan($qr,1),'zwrot odrzucany przy wejściu');
$settings['mode']='live';$live=Decka_Service::create(['request_key'=>bin2hex(random_bytes(16)),'event_id'=>1,'email'=>'live@example.test','seats'=>[['id'=>'66','kind'=>'normal']]],true);check($live['status']==='voucher','testowe miejsce nie blokuje produkcji');
$settings['mode']='test';
$early=buy(73);Decka_Service::revoke_payment('test','pi_'.$early['order_id'],$early['order_id']);$late=Decka_Stripe::$sessions[$early['order_id']];$late['payment_status']='paid';$late['status']='complete';Decka_Service::settle($late,'test');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_decka_tickets WHERE order_id='.$early['order_id'])===0,'zwrot przed potwierdzeniem płatności nie wydaje biletu');
Decka_DB::insert('inventory',['mode'=>'test','event_id'=>1,'seat_id'=>'74','order_id'=>0,'state'=>'blocked']);
rejects(fn()=>buy(74),'ręczna blokada organizatora uniemożliwia zakup');
// A package has one QR with independent admission per included event.
$ps=Decka_Stripe::$sessions[$pack['order_id']];$ps['payment_status']='paid';$ps['status']='complete';Decka_Service::settle($ps,'test');
$docs=Decka_Tickets::documents($pack['order_id']);check(count($docs)===1&&count($docs[0]['events'])===3,'jeden dokument na miejsce, komplet trzech meczów');$pq=$docs[0]['token'];check(str_starts_with($pq,'DK2.'),'mini-karnet używa wspólnego kodu');
check(Decka_Tickets::scan($pq,1)['valid'],'pakiet: pierwsze wejście mecz 1');rejects(fn()=>Decka_Tickets::scan($pq,1),'pakiet: ponowne wejście mecz 1 odrzucone');check(Decka_Tickets::scan($pq,2)['valid'],'ten sam kod wpuszcza na mecz 2');rejects(fn()=>Decka_Tickets::scan($pq,99),'pakiet nie wpuszcza na obcy mecz');
$wpdb->query('UPDATE wp_decka_tickets SET status="revoked" WHERE order_id='.$pack['order_id'].' AND event_id=1');check(Decka_Tickets::scan($pq,3)['valid'],'unieważnienie jednego meczu nie unieważnia pozostałych');
$settings['max_per_fan']=1;$uid=100;buy(80);rejects(fn()=>buy(81),'limit kibica obejmuje wcześniejsze oczekujące zamówienie');rejects(fn()=>buy(82,1,['offer_id'=>1,'package_ack'=>true]),'limit obejmuje również zakup pakietu');$uid=101;check(buy(81)['status']==='pending','limit liczony osobno dla każdego kibica');
$settings['max_per_fan']=10;$uid=102;$wpdb->query('UPDATE wp_decka_events SET normal_price=3100 WHERE id=2');check(buy(83,2)['total']===3100,'cena indywidualnego meczu');check(buy(84,1)['total']===2500,'cena jednego meczu nie zmienia drugiego');
rejects(fn()=>buy(85,1,['offer_id'=>1]),'pakiet wymaga potwierdzenia informacji o terminach');$wpdb->query('UPDATE wp_decka_events SET starts_at=NULL,sale_open=0 WHERE id=3');check(buy(85,1,['offer_id'=>1,'package_ack'=>true])['total']===6500,'pakiet obejmuje mecz bez ustalonej daty');rejects(fn()=>buy(86,3),'pojedynczy mecz bez daty nie jest sprzedawany');
@mkdir(__DIR__.'/artifacts',0777,true);file_put_contents(__DIR__.'/artifacts/package-TEST.pdf',Decka_Tickets::pdf($pack['order_id']));
$pdf=Decka_Tickets::pdf($voucher['order_id']);@mkdir(__DIR__.'/artifacts',0777,true);check(file_put_contents(__DIR__.'/artifacts/voucher-TEST.pdf',$pdf)!==false,'PDF zapisany do pliku');check(str_starts_with($pdf,'%PDF-'),'generowany rzeczywisty bilet PDF');
file_put_contents(__DIR__.'/expected-qr.txt',Decka_Domain::token($wpdb->get_row('SELECT * FROM wp_decka_tickets WHERE order_id='.$voucher['order_id']),wp_salt('secure_auth')));
echo "TOTAL $checks integration checks (SQLite adapter, fake Stripe)\n";
