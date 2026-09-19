#!/usr/bin/env python3
import hashlib,json,pathlib,platform,subprocess,sys
out=pathlib.Path(sys.argv[1]);binary=out/'wp_dsql_native.so'
info=subprocess.check_output(['php','-n','-i'],text=True)
fields={}
for line in info.splitlines():
    key,sep,val=line.partition(' => ')
    if sep and key in ['PHP API','PHP Extension Build','Zend Extension Build','Thread Safety','Debug Build']:fields[key]=val
fields.update({'architecture':platform.machine(),'php_version':subprocess.check_output(['php-config','--version'],text=True).strip(),'rust_version':subprocess.check_output(['rustc','--version'],text=True).strip(),'sha256':hashlib.sha256(binary.read_bytes()).hexdigest(),'os':'Debian GNU/Linux 12 (bookworm)'})
(out/'manifest.json').write_text(json.dumps(fields,indent=2)+'\n')
