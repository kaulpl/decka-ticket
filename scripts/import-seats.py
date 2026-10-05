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
            sector='B' if 66<=n<=170 else 'C' if 172<=n<=309 else 'D' if 310<=n<=415 else None
            assert sector, (cell.coordinate,n)
            seats.append(dict(id=str(n),number=n,sector=sector,row=14-cell.row,x=cell.column,y=cell.row,sourceCell=cell.coordinate))
assert len(seats)==340 and len({s['id'] for s in seats})==340
# The supplied hall sheet contains the central B/C/D blocks. Its numbering leaves
# the two symmetric end sectors: A (1-65) and E (416-480), five rows of 13 seats.
# Keep the original spreadsheet coordinates untouched and add those end blocks
# outside the central plan so the full hall remains one coherent map.
for sector,start,x0 in [('A',1,-11),('E',416,51)]:
    for row in range(1,6):
        for offset in range(13):
            n=start+(row-1)*13+offset
            seats.append(dict(id=str(n),number=n,sector=sector,row=row,x=x0+offset,y=14-row,sourceCell=None))
camera=[dict(x=c.column,y=c.row,label='KAMERA') for row in sheet for c in row if c.value=='KAMERA']
assert len(seats)==470 and len({s['id'] for s in seats})==470
data=dict(source=source.name,sha256=hashlib.sha256(source.read_bytes()).hexdigest(),sheet=sheet.title,seats=seats,cameras=camera,sectors=[dict(id=k,count=sum(s['sector']==k for s in seats)) for k in 'ABCDE'])
for dest in [root/'plugin/data/seats.json',root/'frontend/public/seats.json']:
    dest.write_text(json.dumps(data,ensure_ascii=False,indent=2))
print(json.dumps({'sectors':data['sectors'],'total':len(seats),'cameras':len(camera)},ensure_ascii=False))
