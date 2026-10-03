<?php
/** Fan contact details are private user metadata, never returned in public catalog responses. */
final class Decka_Profile {
    public const FIELDS=['first_name','last_name','street','house_number','apartment','postcode','city','phone'];
    public static function validate(array $input):array {
        $out=[];foreach(self::FIELDS as $field){$v=trim(sanitize_text_field($input[$field]??''));if(($field!=='apartment'&&$v==='')||mb_strlen($v)>(in_array($field,['first_name','last_name','city'],true)?100:(in_array($field,['house_number','apartment'],true)?20:150)))throw new RuntimeException('Uzupełnij poprawnie wszystkie wymagane dane kibica.');$out[$field]=$v;}
        if(!preg_match('/^[0-9]{2}-[0-9]{3}$/',$out['postcode']))throw new RuntimeException('Kod pocztowy powinien mieć format 00-000.');
        if(!preg_match('/^[+0-9 ()-]{6,40}$/',$out['phone']))throw new RuntimeException('Podaj poprawny numer telefonu.');return $out;
    }
    public static function read(int $uid):array {$p=[];foreach(self::FIELDS as $f)$p[$f]=(string)get_user_meta($uid,'decka_'.$f,true);return $p;}
    public static function save(int $uid,array $input):void {$p=self::validate($input);foreach($p as $k=>$v)update_user_meta($uid,'decka_'.$k,$v);wp_update_user(['ID'=>$uid,'first_name'=>$p['first_name'],'last_name'=>$p['last_name'],'display_name'=>$p['first_name'].' '.$p['last_name']]);delete_user_meta($uid,'decka_profile_required');}
    public static function required(int $uid):bool {return (bool)get_user_meta($uid,'decka_profile_required',true);}
}
