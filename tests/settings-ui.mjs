/** UI regression checks against the built static admin, using isolated API fixtures. */
import {chromium,launchOptions} from './browser.mjs';
import fs from 'node:fs';import path from 'node:path';import assert from 'node:assert/strict';
const root=path.resolve('frontend/out');
const browser=await chromium.launch(launchOptions);
const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
const stamp=new Date().toISOString();
const database={namespace:'dect',version:'0.3.6',expected:'0.3.6',audit:{ok:true,checked_at:stamp,issues:[]},inventory:{source_prefix:'wp_decka_',target_prefix:'wp_dect_',tables:[{name:'orders',source:{exists:true,rows:12,engine:'InnoDB'},target:{exists:true,rows:12,engine:'InnoDB'}}]}};
const boot={version:'0.3.6',mode:'test',events:[],alerts:[],offers:[],promos:[],seats:{seats:[]},settings:{database,normal:2500,reduced:1500,max_per_fan:10,mode:'test',registration:1,league_url:'https://example.test/team',team_id:7625,ticket_color:'#19569d',test_secret_set:true,test_webhook_set:true,https:true,wallet_zip:true,test_webhook_url:'https://example.test/test',live_webhook_url:'https://example.test/live'},permissions:{updates:true},links:{plugins:'#'},updater:{status:'current'}};
let saved;
await page.route('http://decka-ui.test/**',async route=>{const url=new URL(route.request().url());
 if(url.pathname==='/api/admin/bootstrap')return route.fulfill({json:boot});
 if(url.pathname==='/api/admin/action'){
  const p=route.request().postDataJSON();
  if(p.operation==='settings'){saved=p;boot.settings.normal=p.normal;return route.fulfill({json:{message:'Zapisano zmiany.'}});}
  if(p.operation==='database_inspect'){database.audit.checked_at=new Date().toISOString();return route.fulfill({json:{message:'Sprawdzono stan bazy.'}});}
  database.status={state:'failed',time:stamp,step:'copy',table:'orders',message:'Migracja nie została zakończona.',diagnostic:{reason:'duplicate',index:'request_once',reference:'DB-ORDERS-UI',time:stamp,table:'orders',operation:'query'}};
  return route.fulfill({status:400,json:{message:'Migracja nie została zakończona.'}});
 }
 const file=path.join(root,url.pathname==='/admin/'?'admin/index.html':decodeURIComponent(url.pathname));
 if(!fs.existsSync(file)||fs.statSync(file).isDirectory())return route.fulfill({status:404,body:''});
 let body=fs.readFileSync(file);const type=({'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.woff2':'font/woff2'}[path.extname(file)]||'application/octet-stream');
 if(type==='text/html')body=Buffer.from(body.toString().replace('<head>','<head><base href="http://decka-ui.test/"/><script>window.DECKA={api:"http://decka-ui.test/api/",nonce:"test",adminScreen:"settings"}</script>'));
 return route.fulfill({body,contentType:type});
});
const tab=name=>page.getByRole('navigation',{name:'Kategorie ustawień'}).getByRole('button',{name:new RegExp('^'+name+'(?: Wymaga uwagi)?$')});
await page.goto('http://decka-ui.test/admin/');await page.getByRole('heading',{name:'Zasady sprzedaży'}).waitFor();
assert.equal(await page.getByLabel('Filtr meczu').count(),0,'irrelevant event filter hidden');
await page.getByLabel('Bilet normalny (zł)',{exact:true}).fill('30');
await tab('Płatności').click();await page.locator('input[name="test_secret"]').fill('sk_test_fixture');
await tab('System').click();await page.getByRole('button',{name:'Sprawdź stan bazy',exact:true}).click();await page.getByText('Sprawdzono stan bazy.',{exact:true}).waitFor();
await tab('Sprzedaż').click();assert.equal(await page.getByLabel('Bilet normalny (zł)',{exact:true}).inputValue(),'30','draft survives tab and diagnostic refresh');
await tab('Płatności').click();assert.equal(await page.locator('input[name="test_secret"]').inputValue(),'sk_test_fixture');
await page.getByRole('button',{name:'Zapisz zmiany',exact:true}).click();await page.getByText('Zapisano zmiany.',{exact:true}).waitFor();assert.equal(saved.normal,3000);assert.equal(saved.reduced,1500);assert.equal(saved.registration,true);assert.equal(saved.test_secret,'sk_test_fixture');assert.equal(saved.team_id,'7625');
assert.equal(await tab('Płatności').getAttribute('aria-current'),'page','saving preserves active category');
await tab('Liga').click();await page.getByLabel('Adres terminarza ligi').fill('invalid-url');await tab('Sprzedaż').click();await page.getByRole('button',{name:'Zapisz zmiany',exact:true}).click();await page.getByLabel('Adres terminarza ligi').waitFor({state:'visible'});await page.getByLabel('Adres terminarza ligi').fill('https://example.test/team');
database.namespace='pending';await tab('System').click();await page.getByRole('button',{name:'Sprawdź stan bazy',exact:true}).click();await page.getByRole('button',{name:'Ponów migrację',exact:true}).waitFor();
await page.getByRole('button',{name:'Ponów migrację',exact:true}).click();await page.getByRole('alert').getByText('Migracja nie została zakończona.',{exact:true}).waitFor();await page.getByText('Powtórzona wartość klucza unikatowego · request_once',{exact:true}).waitFor();
assert.equal(await page.getByRole('heading',{name:'Zapisany błąd'}).isVisible(),false,'technical history collapsed');
const downloadPromise=page.waitForEvent('download');await page.getByRole('button',{name:'Pobierz raport',exact:true}).click();const download=await downloadPromise;const content=fs.readFileSync(await download.path(),'utf8');assert.equal(JSON.parse(content).status.diagnostic.index,'request_once');assert.ok(!content.includes('sk_test_fixture'));
fs.mkdirSync('tests/artifacts',{recursive:true});await page.screenshot({path:'tests/artifacts/settings-system-desktop.png',fullPage:true});
await page.setViewportSize({width:390,height:844});
for(const name of ['Sprzedaż','Płatności','Konta kibiców','Bilety i e-mail','Portfele','Liga','System']){await tab(name).click();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no mobile overflow: '+name);}
await page.screenshot({path:'tests/artifacts/settings-system-mobile.png',fullPage:true});assert.deepEqual(errors,[]);
console.log('PASS settings: categories, draft retention, full save payload, invalid-field navigation, migration failure refresh, safe report download, mobile, no JS errors');await browser.close();
