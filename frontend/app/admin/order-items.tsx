'use client';

const canChange=(item:Record<string,any>)=>item.ticket_status==='valid'&&!item.used_at;
const unavailable='Zmiana jest niedostępna dla biletu wykorzystanego, unieważnionego lub jeszcze niewystawionego.';

export default function OrderItems({items,onChange}:{items:Record<string,any>[];onChange?:(mode:'seat'|'event',eventId:number,itemId?:number)=>void}){
 const groups:Record<string,Record<string,any>[]>={};
 for(const item of items)(groups[item.event_id]||=[]).push(item);
 return <div className="da-order-matches">{Object.entries(groups).map(([id,rows])=>{
  const wholeOrderAvailable=rows.every(canChange);
  return <article className="da-order-match" key={id}><header><span>MECZ</span><h3>Decka Pelplin — {rows[0].opponent}</h3><p>{rows[0].starts_at?new Date(rows[0].starts_at.replace(' ','T')+'Z').toLocaleString('pl-PL',{timeZone:'Europe/Warsaw'}):'Termin do potwierdzenia'}</p><small>{rows[0].venue}</small>{onChange&&<div className="da-order-actions"><button type="button" disabled={!wholeOrderAvailable} title={wholeOrderAvailable?undefined:unavailable} onClick={()=>onChange('seat',Number(id))}>Zmień miejsca całego zamówienia</button><button type="button" disabled={!wholeOrderAvailable} title={wholeOrderAvailable?undefined:unavailable} onClick={()=>onChange('event',Number(id))}>Zmień mecz całego zamówienia</button></div>}</header>{rows.map(i=>{
   const available=canChange(i);
   return <div className="da-order-seat" key={i.id}><div className="da-seat-chips"><span>Sektor <b>{i.seat?.sector||'—'}</b></span><span>Rząd <b>{i.seat?.row||'—'}</b></span><span>Miejsce <b>{i.seat?.number||i.seat_id}</b></span></div><div className="da-ticket-meta"><strong>{i.ticket_number||'Bilet jeszcze niewystawiony'}</strong><span>{i.kind==='reduced'?'Ulgowy':i.kind==='voucher'?'Voucher':'Normalny'} · {new Intl.NumberFormat('pl-PL',{style:'currency',currency:'PLN'}).format(Number(i.amount||0)/100)}</span><small>{!i.ticket_number?'Oczekuje na wystawienie':i.ticket_status==='revoked'?'Unieważniony':i.used_at?'Wejście zeskanowane':'Gotowy do wejścia'}</small>{onChange&&<div className="da-ticket-actions"><button type="button" disabled={!available} title={available?undefined:unavailable} onClick={()=>onChange('seat',Number(id),Number(i.id))}>Zmień miejsce biletu</button><button type="button" disabled={!available} title={available?undefined:unavailable} onClick={()=>onChange('event',Number(id),Number(i.id))}>Zmień mecz biletu</button></div>}</div></div>;
  })}</article>;
 })}</div>;
}
