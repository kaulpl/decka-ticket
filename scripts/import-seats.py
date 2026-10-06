"""Read-only XLSX importer. The seat number, never the column, is the identifier."""
import json, pathlib, sys, hashlib
import openpyxl
source=pathlib.Path(sys.argv[1])
root=pathlib.Path(__file__).resolve().parents[1]
sheet=openpyxl.load_workbook(source,data_only=True).active
seats=[]
for row in sheet:
    for cell in row:
        if isinstance(cell.value,int):
            n=cell.value
            sector='A' if 1<=n<=65 else 'B' if 66<=n<=171 else 'C' if 172<=n<=309 else 'D' if 310<=n<=415 else 'E' if 416<=n<=480 else None
            assert sector, (cell.coordinate,n)
            seats.append(dict(id=str(n),number=n,sector=sector,row=14-cell.row,x=cell.column,y=cell.row,sourceCell=cell.coordinate))
camera=[dict(x=c.column,y=c.row,label='KAMERA') for row in sheet for c in row if c.value=='KAMERA']
assert len(seats)==472 and len({s['id'] for s in seats})==472
assert set(map(int,(s['id'] for s in seats)))==set(range(1,481))-{261,262,277,278,279,295,296,297}
data=dict(source=source.name,sha256=hashlib.sha256(source.read_bytes()).hexdigest(),sheet=sheet.title,seats=seats,cameras=camera,sectors=[dict(id=k,count=sum(s['sector']==k for s in seats)) for k in 'ABCDE'])
for dest in [root/'plugin/data/seats.json',root/'frontend/public/seats.json']:
    dest.write_text(json.dumps(data,ensure_ascii=False,indent=2))
print(json.dumps({'sectors':data['sectors'],'total':len(seats),'cameras':len(camera)},ensure_ascii=False))
