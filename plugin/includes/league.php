<?php
final class Decka_League {
    public static function parse(string $html,string $team_id):array {
        $doc=new DOMDocument();$prev=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();libxml_use_internal_errors($prev);$xp=new DOMXPath($doc);$result=[];
        foreach($xp->query('//table//tr') as $tr){$td=$xp->query('./td',$tr);if($td->length!==5)continue;$home=$xp->query('.//a',$td->item(1))->item(0);$game=$xp->query('.//a',$td->item(2))->item(0);if(!$home||!$game||!preg_match('~/d/'.preg_quote($team_id,'~').'/~',$home->getAttribute('href'))||!preg_match('~/mecz/(\d+)/~',$game->getAttribute('href'),$m))continue;
            $label=trim(preg_replace('/\s+/u',' ',$td->item(4)->textContent));$date=null;
            if(preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/',$label)){$d=DateTimeImmutable::createFromFormat('!d.m.Y H:i',$label,new DateTimeZone('Europe/Warsaw'));if($d && $d->format('d.m.Y H:i')===$label)$date=$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            $result[]=['external_id'=>'pzkosz-'.$m[1],'opponent'=>trim(preg_replace('/\s+/u',' ',$td->item(3)->textContent)),'starts_at'=>$date,'date_label'=>$label,'source_url'=>'https://rozgrywki.pzkosz.pl'.$game->getAttribute('href')];
        }
        if(!$result)throw new RuntimeException('Nie znaleziono domowych meczów. Sprawdź adres, ID drużyny i strukturę strony ligowej.');return $result;
    }
    public static function sync():int {
        global $wpdb;$s=Decka_DB::settings();$url=$s['league_url']??'';$host=wp_parse_url($url,PHP_URL_HOST);
        if(wp_parse_url($url,PHP_URL_SCHEME)!=='https'||!in_array($host,['rozgrywki.pzkosz.pl','1lm.pzkosz.pl'],true))throw new RuntimeException('Dozwolone źródło: HTTPS rozgrywki.pzkosz.pl lub 1lm.pzkosz.pl.');
        $r=wp_safe_remote_get($url,['timeout'=>20,'redirection'=>0,'limit_response_size'=>2000000]);if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Nie udało się pobrać terminarza.');$rows=self::parse(wp_remote_retrieve_body($r),(string)($s['team_id']??'7625'));
        Decka_DB::tx(function()use($wpdb,$rows){foreach($rows as $row){$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Decka_DB::table('events').' WHERE external_id=%s FOR UPDATE',$row['external_id']));$row['updated_at']=gmdate('Y-m-d H:i:s');if($old){if($old->starts_at!==$row['starts_at']){$row['sale_open']=0;$row['gate_open']=null;$row['gate_close']=null;Decka_DB::audit('schedule_changed',(int)$old->id,'Sprzedaż i wejścia zamknięte do potwierdzenia terminu.');}if(false===$wpdb->update(Decka_DB::table('events'),$row,['id'=>$old->id]))throw new RuntimeException('Nie można zapisać terminarza.');}else Decka_DB::insert('events',$row+['normal_price'=>(int)(Decka_DB::settings()['normal']??2500),'reduced_price'=>(int)(Decka_DB::settings()['reduced']??1500)]);}});
        Decka_DB::audit('league_sync',0,(string)count($rows));return count($rows);
    }
}
