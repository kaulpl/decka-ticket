import fs from 'node:fs';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const req=createRequire(import.meta.url);
const pngRequire=createRequire(req.resolve('qrcode'));
const {PNG}=pngRequire('pngjs');
const uiRequire=createRequire(new URL('../frontend/package.json',import.meta.url));
const zxRequire=createRequire(uiRequire.resolve('@zxing/browser'));
const {RGBLuminanceSource,HybridBinarizer,BinaryBitmap,QRCodeReader,Code128Reader}=zxRequire('@zxing/library');
const png=PNG.sync.read(fs.readFileSync(process.env.QR_IMAGE||'/tmp/decka-voucher.png'));
const gray=new Uint8ClampedArray(png.width*png.height);
for(let i=0;i<gray.length;i++)gray[i]=(png.data[i*4]+2*png.data[i*4+1]+png.data[i*4+2])/4;
const text=new QRCodeReader().decode(new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(gray,png.width,png.height)))).getText();
assert.equal(text,fs.readFileSync('tests/expected-qr.txt','utf8'));
console.log('PASS: QR decoded from rendered A4 PDF matches the ticket token exactly.');

if(process.env.BARCODE_EXPECTED){const bitmap=new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(gray,png.width,png.height)));let barcode='';for(let y=0;y<png.height;y+=2){try{barcode=new Code128Reader().decodeRow(y,bitmap.getBlackRow(y,null),new Map()).getText();if(barcode===process.env.BARCODE_EXPECTED)break;}catch{}}assert.equal(barcode,process.env.BARCODE_EXPECTED);console.log('PASS: Code 128 decoded from rendered PDF matches the human-readable ticket number.');}
