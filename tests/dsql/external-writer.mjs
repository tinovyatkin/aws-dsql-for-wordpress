/** A standalone Node.js process writes a native WordPress comment to DSQL. */
import { readFile, writeFile } from 'node:fs/promises';
import { AuroraDSQLPool } from '@aws/aurora-dsql-node-postgres-connector';
import { fromNodeProviderChain } from '@aws-sdk/credential-providers';
const local = new URL('../../.local/', import.meta.url);
const settings = JSON.parse(await readFile(new URL('settings.json', local), 'utf8'));
const cluster = JSON.parse(await readFile(new URL('cluster.json', local), 'utf8'));
if (cluster.tags?.Purpose !== 'synthetic-wordpress-compatibility') throw new Error('Synthetic cluster required');
const smoke = JSON.parse(await readFile(new URL('smoke-results.json', local), 'utf8'));
if (!smoke.post_id || smoke.db_errors.length) throw new Error('Run the WordPress smoke test first');
const pool = new AuroraDSQLPool({
  host: settings.endpoint, user: 'admin', max: 2,
  customCredentialsProvider: fromNodeProviderChain({ profile: settings.profile }),
});
const message = "External Node.js → DSQL → WordPress: O'Reilly, ID, 🌍 and Русский текст.";
try {
  const result = await pool.transaction(async client => {
    const { rows } = await client.query(
      `INSERT INTO dsqlwp_comments ("comment_post_ID", comment_author, comment_content, comment_approved, comment_date, comment_date_gmt, comment_agent)
       VALUES ($1, $2, $3, '1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, $4) RETURNING "comment_ID"`,
      [smoke.post_id, 'Synthetic Node.js Worker', message, 'dsql-external-worker-test']);
    // Direct writers also own WordPress's denormalized count maintenance.
    await client.query(`UPDATE dsqlwp_posts SET comment_count=(SELECT COUNT(*) FROM dsqlwp_comments WHERE "comment_post_ID"=$1 AND comment_approved='1') WHERE "ID"=$1`, [smoke.post_id]);
    return { post_id: smoke.post_id, comment_id: Number(rows[0].comment_ID), message };
  });
  await writeFile(new URL('external-writer.json', local), JSON.stringify(result, null, 2));
  console.log(`PASS Node.js wrote comment ${result.comment_id} to WordPress post ${result.post_id} through the AWS connector`);
} finally {
  await pool.end();
}
