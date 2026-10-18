"""Real Chromium IndexedDB + Web Locks; synthetic HTTP contracts, not Laravel E2E.
No npm dependencies needed. Node's built-in TS transform is not a type check.
"""
import asyncio
import functools
import http.server
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]

class OfflineStorageTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='driver-offline-')
        cls.directory = Path(cls.temp.name)
        script = """const fs=require('node:fs'),path=require('node:path'),{stripTypeScriptTypes}=require('node:module');
for(const file of ['DriverPortal/offline','DriverPortal/local','Administration/api','FleetAndDocuments/api','FleetAndDocuments/contracts','System/sessionIdentity']){
 const source=fs.readFileSync('resources/js/modules/'+file+'.ts','utf8');
 const js=stripTypeScriptTypes(source,{mode:'transform'}).replace(/(from\\s+['"])(\\.[^'"]+)(['"])/g,'$1$2.js$3');
 const target=path.join(process.argv[1],file+'.js');fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,js);
}"""
        subprocess.run(['node','-e',script,cls.temp.name],cwd=ROOT,check=True,capture_output=True)
        (cls.directory/'index.html').write_text('<script type="module">import * as offline from "/DriverPortal/offline.js"; window.offline=offline;</script>')
        class Quiet(http.server.SimpleHTTPRequestHandler):
            def log_message(self,*args): pass
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Quiet,directory=cls.temp.name))
        cls.thread=threading.Thread(target=cls.server.serve_forever,daemon=True);cls.thread.start()
        cls.base=f'http://127.0.0.1:{cls.server.server_port}'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown();cls.server.server_close();cls.temp.cleanup()

    async def asyncSetUp(self):
        self.playwright=await async_playwright().start()
        self.browser=await self.playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE','/usr/bin/chromium'),args=['--no-sandbox','--disable-dev-shm-usage'])
        self.context=await self.browser.new_context()
        self.page=await self.context.new_page()
        self.identity='a';self.status=201;self.posts=[];self.lost=False;self.seen={}
        await self.context.route('**/api/**',self.api)
        await self.page.goto(self.base)
        await self.page.wait_for_function('!!window.offline')
        await self.page.evaluate("offline.rememberActor({id:'a',roles:['driver'],login:null})")

    async def asyncTearDown(self):
        await self.browser.close();await self.playwright.stop()

    async def api(self,route):
        path=route.request.url.split('/api',1)[1]
        if path=='/session':
            if self.identity is None: await route.fulfill(status=401,json={'message':'Session expired'});return
            await route.fulfill(json={'data':{'id':self.identity,'login':'synthetic','roles':['driver']}});return
        if path=='/csrf':await route.fulfill(json={});return
        self.posts.append(route.request)
        if self.status!=201:await route.fulfill(status=self.status,json={'message':'Conflicto sintético'});return
        if '/receipts' in path:
            result={'receipt_id':'proof-server'}
        else:
            body=route.request.post_data_json
            result=self.seen.setdefault(body['request_key'],{'expense_id':'expense-server'} if path=='/expenses' else {'id':'report-server'})
        if self.lost:self.lost=False;await route.abort('failed');return
        await route.fulfill(status=201,json={'data':result})

    async def capture(self,path='/transport/my-trips/trip/reports',file=False):
        return await self.page.evaluate('''async({path,file})=>{const c=offline.newCapture('a','Captura',path,{information:{observation:'Conservar'}},file?new File(['%PDF-synthetic'],'proof.pdf',{type:'application/pdf'}):null);await offline.saveCapture(c);return c.id}''',{'path':path,'file':file})

    async def sync(self,manual=True):
        return await self.page.evaluate("async manual=>{try{await offline.synchronize('a',async()=>{},manual);return null;}catch(e){return e.status??e.message;}}",manual)

    async def rows(self,actor='a'):
        return await self.page.evaluate("async actor=>(await offline.captures(actor)).map(c=>({...c,file:c.file?{name:c.file.name,size:c.file.size}:null}))",actor)

    async def test_offline_capture_persists_reload_and_new_window(self):
        await self.context.set_offline(True)
        key=await self.capture(file=True)
        await self.context.set_offline(False)
        await self.page.reload();await self.page.wait_for_function('!!window.offline')
        row=(await self.rows())[0];self.assertEqual(key,row['id']);self.assertEqual('pendiente',row['status']);self.assertGreater(row['file']['size'],0)
        await self.page.close();self.page=await self.context.new_page();await self.page.goto(self.base);await self.page.wait_for_function('!!window.offline')
        self.assertEqual(key,(await self.rows())[0]['id']);self.assertEqual([],self.posts)

    async def test_reconnection_and_lost_response_reuse_original_key(self):
        await self.capture();self.lost=True
        await self.sync();before=(await self.rows())[0]
        self.assertEqual('pendiente',before['status']);self.assertEqual(1,len(self.seen))
        await self.page.reload();await self.page.wait_for_function('!!window.offline')
        await self.sync();after=(await self.rows())[0]
        self.assertEqual('sincronizado',after['status']);self.assertEqual(before['body'],after['body']);self.assertEqual(1,len(self.seen));self.assertEqual(2,len(self.posts))

    async def test_receipt_waits_for_expense_and_retains_bytes_until_acknowledged(self):
        await self.page.evaluate("""async()=>{const a=offline.newCapture('a','Gasto','/expenses',{amount:'1.02'});const b=offline.newCapture('a','Comprobante','/expenses/local/receipts',{},new File(['%PDF-data'],'proof.pdf',{type:'application/pdf'}),a.id);await offline.saveCaptures([a,b]);}""")
        self.lost=True;await self.sync();rows=await self.rows();self.assertEqual(1,len(self.posts));self.assertIsNotNone(rows[1]['file'])
        await self.sync();rows=await self.rows();self.assertEqual(['sincronizado','sincronizado'],[r['status'] for r in rows]);self.assertIsNone(rows[1]['file']);self.assertIn('/expenses/expense-server/receipts',self.posts[-1].url)

    async def test_expired_session_and_original_account_authentication(self):
        await self.capture(file=True);self.identity=None
        self.assertEqual(401,await self.sync());self.assertEqual([],self.posts);self.assertIsNotNone((await self.rows())[0]['file'])
        self.assertIsNone(await self.page.evaluate('offline.offlineActor()'))
        self.identity='a';await self.page.evaluate("offline.rememberActor({id:'a',roles:['driver'],login:null})")
        # File operations expect a receipt acknowledgement, so use its proper endpoint.
        self.status=403;await self.sync();self.assertEqual('en conflicto',(await self.rows())[0]['status'])

    async def test_account_change_does_not_send_or_expose_original_queue(self):
        await self.capture();self.identity='b'
        self.assertEqual(401,await self.sync());self.assertEqual([],self.posts);self.assertEqual([],await self.rows('b'));self.assertEqual(1,len(await self.rows('a')))
        await self.page.evaluate('offline.lockLocal()');await self.sync();self.assertEqual([],self.posts)

    async def test_conflict_and_rejection_preserve_payload_and_file(self):
        for status,expected in [(403,'en conflicto'),(404,'en conflicto'),(409,'en conflicto'),(422,'rechazado')]:
            self.status=status;await self.capture('/expenses/server/receipts',True);await self.sync()
            row=(await self.rows())[-1];self.assertEqual(expected,row['status']);self.assertIsNotNone(row['file']);self.assertEqual('Conservar',row['body']['information']['observation'])
        count=len(self.posts);await self.sync();self.assertEqual(count,len(self.posts))

    async def test_simultaneous_tabs_and_interrupted_sync_do_not_duplicate(self):
        await self.capture()
        await asyncio.gather(self.sync(),self.sync())
        self.assertEqual(1,len(self.posts));self.assertEqual('sincronizado',(await self.rows())[0]['status'])
        await self.page.evaluate("async()=>{const c=offline.newCapture('a','Interrumpida','/transport/my-trips/trip/reports',{});c.status='sincronizando';await offline.saveCapture(c)}")
        await self.sync();self.assertEqual('sincronizado',(await self.rows())[-1]['status'])

    async def test_backoff_limits_automatic_attempts_manual_retry_is_available(self):
        await self.capture();self.status=503
        await self.sync(False);await self.sync(False);self.assertEqual(1,len(self.posts))
        await self.page.evaluate("async()=>{const [c]=await offline.captures('a');c.attempts=5;c.next=0;await offline.saveCapture(c)}")
        await self.sync(False);self.assertEqual(1,len(self.posts));self.status=201
        await self.sync();self.assertEqual('sincronizado',(await self.rows())[0]['status'])

if __name__=='__main__':unittest.main()
