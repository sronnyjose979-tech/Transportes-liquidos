"""Functional audit: business writes use visible forms, never fixture/API shortcuts.
Office A/B create master data by UI after the synthetic accounts/roles bootstrap.
C/D declares its synthetic initial vehicle, mechanic and supplier below.
The CLI inspector uses a read-only PostgreSQL transaction for persistence evidence.
"""
import asyncio
import json
import subprocess
import unittest
from decimal import Decimal
from playwright.async_api import expect
import test_administration as administration
import test_document_module as support
import test_billing_operations as billing
import test_workshop_module as workshop


class OfficeProcessesE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls): support.DocumentModuleE2E.setUpClass.__func__(cls)
    @classmethod
    def tearDownClass(cls): support.DocumentModuleE2E.tearDownClass.__func__(cls)
    asyncSetUp = administration.AdministrationE2E.asyncSetUp
    asyncTearDown = administration.AdministrationE2E.asyncTearDown
    save = administration.AdministrationE2E.save
    account = administration.AdministrationE2E.account
    employee = administration.AdministrationE2E.employee

    def inspect(self, records=None, inventory=False, part=None):
        result=subprocess.run(['php', str(support.ROOT/'tests/E2E/functional-inspect.php')],
            input=json.dumps({'records':records or {},'inventory':inventory,'spare_part_id':part}),text=True,capture_output=True,check=True,cwd=support.ROOT)
        data=json.loads(result.stdout)
        (self.artifacts/(self._testMethodName+'-database.json')).write_text(json.dumps(data,indent=2))
        return data

    async def sign_in(self, role, page=None, login=None):
        page=page or self.page
        await page.goto(self.base+'/login')
        await page.get_by_label('Usuario',exact=True).fill(login or 'e2e-'+role)
        await page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await page.wait_for_url('**'+{'administrator':'/dashboard','secretary':'/transporte','driver':'/mis-viajes'}[role])
        await expect(page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_visible()

    async def sign_out(self):
        async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')):
            await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.page.goto(self.base+'/login')
        await expect(self.page.get_by_label('Usuario',exact=True)).to_be_visible()

    async def submit(self, button, suffix, status=201, method='POST', page=None):
        page=page or self.page
        async with page.expect_response(lambda r:r.request.method==method and r.url.endswith(suffix)) as event:
            await button.click()
        response=await event.value
        self.assertEqual(status,response.status,await response.text())
        self.assertTrue(response.request.headers.get('x-xsrf-token'))
        await page.wait_for_load_state('networkidle')
        return (await response.json())['data']

    async def catalog(self, kind, name, address=None):
        title={'customers':'cliente','liquid-products':'producto','operation-sites':'sitio'}[kind]
        await self.page.get_by_label('Catálogo',exact=True).select_option(kind)
        await self.page.get_by_role('button',name='Registrar '+title,exact=True).click()
        form=self.page.get_by_role('form',name='Registro de catálogo')
        await form.get_by_label('Nombre',exact=True).fill(name)
        if address is not None: await form.get_by_label('Dirección').fill(address)
        return await self.save(form.get_by_role('button',name='Guardar catálogo'),'/catalogs/'+kind)

    async def test_a_transport_from_visible_catalogs_to_driver_offline_invoice_and_payment(self):
        await self.sign_in('administrator')
        await self.page.get_by_role('link',name='Catálogos',exact=True).click()
        customer=await self.catalog('customers',self.tag+' cliente')
        product=await self.catalog('liquid-products',self.tag+' agua')
        await self.page.get_by_role('button',name='Rutas de transporte',exact=True).click()
        # Truly empty prerequisites: no catalog fixture disguises the missing entry point.
        await expect(self.page.get_by_role('button',name='Registrar ruta',exact=True)).to_be_disabled()
        await expect(self.page.get_by_role('status').filter(has_text='Todavía no hay sitios')).to_be_visible()
        await self.page.get_by_role('button',name='Registrar sitios de origen y destino',exact=True).click()
        origin=await self.catalog('operation-sites',self.tag+' origen','Dirección sintética origen')
        destination=await self.catalog('operation-sites',self.tag+' destino','Dirección sintética destino')
        await self.page.get_by_role('button',name='Rutas de transporte',exact=True).click()
        await self.page.reload()
        await expect(self.page.get_by_role('heading',name='Rutas de transporte',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Registrar ruta',exact=True).click()
        form=self.page.get_by_role('form',name='Registro de ruta')
        await form.get_by_label('Nombre de ruta',exact=True).fill(self.tag+' ruta')
        await form.get_by_label('Origen',exact=True).select_option(origin['id'])
        await form.get_by_label('Destino',exact=True).select_option(destination['id'])
        route=await self.save(form.get_by_role('button',name='Guardar ruta'),'/catalogs/routes')
        await self.page.get_by_role('link',name='Flota',exact=True).click()
        await self.page.get_by_role('button',name='Registrar cabezal',exact=True).click()
        await self.page.get_by_label('Código del activo',exact=True).fill(self.tag+'-CAB')
        head=await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tractor-units')
        await self.page.get_by_role('button',name='Volver al listado',exact=True).click()
        await self.page.get_by_label('Tipo de activo',exact=True).select_option('tanker')
        await self.page.get_by_role('button',name='Registrar cisterna',exact=True).click()
        await self.page.get_by_label('Código del activo',exact=True).fill(self.tag+'-CIS')
        await self.page.get_by_label('Código de compartimento',exact=True).fill('A')
        await self.page.get_by_label('Capacidad en litros',exact=True).fill('1000')
        tank=await self.save(self.page.get_by_role('button',name='Guardar activo'),'/tankers')
        await self.page.get_by_role('link',name='Personal',exact=True).click()
        driver=await self.account('Chofer',self.tag+'-driver')
        employee=await self.employee(driver,self.tag+' chofer')
        records={'customers':[customer['id']],'liquid_products':[product['id']],'operation_sites':[origin['id'],destination['id']],
            'routes':[route['id']],'users':[driver['id']],'employees':[employee['id']]}
        saved=self.inspect(records,inventory=True)
        for table,ids in records.items(): self.assertEqual(len(ids),len(saved[table]))
        self.assertEqual(driver['id'],str(saved['employees'][0]['user_id']))
        (self.artifacts/'endpoints.json').write_text(json.dumps(saved['endpoints'],indent=2))
        await self.sign_out();await self.sign_in('secretary')
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        await expect(self.page.get_by_role('link',name='Registrar rutas de transporte',exact=True)).to_have_count(0)
        await expect(self.page.get_by_text('solicita su registro al administrador',exact=False)).to_be_visible()
        for label,record in [('Cliente',customer),('Producto líquido',product),('Ruta',route)]:
            await form.get_by_label(label,exact=True).select_option(record['id'])
        await form.get_by_label('Litros solicitados').fill('100')
        request=await self.submit(form.get_by_role('button',name='Guardar',exact=True),'/api/transport/requests')
        await self.page.get_by_role('row').filter(has_text=request['id']).get_by_role('button',name='Crear viaje',exact=True).click()
        trip=await self.submit(self.page.get_by_role('form',name='Crear viaje asociado').get_by_role('button',name='Guardar',exact=True),'/api/transport/trips')
        trip_id=trip['id']
        await self.page.get_by_role('button',name='Programar viaje',exact=True).click()
        form=self.page.get_by_role('form',name='Programar viaje',exact=True)
        await form.get_by_label('Motivo / confirmación').fill('Programa sintético de servicio')
        await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/schedule',200)
        for kind,record in [('driver',employee),('tractor_unit',head),('tanker',tank)]:
            await self.page.get_by_role('button',name='Recursos',exact=True).click()
            await self.page.get_by_label('Recurso',exact=True).select_option(kind)
            await self.page.get_by_label('Seleccionar recurso',exact=True).select_option(record['id'])
            await self.page.get_by_role('button',name='Consultar disponibilidad',exact=True).click()
            await expect(self.page.get_by_text('Recurso disponible al consultar',exact=True)).to_be_visible()
            form=self.page.get_by_role('form',name='Asignar recurso')
            await form.get_by_label('Motivo de la operación').fill('Asignación comprobada desde pantalla')
            await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/assignments/assign')
        await self.page.get_by_role('button',name='Resumen y estados',exact=True).click()
        await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
        form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
        await form.get_by_label('Motivo / confirmación').fill('Iniciar con recursos completos')
        await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/start',200)
        driver_context=await self.browser.new_context(viewport={'width':390,'height':844})
        try:
            dp=await driver_context.new_page();dp.on('dialog',lambda d:asyncio.create_task(d.accept()))
            await self.sign_in('driver',dp,driver['login'])
            await dp.get_by_role('button',name='Consultar viaje y registrar información',exact=True).click()
            await dp.get_by_label('Kilometraje',exact=True).fill('1000')
            await dp.get_by_label('Motivo de lectura',exact=True).fill('Lectura inicial del conductor')
            await self.submit(dp.get_by_role('button',name='Registrar kilometraje',exact=True),f'/my-trips/{trip_id}/odometer',page=dp)
            await driver_context.set_offline(True)
            await dp.get_by_label('Observación',exact=True).fill(self.tag+' incidencia offline')
            await dp.get_by_role('button',name='Registrar reporte',exact=True).click()
            await dp.get_by_label('Concepto',exact=True).fill(self.tag+' gasto')
            await dp.get_by_label('Categoría',exact=True).fill('Operación')
            await dp.get_by_label('Monto',exact=True).fill('1.02')
            await dp.get_by_role('button',name='Registrar gasto pendiente',exact=True).click()
            await expect(dp.get_by_role('status').filter(has_text='2 capturas sin confirmar')).to_be_visible()
            await driver_context.set_offline(False)
            await expect(dp.get_by_role('status').filter(has_text='0 capturas sin confirmar')).to_be_visible(timeout=30000)
            self.assertTrue(await dp.evaluate('document.documentElement.scrollWidth<=innerWidth'))
            await dp.screenshot(path=str(self.artifacts/'process-a-driver-mobile.png'),full_page=True)
        finally: await driver_context.close()
        await self.page.get_by_role('button',name='Carga y descarga',exact=True).click()
        await self.page.get_by_label('A · capacidad',exact=False).check()
        for kind,site in [('carga',origin),('descarga',destination)]:
            form=self.page.get_by_role('form',name='Registrar '+kind,exact=True)
            await form.get_by_label('Sitio real de '+kind).select_option(site['id'])
            await form.get_by_label('Litros reales',exact=False).fill('100')
            await form.get_by_label('Motivo / información recibida').fill(kind+' comprobada')
            await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/'+('load' if kind=='carga' else 'unload'))
        await self.page.get_by_role('button',name='Kilometraje',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar kilometraje')
        await form.get_by_label('Asignación de cabezal').select_option(index=1)
        await form.get_by_label('Tipo de lectura',exact=True).select_option('final')
        await form.get_by_label('Lectura de kilometraje').fill('1050')
        await form.get_by_label('Motivo / información recibida').fill('Lectura final verificada')
        await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/odometer')
        await self.page.get_by_role('button',name='Resumen y estados',exact=True).click()
        for button,title,action in [('Finalizar oficialmente','Finalizar viaje oficialmente','finish'),('Cerrar administrativamente','Cerrar administrativamente','close')]:
            await self.page.get_by_role('button',name=button,exact=True).click()
            form=self.page.get_by_role('form',name=title,exact=True)
            await form.get_by_label('Motivo / confirmación').fill('Verificación funcional '+action)
            await self.submit(form.get_by_role('button',name='Guardar',exact=True),f'/trips/{trip_id}/{action}',200)
        self.fixture={'trip_id':trip_id}
        await self.page.get_by_role('link',name='Facturación',exact=True).click()
        await billing.BillingOperationsE2E.price(self,'123.45')
        await expect(self.page.get_by_role('button',name='Aprobar importe',exact=True)).to_have_count(0)
        await self.sign_out();await self.sign_in('administrator')
        await self.page.get_by_role('link',name='Facturación',exact=True).click()
        await billing.BillingOperationsE2E.approve(self)
        invoice=await billing.BillingOperationsE2E.invoice(self)
        await billing.BillingOperationsE2E.detail(self,invoice)
        await billing.BillingOperationsE2E.fill_payment(self,'123.45')
        await self.submit(self.page.get_by_role('button',name='Registrar pago',exact=True),f'/invoices/{invoice}/payments')
        saved=self.inspect({**records,'transport_requests':[request['id']],'trips':[trip_id],'billing_invoices':[invoice]})
        self.assertEqual(('completed','closed'),(saved['trips'][0]['execution_status'],saved['trips'][0]['administrative_status']))
        for table in ['transport_requests','trips','billing_invoices']: self.assertEqual(1,len(saved[table]))
        self.assertEqual(1,len(saved['trip_expenses']))
        self.assertEqual(2,saved['trip_readings'])
        self.assertEqual(1,len(saved['payments']))
        self.assertEqual(1,len(saved['invoice_items']))
        self.assertEqual(Decimal('123.45'),Decimal(saved['payments'][0]['amount']))
        self.assertEqual(Decimal('123.45'),Decimal(saved['invoice_items'][0]['amount']))
        await self.page.get_by_role('link',name='Gastos',exact=True).click()
        await expect(self.page.get_by_text(self.tag+' gasto',exact=False)).to_be_visible()
        await self.page.get_by_role('link',name='Reportes',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Reportes administrativos',exact=True)).to_be_visible()
        await self.page.get_by_label('Reporte',exact=True).select_option('transport')
        await self.page.get_by_label('Cliente',exact=True).select_option(customer['id'])
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('table',name='Transporte · registros',exact=False)).to_contain_text(trip_id)
        print('PROCESO A: catálogos/flota/persona/solicitud/viaje/offline/factura/pago; todos los cambios por UI.')

    async def test_b_separate_account_employee_link_login_permissions_and_deactivation(self):
        await self.sign_in('administrator')
        await self.page.get_by_role('link',name='Personal',exact=True).click()
        user=await self.account('Chofer',self.tag+'-driver')
        employee=await self.employee(user,self.tag+' empleado')
        data=self.inspect({'users':[user['id']],'employees':[employee['id']]})
        self.assertEqual(str(data['users'][0]['id']),str(data['employees'][0]['user_id']))
        context=await self.browser.new_context()
        try:
            page=await context.new_page();await self.sign_in('driver',page,user['login'])
            await expect(page.get_by_role('heading',name='Mis viajes',exact=True)).to_be_visible()
            await expect(page.get_by_role('link',name='Personal',exact=True)).to_have_count(0)
            await self.page.get_by_role('button',name='Cuentas y roles',exact=True).click()
            row=self.page.get_by_role('row').filter(has_text=user['login'])
            await self.submit(row.get_by_role('button',name='Desactivar',exact=True),f"/users/{user['id']}/activation",200,'PATCH')
            await page.reload();await expect(page).to_have_url(self.base+'/login')
            await page.get_by_label('Usuario',exact=True).fill(user['login'])
            await page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
            await page.get_by_role('button',name='Iniciar sesión',exact=True).click()
            await expect(page.get_by_role('alert')).to_be_visible()
            self.assertFalse(self.inspect({'users':[user['id']]})['users'][0]['is_active'])
        finally: await context.close()
        print('PROCESO B: cuenta/empleado/vínculo/login/restricción/desactivación por UI.')


class WorkshopPurchaseProcessesE2E(unittest.IsolatedAsyncioTestCase):
    """Initial vehicle/mechanic/supplier are explicit synthetic master-data fixtures.
    All reports, repair, procurement and inventory operations below use forms.
    """
    submit = OfficeProcessesE2E.submit
    inspect = OfficeProcessesE2E.inspect

    async def asyncSetUp(self):
        await workshop.WorkshopModuleE2E.asyncSetUp(self)
        self.page.on('dialog',lambda dialog:asyncio.create_task(dialog.accept()))

    async def test_c_d_workshop_purchase_receipt_inventory_usage_and_close_by_forms(self):
        await self.login('administrator')
        await self.page.get_by_role('link',name='Catálogos',exact=True).click()
        await self.page.get_by_label('Catálogo',exact=True).select_option('spare-parts')
        await self.page.get_by_role('button',name='Registrar repuesto',exact=True).click()
        form=self.page.get_by_role('form',name='Registro de catálogo')
        code=self.fixture['tag']+'-NUEVO'
        for label,value in [('Código',code),('Descripción','Sello sintético para proceso integral'),('Unidad','unidad')]:
            await form.get_by_label(label,exact=True).fill(value)
        part=await self.submit(form.get_by_role('button',name='Guardar catálogo'),'/api/catalogs/spare-parts')
        self.fixture['part']=part
        await self.page.get_by_role('link',name='Taller',exact=True).click();await self.ready()
        order,_=await self.report_and_order()
        assignment=await self.assign(order)
        diagnosis=await self.diagnosis(order,assignment)
        await self.page.get_by_role('button',name='Solicitar autorización de alcance',exact=True).click()
        form=self.page.get_by_role('form',name='Solicitar autorización de alcance')
        await self.choose(form,'Diagnóstico',diagnosis['diagnosis_id'],'Fuga diagnosticada')
        await form.get_by_label('Descripción del alcance',exact=True).fill('Reparación con dos sellos')
        await form.get_by_label('Trabajo 1',exact=True).fill('Cambiar sellos y verificar')
        await form.get_by_role('button',name='Añadir repuesto',exact=True).click()
        await self.choose(form,'Repuesto 1',part['id'],code)
        await form.get_by_label('Cantidad 1',exact=True).fill('2')
        await form.get_by_label('Trabajo asociado 1',exact=True).select_option('1')
        await form.get_by_label('Motivo / información recibida',exact=True).fill('Alcance autorizado de la simulación')
        scope=await self.save(form,'orders/'+order+'/scopes',status=200)
        await self.page.get_by_role('button',name='Revisar autorización',exact=True).click();await self.ready()
        await self.page.get_by_role('button',name='Registrar decisión',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar decisión')
        await form.get_by_label('Decisión',exact=True).select_option('approved')
        await form.get_by_label('Motivo / información recibida',exact=True).fill('Revisión administrativa sintética')
        decision=await self.save(form,'authorization-requests/'+scope['request_id']+'/decisions',status=200)
        await self.page.get_by_role('button',name='Aplicar autorización',exact=True).click()
        await self.save(self.page.get_by_role('form',name='Aplicar autorización'),'decisions/'+decision['decision_id']+'/apply-scope',status=200)
        await self.open_order(order)
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await self.page.get_by_role('button',name='Solicitar repuesto',exact=True).click()
        form=self.page.get_by_role('form',name='Solicitar repuesto')
        await self.choose(form,'Línea de alcance (opcional)',self.snapshot()['parts'][0]['id'],code)
        await self.choose(form,'Repuesto solicitado',part['id'],code)
        await form.get_by_label('Cantidad decimal',exact=True).fill('2')
        await form.get_by_label('Necesidad de material',exact=True).fill('Comprar dos sellos para '+self.fixture['tag'])
        await form.get_by_label('Motivo del registro',exact=True).fill('Repuesto necesario')
        need=await self.save(form,'orders/'+order+'/material-needs',status=200)
        await self.page.get_by_role('link',name='Inventario',exact=True).click()
        await self.page.get_by_role('button',name='Solicitar conciliación o ajuste',exact=True).click()
        form=self.page.get_by_role('form',name='Corrección de inventario')
        await self.choose(form,'Repuesto',part['id'],code)
        await form.get_by_label('Cantidad',exact=True).fill('0')
        reason='Conteo inicial verificado '+self.fixture['tag']
        await form.get_by_label('Motivo',exact=True).fill(reason)
        await self.submit(form.get_by_role('button',name='Guardar operación',exact=True),'/api/inventory/corrections')
        article=self.page.locator('article').filter(has_text=reason)
        await article.get_by_role('button',name='Decidir corrección',exact=True).click()
        form=self.page.get_by_role('form',name='Corrección de inventario')
        await form.get_by_label('Motivo',exact=True).fill('Se constató ausencia física')
        await self.submit(form.get_by_role('button',name='Guardar operación',exact=True),'/decisions')
        await self.submit(article.get_by_role('button',name='Aplicar corrección autorizada',exact=True),'/apply')
        await self.page.get_by_role('link',name='Compras',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud de compra',exact=True).click()
        form=self.page.get_by_role('form',name='Operación de compra')
        await self.choose(form,'Proveedor',self.fixture['supplier']['id'],self.fixture['supplier']['name'])
        await self.choose(form,'Repuesto',part['id'],code)
        await form.get_by_label('Cantidad solicitada',exact=True).fill('2')
        await self.choose(form,'Necesidad de Taller (opcional)',need['material_need_id'],'Comprar dos sellos')
        purchase_reason='Reposición sintética '+self.fixture['tag']
        await form.get_by_label('Motivo',exact=True).fill(purchase_reason)
        purchase=await self.submit(form.get_by_role('button',name='Guardar operación',exact=True),'/api/purchasing/requests')
        row=self.page.get_by_role('row').filter(has_text=purchase_reason)
        await row.get_by_role('button',name='Decidir solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Operación de compra')
        await form.get_by_label('Motivo',exact=True).fill('Autorizar dos unidades')
        await self.submit(form.get_by_role('button',name='Guardar operación',exact=True),'/decisions',200)
        await row.get_by_role('button',name='Generar orden de compra',exact=True).click()
        form=self.page.get_by_role('form',name='Operación de compra')
        await form.get_by_label('Cantidad ordenada '+code,exact=True).fill('2')
        await form.get_by_label('Motivo',exact=True).fill('Orden completa de dos unidades')
        po=await self.submit(form.get_by_role('button',name='Guardar operación',exact=True),'/api/purchasing/orders')
        await self.page.get_by_role('button',name='Registrar recepción física',exact=True).click()
        form=self.page.get_by_role('form',name='Recepción física')
        await form.get_by_label('Cantidad recibida '+code,exact=True).fill('2')
        await form.get_by_label('Motivo',exact=True).fill('Se recibieron físicamente dos unidades')
        await self.submit(form.get_by_role('button',name='Confirmar recepción física',exact=True),'/receipts',200)
        await self.page.get_by_role('link',name='Inventario',exact=True).click()
        await expect(self.page.get_by_role('row').filter(has_text=code)).to_contain_text('2')
        await self.page.get_by_role('link',name='Taller',exact=True).click();await self.ready();await self.open_order(order)
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await self.page.get_by_role('button',name='Registrar entrega',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar entrega física')
        await self.choose(form,'Repuesto autorizado',self.snapshot()['parts'][0]['id'],code)
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal',exact=True).fill('2')
        await form.get_by_label('Motivo del registro',exact=True).fill('Entrega física al mecánico')
        delivery=await self.save(form,'orders/'+order+'/deliveries',status=200)
        await self.switch('mechanic');await self.open_order(order)
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await self.page.get_by_role('button',name='Registrar utilización',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar utilización')
        # Mechanic sees authorized custody, not the administrator's full parts catalog.
        await self.choose(form,'Entrega recibida',delivery['delivery_id'],'')
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal',exact=True).fill('2')
        await form.get_by_label('Motivo del registro',exact=True).fill('Dos unidades utilizadas en reparación')
        await self.save(form,'orders/'+order+'/usages',status=200)
        await self.page.get_by_role('button',name='Ejecución',exact=True).click()
        await self.page.get_by_role('button',name='Registrar actividad',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar actividad')
        await self.choose(form,'Trabajo autorizado',self.snapshot()['tasks'][0]['id'],'Cambiar sellos')
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Actividad realizada',exact=True).fill('Reparación realizada y comprobada')
        await form.get_by_label('Motivo del registro',exact=True).fill('Actividad terminada')
        await self.save(form,'orders/'+order+'/activities',status=200)
        await self.page.get_by_role('button',name='Finalizar reparación',exact=True).click()
        form=self.page.get_by_role('form',name='Finalizar reparación de la orden')
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Motivo del registro',exact=True).fill('Reparación sin pendientes físicos')
        await self.save(form,'orders/'+order+'/finish',status=200)
        await self.switch('administrator');await self.open_order(order)
        await self.page.get_by_role('button',name='Ejecución',exact=True).click()
        await self.page.get_by_role('button',name='Revisar y cerrar orden',exact=True).click()
        form=self.page.get_by_role('form',name='Cerrar orden con revisión administrativa')
        await form.get_by_label('Revisión:',exact=False).fill('Se revisaron tareas y dos repuestos utilizados')
        await form.get_by_label('Motivo del registro',exact=True).fill('Cierre administrativo comprobado')
        await self.save(form,'orders/'+order+'/close',status=200)
        self.assertEqual('closed',self.snapshot()['orders'][0]['status'])
        durable=self.inspect({'purchase_requests':[purchase['id']],'purchase_orders':[po['id']]},part=part['id'])
        self.assertEqual(1,len(durable['purchase_requests']))
        self.assertEqual(1,len(durable['purchase_orders']))
        for key,value in {'warehouse':'0','custody':'0','consumed':'2'}.items():
            self.assertEqual(Decimal(value),Decimal(durable['stock'][key]))
        self.assertEqual(4,len(durable['movements']))
        (self.artifacts/'process-cd-database.json').write_text(json.dumps(self.snapshot(),indent=2))
        print('PROCESO C/D: problema, diagnóstico, alcance, compra, recepción, entrega, uso, actividad, fin y cierre por UI.')


for _name,_value in workshop.WorkshopModuleE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in WorkshopPurchaseProcessesE2E.__dict__:
        setattr(WorkshopPurchaseProcessesE2E,_name,_value)

if __name__=='__main__': unittest.main(verbosity=2)
