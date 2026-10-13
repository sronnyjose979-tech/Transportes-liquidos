"""Real Chromium, Laravel session/CSRF and isolated PostgreSQL 18; no API mocks.
Run bash tests/run-postgres.sh --e2e-administration after npm run build.
"""
import asyncio
import os
import unittest
import uuid
from playwright.async_api import async_playwright, expect
import test_document_module as support


class AdministrationE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls): support.DocumentModuleE2E.setUpClass.__func__(cls)
    @classmethod
    def tearDownClass(cls): support.DocumentModuleE2E.tearDownClass.__func__(cls)
    async def asyncSetUp(self):
        asyncio.get_running_loop().slow_callback_duration = 2.0
        self.playwright = await async_playwright().start()
        self.browser = await self.playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE','/usr/bin/chromium'),args=['--no-sandbox','--disable-dev-shm-usage'])
        self.context = await self.browser.new_context(viewport={'width':1440,'height':1000})
        await self.context.tracing.start(screenshots=True,snapshots=True)
        self.page = await self.context.new_page()
        self.errors = []
        self.page.on('pageerror',lambda e:self.errors.append(str(e)))
        self.page.on('dialog',lambda dialog:asyncio.create_task(dialog.accept()))
        self.tag = 'ADM-'+str(uuid.uuid4())[:8]
    async def asyncTearDown(self):
        try:
            await self.page.screenshot(path=str(self.artifacts/(self._testMethodName+'.png')),full_page=True)
            await self.context.tracing.stop(path=str(self.artifacts/(self._testMethodName+'.zip')))
        finally:
            self.proxy.drop_response_path=None
            self.proxy.drop_response_statuses=(201,)
            await self.context.close(); await self.browser.close(); await self.playwright.stop()
        self.assertEqual([],self.errors)
    async def login(self,role='administrator'):
        await self.page.goto(self.base+'/taller')
        await self.page.get_by_label('Usuario',exact=True).fill('e2e-'+role)
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Inicio de Taller',exact=True)).to_be_visible()
    async def save(self,button,path,method='POST',status=201):
        async with self.page.expect_response(lambda r:r.request.method==method and r.url.endswith('/api'+path)) as event:
            await button.click()
        response=await event.value
        self.assertEqual(status,response.status,await response.text())
        self.assertTrue(response.request.headers.get('x-xsrf-token'))
        if status in [200,201]: await expect(self.page.get_by_text('Operación confirmada y registros actualizados.',exact=True)).to_be_visible()
        return (await response.json()).get('data')
    async def account(self,role,name):
        await self.page.get_by_role('button',name='Cuentas y roles',exact=True).click()
        await self.page.get_by_role('button',name='Nueva cuenta',exact=True).click()
        form=self.page.get_by_role('form',name='Registro de personal')
        await form.get_by_label('Nombre visible').fill(name)
        await form.get_by_label('Usuario de acceso').fill(name)
        await form.get_by_label('Correo electrónico').fill(name+'@e2e.invalid')
        await form.get_by_label(role,exact=True).check()
        await form.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await form.get_by_label('Confirmar contraseña',exact=True).fill(support.PASSWORD)
        return await self.save(form.get_by_role('button',name='Guardar cuenta'),'/users')
    async def employee(self,user,name):
        await self.page.get_by_role('button',name='Empleados',exact=True).click()
        await self.page.get_by_role('button',name='Nuevo empleado',exact=True).click()
        form=self.page.get_by_role('form',name='Registro de personal')
        await form.get_by_label('Nombre del empleado').fill(name)
        await form.get_by_label('Buscar Cuenta vinculada').fill(user['login'])
        await form.get_by_label('Cuenta vinculada',exact=True).select_option(user['id'])
        return await self.save(form.get_by_role('button',name='Guardar empleado'),'/employees')

    async def test_create_edit_resources_and_see_real_operational_selectors(self):
        await self.login()
        await self.page.get_by_role('link',name='Administración',exact=False).click()
        await self.page.get_by_role('button',name='Registrar cabezal',exact=True).click()
        await self.page.get_by_label('Código del activo',exact=True).fill(self.tag+'-CAB')
        head=await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tractor-units')
        await self.page.get_by_role('button',name='Editar activo').click()
        await self.page.get_by_label('Código del activo',exact=True).fill(self.tag+'-CAB-EDIT')
        await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tractor-units/'+head['id'],'PATCH',200)
        await self.page.get_by_role('button',name='Volver al listado').click()
        await self.page.get_by_label('Tipo de activo',exact=True).select_option('tanker')
        await self.page.get_by_role('button',name='Registrar cisterna').click()
        await self.page.get_by_label('Código del activo',exact=True).fill(self.tag+'-CIS')
        await self.page.get_by_label('Tiene divisiones físicas').check()
        await self.page.get_by_label('Código de compartimento',exact=True).fill('A')
        await self.page.get_by_label('Capacidad en litros',exact=True).fill('1000.50')
        await self.page.get_by_role('button',name='Añadir compartimento').click()
        await self.page.get_by_label('Código de compartimento',exact=True).nth(1).fill('B')
        await self.page.get_by_label('Capacidad en litros',exact=True).nth(1).fill('2000.25')
        tank=await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tankers')
        self.assertEqual(2,len(tank['compartments']))
        await self.page.get_by_role('button',name='Editar activo').click()
        await self.page.get_by_label('Capacidad en litros',exact=True).first.fill('1001.75')
        await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tankers/'+tank['id'],'PATCH',200)
        await self.page.get_by_role('link',name='Administración de Personal',exact=True).click()
        driver=await self.account('Chofer',self.tag+'-driver')
        employee=await self.employee(driver,self.tag+'-Chofer')
        await expect(self.page.get_by_role('row').filter(has_text=self.tag+'-Chofer')).to_contain_text('Chofer habilitado')
        await self.page.get_by_role('row').filter(has_text=self.tag+'-Chofer').get_by_role('button',name='Consultar / editar').click()
        form=self.page.get_by_role('form',name='Registro de personal')
        await form.get_by_label('Nombre del empleado').fill(self.tag+'-Chofer corregido')
        await self.save(form.get_by_role('button',name='Guardar empleado'),'/employees/'+employee['id'],'PATCH',200)
        mechanic=await self.account('Mecánico',self.tag+'-mechanic')
        worker=await self.employee(mechanic,self.tag+'-Mecánico')
        await self.page.get_by_role('link',name='Catálogos operativos',exact=True).click()
        records={}
        for kind,title in [('customers','cliente'),('liquid-products','producto'),('operation-sites','sitio')]:
            await self.page.get_by_label('Catálogo',exact=True).select_option(kind)
            await self.page.get_by_role('button',name='Registrar '+title,exact=True).click()
            form=self.page.get_by_role('form',name='Registro de catálogo')
            await form.get_by_label('Nombre',exact=True).fill(self.tag+'-'+title)
            if kind=='operation-sites': await form.get_by_label('Dirección').fill('Dirección sintética')
            records[kind]=await self.save(form.get_by_role('button',name='Guardar catálogo'),'/catalogs/'+kind)
        row=self.page.get_by_role('row').filter(has_text=self.tag+'-sitio')
        await row.get_by_role('button',name='Consultar / editar').click()
        form=self.page.get_by_role('form',name='Registro de catálogo')
        await form.get_by_label('Dirección').fill('Dirección corregida')
        await self.save(form.get_by_role('button',name='Guardar catálogo'),'/catalogs/operation-sites/'+records['operation-sites']['id'],'PUT',200)
        await self.page.set_viewport_size({'width':768,'height':1024})
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        await self.page.screenshot(path=str(self.artifacts/'administration-tablet.png'),full_page=True)
        await self.page.set_viewport_size({'width':1440,'height':1000})
        await self.page.get_by_role('link',name='Documentación',exact=True).click()
        await expect(self.page.get_by_role('button').filter(has_text=self.tag+'-CAB-EDIT')).to_be_visible()
        await expect(self.page.get_by_role('button').filter(has_text=self.tag+'-CIS')).to_be_visible()
        await self.page.get_by_role('link',name='Transporte y Despacho',exact=True).click()
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud').click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        await form.get_by_label('Cliente',exact=True).select_option(records['customers']['id'])
        await form.get_by_label('Producto líquido',exact=True).select_option(records['liquid-products']['id'])
        # Real browser GET confirms driver eligibility and fleet compartments; no fabricated API.
        values=await self.page.evaluate("""async () => {const paths=['drivers','assets','sites'];return await Promise.all(paths.map(async p=>(await (await fetch('/api/transport/lookups/'+p+'?per_page=100')).json()).data));}""")
        self.assertTrue(any(r['id']==employee['id'] for r in values[0]))
        self.assertTrue(any(r['id']==tank['id'] and len(r['compartments'])==2 for r in values[1]))
        self.assertTrue(any(r['id']==records['operation-sites']['id'] for r in values[2]))
        await self.page.goto(self.base+'/taller')
        await self.page.get_by_role('button',name='Crear orden',exact=True).click()
        form=self.page.get_by_role('form',name='Crear orden')
        await form.get_by_label('Vehículo',exact=True).select_option(head['id'])
        await form.get_by_label('Descripción de la reparación',exact=True).fill('Reparación sintética única')
        await form.get_by_label('Motivo / información recibida',exact=True).fill('Prueba de integración')
        async with self.page.expect_response(lambda r:r.request.method=='POST' and r.url.endswith('/api/workshop/orders')) as event:
            await form.get_by_role('button',name='Guardar',exact=True).click()
        response=await event.value;self.assertEqual(201,response.status,await response.text())
        # Go to newly created order using the real list, then check the new mechanic selector.
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await self.page.get_by_role('row').filter(has_text='Reparación sintética única').get_by_role('button',name='Ver detalle',exact=False).click()
        await self.page.get_by_role('button',name='Asignar mecánico',exact=True).click()
        await self.page.get_by_label('Empleado mecánico',exact=True).select_option(worker['id'])
        await self.page.set_viewport_size({'width':768,'height':1024})
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_permissions_validation_activation_and_lost_response_recovery(self):
        await self.login()
        await self.page.goto(self.base+'/administracion/flota')
        await self.page.get_by_role('button',name='Registrar cabezal',exact=True).click()
        await self.page.get_by_label('Código del activo').fill(self.tag)
        await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tractor-units')
        await self.page.get_by_role('button',name='Volver al listado').click()
        await self.page.get_by_role('button',name='Registrar cabezal').click()
        await self.page.get_by_label('Código del activo').fill(self.tag)
        await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tractor-units',status=422)
        await expect(self.page.get_by_label('Código del activo')).to_have_value(self.tag)
        await self.page.get_by_label('Código del activo').fill(self.tag+'-lost')
        self.proxy.drop_response_path='/api/tractor-units'
        self.proxy.drop_response_statuses=(201,)
        await self.page.get_by_role('button',name='Guardar activo').click()
        await expect(self.page.get_by_role('alert')).to_contain_text('No se pudo confirmar')
        await expect(self.page.get_by_role('button',name='Guardar activo')).to_be_disabled()
        await self.page.get_by_role('button',name='Consultar estado confirmado').click()
        await self.page.get_by_label('Buscar por código').fill(self.tag+'-lost')
        await expect(self.page.get_by_role('button',name='Consultar '+self.tag+'-lost',exact=True)).to_have_count(1)
        await self.page.get_by_role('link',name='Administración de Personal',exact=True).click()
        user=await self.account('Chofer',self.tag+'-activation')
        row=self.page.get_by_role('row').filter(has_text=user['login'])
        await row.get_by_role('button',name='Consultar / editar').click()
        form=self.page.get_by_role('form',name='Registro de personal')
        await form.get_by_label('Nombre visible').fill(self.tag+'-Nombre corregido')
        await form.get_by_label('Mecánico',exact=True).check()
        changed=await self.save(form.get_by_role('button',name='Guardar cuenta'),'/users/'+user['id'],'PATCH',200)
        self.assertEqual({'driver','mechanic'},{r['name'] for r in changed['roles']})
        await expect(row).to_contain_text(self.tag+'-Nombre corregido')
        await self.save(row.get_by_role('button',name='Desactivar',exact=True),'/users/'+user['id']+'/activation','PATCH',200)
        await expect(row.get_by_role('button',name='Activar',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.page.goto(self.base+'/administracion/personal')
        await expect(self.page.get_by_role('heading',name='Acceso a Administración')).to_be_visible()
        await self.login('secretary')
        await expect(self.page.get_by_role('link',name='Administración',exact=False)).to_have_count(0)
        for section in ['flota','personal','catalogos']:
            await self.page.goto(self.base+'/administracion/'+section)
            await expect(self.page.get_by_role('heading',name='Acceso no autorizado')).to_be_visible()

if __name__=='__main__': unittest.main(verbosity=2)
