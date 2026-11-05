"""Build a reproducible WordPress-installable ZIP from runtime files only."""
import hashlib,pathlib,re,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
version=(root/'VERSION.txt').read_text().strip()
if not re.fullmatch(r'\d+\.\d+\.\d+(?:[.-][A-Za-z0-9]+)*',version):
 raise SystemExit('Invalid VERSION.txt')
main=(root/'archivio-micologico.php').read_text()
readme=(root/'readme.txt').read_text()
if not re.search(r'^ \* Version:\s*'+re.escape(version)+r'\s*$',main,re.M):
 raise SystemExit('Plugin header version mismatch')
if f"define('AM_VERSION', '{version}');" not in main or f'Stable tag: {version}\n' not in readme:
 raise SystemExit('Runtime/readme version mismatch')
files=[root/n for n in ['archivio-micologico.php','uninstall.php','VERSION.txt','CHANGELOG.txt','readme.txt']]
for folder in ['assets','includes','templates']:
 files.extend(p for p in (root/folder).rglob('*') if p.is_file())
output=root/'dist';output.mkdir(exist_ok=True)
target=output/f'archivio-micologico-{version}.zip'
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
 for path in sorted(files):
  info=zipfile.ZipInfo('archivio-micologico/'+path.relative_to(root).as_posix(),(2020,1,1,0,0,0))
  info.compress_type=zipfile.ZIP_DEFLATED
  info.external_attr=0o100644 << 16
  archive.writestr(info,path.read_bytes())
with zipfile.ZipFile(target) as archive:
 if archive.testzip() is not None: raise SystemExit('Corrupt ZIP')
 for path in files:
  if archive.read('archivio-micologico/'+path.relative_to(root).as_posix()) != path.read_bytes():
   raise SystemExit('ZIP/source mismatch')
digest=hashlib.sha256(target.read_bytes()).hexdigest()
(target.with_suffix('.zip.sha256')).write_text(f'{digest}  {target.name}\n')
print(f'{target.name}: {len(files)} files, SHA-256 {digest}')
