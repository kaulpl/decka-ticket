<?php
require __DIR__.'/../plugin/includes/xlsx.php';
$binary=Decka_Xlsx::build(['Klient','Kwota PLN'],[['Jan Kowalski',12345],['Anna & Syn',500]]);
$tmp=tempnam(sys_get_temp_dir(),'decka-xlsx-test-');file_put_contents($tmp,$binary);$zip=new ZipArchive();if($zip->open($tmp)!==true)throw new RuntimeException('XLSX is not a valid ZIP container.');$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$styles=$zip->getFromName('xl/styles.xml');$zip->close();unlink($tmp);
if(!str_contains($sheet,'Jan Kowalski')||!str_contains($sheet,'Anna &amp; Syn')||!str_contains($sheet,'<v>123.45</v>'))throw new RuntimeException('XLSX rows or monetary values are invalid.');
if(!str_contains($styles,'[$zł-pl-PL]'))throw new RuntimeException('XLSX currency style is missing.');
echo "XLSX accounting export test passed\n";
