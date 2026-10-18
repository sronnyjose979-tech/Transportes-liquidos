"""Real Chromium, Laravel session/CSRF and isolated PostgreSQL18; no API mocks."""
import uuid
import asyncio
import json
import os
import subprocess
import unittest
from playwright.async_api import async_playwright,expect
import test_document_module as support


class SystemNavigationE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):support.DocumentModuleE2E.setUpClass.__func__(cls)
    @classmethod
    def tearDownClass(cls):support.DocumentModuleE2E.tearDownClass.__func__(cls)
    @classmethod
    def command(cls,action,data=None):return support.DocumentModuleE2E.command(action,data)

    async def asyncSetUp(self):
        self.command('clear-case-cache')
        self.playwright=await async_playwright().start()
        self.browser=await self.playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE','/usr/bin/chromium'),args=['--no-sandbox','--disable-dev-shm-usage'])
        self.context=await self.browser.new_context(viewport={'width':1440,'height':1000})
        await self.context.tracing.start(screenshots=True,snapshots=True)
        self.page=await self.context.new_page();self.errors=[]
        self.page.on('pageerror',lambda e:self.errors.append(str(e)))

    async def asyncTearDown(self):
        try:
            await self.page.screenshot(path=str(self.artifacts/(self._testMethodName+'.png')),full_page=True)
            await self.context.tracing.stop(path=str(self.artifacts/(self._testMethodName+'.zip')))
        finally:
            await self.context.close();await self.browser.close();await self.playwright.stop()
            self.proxy.drop_response_path=None
            self.proxy.drop_response_statuses=(201,)
        self.assertEqual([],self.errors)

    async def login(self,role,username=None):
        await self.page.goto(self.base+'/')
        await expect(self.page).to_have_url(self.base+'/login')
        await self.page.get_by_label('Usuario',exact=True).fill(username or 'e2e-'+role)
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        async with self.page.expect_response(lambda r:r.url.endswith('/api/login')) as event:
            await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
            await self.page.get_by_role('button',name='Ingresando…',exact=True).count() # no duplicate submission
        self.assertEqual(200,(await event.value).status)
        await self.page.wait_for_url(self.base+{'administrator':'/dashboard','secretary':'/transporte','mechanic':'/taller','driver':'/mis-viajes'}[role])
        await expect(self.page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_visible()

    async def api(self,path,body=None):
        return await self.page.evaluate('''async({path,body})=>{const headers={Accept:'application/json'};if(body!==null){headers['Content-Type']='application/json';headers['X-XSRF-TOKEN']=decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'');}const r=await fetch('/api'+path,{method:body===null?'GET':'POST',credentials:'same-origin',headers,...(body===null?{}:{body:JSON.stringify(body)})});return {status:r.status,body:r.status===204?null:await r.json()};}''',{'path':path,'body':body})

    async def test_shared_tabs_hide_old_user_without_navigating_or_closing_each_other(self):
        await self.login('administrator')
        await self.page.goto(self.base+'/administracion/personal')
        await expect(self.page.get_by_role('button',name='Registrar personal con acceso',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Cuentas y roles',exact=True).click()
        await expect(self.page.get_by_role('row').filter(has_text='e2e-administrator')).to_be_visible()
        old_url=self.page.url
        second=await self.context.new_page()
        await second.goto(self.base+'/login')
        await expect(second).to_have_url(self.base+'/dashboard')
        await second.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await expect(second).to_have_url(self.base+'/login')
        await expect(self.page.get_by_role('heading',name='La sesión cambió en este navegador',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Registrar personal con acceso',exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('row').filter(has_text='e2e-administrator')).to_have_count(0)
        self.assertEqual(old_url,self.page.url)
        self.assertFalse(self.page.is_closed())
        await second.get_by_label('Usuario',exact=True).fill('e2e-secretary')
        await second.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await second.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(second).to_have_url(self.base+'/transporte')
        self.assertEqual(403,(await self.api('/users'))['status'])
        self.assertEqual(old_url,self.page.url)
        await self.page.goto(self.base+'/login')
        await expect(self.page).to_have_url(self.base+'/transporte')
        await expect(self.page.get_by_text('Mi jornada de oficina',exact=True)).to_be_visible()

    async def test_focus_detects_cookie_change_without_storage_notification_and_profiles_are_separate(self):
        await self.login('administrator')
        old_url=self.page.url
        previous=(await self.api('/session'))['body']['data']['id']
        # An HTTP client can change the shared cookie without emitting an application signal.
        token=await self.page.evaluate("decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'')")
        response=await self.context.request.post(self.base+'/api/login',headers={'X-XSRF-TOKEN':token},data={'login':'e2e-secretary','password':support.PASSWORD})
        self.assertEqual(200,response.status)
        stale=await self.context.request.get(self.base+'/api/session',headers={'X-Session-User':previous,'Accept':'application/json'})
        self.assertEqual(409,stale.status)
        self.assertEqual('session_changed',(await stale.json())['code'])
        await self.page.evaluate("window.dispatchEvent(new Event('focus'))")
        await expect(self.page.get_by_role('heading',name='La sesión cambió en este navegador',exact=True)).to_be_visible()
        await expect(self.page.get_by_label('Resumen general')).to_have_count(0)
        self.assertEqual(old_url,self.page.url)
        other=await self.browser.new_context()
        try:
            self.assertEqual(401,(await other.request.get(self.base+'/api/session',headers={'Accept':'application/json'})).status)
            self.assertEqual(200,(await self.context.request.get(self.base+'/api/session')).status)
        finally:await other.close()

    async def test_role_landings_all_authorized_pages_and_server_denials(self):
        for role in ['administrator','secretary','mechanic','driver']:
            await self.login(role)
            data=await self.api('/navigation')
            self.assertEqual(200,data['status'],data)
            for module in data['body']['data']['modules']:
                self.assertEqual(200,(await self.context.request.get(self.base+module['href'])).status,module)
            if role!='administrator':
                self.assertEqual(403,(await self.context.request.get(self.base+'/dashboard')).status)
                self.assertEqual(403,(await self.api('/dashboard'))['status'])
            if role in ['mechanic','driver']:
                self.assertEqual(403,(await self.context.request.get(self.base+'/facturacion')).status)
                self.assertEqual(403,(await self.api('/billing/trips'))['status'])
            async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')) as event:
                await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
            self.assertEqual(204,(await event.value).status)
            if role in ['administrator','driver']:
                await self.page.wait_for_url('**/login')
            await self.page.goto(self.base+'/')
            await expect(self.page).to_have_url(self.base+'/login')
            self.assertEqual(401,(await self.api('/navigation'))['status'])

    async def test_assigned_driver_real_trip_release_and_unapproved_billing(self):
        result=subprocess.run(['php',str(support.ROOT/'tests/E2E/transport-console.php'),'fixture'],input='{}',capture_output=True,text=True,check=True,cwd=support.ROOT)
        fixture=json.loads(result.stdout)
        await self.login('secretary')
        request=await self.api('/transport/requests',{'request_key':str(uuid.uuid4()),'customer_id':fixture['customer'],'liquid_product_id':fixture['product'],'route_id':fixture['route'],'requested_liters':'100','requested_at':'2026-10-14T08:00:00-06:00'})
        self.assertEqual(201,request['status'],request)
        trip=await self.api('/transport/trips',{'request_key':str(uuid.uuid4()),'transport_request_id':request['body']['data']['id']})
        self.assertEqual(201,trip['status'],trip)
        tripid=trip['body']['data']['id']
        assigned=await self.api('/transport/trips/'+tripid+'/assignments/assign',{'resource_kind':'driver','resource_id':fixture['driver'],'reason':'Asignación E2E de conductor'})
        self.assertEqual(201,assigned['status'],assigned)
        await self.page.goto(self.base+'/facturacion')
        await expect(self.page.get_by_role('row').filter(has_text=tripid)).to_be_visible()
        await expect(self.page.get_by_role('checkbox',name='Seleccionar viaje '+tripid)).to_be_disabled()
        await expect(self.page.get_by_role('button',name='Emitir factura electrónica')).to_have_count(0)
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.page.wait_for_url('**/login')
        await self.login('driver',fixture['driver_login'])
        await self.page.get_by_text('Referencia del viaje',exact=True).click()
        await expect(self.page.get_by_text('Viaje '+tripid,exact=True)).to_be_visible()
        own=await self.api('/transport/my-trips')
        self.assertEqual(1,own['body']['meta']['total'])
        for width in [768,390]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.page.wait_for_url('**/login')
        await self.login('secretary')
        released=await self.api('/transport/trips/'+tripid+'/assignments/release',{'resource_kind':'driver','reason':'Liberación manual E2E'})
        self.assertEqual(201,released['status'],released)
        async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')) as event:
            await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        self.assertEqual(204,(await event.value).status)
        await self.login('driver',fixture['driver_login'])
        await expect(self.page.get_by_text('No hay viajes asignados vigentes.',exact=True)).to_be_visible()
        self.assertEqual(404,(await self.api('/transport/my-trips/'+tripid))['status'])

    async def test_lost_login_response_checks_session_before_manual_login(self):
        await self.page.goto(self.base+'/login')
        writes=[]
        self.page.on('request',lambda r:writes.append(r.url) if r.method=='POST' and r.url.endswith('/api/login') else None)
        await self.page.get_by_label('Usuario',exact=True).fill('e2e-administrator')
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        self.proxy.drop_response_statuses=(200,)
        self.proxy.drop_response_path='/api/login'
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(self.page.get_by_role('alert')).to_be_visible()
        await asyncio.sleep(.3)
        self.assertEqual(1,len(writes))
        # Losing the whole response also loses the rotated session cookie. Never
        # undo session regeneration or silently resend credentials to mask this.
        self.assertEqual(401,(await self.api('/session'))['status'])
        await self.page.get_by_role('button',name='Comprobar sesión y actualizar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        self.assertEqual(1,len(writes))
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await self.page.wait_for_url('**/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        self.assertEqual(2,len(writes))

    async def test_session_expiration_clears_dashboard_and_redirects_private_pages(self):
        await self.login('administrator')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await self.page.wait_for_load_state('networkidle')
        self.assertGreater(self.command('expire-sessions',{'role':'administrator'})['expired'],0)
        self.assertEqual(401,(await self.api('/dashboard'))['status'])
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page).to_have_url(self.base+'/login')
        await expect(self.page.get_by_label('Resumen general')).to_have_count(0)

if __name__=='__main__':unittest.main()
