<?php
final class Decka_Domain {
    public static function allocate(int $total, int $count): array {
        if ($total<0 || $count<1) throw new InvalidArgumentException('Niepoprawna kwota lub liczba pozycji.');
        return array_map(fn($i)=>intdiv($total,$count)+($i<$total%$count?1:0),range(0,$count-1));
    }
    public static function discount(int $total,string $type,int $value): int {
        if ($value<0 || !in_array($type,['percent','fixed'],true) || ($type==='percent' && $value>100)) throw new InvalidArgumentException('Niepoprawna promocja.');
        return max(0,$total-($type==='percent'?(int)round($total*$value/100):$value));
    }
    public static function signature(string $raw,string $header,string $secret,int $now): bool {
        if (!$secret) return false;
        $parts=[]; foreach(explode(',',$header) as $piece){ $p=explode('=',trim($piece),2); if(count($p)===2) $parts[$p[0]][]=$p[1]; }
        $time=(int)($parts['t'][0]??0);
        if(abs($now-$time)>300) return false;
        $expected=hash_hmac('sha256',"$time.$raw",$secret);
        foreach($parts['v1']??[] as $sig) if(hash_equals($expected,$sig)) return true;
        return false;
    }
    public static function token(object $ticket,string $secret): string {
        $data=$ticket->id.'.'.$ticket->event_id.'.'.$ticket->nonce;
        return 'DK1.'.$ticket->id.'.'.hash_hmac('sha256',$data,$secret);
    }
}
