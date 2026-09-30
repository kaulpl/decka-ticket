<?php
final class Decka_Tickets {
    public static function pdf(int $order_id):string {
        global $wpdb;require_once DECKA_DIR.'vendor/tcpdf/tcpdf.php';$o=Decka_Service::order($order_id);
        if(!$o || !in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Bilety nie są dostępne.');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT t.*, e.opponent,e.starts_at,e.venue FROM '.Decka_DB::table('tickets').' t JOIN '.Decka_DB::table('events').' e ON e.id=t.event_id WHERE t.order_id=%d ORDER BY t.event_id,t.id',$order_id));
        $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetMargins(20,20,20);$pdf->SetAutoPageBreak(false);$pdf->SetCreator('Decka Bilety');$pdf->SetTitle('Bilety Decka Pelplin');
        $seats=array_column(Decka_DB::seats()['seats'],null,'id');
        foreach($rows as $t){$seat=$seats[$t->seat_id];$pdf->AddPage();$color=sscanf(Decka_DB::settings()['ticket_color']??'#0c253a','#%02x%02x%02x');$pdf->SetFillColor(...$color);$pdf->Rect(0,0,210,54,'F');$pdf->SetTextColor(255,255,255);$pdf->SetFont('dejavusans','B',24);$pdf->SetXY(20,18);$pdf->Cell(170,12,'DECKA PELPLIN');$pdf->SetFont('dejavusans','',11);$pdf->SetXY(20,33);$pdf->Cell(170,8,$t->status==='revoked'?'BILET UNIEWAŻNIONY':($o->mode==='test'?'BILET TESTOWY — NIEWAŻNY NA PRAWDZIWY MECZ':'BILET WSTĘPU'));
            $pdf->SetTextColor(10,31,53);$pdf->SetXY(20,67);$pdf->SetFont('dejavusans','B',20);$pdf->MultiCell(170,32,'Decka Pelplin' . "\n" . '— '.$t->opponent,0,'L',false,1,'','',true,0,false,true,32,'T',true);$pdf->SetFont('dejavusans','',12);$pdf->SetXY(20,103);$pdf->Cell(170,8,wp_date('d.m.Y, H:i',strtotime($t->starts_at.' UTC'),new DateTimeZone('Europe/Warsaw')));$pdf->SetXY(20,115);$pdf->MultiCell(170,7,$t->venue,0,'L');
            $pdf->SetFillColor(238,244,246);$pdf->RoundedRect(20,137,170,28,3,'1111','F');$pdf->SetXY(28,143);$pdf->SetFont('dejavusans','B',17);$pdf->Cell(155,14,'SEKTOR '.$seat['sector'].'     RZĄD '.$seat['row'].'     MIEJSCE '.$seat['number']);
            $pdf->SetFont('dejavusans','B',13);$pdf->SetXY(20,177);$pdf->Cell(80,9,$t->kind==='voucher'?'VOUCHER':($t->kind==='reduced'?'BILET ULGOWY':'BILET NORMALNY'));
            $pdf->SetFont('dejavusans','',10);$pdf->SetXY(20,191);$pdf->MultiCell(91,6,"Zamówienie #$order_id · Bilet #$t->id\n\nPokaż kod QR przy wejściu.\nBilet umożliwia jedno wejście na wskazany mecz.\nNie udostępniaj kodu innym osobom.",0,'L');
            $pdf->write2DBarcode(Decka_Domain::token($t,wp_salt('secure_auth')),'QRCODE,H',128,178,60,60,['border'=>0,'padding'=>3,'fgcolor'=>[0,0,0],'bgcolor'=>[255,255,255]],'N');
            $pdf->SetXY(20,256);$pdf->SetFont('dejavusans','',9);$pdf->MultiCell(170,6,Decka_DB::settings()['ticket_footer']??'W razie zmiany terminu obowiązuje aktualna data meczu. Aktualny bilet znajdziesz na swoim koncie. Numeracja rzędów: rząd 1 najbliżej boiska.',0,'L');
        }
        return $pdf->Output('decka-bilety.pdf','S');
    }
    public static function email(int $id):void {
        global $wpdb;$o=Decka_Service::order($id);if(!$o || $o->mail_sent_at)return;
        $wpdb->query($wpdb->prepare('UPDATE '.Decka_DB::table('orders').' SET mail_attempts=mail_attempts+1 WHERE id=%d',$id));
        $tmp=tempnam(sys_get_temp_dir(),'decka-');if(!$tmp)throw new RuntimeException('Brak katalogu tymczasowego dla PDF.');$file=$tmp.'.pdf';rename($tmp,$file);
        try{if(file_put_contents($file,self::pdf($id))===false)throw new RuntimeException('Nie można zapisać PDF.');$sent=wp_mail($o->email,($o->mode==='test'?'[TEST] ':'').str_replace('{order}',(string)$id,(Decka_DB::settings()['mail_subject']??'')?:'Decka Pelplin — Twoje bilety').($o->status==='voucher'?' · VOUCHER':''),(Decka_DB::settings()['mail_body']??'W załączniku przesyłamy bilety. Pokaż kod QR przy wejściu na mecz. Do zobaczenia w hali!'),['Content-Type: text/plain; charset=UTF-8'],[$file]);if(!$sent)throw new RuntimeException('Wysyłka wiadomości nie powiodła się. Sprawdź SMTP.');$wpdb->update(Decka_DB::table('orders'),['mail_sent_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$id]);}finally{if(file_exists($file))unlink($file);}
    }
    public static function download():void {
        $id=absint($_GET['order']??0);check_admin_referer('decka_pdf_'.$id);$o=Decka_Service::order($id);
        if(!$o || ((int)$o->user_id!==get_current_user_id() && !current_user_can('decka_manage')))wp_die('Brak dostępu.',403);
        try{$pdf=self::pdf($id);nocache_headers();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="Decka-bilety-'.$id.'.pdf"');header('X-Content-Type-Options: nosniff');echo $pdf;}catch(Throwable $e){wp_die(esc_html($e->getMessage()));}exit;
    }
    public static function scan(string $token,int $event_id):array {
        global $wpdb;if(!preg_match('/^DK1\.(\d+)\.[a-f0-9]{64}$/',trim($token),$m))throw new RuntimeException('Nieprawidłowy kod biletu.');$id=(int)$m[1];$mode=Decka_DB::mode();
        return Decka_DB::tx(function()use($wpdb,$token,$event_id,$id,$mode){
            // Lock the order first, consistently with refund processing, then the ticket.
            $oid=$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.Decka_DB::table('tickets').' WHERE id=%d',$id));
            $o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('orders').' WHERE id=%d FOR UPDATE',$oid));
            $t=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('tickets').' WHERE id=%d FOR UPDATE',$id));
            if(!$o || !$t || !hash_equals(Decka_Domain::token($t,wp_salt('secure_auth')),trim($token)))throw new RuntimeException('Nieprawidłowy kod biletu.');
            if($o->mode!==$mode)throw new RuntimeException('Bilet pochodzi z innego środowiska (test/produkcja).');
            if((int)$t->event_id!==$event_id)throw new RuntimeException('Bilet jest na inny mecz.');
            if($t->status!=='valid'||!in_array($o->status,['paid','free','voucher'],true))throw new RuntimeException('Bilet został unieważniony.');
            if($t->used_at)throw new RuntimeException('Bilet już wykorzystany: '.wp_date('d.m.Y H:i:s',strtotime($t->used_at.' UTC')));
            $e=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('events').' WHERE id=%d',$event_id));$now=gmdate('Y-m-d H:i:s');
            if(!$e || $e->cancelled || !$e->gate_open || !$e->gate_close || $now<$e->gate_open || $now>$e->gate_close)throw new RuntimeException('Wejście na ten mecz jest obecnie zamknięte.');
            $n=Decka_DB::query($wpdb->prepare('UPDATE '.Decka_DB::table('tickets').' SET used_at=%s,used_by=%d WHERE id=%d AND used_at IS NULL AND status=%s',$now,get_current_user_id(),$id,'valid'));
            if($n!==1)throw new RuntimeException('Bilet został już wykorzystany.');Decka_DB::audit('scan_accepted',$id);
            $seats=array_column(Decka_DB::seats()['seats'],null,'id');return ['valid'=>true,'seat'=>$seats[$t->seat_id],'kind'=>$t->kind,'time'=>$now];
        });
    }
}
