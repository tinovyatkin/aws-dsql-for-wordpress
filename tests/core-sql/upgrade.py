"""UTF-8 metadata widening and interruption recovery on the isolated upgrade lab."""
import json
import os
import subprocess
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / '.local/upgrade-lab'
SESSION = LAB / ('core-gap-' + str(int(time.time())))
ENV = {**os.environ, 'PGSSLROOTCERT': 'system'}
target = json.loads((LAB / 'target.json').read_text())
assert target.get('classification') == 'synthetic', 'Synthetic upgrade lab required'


def call(action, *args, expected=0, fault=None):
    env = dict(ENV)
    if fault:
        env['DSQL_UPGRADE_FAULT'] = fault
    p = subprocess.run(['php', '-d', 'zend.exception_ignore_args=1', 'scripts/upgrade.php',
                        action, '--session=' + str(SESSION), *args],
                       cwd=ROOT, env=env, capture_output=True, text=True)
    with (LAB / 'core-gap.log').open('a') as f:
        f.write(p.stdout + p.stderr)
    assert p.returncode == expected, (action, p.returncode, 'See private core-gap.log')
    return p.stdout


def native(body):
    code = "require 'vendor/autoload.php';require 'migration/Restore.php';$target=json_decode(file_get_contents('.local/upgrade-lab/target.json'),true);if(($target['classification']??'')!=='synthetic')throw new RuntimeException('Synthetic only');$p=WPDSQLMigration\\Restore::target($target);$p->exec('SET search_path TO wp_live,pg_catalog');" + body
    return subprocess.run(['php', '-d', 'zend.exception_ignore_args=1', '-r', code],
                          cwd=ROOT, env=ENV, check=True, capture_output=True, text=True).stdout


def sql(statement, **kwargs):
    return call('exec', '--', 'eval', 'global $wpdb;$wpdb->query(' + json.dumps(statement) + ');', **kwargs)


call('begin', '--wordpress=' + str(ROOT / '.local/upgrade-wordpress'),
     '--target-config=' + str(LAB / 'upgrade-target.json'), '--writers-frozen=yes',
     '--backup-reference=synthetic-fixture', '--allow-destructive=yes')
sql('CREATE TABLE wp_core_gap_upgrade (id bigint PRIMARY KEY,title varchar(50)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')
sql("INSERT INTO wp_core_gap_upgrade VALUES (1,'original')")
# Simulate a retained utf8/utf8mb3 definition, leaving the DSQL UTF-8 data intact.
native("$s=$p->query(\"SELECT metadata FROM __wp_dsql_schema WHERE table_name='wp_core_gap_upgrade'\");$m=json_decode($s->fetchColumn(),true);$m['table_options']['charset']='utf8';$m['table_options']['collation']='utf8_unicode_ci';$m['mysql_ddl']=str_replace('utf8mb4','utf8',$m['mysql_ddl']);foreach($m['columns'] as &$c)if($c['Field']==='title')$c['Collation']='utf8_unicode_ci';unset($c);$s=$p->prepare(\"UPDATE __wp_dsql_schema SET metadata=? WHERE table_name='wp_core_gap_upgrade'\");$s->execute([json_encode($m)]);")
snapshot = "echo json_encode($p->query(\"SELECT fingerprint,metadata FROM __wp_dsql_schema WHERE table_name='wp_core_gap_upgrade'\")->fetch(PDO::FETCH_ASSOC));"
before = json.loads(native(snapshot))
sql('ALTER TABLE wp_core_gap_upgrade CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    expected=1, fault='after-catalog')
pending = json.loads((SESSION / 'pending.json').read_text())
assert pending['mode'] == 'metadata'
call('recover')
after = json.loads(native(snapshot))
assert before['fingerprint'] == after['fingerprint']
assert json.loads(after['metadata'])['table_options']['charset'] == 'utf8mb4'
assert 'original' in call('exec', '--', 'eval', "global $wpdb;echo $wpdb->get_var('SELECT title FROM wp_core_gap_upgrade WHERE id=1');")
assert 'true' in call('exec', '--', 'eval', "require_once ABSPATH.'wp-admin/includes/upgrade.php';var_export(maybe_convert_table_to_utf8mb4('wp_core_gap_upgrade'));")
sql('ALTER TABLE wp_core_gap_upgrade CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
sql('DROP TABLE wp_core_gap_upgrade')
call('verify')
call('finish')
print('PASS controlled UTF-8 widening, unchanged physical schema/data, interrupted catalog recovery, core helper and idempotence')
