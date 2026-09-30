import os,re
from pathlib import Path
version=re.search(r'Version:\s*([\d.]+)',Path('plugin/decka-bilety.php').read_text()).group(1)
assert os.environ['RELEASE_TAG']=='v'+version, 'Tag musi odpowiadać wersji wtyczki'
