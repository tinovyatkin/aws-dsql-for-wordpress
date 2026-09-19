#!/usr/bin/env python3
"""Serial loopback FastCGI requests to a disposable single-worker PHP-FPM pool."""
import argparse,json,socket,struct,time,statistics,pathlib

def request(port,script,query):
    def record(t,data):return struct.pack('!BBHHBB',1,t,1,len(data),0,0)+data
    def size(n):return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
    values={'SCRIPT_FILENAME':str(script),'REQUEST_METHOD':'GET','QUERY_STRING':query,'SERVER_PROTOCOL':'HTTP/1.1','GATEWAY_INTERFACE':'CGI/1.1','SERVER_NAME':'localhost','SERVER_PORT':'80','REMOTE_ADDR':'127.0.0.1'}
    params=b''
    for k,v in values.items():
        k,v=k.encode(),v.encode();params+=size(len(k))+size(len(v))+k+v
    started=time.perf_counter()
    with socket.create_connection(('127.0.0.1',port),timeout=30) as s:
        s.sendall(record(1,b'\x00\x01\x00'+b'\x00'*5)+record(4,params)+record(4,b'')+record(5,b''))
        output=b'';errors=b''
        def read(n):
            b=b''
            while len(b)<n:
                d=s.recv(n-len(b))
                if not d:raise RuntimeError('Unexpected FastCGI EOF')
                b+=d
            return b
        while True:
            _,t,_,length,padding,_=struct.unpack('!BBHHBB',read(8));body=read(length);read(padding)
            if t==6:output+=body
            elif t==7:errors+=body
            elif t==3:break
    if errors:raise RuntimeError(errors.decode())
    raw=output.split(b'\r\n\r\n',1)[1];result=json.loads(raw)
    if 'error' in result:raise RuntimeError(result['error'])
    result['roundtrip_ms']=(time.perf_counter()-started)*1000
    return result

def main():
    p=argparse.ArgumentParser();p.add_argument('--port',type=int,default=19427);p.add_argument('--samples',type=int,default=12);p.add_argument('--fresh',action='store_true');p.add_argument('--output',required=True);a=p.parse_args()
    native_mode='rust_fresh' if a.fresh else 'rust'
    script=pathlib.Path(__file__).with_name('request.php').resolve();results=[]
    for case in ['one','mix']:
        for i in range(a.samples+1):
            # Alternate ordering, use one worker, and allow normal idle gaps. No concurrency/load test.
            for mode in (['php',native_mode] if i%2==0 else [native_mode,'php']):
                r=request(a.port,script,f'mode={mode}&case={case}');r['sample']=i;results.append(r);time.sleep(.05)
    summary={}
    for case in ['one','mix']:
        checks={r['checksum'] for r in results if r['case']==case}
        if len(checks)!=1:raise RuntimeError('Result parity failed')
        for mode in ['php',native_mode]:
            group=[r for r in results if r['case']==case and r['mode']==mode and r['sample']>0]
            vals=sorted(r['server_ms'] for r in group)
            summary[f'{case}_{mode}']={'n':len(group),'median_ms':statistics.median(vals),'p95_ms':vals[min(len(vals)-1,int(len(vals)*.95))],'php_peak_mib':statistics.median(r['peak_php_mib'] for r in group)}
    pathlib.Path(a.output).write_text(json.dumps({'summary':summary,'samples':results},indent=2));print(json.dumps(summary,indent=2))
if __name__=='__main__':main()
