"""Request-level integration checks of WordPress's real login and admin endpoints."""
import http.cookiejar, json, urllib.request, urllib.parse
from pathlib import Path
root = Path(__file__).resolve().parents[2]
base = 'http://127.0.0.1:9417'
error_log = root/'.local/wordpress-errors.log'
log_offset = error_log.stat().st_size if error_log.exists() else 0
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
client.open(base + '/wp-login.php', timeout=30).read()
body = urllib.parse.urlencode({'log':'dsqltest','pwd':(root/'.local/admin-password').read_text().strip(),'wp-submit':'Log In','redirect_to':base+'/wp-admin/','testcookie':'1'}).encode()
response = client.open(urllib.request.Request(base+'/wp-login.php', data=body), timeout=60)
html = response.read().decode()
assert response.status == 200 and '/wp-admin/' in response.url, 'Login failed'
assert any(c.name.startswith('wordpress_logged_in_') for c in jar), 'Authenticated cookie missing'
assert 'Dashboard' in html and 'critical error' not in html.lower(), 'Dashboard failed'
print('PASS HTTP login sets a session cookie and renders the authenticated dashboard')
for path, expected in [('/wp-admin/edit.php','Posts'),('/wp-admin/post-new.php','wp-edit-post'),('/wp-admin/edit-comments.php','Comments'),('/wp-admin/plugins.php','Plugins')]:
    r=client.open(base+path,timeout=60); html=r.read().decode()
    assert r.status == 200 and expected in html and 'critical error' not in html.lower(), path
    print('PASS authenticated '+path)
external=json.loads((root/'.local/external-writer.json').read_text())
public=urllib.request.urlopen(base+'/?p='+str(external['post_id']),timeout=60).read().decode()
assert 'Synthetic Node.js Worker' in public and 'External Node.js' in public, 'External comment not rendered'
print('PASS anonymous article renders the Node.js comment')
with error_log.open('rb') as f:
    f.seek(log_offset)
    recent_errors=f.read().decode()
assert 'WordPress database error' not in recent_errors and 'PHP Fatal' not in recent_errors, 'New server/database errors during HTTP tests'
print('PASS no new database errors or PHP fatal errors during HTTP tests')
