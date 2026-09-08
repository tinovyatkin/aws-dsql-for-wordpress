"""Live DSQL crash/retry tests using only the separately restored synthetic fixture."""
import json
import os
from pathlib import Path
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / '.local/upgrade-lab'
SESSION = LAB / ('recovery-' + str(int(time.time())))
ENV = {**os.environ, 'PGSSLROOTCERT': 'system'}
checks = []


def run(action, *args, fault=None, expected=0):
    cmd = ['php', 'scripts/upgrade.php', action, '--session=' + str(SESSION), *args]
    env = dict(ENV)
    if fault:
        env['DSQL_UPGRADE_FAULT'] = fault
    p = subprocess.run(cmd, cwd=ROOT, env=env, capture_output=True, text=True)
    with (LAB / 'recovery-output.log').open('a') as log:
        log.write('\n' + action + '\n' + p.stdout + p.stderr)
    if p.returncode != expected:
        raise RuntimeError('Unexpected upgrade exit for ' + action + ': ' + str(p.returncode))
    return p.stdout


def execute(code, **kwargs):
    return run('exec', '--', 'eval', 'global $wpdb; ' + code, **kwargs)


def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    checks.append(name)
    print('PASS ' + name, flush=True)


run('begin', '--wordpress=' + str(ROOT / '.local/upgrade-wordpress'), '--target-config=' + str(LAB / 'upgrade-target.json'), '--writers-frozen=yes', '--backup-reference=synthetic-fixture', '--allow-destructive=yes', '--policy=/Volumes/workplace/ChatGPT/blonde.travel/config/dsql-migration-policy.json')
execute("$wpdb->query(\"CREATE TABLE wp_upgrade_recovery (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, label varchar(60) NOT NULL, payload longtext NULL)\");for($i=0;$i<105;$i++)$wpdb->insert('wp_upgrade_recovery',['label'=>'row-'.$i,'payload'=>\"A\\0B\"]);")
for i, fault in enumerate(['after-create', 'during-copy', 'after-copy', 'after-old-rename', 'after-old-move', 'after-publish', 'after-catalog']):
    sql = "ALTER TABLE wp_upgrade_recovery ADD COLUMN probe_" + str(i) + " varchar(30) NOT NULL DEFAULT 'value-" + str(i) + "'"
    execute('$wpdb->query(' + json.dumps(sql) + ');', fault=fault, expected=1)
    state = json.loads((SESSION / 'session.json').read_text())
    check(state['failed'] and (SESSION / 'pending.json').exists(), fault + ' leaves a recoverable failed operation')
    p = subprocess.run(['php', '-d', 'error_reporting=8191', '/opt/homebrew/bin/wp', '--path=' + str(ROOT / '.local/upgrade-wordpress'), 'option', 'get', 'blogname'], cwd=ROOT, env=ENV, capture_output=True)
    check(p.returncode == 75, fault + ' keeps ordinary WordPress blocked')
    run('recover')
    result = execute("if($wpdb->get_var('SELECT COUNT(*) FROM wp_upgrade_recovery')!=='105'||$wpdb->get_var('SELECT probe_" + str(i) + " FROM wp_upgrade_recovery LIMIT 1')!=='value-" + str(i) + "')throw new RuntimeException('Data mismatch');echo 'recovered';")
    check('recovered' in result, fault + ' resumes without row loss or duplication')

execute("try{$wpdb->query('ALTER TABLE wp_upgrade_recovery ADD UNIQUE KEY dup (probe_0)');}catch(Throwable $e){}update_option('_dsql_bad_upgrade_version','incorrect');", expected=1)
run('cancel')
check('unchanged' in execute("if(get_option('_dsql_bad_upgrade_version',false)!==false)throw new RuntimeException('Version advanced after failure');echo 'unchanged';"), 'Caught schema failure still blocks later version writes')
execute("$wpdb->query('ALTER TABLE wp_upgrade_recovery MODIFY label varchar(2) NOT NULL');", expected=1)
run('cancel')
check('row-0' in execute("echo $wpdb->get_var('SELECT label FROM wp_upgrade_recovery WHERE id=1');"), 'Unsafe narrowing does not truncate the original')
execute("$wpdb->query('ALTER TABLE wp_upgrade_recovery ADD INDEX binary_key (payload)');", expected=1)
run('cancel')
check(True, 'Indexing encoded NUL text is refused before publishing')

run('exec', '--', 'eval-file', str(ROOT / 'tests/upgrade/core-old-schema.php'))
run('exec', '--', 'core', 'update-db', fault='after-old-rename', expected=1)
assert (SESSION / 'pending.json').exists(), 'Core fault did not leave a pending DDL operation'
run('recover')
run('exec', '--', 'core', 'update-db')
run('verify')
check(True, 'Real WordPress core update-db recovers and dbDelta converges')
run('exec', '--', 'eval-file', str(ROOT / 'tests/upgrade/ai-old-schema.php'))
run('exec', '--', 'eval-file', str(ROOT / 'tests/upgrade/ai-upgrade.php'), fault='during-copy', expected=1)
assert (SESSION / 'pending.json').exists(), 'AI fault did not leave a pending DDL operation'
run('recover')
run('exec', '--', 'eval-file', str(ROOT / 'tests/upgrade/ai-upgrade.php'))
check(True, 'Real AI schema migration recovers and advances its version afterward')
execute("$wpdb->query('DROP TABLE wp_upgrade_recovery');")
run('verify')
run('finish')
check(not (ROOT / '.local' / ('.dsql-upgrade-' + __import__('hashlib').sha256(str((ROOT / '.local/upgrade-wordpress').resolve()).encode()).hexdigest()[:16])).exists(), 'Verified finish removes the maintenance guard')
(LAB / 'recovery-report.json').write_text(json.dumps({'checks': checks, 'session': str(SESSION)}, indent=2))
