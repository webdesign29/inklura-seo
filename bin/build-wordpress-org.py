"""Build a complete, free directory edition without the direct-distribution updater."""
from pathlib import Path
from zipfile import ZipFile,ZIP_DEFLATED,ZipInfo
import hashlib,re
root=Path(__file__).resolve().parents[1]
out=root/'dist/wordpress-org/inklura-seo.zip';out.parent.mkdir(parents=True,exist_ok=True)
files=[root/n for n in ['inklura-seo.php','uninstall.php','readme.txt','LICENSE']]+sorted((root/'src').glob('*.php'))+sorted((root/'languages').glob('*'))
with ZipFile(out,'w',ZIP_DEFLATED) as z:
 for f in files:
  if f.name=='Updater.php':continue
  data=f.read_bytes()
  if f.suffix=='.php':data=data.decode('utf-8')
  if f.name=='Plugin.php':
   start=data.index('\t\t// Auto-updates for normal-plugin installs only.')
   end=data.index('\n\t\t/**',start)
   data=data[:start]+'\t\t// This edition receives updates only from the WordPress Plugin Directory.\n'+data[end:]
  if f.name=='inklura-seo.php':
   data=data.replace('wp-content/mu-plugins). The Updater only registers for normal-plugin installs.','wp-content/mu-plugins). Directory updates apply to normal-plugin installs.')
   data=data.replace('// Must-use installs can\'t self-update through wp-admin (a fleet deploy handles\n// those); the Updater only registers for normal-plugin installs.','// Must-use installs are updated by the site administrator.')
  info=ZipInfo('inklura-seo/'+str(f.relative_to(root)),(2026,11,2,12,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
  z.writestr(info,data)
digest=hashlib.sha256(out.read_bytes()).hexdigest();out.with_suffix('.zip.sha256').write_text(digest+'  '+out.name+'\n')
print(out);print(digest)
