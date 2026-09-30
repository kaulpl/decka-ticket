import runpy
choose=runpy.run_path('scripts/release-version.py')['choose']
assert choose((0,2,0),[],[])==(0,2,0)
assert choose((0,2,0),[(0,2,0)],[])==(0,2,1)
assert choose((0,2,0),[(0,2,9)],[])==(0,2,10)
assert choose((0,3,0),[(0,2,9)],[])==(0,3,0)
assert choose((0,2,0),[(0,2,9)],[(0,2,5)])==(0,2,5)
print('5 release version tests passed')
