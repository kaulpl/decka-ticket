<?php
require __DIR__.'/../plugin/includes/db.php';
$errors=[
    ["Unknown column 'package_ack' in 'field list'",'missing_column','package_ack'],
    ["Field 'required_legacy' doesn't have a default value",'required_field','required_legacy'],
    ["Duplicate entry 'customer@example.test' for key 'request_once'",'duplicate',''],
    ["Data too long for column 'email' at row 1",'value_length','email'],
    ["INSERT command denied to user 'secret-user'@'secret-host'",'permissions',''],
    ["Deadlock found when trying to get lock",'busy',''],
    ['', 'validation',''],
];
foreach($errors as [$raw,$reason,$field]){$d=Decka_DB::diagnose('insert','orders',$raw);if($d['reason']!==$reason||$d['field']!==$field||str_contains(json_encode($d),'customer@')||str_contains(json_encode($d),'secret-'))throw new Exception('Invalid or sensitive diagnostic');echo "PASS $reason diagnostic without data\n";}
foreach(["Duplicate entry 'private-buyer@example.test' for key 'wp_decka_orders.stripe_session'"=>'stripe_session',"Duplicate entry 'private-buyer@example.test' for key 'PRIMARY'"=>'PRIMARY',"Duplicate entry 'private-buyer@example.test' for key `request_once`"=>'request_once',"Duplicate entry 'private-buyer@example.test' for key 'unsafe@email.test'"=>''] as $raw=>$expected){$d=Decka_DB::diagnose('insert','orders',$raw);if($d['index']!==$expected||str_contains(json_encode($d),'@'))throw new Exception('Unsafe or incorrect index diagnostic');echo "PASS safe duplicate index $expected\n";}
