/** Same one-layer text envelope as DSQL_Value_Codec (PHP). */
import { createHash } from 'node:crypto';
import { isUtf8 } from 'node:buffer';
export const prefix='~dsqlb64:v1:';
export function encodeValue(value) {
 const bytes=Buffer.isBuffer(value)?value:Buffer.from(value,'utf8');
 if(!isUtf8(bytes))throw new Error('Text codec requires UTF-8, optionally containing NUL bytes');
 if(!bytes.includes(0)&&!bytes.subarray(0,prefix.length).equals(Buffer.from(prefix)))return bytes.toString('utf8');
 return prefix+createHash('sha256').update(bytes).digest('hex')+':'+bytes.toString('base64');
}
export function decodeValue(value) {
 if(!value.startsWith(prefix))return Buffer.from(value,'utf8');
 const frame=value.slice(prefix.length);if(frame[64]!==':')throw new Error('Malformed DSQL value envelope');
 const bytes=Buffer.from(frame.slice(65),'base64');if(bytes.toString('base64')!==frame.slice(65)||createHash('sha256').update(bytes).digest('hex')!==frame.slice(0,64))throw new Error('DSQL value checksum mismatch');return bytes;
}
