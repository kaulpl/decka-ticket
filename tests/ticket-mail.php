<?php
require __DIR__.'/integration.php';
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
$fixture=__DIR__.'/artifacts/sponsors-test.png';if(!file_exists($fixture)){$im=imagecreatetruecolor(2480,300);$white=imagecolorallocate($im,255,255,255);$blue=imagecolorallocate($im,25,86,157);imagefill($im,0,0,$white);imagefilledrectangle($im,40,40,2440,260,$blue);imagepng($im,$fixture);}
$settings['sponsor_image_id']=999;$o=Decka_Service::order($voucher['order_id']);
$html=Decka_Tickets::email_html($o->id);file_put_contents(__DIR__.'/artifacts/ticket-email.html',$html);
check(str_contains($html,'Pobierz bilety PDF')&&str_contains($html,'mail_signature='),'mail contains durable download button');
$other=clone $o;$other->id++;check(Decka_Tickets::email_signature($o)!==Decka_Tickets::email_signature($other),'email signature bound to order');
Decka_DB::update('orders',['first_name'=>'<script>test</script>'],['id'=>$o->id]);check(!str_contains(Decka_Tickets::email_html($o->id),'<script>'),'HTML escapes customer input');
file_put_contents(__DIR__.'/artifacts/sponsor-voucher.pdf',Decka_Tickets::pdf($o->id));
file_put_contents(__DIR__.'/artifacts/sponsor-package.pdf',Decka_Tickets::pdf($pack['order_id']));
echo "PASS ticket email and sponsor fixtures\n";
