import re,pathlib,urllib.request
root=pathlib.Path(__file__).resolve().parents[1]
css=pathlib.Path('/private/tmp/decka-fonts.css').read_text()
out=root/'frontend/app/fonts';out.mkdir(exist_ok=True)
for url in re.findall(r'url\((https://[^)]+)\)',css):
    filename=url.rsplit('/',1)[-1]
    (out/filename).write_bytes(urllib.request.urlopen(url).read())
    css=css.replace(url,'./fonts/'+filename)
target=root/'frontend/app/globals.css';text=target.read_text();target.write_text(re.sub(r"^@import url\([^\n]+\);",lambda _:css,text,count=1))
for family,path in [('barlowcondensed','ofl/barlowcondensed'),('manrope','ofl/manrope')]:
    (out/(family+'-OFL.txt')).write_bytes(urllib.request.urlopen('https://raw.githubusercontent.com/google/fonts/main/'+path+'/OFL.txt').read())
print('Fonts bundled locally with OFL licenses.')
