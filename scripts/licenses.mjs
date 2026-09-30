import fs from 'node:fs';import path from 'node:path';
const dir='frontend/node_modules/.pnpm';const entries=[];
for(const folder of fs.readdirSync(dir)){
 const nm=path.join(dir,folder,'node_modules');if(!fs.existsSync(nm))continue;
 for(const item of fs.readdirSync(nm)){
  const roots=item.startsWith('@')?fs.readdirSync(path.join(nm,item)).map(n=>path.join(nm,item,n)):[path.join(nm,item)];
  for(const root of roots){const pkg=path.join(root,'package.json');if(!fs.existsSync(pkg)||fs.lstatSync(root).isSymbolicLink())continue;
   const p=JSON.parse(fs.readFileSync(pkg,'utf8'));if(!p.name)continue;const license=fs.readdirSync(root).find(n=>/^licen[sc]e(?:\.md|\.txt)?$/i.test(n));
   entries.push(`\n${p.name}@${p.version} — ${p.license||'see package'}\n${license?fs.readFileSync(path.join(root,license),'utf8'):''}`);
  }
 }
}
fs.mkdirSync('plugin/licenses',{recursive:true});fs.writeFileSync('plugin/licenses/FRONTEND-NOTICES.txt',entries.join('\n\n'));
console.log('Bundled license notices:',entries.length);
