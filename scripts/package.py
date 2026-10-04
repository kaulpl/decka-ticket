"""Build the installable WordPress ZIP and GitHub updater manifest."""
from pathlib import Path
import argparse,zipfile,shutil,hashlib,json,re
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--output',default=str(root/'dist'));args=parser.parse_args()
out=Path(args.output).resolve();out.mkdir(parents=True,exist_ok=True)
version=re.search(r'Version:\s*([\d.]+)',(root/'plugin/decka-bilety.php').read_text()).group(1)
assets=root/'plugin/assets'
if assets.exists():
    for p in assets.rglob('*'):
        if p.is_file():p.chmod(0o644)
    shutil.rmtree(assets)
shutil.copytree(root/'frontend/out',assets)
for css in (assets/'_next').rglob('*.css'):
    css.write_text(css.read_text().replace('./_next/static/media/','../media/'))
assert (assets/'admin/index.html').exists(), 'Build the Next.js admin first'
licenses=root/'plugin/licenses';licenses.mkdir(exist_ok=True)
for p in (root/'frontend/app/fonts').glob('*OFL.txt'):shutil.copy2(p,licenses/p.name)
shutil.copy2(root/'README.md',root/'plugin/INSTRUKCJA.md')
for name in ['README.md','RAPORT-TESTOW.md','REPOZYTORIUM.md']:
    if (root/name).exists():shutil.copy2(root/name,out/name)
install=out/f'decka-bilety-{version}.zip'
with zipfile.ZipFile(install,'w',zipfile.ZIP_DEFLATED) as z:
    for p in sorted((root/'plugin').rglob('*')):
        if p.is_file():z.write(p,Path('decka-bilety')/p.relative_to(root/'plugin'))
source=out/f'decka-bilety-zrodla-{version}.zip'
with zipfile.ZipFile(source,'w',zipfile.ZIP_DEFLATED) as z:
    for p in sorted(root.rglob('*')):
        rel=p.relative_to(root)
        if any(x in {'.git','node_modules','.next','out','assets','dist','__pycache__','tmp','output','artifacts'} for x in rel.parts):continue
        if not p.is_file() or p.is_relative_to(out):continue
        if p.suffix in {'.pdf','.tsbuildinfo'} or p.name.startswith(('wordpress-','expected-qr')):continue
        if p.suffix=='.png' and not rel.as_posix().startswith('frontend/public/'):continue
        z.write(p,Path('decka-bilety-zrodla')/rel)
sha=hashlib.sha256(install.read_bytes()).hexdigest()
manifest={'slug':'decka-bilety','version':version,'asset':install.name,'sha256':sha,'requires':'6.6','requires_php':'8.2'}
(out/'decka-bilety-update.json').write_text(json.dumps(manifest,indent=2)+'\n')
for file in [install,source]:
    with zipfile.ZipFile(file) as z:
        assert z.testzip() is None
        assert not any('/.git/' in n or '/node_modules/' in n for n in z.namelist())
    print(file.name,round(file.stat().st_size/1024/1024,2),'MB',hashlib.sha256(file.read_bytes()).hexdigest())
(out/'SHA256.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in [install,source]},indent=2)+'\n')
