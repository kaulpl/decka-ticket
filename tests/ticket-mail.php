<?php
require __DIR__.'/integration.php';
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
$fixture=__DIR__.'/artifacts/sponsors-test.png';if(!file_exists($fixture)){$im=imagecreatetruecolor(2480,300);$white=imagecolorallocate($im,255,255,255);$blue=imagecolorallocate($im,25,86,157);imagefill($im,0,0,$white);imagefilledrectangle($im,40,40,2440,260,$blue);imagepng($im,$fixture);}
$fixture=__DIR__.'/artifacts/main-sponsors-test.png';if(!file_exists($fixture)){$im=imagecreatetruecolor(2480,300);$white=imagecolorallocate($im,255,255,255);$red=imagecolorallocate($im,207,37,25);imagefill($im,0,0,$white);imagefilledrectangle($im,40,40,2440,260,$red);imagepng($im,$fixture);}
@mkdir(DECKA_DIR.'assets/opponents',0777,true);foreach(glob(__DIR__.'/../frontend/public/opponents/*.png') as $logo)copy($logo,DECKA_DIR.'assets/opponents/'.basename($logo));
foreach([1=>'Miasto Szkła Krosno',2=>'Weegree AZS Politechnika Opolska',3=>'KSK Qemetica Noteć Inowrocław'] as $id=>$name)Decka_DB::update('events',['opponent'=>$name],['id'=>$id]);
$settings['main_sponsor_image_id']=998;$settings['sponsor_image_id']=999;$o=Decka_Service::order($voucher['order_id']);
$html=Decka_Tickets::email_html($o->id);file_put_contents(__DIR__.'/artifacts/ticket-email.html',$html);
check(str_contains($html,'Pobierz bilety PDF')&&str_contains($html,'mail_signature='),'mail contains durable download button');
$other=clone $o;$other->id++;check(Decka_Tickets::email_signature($o)!==Decka_Tickets::email_signature($other),'email signature bound to order');
Decka_DB::update('orders',['first_name'=>'<script>test</script>'],['id'=>$o->id]);check(!str_contains(Decka_Tickets::email_html($o->id),'<script>'),'HTML escapes customer input');
file_put_contents(__DIR__.'/artifacts/sponsor-voucher.pdf',Decka_Tickets::pdf($o->id));
file_put_contents(__DIR__.'/artifacts/sponsor-package.pdf',Decka_Tickets::pdf($pack['order_id']));
echo "PASS ticket email and sponsor fixtures\n";

// Largest supported package must remain on one A4 with both full-width sponsor strips.
for($id=4;$id<=20;$id++){
Decka_DB::insert('events',['id'=>$id,'opponent'=>'Weegree AZS Politechnika Opolska','starts_at'=>gmdate('Y-m-d H:i:s',time()+86400*$id),'venue'=>'Hala testowa']);
Decka_DB::insert('items',['order_id'=>$pack['order_id'],'event_id'=>$id,'seat_id'=>'68','kind'=>'normal','amount'=>0]);
Decka_DB::insert('tickets',['order_id'=>$pack['order_id'],'event_id'=>$id,'seat_id'=>'68','kind'=>'normal','nonce'=>bin2hex(random_bytes(16)),'status'=>'valid']);
}
file_put_contents(__DIR__.'/artifacts/sponsor-package-20.pdf',Decka_Tickets::pdf($pack['order_id']));
