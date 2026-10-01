import {chromium,launchOptions} from './browser.mjs';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const browser=await chromium.launch(launchOptions);
const page=await browser.newPage({viewport:{width:1400,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base=process.env.WP_URL||'http://127.0.0.1:9410';
async function api(path,body){return page.evaluate(async({path,body})=>{const c=window.DECKA;const r=await fetch(c.api+path,{method:body===undefined?'GET':'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':c.nonce},body:body===undefined?undefined:JSON.stringify(body)});let data;try{data=await r.json()}catch{data=null}return {status:r.status,data};},{path,body});}
async function reload(view='shop'){await page.goto(base+'/wp-admin/admin-post.php?action=decka_app&view='+view);await page.getByRole('heading',{name:view==='shop'?/Twoje miejsce/:/Panel biletera/}).waitFor();if(view==='shop')await page.locator('.product-tile').first().click();}
async function login(email,password){const r=await page.evaluate(async({email,password})=>{const c=window.DECKA;const r=await fetch(c.api+'login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password,auth_nonce:c.authNonce})});const data=await r.json();if(r.ok)window.DECKA=data;return {status:r.status,data};},{email,password});assert.equal(r.status,200,JSON.stringify(r));}
await reload();
const cat=await api('catalog');assert.equal(cat.data.seats.seats.length,340);assert.ok(cat.data.events.length>=1);
const eid=Number(cat.data.events[0].id);
const initialAvailability=(await api('availability?events='+eid)).data.seats;
const shopSeat=cat.data.seats.seats.find(s=>s.sector==='B'&&!initialAvailability.some(i=>i.seat_id===s.id));
const voucherSeat=cat.data.seats.seats.find(s=>s.id!==shopSeat.id&&!initialAvailability.some(i=>i.seat_id===s.id)).id;
await page.getByRole('button',{name:'Powiększ sektor B'}).click();
await page.getByRole('button',{name:`Sektor B, rząd ${shopSeat.row}, miejsce ${shopSeat.number}`,exact:true}).click();
await page.getByRole('button',{name:'Dalej — Twoje konto'}).click();
await page.getByRole('button',{name:'Nie masz konta? Zarejestruj się'}).click();
await page.getByLabel('Imię',{exact:true}).fill('Kibic Testowy');
const email='fan-'+Date.now()+'@example.test';
await page.getByLabel('E-mail',{exact:true}).fill(email);
await page.locator('input[name="password"]').fill('Local-Test-Password-123');
await page.getByRole('button',{name:'Utwórz konto',exact:true}).click();
await page.getByText('Jesteś zalogowany. Możesz przejść do płatności.').waitFor();
// The very next request uses the freshly returned nonce: this catches guest-token bugs.
await page.getByRole('button',{name:'Przejdź do płatności',exact:true}).click();
await page.getByText('Płatności nie są jeszcze skonfigurowane. Skontaktuj się z klubem.').waitFor();
await page.getByRole('heading',{name:'Moje bilety',exact:true}).waitFor();
await page.getByText('Nie masz jeszcze zamówień.',{exact:false}).waitFor();
await reload();assert.equal((await api('orders')).status,200);
assert.equal((await api('gate/events')).status,403);
assert.equal((await api('admin/voucher',{event_id:eid})).status,403);
assert.equal((await api('availability?events='+eid)).data.seats.length,initialAvailability.length);
await api('logout',{});await reload();
// Playground's local-only default administrator; no credentials for a real website.
await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('password');await page.locator('#wp-submit').click();await page.waitForURL(/wp-admin/);await reload();
const v=await api('admin/voucher',{request_key:crypto.randomUUID(),event_id:eid,email:'voucher@example.test',seats:[{id:voucherSeat,kind:'normal'}]});assert.equal(v.status,200,JSON.stringify(v));assert.equal(v.data.status,'voucher');
const duplicate=await api('admin/voucher',{request_key:crypto.randomUUID(),event_id:eid,email:'voucher@example.test',seats:[{id:voucherSeat,kind:'normal'}]});assert.equal(duplicate.status,400);
const orders=await api('orders');const order=orders.data.find(o=>Number(o.id)===v.data.order_id);assert.ok(order.download);
const pdf=await page.request.get(order.download);assert.equal(pdf.status(),200);assert.ok(pdf.headers()['content-type'].includes('pdf'));fs.writeFileSync('tests/wordpress-voucher.pdf',await pdf.body());
const adminData=await api('admin/bootstrap');assert.equal(adminData.status,200);const csvRes=await page.request.get(adminData.data.links.csv);assert.equal(csvRes.status(),200);assert.ok((await csvRes.text()).includes('voucher@example.test'));
await reload();await api('logout',{});await reload('gate');await login('gate@example.test','Local-Testing-Only-123');
const gate=await api('gate/events');assert.equal(gate.status,200);assert.equal((await api('admin/voucher',{})).status,403);
await page.goto(base+'/wp-admin/admin-post.php?action=decka_app&view=gate');await page.getByRole('heading',{name:'Decka · bileter',exact:true}).waitFor();
await page.screenshot({path:'tests/artifacts/panel-biletera.png',fullPage:true});
fs.writeFileSync('tests/wordpress-errors.json',JSON.stringify(errors));assert.deepEqual(errors,[]);
console.log('PASS WordPress: activation, real registration/login and session nonce, permissions, missing Stripe config leaves no holds, voucher, duplicate seat prevention, protected PDF, admin CSV, gate UI.');
await browser.close();
