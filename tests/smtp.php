<?php
namespace PHPMailer\PHPMailer {
#[\AllowDynamicProperties]
class PHPMailer {
 public static array $instances=[];public static bool $fail=false;public array $addresses=[],$attachments=[];public bool $closed=false,$sent=false;public int $connections=0;
 public function __construct($exceptions=true){self::$instances[]=$this;}
 public function getSMTPInstance(){return $this;}public function isSMTP(){$this->transport='smtp';}public function setFrom($email,$name){$this->from=[$email,$name];}public function addReplyTo($email,$name){$this->reply=[$email,$name];}
 public function smtpConnect(){$this->connections++;if(self::$fail)throw new \RuntimeException('private SMTP password fixture-password');return true;}
 public function smtpClose(){$this->closed=true;}public function addAddress($to){$this->addresses[]=$to;}public function isHTML($v){$this->html=$v;}public function addAttachment($file,$name){$this->attachments[]=[$file,$name];}
 public function send(){$this->smtpConnect();$this->sent=true;return true;}
}
}
namespace {
require __DIR__.'/../plugin/includes/mail.php';
class Decka_Stripe {static function encrypt($s){return 'encrypted:'.base64_encode($s);}static function decrypt($s){return str_starts_with($s,'encrypted:')?base64_decode(substr($s,10)):'';}}
class Decka_DB {static array $s=[];static function settings(){return self::$s;}}
$admin=true;$wpCalls=0;$checks=0;function current_user_can($c){return $GLOBALS['admin'];}function wp_mail(...$args){$GLOBALS['wpCalls']++;$GLOBALS['wpArgs']=$args;return true;}
function ok($v,$label){if(!$v)throw new \RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
function rejects($fn,$label){try{$fn();}catch(\Throwable $e){ok(true,$label);return;}ok(false,$label);}
$dir=sys_get_temp_dir().'/decka-smtp-fixture-'.bin2hex(random_bytes(5));mkdir($dir.'/wp-includes/PHPMailer',0777,true);foreach(['Exception','PHPMailer','SMTP'] as $file)file_put_contents($dir.'/wp-includes/PHPMailer/'.$file.'.php','<?php');define('ABSPATH',$dir.'/');define('WPINC','wp-includes');
try {
$input=['smtp_enabled'=>true,'smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'bilety@deckapelplin.pl','smtp_password'=>'fixture-password'];
$s=Decka_Mail::settings($input,[]);ok($s['smtp_password']!=='fixture-password'&&Decka_Stripe::decrypt($s['smtp_password'])==='fixture-password','password encrypted');
ok(Decka_Mail::settings(array_merge($input,['smtp_password'=>'']),$s)['smtp_password']===$s['smtp_password'],'empty password preserves saved secret');
foreach(['https://smtp.example.test','smtp.example.test:587',"smtp.example.test\r\nother",'smtp.example.test;evil.example.test'] as $host)rejects(fn()=>Decka_Mail::settings(array_merge($input,['smtp_host'=>$host]),[]),'reject multiple/invalid SMTP hosts');
rejects(fn()=>Decka_Mail::settings(array_merge($input,['smtp_security'=>'','smtp_port'=>25]),[]),'plaintext transport rejected');rejects(fn()=>Decka_Mail::settings(array_merge($input,['smtp_password'=>'']),[]),'incomplete enabled settings rejected');
$admin=false;rejects(fn()=>Decka_Mail::settings($input,[]),'non-admin cannot configure transport');rejects(fn()=>Decka_Mail::check(),'non-admin cannot probe server');ok(Decka_Mail::settings(array_merge($input,['smtp_password'=>'']),$s)===$s,'non-admin can preserve identical settings');$admin=true;
Decka_DB::$s=[];ok(Decka_Mail::send('fan@example.test','subject','<p>Body</p>',['header'],['ticket.pdf'])&&$wpCalls===1&&count(\PHPMailer\PHPMailer\PHPMailer::$instances)===0,'disabled SMTP uses normal WordPress transport');
Decka_DB::$s=$s;$result=Decka_Mail::check();$client=end(\PHPMailer\PHPMailer\PHPMailer::$instances);ok($result['ok']&&!$client->sent&&$client->closed&&$client->connections===1,'connection check authenticates and closes without sending');
ok($client->SMTPAuth&&$client->SMTPSecure==='tls'&&$client->SMTPOptions['ssl']['verify_peer']&&$client->SMTPOptions['ssl']['verify_peer_name']&&!$client->SMTPOptions['ssl']['allow_self_signed'],'encrypted transport requires valid certificate');
$global=(object)['marker'=>'existing WordPress mailer'];$GLOBALS['phpmailer']=$global;Decka_Mail::send('fan@example.test','Bilety','<p>Treść</p>',[],['ticket.pdf']);$mail=end(\PHPMailer\PHPMailer\PHPMailer::$instances);ok($mail!==$client&&$mail->sent&&$mail->closed&&$GLOBALS['phpmailer']===$global&&$wpCalls===1,'ticket uses isolated instance without mutating WordPress global mailer');ok($mail->from===['bilety@deckapelplin.pl','Bilety Decka Pelplin']&&$mail->reply[0]==='biuro@deckapelplin.pl'&&$mail->attachments===[['ticket.pdf','Decka-bilety.pdf']],'sender, reply-to and PDF attachment retained');
\PHPMailer\PHPMailer\PHPMailer::$fail=true;$result=Decka_Mail::check();ok(!$result['ok']&&!str_contains($result['message'],'fixture-password')&&end(\PHPMailer\PHPMailer\PHPMailer::$instances)->closed,'failed check closes and redacts server error');
try{Decka_Mail::send('fan@example.test','Bilety','<p>Body</p>',[],[]);ok(false,'send must fail');}catch(\RuntimeException $e){ok(!str_contains($e->getMessage(),'fixture-password')&&$wpCalls===1&&end(\PHPMailer\PHPMailer\PHPMailer::$instances)->closed,'failed send redacts error and never falls back to other transport');}
echo "TOTAL $checks SMTP checks (isolated transport fixture, no external connection)\n";
} finally {foreach(glob($dir.'/wp-includes/PHPMailer/*') as $file)unlink($file);rmdir($dir.'/wp-includes/PHPMailer');rmdir($dir.'/wp-includes');rmdir($dir);}
}
