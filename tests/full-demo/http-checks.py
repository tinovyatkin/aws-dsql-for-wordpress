"""Authenticated HTTP coverage for the synthetic production-shaped demo."""
from pathlib import Path
import urllib.request,urllib.parse,http.cookiejar,json
root=Path(__file__).resolve().parents[2];base='http://127.0.0.1:9421';report=[]
log=root/'.local/full-demo/target-errors.log';offset=log.stat().st_size if log.exists() else 0
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(path,expected):
 r=client.open(base+path,timeout=90);html=r.read().decode();assert r.status==200 and expected in html and 'critical error' not in html.lower(),path
 report.append({'path':path,'status':r.status});print('PASS HTTP '+path,flush=True);return html
get('/wp-login.php','user_login')
body=urllib.parse.urlencode({'log':'demo','pwd':(root/'.local/full-demo/admin-password').read_text().strip(),'redirect_to':base+'/wp-admin/','testcookie':'1'}).encode()
r=client.open(urllib.request.Request(base+'/wp-login.php',data=body),timeout=90);html=r.read().decode();assert '/wp-admin/' in r.url and 'Dashboard' in html
print('PASS restored HTTP login',flush=True)
for path,expected in [('/wp-admin/edit.php','Posts'),('/wp-admin/post-new.php','wp-edit-post'),('/wp-admin/upload.php','Media Library'),('/wp-admin/edit-comments.php','Comments'),('/wp-admin/plugins.php','Site Kit'),('/wp-admin/options-general.php','General Settings'),('/wp-admin/tools.php?page=ai-request-logs','AI Request Logs'),('/wp-admin/site-health.php','Site Health')]:get(path,expected)
public=urllib.request.urlopen(base,timeout=90).read().decode();assert 'blonde.travel' in public and 'critical error' not in public.lower();print('PASS public Olga homepage',flush=True)
id=json.loads((root/'.local/full-demo/application-report.json').read_text())['post_id']
public=urllib.request.urlopen(base+'/?p='+str(id),timeout=90).read().decode();assert 'Full DSQL demo' in public and 'critical error' not in public.lower();print('PASS public migrated article',flush=True)
if log.exists():
 with log.open('rb') as f:f.seek(offset);recent=f.read().decode()
 errors=[line for line in recent.splitlines() if 'WordPress database error' in line or 'wordpress_dsql_error' in line or 'PHP Fatal' in line]
 assert not errors, '\n'.join(errors[:5])
print('PASS no new database or fatal errors during HTTP checks',flush=True)
(root/'.local/full-demo/http-report.json').write_text(json.dumps(report,indent=2))
