/** Static client regression: complete guest purchase and responsive match cards. */
import {chromium,launchOptions} from './browser.mjs';
import fs from 'node:fs';import path from 'node:path';import assert from 'node:assert/strict';
const root=path.resolve('frontend/out'),seats=JSON.parse(fs.readFileSync('plugin/data/seats.json','utf8'));
fs.mkdirSync('tests/artifacts',{recursive:true});
const browser=await chromium.launch(launchOptions),page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
page.on('pageerror',e=>errors.push(e.message));let payload,profilePayload,registerPayload,profileUser=null;
const event={id:1,opponent:'Test przeciwnika',starts_at:'2030-10-10 16:00:00',venue:'Hala ZKiW nr 1, Sambora 5A',sale_open:1,image_url:'/match.svg'};
await page.route('http://decka-ui.test/**',async route=>{const url=new URL(route.request().url());
 if(url.pathname==='/api/catalog')return route.fulfill({json:{mode:'test',normal:2500,reduced:1500,seats,events:[event],offers:[],registration:true}});
 if(url.pathname==='/api/availability')return route.fulfill({json:{seats:[]}});
 if(url.pathname==='/api/profile'){profilePayload=route.request().postDataJSON();return route.fulfill({json:{user:{...profileUser,profileRequired:false}}});}
 if(url.pathname==='/api/register'){registerPayload=route.request().postDataJSON();return route.fulfill({json:{user:{name:'Jan Kowalski',email:registerPayload.email,profileRequired:false}}});}
 if(url.pathname==='/api/checkout'){payload=route.request().postDataJSON();return route.fulfill({json:{id:1}});}
 if(url.pathname==='/api/orders')return route.fulfill({json:[{id:1,status:'pending',mode:'test',total:2500,download:null,checkout_url:'https://checkout.stripe.com/fixture',items:[{seat_id:'66',kind:'normal',opponent:event.opponent}]}]});
 if(url.pathname==='/match.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1008"><rect width="1920" height="1008" fill="#19569d"/></svg>'});
 const file=path.join(root,url.pathname==='/'?'index.html':decodeURIComponent(url.pathname));if(!fs.existsSync(file)||fs.statSync(file).isDirectory())return route.fulfill({status:404,body:''});
 let body=fs.readFileSync(file);const type=({'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.woff2':'font/woff2'}[path.extname(file)]||'application/octet-stream');
 if(type==='text/html')body=Buffer.from(body.toString().replace('<head>','<head><base href="http://decka-ui.test/"/><script>window.DECKA={api:"http://decka-ui.test/api/",nonce:"test",authNonce:"guest-nonce",user:null,shopUrl:"http://decka-ui.test/bilety",googleLogin:"https://accounts.google.com/fixture"}</script>'));
 if(type==='text/html')body=Buffer.from(body.toString().replace('user:null','user:'+JSON.stringify(profileUser)));
 return route.fulfill({body,contentType:type});
});
await page.goto('http://decka-ui.test/');await page.locator('.product-tile').waitFor();
const image=await page.locator('.product-tile img').evaluate(el=>({width:el.clientWidth,height:el.clientHeight,fit:getComputedStyle(el).objectFit}));assert.equal(image.fit,'contain');assert.ok(image.width<=400);assert.ok(Math.abs(image.width/image.height-1920/1008)<.02);
assert.equal(await page.locator('a.brand').getAttribute('href'),'http://decka-ui.test/bilety');
await page.setViewportSize({width:390,height:844});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'tests/artifacts/shop-mobile.png',fullPage:true});
await page.locator('.product-tile').click();await page.getByRole('button',{name:'Powiększ sektor B'}).click();await page.getByRole('button',{name:'Sektor B, rząd 1, miejsce 66',exact:true}).click();
await page.getByRole('button',{name:'Dalej — dane kupującego'}).click();await page.getByRole('button',{name:'Zaloguj za pomocą Google'}).waitFor();await page.getByRole('button',{name:'Kup jako gość',exact:true}).click();
await page.getByLabel('E-mail *',{exact:true}).fill('guest@example.test');await page.getByLabel('Imię *',{exact:true}).fill('Jan');await page.getByLabel('Nazwisko *',{exact:true}).fill('Kowalski');await page.getByLabel('Telefon (opcjonalnie)').fill('+48 123 456 789');
await page.screenshot({path:'tests/artifacts/guest-mobile.png',fullPage:true});await page.getByRole('button',{name:'Przejdź do płatności',exact:true}).click();await page.locator('summary .order-status.pending').waitFor();
assert.equal(payload.email,'guest@example.test');assert.equal(payload.first_name,'Jan');assert.equal(payload.last_name,'Kowalski');assert.equal(payload.phone,'+48 123 456 789');assert.equal(payload.auth_nonce,'guest-nonce');assert.equal(payload.seats.length,1);assert.equal(payload.seats[0].id,'66');assert.equal(await page.getByRole('link',{name:'Pobierz bilety PDF'}).count(),0);assert.deepEqual(errors,[]);
profileUser={name:'Jan',email:'google@example.test',profileRequired:true,profile:{first_name:'Jan',last_name:'Kowalski'}};await page.reload();await page.getByRole('heading',{name:'Uzupełnij dane kibica'}).waitFor();
assert.equal(await page.locator('input[name="first_name"]').inputValue(),'Jan');assert.equal(await page.getByLabel('E-mail',{exact:true}).inputValue(),'google@example.test');
for(const [name,value] of Object.entries({street:'Sambora',house_number:'5A',postcode:'83-130',city:'Pelplin',phone:'+48 123 456 789'}))await page.locator('input[name="'+name+'"]').fill(value);
await page.screenshot({path:'tests/artifacts/profile-mobile.png',fullPage:true});await page.getByRole('button',{name:'Zapisz dane i przejdź do biletów'}).click();await page.locator('.product-tile').waitFor();assert.equal(profilePayload.city,'Pelplin');assert.equal(profilePayload.apartment,'');
profileUser=null;await page.reload();await page.locator('.product-tile').click();await page.getByRole('button',{name:'Powiększ sektor B'}).click();await page.getByRole('button',{name:'Sektor B, rząd 1, miejsce 66',exact:true}).click();await page.getByRole('button',{name:'Dalej — dane kupującego'}).click();await page.getByRole('button',{name:'Nie masz konta? Zarejestruj się'}).click();await page.getByRole('button',{name:'Utwórz konto za pomocą Google'}).waitFor();
for(const [name,value] of Object.entries({first_name:'Jan',last_name:'Kowalski',street:'Sambora',house_number:'5A',apartment:'2',postcode:'83-130',city:'Pelplin',phone:'+48 123 456 789',email:'register@example.test',password:'Test-password-12345'}))await page.locator('input[name="'+name+'"]').fill(value);
await page.getByRole('button',{name:'Utwórz konto',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});assert.equal(registerPayload.last_name,'Kowalski');assert.equal(registerPayload.house_number,'5A');assert.equal(registerPayload.apartment,'2');assert.deepEqual(errors,[]);
console.log('PASS shop: Google profile completion, full registration fields, complete uncropped card, canonical logo, mobile, guest form, Google option, checkout payload, pending without PDF, no JS errors');await browser.close();
