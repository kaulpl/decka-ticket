import fs from 'node:fs';
import path from 'node:path';
import Engine from 'php-parser';
const parser=new Engine({parser:{phpVersion:'8.2',suppressErrors:false},ast:{withPositions:true}});
for(const f of ['plugin/decka-bilety.php',...fs.readdirSync('plugin/includes').map(f=>'plugin/includes/'+f)]){
  parser.parseCode(fs.readFileSync(f,'utf8'),f);console.log('PHP syntax OK:',f);
}
