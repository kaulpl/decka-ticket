<?php
require __DIR__.'/../plugin/includes/domain.php';
require __DIR__.'/../plugin/includes/league.php';
$checks=0;
function check($condition,$label){global $checks;if(!$condition)throw new Exception('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
check(Decka_Domain::allocate(6500,3)===[2167,2167,2166],'mini-karnet: grosze rozdzielone bez utraty kwoty');
check(array_sum(Decka_Domain::allocate(1,20))===1,'minimalna kwota, wiele wejść');
check(Decka_Domain::discount(7500,'percent',10)===6750,'rabat procentowy');
check(Decka_Domain::discount(7500,'fixed',1000)===6500,'rabat kwotowy');
check(Decka_Domain::discount(1500,'fixed',2000)===0,'rabat nie tworzy ujemnej ceny');
try{Decka_Domain::discount(100,'percent',101);check(false,'niepoprawny rabat');}catch(InvalidArgumentException $e){check(true,'odrzucenie rabatu ponad 100%');}
$raw='{"id":"evt_example"}';$secret='test_secret';$time=time();$sig=hash_hmac('sha256',"$time.$raw",$secret);$header="t=$time,v1=$sig";
check(Decka_Domain::signature($raw,$header,$secret,$time),'podpis webhook poprawny');
check(!Decka_Domain::signature($raw.' ',$header,$secret,$time),'zmieniona treść webhook odrzucona');
check(!Decka_Domain::signature($raw,$header,$secret,$time+301),'stary webhook odrzucony');
check(!Decka_Domain::signature($raw,$header,'',$time),'brak sekretu nie akceptuje webhook');
check(Decka_Domain::signature($raw,"t=$time,v1=bad,v1=$sig",$secret,$time),'rotacja wielu podpisów');
$ticket=(object)['id'=>1,'event_id'=>2,'nonce'=>'random123'];$token=Decka_Domain::token($ticket,'secret');
check((bool)preg_match('/^DK1\.1\.[a-f0-9]{64}$/',$token),'format tokenu QR');
$ticket->event_id=3;check($token!==Decka_Domain::token($ticket,'secret'),'bilet powiązany z meczem');
$rows=Decka_League::parse(file_get_contents(__DIR__.'/league.html'),'7625');
check(count($rows)===15,'15 domowych meczów w pobranym terminarzu');
check(count(array_unique(array_column($rows,'external_id')))===count($rows),'brak zduplikowanych ID meczów');
check(count(array_filter($rows,fn($r)=>$r['starts_at']===null))>0,'brak godziny pozostaje nieznany');
$oct=array_values(array_filter($rows,fn($r)=>$r['opponent']==='Miasto Szkła Krosno'));
check($oct[0]['starts_at']==='2026-10-09 16:00:00','Europe/Warsaw poprawnie przeliczona na UTC');
$seats=json_decode(file_get_contents(__DIR__.'/../plugin/data/seats.json'),true);
check(count($seats['seats'])===470,'470 miejsc w sektorach A-E');
check(count($seats['cameras'])===8,'8 stanowisk kamer nie jest sprzedawanych');
check(count(array_unique(array_column($seats['seats'],'id')))===470,'unikatowe identyfikatory miejsc');
echo "TOTAL $checks checks\n";
