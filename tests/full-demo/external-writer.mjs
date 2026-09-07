import {readFile,writeFile} from 'node:fs/promises';
import {AuroraDSQLPool} from '@aws/aurora-dsql-node-postgres-connector';
import {fromNodeProviderChain} from '@aws-sdk/credential-providers';
import {encodeValue} from '../../scripts/value-codec.mjs';
const local=new URL('../../.local/full-demo/',import.meta.url);
const settings=JSON.parse(await readFile(new URL('runtime-target.json',local),'utf8'));
const app=JSON.parse(await readFile(new URL('application-report.json',local),'utf8'));
if(app.errors.length||!app.post_id||!/^wp_/.test(settings.user))throw new Error('Verified synthetic runtime is required');
const pool=new AuroraDSQLPool({host:settings.endpoint,user:settings.user,max:2,customCredentialsProvider:fromNodeProviderChain({profile:settings.profile})});
try{
 const raw='External worker\0payload 🌍';
 const data=await pool.transaction(async c=>{
  const comment=await c.query(`INSERT INTO wp_live.wp_comments ("comment_post_ID",comment_author,comment_content,comment_approved,comment_date,comment_date_gmt) VALUES ($1,'Synthetic external worker',$2,'1',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING "comment_ID"`,[app.post_id,'External Node.js writer on the full DSQL migration demo.']);
  const meta=await c.query('INSERT INTO wp_live.wp_postmeta (post_id,meta_key,meta_value) VALUES ($1,$2,$3) RETURNING meta_id',[app.post_id,'_dsql_external_binary',encodeValue(raw)]);
  await c.query(`UPDATE wp_live.wp_posts SET comment_count=(SELECT COUNT(*) FROM wp_live.wp_comments WHERE "comment_post_ID"=$1 AND comment_approved='1') WHERE "ID"=$1`,[app.post_id]);
  return {post_id:app.post_id,comment_id:Number(comment.rows[0].comment_ID),meta_id:Number(meta.rows[0].meta_id),expected_base64:Buffer.from(raw).toString('base64')};
 });
 await writeFile(new URL('external-report.json',local),JSON.stringify(data,null,2));
 console.log('PASS independent Node.js writer used the restricted role and reversible binary codec');
}finally{await pool.end();}
