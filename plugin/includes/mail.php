<?php
/** Isolated SMTP transport for ticket messages; never changes WordPress global mail state. */
final class Decka_Mail {
    public static function settings(array $input,array $saved):array {
        if(!array_key_exists('smtp_enabled',$input))return $saved;
        $next=['smtp_enabled'=>empty($input['smtp_enabled'])?0:1,'smtp_host'=>strtolower(trim((string)($input['smtp_host']??''))),'smtp_port'=>(int)($input['smtp_port']??587),'smtp_security'=>(string)($input['smtp_security']??'tls'),'smtp_user'=>trim((string)($input['smtp_user']??'bilety@deckapelplin.pl'))];
        if(!current_user_can('manage_options')){foreach($next as $k=>$v)if((string)$v!==(string)($saved[$k]??(['smtp_enabled'=>0,'smtp_host'=>'','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'bilety@deckapelplin.pl'][$k])))throw new RuntimeException('Tylko administrator może zmieniać SMTP.');if(!empty($input['smtp_password']))throw new RuntimeException('Tylko administrator może zmieniać SMTP.');return $saved;}
        if($next['smtp_host']&&!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$next['smtp_host']))throw new RuntimeException('Podaj nazwę serwera SMTP bez https://, ścieżek i numeru portu.');
        if(!in_array($next['smtp_security'],['tls','ssl'],true)||!in_array($next['smtp_port'],[465,587],true))throw new RuntimeException('Wybierz STARTTLS lub TLS oraz port 587 albo 465.');
        if(preg_match('/[\r\n]/',$next['smtp_user']))throw new RuntimeException('Nieprawidłowy login SMTP.');
        $password=(string)($input['smtp_password']??'');if($password!=='')$next['smtp_password']=Decka_Stripe::encrypt($password);
        $next=array_merge($saved,$next);if($next['smtp_enabled']&&(!$next['smtp_host']||!$next['smtp_user']||!Decka_Stripe::decrypt($next['smtp_password']??'')))throw new RuntimeException('Uzupełnij serwer, login i hasło SMTP przed włączeniem.');return $next;
    }
    public static function configure(object $mail,array $s):void {
        $mail->isSMTP();$mail->Host=$s['smtp_host'];$mail->Port=(int)$s['smtp_port'];$mail->SMTPSecure=$s['smtp_security'];$mail->SMTPAuth=true;$mail->Username=$s['smtp_user'];$mail->Password=Decka_Stripe::decrypt($s['smtp_password']??'');$mail->SMTPAutoTLS=true;$mail->Timeout=15;$mail->getSMTPInstance()->Timelimit=20;$mail->SMTPDebug=0;$mail->SMTPKeepAlive=false;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mail->CharSet='UTF-8';$mail->setFrom('bilety@deckapelplin.pl','Bilety Decka Pelplin');$mail->addReplyTo('biuro@deckapelplin.pl','Biuro Decka Pelplin');
    }
    private static function client():object {
        require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';require_once ABSPATH.WPINC.'/PHPMailer/SMTP.php';
        $s=Decka_DB::settings();if(empty($s['smtp_enabled']))throw new RuntimeException('Najpierw włącz i zapisz SMTP.');$mail=new \PHPMailer\PHPMailer\PHPMailer(true);self::configure($mail,$s);return $mail;
    }
    public static function check():array {
        if(!current_user_can('manage_options'))throw new RuntimeException('Tylko administrator może sprawdzać SMTP.');$mail=null;
        try{$mail=self::client();if(!$mail->smtpConnect())throw new RuntimeException('SMTP');return ['ok'=>true,'message'=>'Połączenie szyfrowane i logowanie SMTP działają. Nie wysłano wiadomości; test nie potwierdza dostarczenia do skrzynki.'];}
        catch(Throwable $e){return ['ok'=>false,'message'=>'Nie udało się połączyć lub zalogować do SMTP. Sprawdź zapisane dane, port, szyfrowanie i certyfikat serwera.'];}
        finally{if($mail)$mail->smtpClose();}
    }
    public static function send(string $to,string $subject,string $html,array $headers,array $attachments):bool {
        if(empty(Decka_DB::settings()['smtp_enabled']))return wp_mail($to,$subject,$html,$headers,$attachments);
        $mail=null;try{$mail=self::client();$mail->addAddress($to);$mail->Subject=$subject;$mail->isHTML(true);$mail->Body=$html;$mail->AltBody=html_entity_decode(strip_tags(str_replace(['</p>','<br>','</tr>'],"\n",$html)),ENT_QUOTES,'UTF-8');foreach($attachments as $file)$mail->addAttachment($file,'Decka-bilety.pdf');return $mail->send();}
        catch(Throwable $e){throw new RuntimeException('Nie udało się wysłać biletów przez SMTP. Sprawdź połączenie w Ustawienia → Bilety i e-mail.');}
        finally{if($mail)$mail->smtpClose();}
    }
}
