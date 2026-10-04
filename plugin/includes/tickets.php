<?php
final class Decka_Scan_Error extends RuntimeException {public function __construct(public string $status,string $message){parent::__construct($message);}}
final class Decka_Tickets {
    public static function number(int $id):string {global $wpdb;$nonce=$wpdb->get_var($wpdb->prepare('SELECT nonce FROM '.Decka_DB::table('tickets').' WHERE id=%d',$id));return 'DK-'.strtoupper(substr(hash('sha256',(string)$nonce),0,16));}
    public static function documents(int $order_id):array {
        global $wpdb;$o=Decka_Service::order($order_id);if(!$o||!in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Bilety nie są dostępne.');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT t.*,e.opponent,e.starts_at,e.venue,e.image_id,i.amount FROM '.Decka_DB::table('tickets').' t JOIN '.Decka_DB::table('events').' e ON e.id=t.event_id JOIN '.Decka_DB::table('items').' i ON i.order_id=t.order_id AND i.event_id=t.event_id AND i.seat_id=t.seat_id WHERE t.order_id=%d ORDER BY t.id',$order_id));$groups=[];
        foreach($rows as $t){$key=$o->offer_id?'seat-'.$t->seat_id:'ticket-'.$t->id;$groups[$key][]=$t;}
        $documents=[];foreach($groups as $group){$t=$group[0];$documents[]=['ticket'=>$t,'events'=>$group,'package'=>(bool)$o->offer_id,'token'=>$o->offer_id?Decka_Domain::package_token($t,wp_salt('secure_auth')):Decka_Domain::token($t,wp_salt('secure_auth')),'amount'=>array_sum(array_map(fn($row)=>(int)$row->amount,$group))];}return $documents;
    }
    public static function pdf(int $order_id):string {
        require_once DECKA_DIR.'vendor/tcpdf/tcpdf.php';$o=Decka_Service::order($order_id);$documents=self::documents($order_id);
        $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetMargins(16,16,16);$pdf->SetAutoPageBreak(false);$pdf->SetCreator('Decka Pelplin');$pdf->SetTitle('Bilety Decka Pelplin');$seats=array_column(Decka_DB::seats()['seats'],null,'id');
        foreach($documents as $doc){$t=$doc['ticket'];$seat=$seats[$t->seat_id];$package=$doc['package'];$pdf->AddPage();
            $text=function($x,$y,$w,$h,$value,$size=10,$bold=false,$color=[23,46,76])use($pdf){$pdf->SetFont('dejavusans',$bold?'B':'',$size);$pdf->SetTextColor(...$color);$pdf->SetXY($x,$y);$pdf->MultiCell($w,$h,(string)$value,0,'L',false,1,'','',true,0,false,true,$h,'T',true);};
            $color=Decka_DB::settings()['ticket_color']??'#19569d';$rgb=preg_match('/^#[a-f0-9]{6}$/i',$color)?[hexdec(substr($color,1,2)),hexdec(substr($color,3,2)),hexdec(substr($color,5,2))]:[25,86,157];$pdf->SetFillColor(...$rgb);$pdf->Rect(0,0,210,5,'F');$pdf->SetFillColor(207,37,25);$pdf->Rect(158,0,52,5,'F');
            $text(16,11,125,9,'DECKA PELPLIN',20,true);$text(16,22,140,6,'OFICJALNY BILET KLUBOWY',8);$text(152,13,45,12,'Zamówienie '.Decka_Service::number($o),9);
            $logo=DECKA_DIR.'assets/logo.png';$image=!empty($t->image_id)&&function_exists('get_attached_file')?get_attached_file((int)$t->image_id):'';
            $sponsor='';$sid=(int)(Decka_DB::settings()['sponsor_image_id']??0);if($sid&&function_exists('get_attached_file')){$candidate=get_attached_file($sid);$sd=$candidate&&is_file($candidate)?getimagesize($candidate):false;if($sd&&in_array($sd[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)&&$sd[0]/$sd[1]>=7)$sponsor=$candidate;}
            $maxHeight=$sponsor?($package?min(80,119-count($doc['events'])*3.1):80):94;$imageHeight=min(53,$maxHeight);$imageWidth=178;
            $validImage=$image&&is_file($image)&&($dim=getimagesize($image))&&in_array($dim[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true);
            if($validImage){$imageHeight=min($maxHeight,178*$dim[1]/$dim[0]);$imageWidth=$imageHeight*$dim[0]/$dim[1];}
            $pdf->SetFillColor(236,243,251);$pdf->RoundedRect(16,34,178,$imageHeight,3,'1111','F');
            if($validImage)$pdf->Image($image,16+(178-$imageWidth)/2,34,$imageWidth,$imageHeight);
            else{if(is_file($logo))$pdf->Image($logo,24,42,39,33);$text(75,45,110,30,$package?'MINI-KARNET':'DECKA PELPLIN'."\n".'vs '.$t->opponent,18,true);}
            $top=34+$imageHeight+7;
            $pdf->SetDrawColor(207,215,226);$pdf->RoundedRect(16,$top,178,70,4,'1111','D');$pdf->Line(142,$top+5,142,$top+65);
            $title=$package?'MINI-KARNET · '.count($doc['events']).' MECZÓW':'Decka Pelplin - '.$t->opponent;$text(22,$top+5,114,16,$title,13,true);
            $kind=$t->kind==='voucher'?'VOUCHER':($t->kind==='reduced'?'Bilet ulgowy':'Bilet normalny');$text(22,$top+23,112,6,$kind.' · '.$o->email,8);
            $text(22,$top+31,110,7,$package?'Jeden QR na wszystkie wymienione mecze':($t->starts_at?wp_date('d.m.Y · H:i',strtotime($t->starts_at.' UTC'),new DateTimeZone('Europe/Warsaw')):'Termin do potwierdzenia'),10,true);
            $text(22,$top+40,110,10,$t->venue,9);
            $pdf->SetFillColor(234,241,249);$pdf->RoundedRect(22,$top+54,113,11,2,'1111','F');$text(26,$top+57,106,7,'SEKTOR '.$seat['sector'].'   RZĄD '.$seat['row'].'   MIEJSCE '.$seat['number'],10,true);
            $pdf->write2DBarcode($doc['token'],'QRCODE,H',147,$top+5,42,42,['border'=>0,'padding'=>3,'fgcolor'=>[0,0,0],'bgcolor'=>[255,255,255]],'N');
            $pdf->write1DBarcode(self::number((int)$t->id),'C128',146,$top+48,44,12,.2,['border'=>false,'stretch'=>true,'padding'=>2,'fgcolor'=>[0,0,0],'bgcolor'=>[255,255,255],'text'=>false],'N');$text(147,$top+61,44,5,self::number((int)$t->id),6,true);$text(22,$top+49,110,5,'Wartość: '.number_format($doc['amount']/100,2,',',' ').' PLN',8);
            $y=$top+72;$all_revoked=!array_filter($doc['events'],fn($row)=>$row->status==='valid');
            if($o->mode==='test'||$all_revoked){$text(20,$y,175,5,$all_revoked?'BILET UNIEWAŻNIONY':'TEST - NIEWAŻNY NA PRAWDZIWY MECZ',8,true,[185,34,26]);$y+=6;}
            if($package){$text(16,$y,178,5,'MECZE OBJĘTE MINI-KARNETEM',9,true);$y+=6;foreach($doc['events'] as $row){$when=$row->starts_at?wp_date('d.m.Y H:i',strtotime($row->starts_at.' UTC'),new DateTimeZone('Europe/Warsaw')):'Termin do potwierdzenia';$state=$row->status==='revoked'?' / unieważniony':($row->used_at?' / wykorzystany':'');$text(18,$y,174,3.1,$when.' · '.$row->opponent.$state,7);$y+=3.1;}}
            else{$text(16,$y+4,178,8,'BILET NA TELEFONIE WYSTARCZY',12,true);$text(16,$y+16,178,20,'Pokaż kod QR przy wejściu. Jedno wejście na wskazany mecz. Nie udostępniaj kodu innym osobom. Bilet ulgowy wymaga potwierdzenia uprawnienia.',9);$text(16,$y+39,178,19,Decka_DB::settings()['ticket_footer']??'Aktualne informacje: deckapelplin.pl/bilety',8);}
            if($sponsor){$height=210*$sd[1]/$sd[0];$pdf->Image($sponsor,0,282-$height,210,$height);}
            $text(16,286,178,7,$package?'Terminy mogą się zmienić. Wejście na halę: godzinę przed meczem.':'Wejście na halę: godzinę przed meczem. · deckapelplin.pl',8);
        }return $pdf->Output('decka-bilety.pdf','S');
    }
    public static function email(int $id):void {
        global $wpdb;Decka_DB::require_storage();$o=Decka_Service::order($id);if(!$o || $o->mail_sent_at || !in_array($o->status,['paid','free','voucher'],true))return;
        Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('orders').' SET mail_attempts=mail_attempts+1 WHERE id=%d',$id));
        $tmp=tempnam(sys_get_temp_dir(),'decka-');if(!$tmp)throw new RuntimeException('Brak katalogu tymczasowego dla PDF.');$file=$tmp.'.pdf';rename($tmp,$file);
        try{if(file_put_contents($file,self::pdf($id))===false)throw new RuntimeException('Nie można zapisać PDF.');$sent=wp_mail($o->email,($o->mode==='test'?'[TEST] ':'').str_replace('{order}',Decka_Service::number($o),(Decka_DB::settings()['mail_subject']??'')?:'Decka Pelplin — Twoje bilety').($o->status==='voucher'?' · VOUCHER':''),self::email_html($id),['Content-Type: text/html; charset=UTF-8','From: Bilety Decka Pelplin <bilety@deckapelplin.pl>','Reply-To: biuro@deckapelplin.pl'],[$file]);if(!$sent)throw new RuntimeException('Wysyłka wiadomości nie powiodła się. Sprawdź SMTP.');Decka_DB::update('orders',['mail_sent_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$id]);}finally{if(file_exists($file))unlink($file);}
    }
    /** Durable bearer link for email. Bound to this order's private random key. */
    public static function email_signature(object $o):string {return hash_hmac('sha256','email-pdf|'.$o->id.'|'.$o->request_key.'|'.$o->email,wp_salt('secure_auth'));}
    public static function email_link(object $o):string {return add_query_arg(['action'=>'decka_pdf','order'=>$o->id,'mail_signature'=>self::email_signature($o)],admin_url('admin-post.php'));}
    public static function email_html(int $id):string {
        $o=Decka_Service::order($id);$docs=self::documents($id);$esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$number=$esc(Decka_Service::number($o));$link=$esc(self::email_link($o));$name=$esc($o->first_name?:'Kibicu');$rows='';$seats=array_column(Decka_DB::seats()['seats'],null,'id');
        foreach($docs as $doc){$t=$doc['ticket'];$seat=$seats[$t->seat_id];$rows.='<tr><td style="padding:20px 0;border-top:1px solid #dde5ef"><strong style="font-size:18px;color:#153b68">'.($doc['package']?'Mini-karnet':'Bilet na mecz').'</strong><p style="margin:8px 0">Sektor '.$esc($seat['sector']).' · Rząd '.$esc($seat['row']).' · Miejsce '.$esc($seat['number']).'</p>';foreach($doc['events'] as $event){$when=$event->starts_at?wp_date('d.m.Y · H:i',strtotime($event->starts_at.' UTC'),new DateTimeZone('Europe/Warsaw')):'Termin do potwierdzenia';$rows.='<p style="margin:8px 0"><b>Decka Pelplin — '.$esc($event->opponent).'</b><br>'.$esc($when).'<br><span style="color:#64748b">'.$esc($event->venue).'</span></p>';}$rows.='</td></tr>';}
        $copy=nl2br($esc(Decka_DB::settings()['mail_body']??'Do zobaczenia w hali!'));$total=number_format($o->total/100,2,',',' ').' zł';$test=$o->mode==='test'?'<p style="background:#fff1cc;padding:12px">TEST — te bilety nie uprawniają do wejścia na prawdziwy mecz.</p>':'';$created=wp_date('d.m.Y H:i',strtotime($o->created_at.' UTC'),new DateTimeZone('Europe/Warsaw'));
        return '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f1f5fa;font-family:Arial,sans-serif;color:#243b53"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:white;border-radius:16px;overflow:hidden"><tr><td style="background:#153b68;border-bottom:5px solid #d7251c;padding:28px;color:white;font-size:24px;font-weight:bold">DECKA PELPLIN<br><span style="font-size:12px;letter-spacing:1px">OFICJALNY SYSTEM BILETOWY</span></td></tr><tr><td style="padding:28px">'.$test.'<h1 style="font-size:27px;margin:0 0 20px">Dziękujemy za zamówienie!</h1><p>Cześć, '.$name.'!</p><p>Twoje bilety są gotowe. Znajdziesz je w załączniku i pod poniższym przyciskiem.</p><p style="line-height:1.6">'.$copy.'</p><table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0"><tr><td style="background:#19569d;border-radius:8px"><a href="'.$link.'" style="display:inline-block;padding:17px 28px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:17px">Pobierz bilety PDF</a></td></tr></table><p style="font-size:12px;color:#64748b">Link jest prywatny i daje dostęp do biletów — nie przekazuj go innym osobom.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4fb"><tr><td style="padding:18px;line-height:1.8"><b>Zamówienie '.$number.'</b><br>Data: '.$esc($created).'<br>Razem: <b>'.$esc($total).'</b></td></tr></table><h2 style="font-size:20px;margin-top:28px">Twoje bilety</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table><p style="line-height:1.6">Bilet na telefonie wystarczy. Pokaż kod QR przy wejściu. Halę otwieramy godzinę przed meczem.</p><p>Masz pytanie? Odpowiedz na tę wiadomość — trafi do biura klubu.</p></td></tr><tr><td style="padding:20px 28px;background:#eef4fb;font-size:13px;color:#52677f">Bilety Decka Pelplin · <a href="mailto:biuro@deckapelplin.pl" style="color:#19569d">biuro@deckapelplin.pl</a></td></tr></table></td></tr></table></body></html>';
    }
    public static function download_link(int $id):string {
        $until=time()+900;$signature=hash_hmac('sha256','pdf|'.$id.'|'.$until,wp_salt('secure_auth'));
        return add_query_arg(['action'=>'decka_pdf','order'=>$id,'until'=>$until,'signature'=>$signature],admin_url('admin-post.php'));
    }
    public static function download_signature(int $id,int $until,string $signature):bool {
        return $until>=time()&&$until<=time()+900&&hash_equals(hash_hmac('sha256','pdf|'.$id.'|'.$until,wp_salt('secure_auth')),$signature);
    }
    public static function download():void {
        $id=absint($_GET['order']??0);$o=Decka_Service::order($id);$mail=(string)($_GET['mail_signature']??'');$signed=($o&&strlen($mail)===64&&hash_equals(self::email_signature($o),$mail))||self::download_signature($id,absint($_GET['until']??0),(string)($_GET['signature']??''));if(!$signed)check_admin_referer('decka_pdf_'.$id);$o=Decka_Service::order($id);
        if(!$o || (!$signed && !Decka_Guest::owns($o) && !current_user_can('decka_manage')))wp_die('Brak dostępu.',403);
        try{$pdf=self::pdf($id);nocache_headers();header('Referrer-Policy: no-referrer');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="Decka-bilety-'.$id.'.pdf"');header('X-Content-Type-Options: nosniff');echo $pdf;}catch(Throwable $e){wp_die(esc_html($e->getMessage()));}exit;
    }
    public static function scan(string $token,int $event_id):array {
        global $wpdb;if(!preg_match('/^DK([12])\.(\d+)\.[a-f0-9]{64}$/',trim($token),$m))throw new RuntimeException('Nieprawidłowy kod biletu.');$package=$m[1]==='2';$id=(int)$m[2];$mode=Decka_DB::mode();
        return Decka_DB::tx(function()use($wpdb,$token,$event_id,$id,$mode,$package){
            // Lock the order first, consistently with refund processing, then the ticket.
            $oid=$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.Decka_DB::table('tickets').' WHERE id=%d',$id));
            $o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE id=%d FOR UPDATE',$oid));
            $t=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('tickets').' WHERE id=%d FOR UPDATE',$id));
            if(!$o || !$t || !hash_equals($package?Decka_Domain::package_token($t,wp_salt('secure_auth')):Decka_Domain::token($t,wp_salt('secure_auth')),trim($token)))throw new RuntimeException('Nieprawidłowy kod biletu.');
            if($package){if(!$o->offer_id)throw new RuntimeException('Nieprawidłowy mini-karnet.');$t=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('tickets').' WHERE order_id=%d AND seat_id=%s AND event_id=%d FOR UPDATE',$o->id,$t->seat_id,$event_id));if(!$t)throw new Decka_Scan_Error('wrong_event','Mini-karnet nie obejmuje tego meczu.');$id=(int)$t->id;}
            if($o->mode!==$mode)throw new RuntimeException('Bilet pochodzi z innego środowiska (test/produkcja).');
            if((int)$t->event_id!==$event_id)throw new Decka_Scan_Error('wrong_event','Bilet jest na inny mecz.');
            if($t->status!=='valid'||!in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Bilet został unieważniony.');
            if($t->used_at)throw new Decka_Scan_Error('used','Bilet już wykorzystany: '.wp_date('d.m.Y H:i:s',strtotime($t->used_at.' UTC')));
            $e=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('events').' WHERE id=%d',$event_id));$now=gmdate('Y-m-d H:i:s');
            if(!$e || $e->cancelled || !$e->gate_open || !$e->gate_close || $now<$e->gate_open || $now>$e->gate_close)throw new RuntimeException('Wejście na ten mecz jest obecnie zamknięte.');
            $n=Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('tickets').' SET used_at=%s,used_by=%d WHERE id=%d AND used_at IS NULL AND status=%s',$now,get_current_user_id(),$id,'valid'));
            if($n!==1)throw new Decka_Scan_Error('used','Bilet został już wykorzystany.');Decka_DB::audit('scan_accepted',$id);
            $seats=array_column(Decka_DB::seats()['seats'],null,'id');return ['valid'=>true,'seat'=>$seats[$t->seat_id],'kind'=>$t->kind,'time'=>$now];
        });
    }
}
