"""Read-only XLSX importer. The printed seat number, never the column, is the identifier."""
import json, pathlib, sys, hashlib, re
import openpyxl
source=pathlib.Path(sys.argv[1])
root=pathlib.Path(__file__).resolve().parents[1]
sheet=openpyxl.load_workbook(source,data_only=True).active
seats=[]
f_rows=sorted({cell.row for row in sheet for cell in row if isinstance(cell.value,str) and re.fullmatch(r'F\d{2}',cell.value)})
for row in sheet:
    for cell in row:
        if isinstance(cell.value,int):
            n=cell.value
            sector='A' if 1<=n<=65 else 'B' if 66<=n<=171 else 'C' if 172<=n<=309 else 'D' if 310<=n<=415 else 'E' if 416<=n<=480 else None
            assert sector, (cell.coordinate,n)
            seats.append(dict(id=str(n),number=n,sector=sector,row=14-cell.row,x=cell.column,y=cell.row,sourceCell=cell.coordinate))
        elif isinstance(cell.value,str) and re.fullmatch(r'F\d{2}',cell.value):
            source_value=cell.value
            # Confirmed correction in the supplied workbook: BV21 duplicates F44,
            # while the descending sequence and the user confirm this seat as F47.
            value='F47' if cell.coordinate=='BV21' and source_value=='F44' else source_value
            n=int(value[1:])
            row_number=min(4,(n-1)//16+1)
            first=1+(row_number-1)*16
            position=n-first+1
            aisle_after=10 if row_number==4 else 8
            seat=dict(id=value,number=n,sector='F',row=row_number,x=position+(1 if position>aisle_after else 0),y=row_number,sourceCell=cell.coordinate)
            if value!=source_value:seat['sourceValue']=source_value
            seats.append(seat)
camera=[dict(x=c.column,y=c.row,label='KAMERA') for row in sheet for c in row if c.value=='KAMERA']
assert len(seats)==540 and len({s['id'] for s in seats})==540
assert set(int(s['id']) for s in seats if s['sector']!='F')==set(range(1,481))-{261,262,277,278,279,295,296,297}
assert {s['id'] for s in seats if s['sector']=='F'}=={f'F{n:02d}' for n in range(1,69)}
assert all(next(s for s in seats if s['id']==f'F{n:02d}')['row']==row for row,span in enumerate((range(1,17),range(17,33),range(33,49),range(49,69)),1) for n in span)
data=dict(source=source.name,sha256=hashlib.sha256(source.read_bytes()).hexdigest(),sheet=sheet.title,seats=seats,cameras=camera,sectors=[dict(id=k,count=sum(s['sector']==k for s in seats)) for k in 'ABCDEF'])
for dest in [root/'plugin/data/seats.json',root/'frontend/public/seats.json']:
    dest.write_text(json.dumps(data,ensure_ascii=False,indent=2))
print(json.dumps({'sectors':data['sectors'],'total':len(seats),'cameras':len(camera)},ensure_ascii=False))
