"""Assign an increasing artifact version; repeat builds of a released commit reuse its tag."""
import json, re, subprocess
from pathlib import Path

def versions(tags):
    return [tuple(map(int, t[1:].split('.'))) for t in tags.splitlines() if re.fullmatch(r'v\d+\.\d+\.\d+', t)]

def choose(source, tags, current):
    if current:
        return max(current)
    latest=max(tags,default=(0,0,0))
    return source if source>latest else (latest[0],latest[1],latest[2]+1)

if __name__=='__main__':
    plugin=Path('plugin/decka-bilety.php');text=plugin.read_text()
    source=tuple(map(int,re.search(r'Version:\s*(\d+\.\d+\.\d+)',text).group(1).split('.')))
    tags=versions(subprocess.check_output(['git','tag','--list'],text=True))
    current=versions(subprocess.check_output(['git','tag','--points-at','HEAD'],text=True))
    version='.'.join(map(str,choose(source,tags,current)))
    text=re.sub(r'(Version:\s*)\d+\.\d+\.\d+',lambda m:m[1]+version,text)
    text=re.sub(r"define\('DECKA_VERSION', '[^']+'\)","define('DECKA_VERSION', '"+version+"')",text)
    plugin.write_text(text)
    path=Path('frontend/package.json');data=json.loads(path.read_text());data['version']=version;path.write_text(json.dumps(data,indent=2)+'\n')
    print(version)
