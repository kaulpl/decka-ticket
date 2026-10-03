/** Camera integration in Chromium with a synthetic QR video stream. */
import {chromium,launchOptions} from './browser.mjs';
import fs from 'node:fs';import path from 'node:path';import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),zxing=createRequire(require.resolve('../frontend/node_modules/@zxing/browser'))('@zxing/library');
const bits=new zxing.QRCodeWriter().encode('decka-scanner-fixture',zxing.BarcodeFormat.QR_CODE,320,320,new Map()),rects=[];
for(let y=0;y<bits.getHeight();y++)for(let x=0;x<bits.getWidth();x++)if(bits.get(x,y))rects.push(`<rect x="${x}" y="${y}" width="1" height="1"/>`);
const qr='data:image/svg+xml;base64,'+Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320"><rect width="320" height="320" fill="white"/><g fill="black">${rects.join('')}</g></svg>`).toString('base64');
fs.mkdirSync('tests/artifacts',{recursive:true});
const browser=await chromium.launch(launchOptions),page=await browser.newPage({viewport:{width:390,height:844}}),errors=[],times=[];page.on('pageerror',e=>errors.push(e.message));
await page.addInitScript(qr=>{Object.defineProperty(navigator,'mediaDevices',{value:{enumerateDevices:async()=>[{kind:'videoinput',deviceId:'fixture',label:'Test camera'}],getUserMedia:async()=>{const c=document.createElement('canvas');c.width=640;c.height=640;const ctx=c.getContext('2d'),img=new Image();img.src=qr;await img.decode();const draw=()=>{ctx.fillStyle='white';ctx.fillRect(0,0,640,640);ctx.drawImage(img,160,160);};draw();const t=setInterval(draw,50),stream=c.captureStream(20);stream.getVideoTracks()[0].addEventListener('ended',()=>clearInterval(t));return stream;}}});},qr);
const root=path.resolve('frontend/out'),events=[{id:1,opponent:'Test',starts_at:'2030-10-10 16:00:00',total:200,scanned:42,entry_open:true}];
await page.route('http://decka-ui.test/**',async route=>{const url=new URL(route.request().url());
 if(url.pathname==='/api/catalog')return route.fulfill({json:{mode:'test',seats:{seats:[],cameras:[],sectors:[]},events,offers:[]}});
 if(url.pathname==='/api/availability')return route.fulfill({json:{seats:[]}});
 if(url.pathname==='/api/gate/events')return route.fulfill({json:events});
 if(url.pathname==='/api/gate/scan'){times.push(Date.now());const n=times.length;return route.fulfill({status:n===4?400:200,json:n===1?{valid:true,seat:{sector:'B',row:1,number:66},kind:'normal'}:n===2?{valid:false,status:'used',message:'Bilet już był zeskanowany'}:n===3?{valid:false,status:'wrong_event',message:'Bilet na inny mecz'}:{message:'Nieprawidłowy kod'}});}
 const file=path.join(root,url.pathname==='/'?'index.html':url.pathname);if(!fs.existsSync(file)||fs.statSync(file).isDirectory())return route.fulfill({status:404,body:''});let body=fs.readFileSync(file);const type=({'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png'}[path.extname(file)]||'application/octet-stream');if(type==='text/html')body=Buffer.from(body.toString().replace('<head>','<head><base href="http://decka-ui.test/"/><script>window.DECKA={api:"http://decka-ui.test/api/",nonce:"test",view:"gate",user:{name:"Bileter",email:"test@example.test",gate:true}}</script>'));return route.fulfill({body,contentType:type});
});
await page.goto('http://decka-ui.test/');await page.getByRole('heading',{name:'Decka · skaner'}).waitFor();assert.equal(await page.locator('.gate input').count(),0);assert.equal(await page.locator('.gate select').count(),0);await page.getByText('42 / 200',{exact:true}).waitFor();assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight));await page.getByRole('button',{name:'Włącz skaner'}).click();
for(const status of ['success','used','wrong-event','failure']){await page.locator('.scanner-window.'+status).waitFor({timeout:15000});await page.screenshot({path:'tests/artifacts/scanner-'+status+'.png'});}
assert.ok(times.length>=4);for(let i=1;i<4;i++)assert.ok(times[i]-times[i-1]>=1950,'result visible for two seconds');assert.deepEqual(errors,[]);console.log('PASS scanner: actual QR decoding from synthetic camera, 4 result states, automatic 2-second restart, mobile viewport, no manual input or JS errors');await browser.close();
