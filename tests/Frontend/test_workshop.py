"""Real Chromium/Blade/Vite integration with synthetic intercepted API; no DB."""
import asyncio
import copy
import re
import unittest
from urllib.parse import parse_qs, urlparse
from playwright.async_api import expect
import test_fleet_documents as support

ORDER='60000000-0000-4000-8000-000000000001'
REPORT='60000000-0000-4000-8000-000000000002'
DIAG='60000000-0000-4000-8000-000000000003'
SCOPE='60000000-0000-4000-8000-000000000004'
AUTH='60000000-0000-4000-8000-000000000005'
DECISION='60000000-0000-4000-8000-000000000006'

class WorkshopBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls): support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)
    @classmethod
    def tearDownClass(cls): support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)
    pagination=support.FleetDocumentsBrowserTest.pagination
    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.sent=[]
        self.page.set_default_timeout(10000)
        self.delay_order=False
        self.catalog_parts=[dict(id=REPORT,code='SELLO-01',description='Sello de bomba',unit='unidad'),dict(id=DECISION,code='FILTRO-02',description='Filtro',unit='unidad')]
        self.data={key:[] for key in ['reports','diagnoses','mechanic-assignments','scopes','report-links','authorization-requests','scope-tasks','scope-parts','authorization-decisions','authorization-applications','impediments']}
        self.data['orders']=[dict(id=ORDER,fleet_asset_id=support.ASSET,description='Reparar bomba',status='pending_diagnosis',priority='normal',kind='corrective',version=1,created_at='2026-10-13T12:00:00Z')]
    def raw_pagination(self,records,query):
        payload=self.pagination(records,query)
        return {'data':payload['data'],**payload['meta']}
    async def asyncTearDown(self): await support.FleetDocumentsBrowserTest.asyncTearDown(self)
    async def api(self,route):
        parsed=urlparse(route.request.url);path=parsed.path;method=route.request.method;query=parse_qs(parsed.query)
        if not path.startswith('/api/workshop/') and (method,path) in self.forced:
            status,body=self.forced[(method,path)];return await route.fulfill(status=status,json=body)
        if path=='/api/employees': return await route.fulfill(json=self.raw_pagination([dict(id=DIAG,name='Mecánico prueba',user_id=8,is_active=True)],query))
        if path=='/api/users': return await route.fulfill(json=self.pagination([dict(id='8',employee_id=DIAG,is_active=True,roles=[{'name':'mechanic'}])],query))
        if path=='/api/catalogs/spare-parts': return await route.fulfill(json=self.raw_pagination(self.catalog_parts,query))
        if path=='/api/catalogs/suppliers': return await route.fulfill(json=self.raw_pagination([dict(id=AUTH,description='Proveedor prueba')],query))
        if path=='/api/transport/lookups/assets': return await route.fulfill(json=self.pagination(self.assets,query))
        if not path.startswith('/api/workshop/'):
            return await support.FleetDocumentsBrowserTest.api(self,route)
        if method!='GET': self.sent.append((path,copy.deepcopy(route.request.post_data_json)))
        if (method,path) in self.forced:
            status,body=self.forced[(method,path)];return await route.fulfill(status=status,json=body)
        if method=='GET' and path==f'/api/workshop/orders/{ORDER}' and self.delay_order: await asyncio.sleep(.5)
        segments=path.removeprefix('/api/workshop/').split('/');kind=segments[0]
        if method=='GET':
            if len(segments)>2 and segments[-1]=='times': return await route.fulfill(json={'data':{'participants':{},'person_seconds':0}})
            if len(segments)>2 and segments[-1]=='cost-totals': return await route.fulfill(json={'data':[]})
            if len(segments)>2 and segments[-1]=='attachments': return await route.fulfill(json=self.pagination([],query))
            rows=self.data.get(kind,[])
            if len(segments)>1:
                row=next((r for r in rows if r['id']==segments[1]),None)
                return await route.fulfill(status=200 if row else 404,json={'data':row})
            for key in ['status','fleet_asset_id','work_order_id']:
                if key in query: rows=[r for r in rows if str(r.get(key,''))==query[key][0]]
            if query.get('pending')==['1']:
                rows=[r for r in rows if not any(d['request_id']==r['id'] for d in self.data['authorization-decisions'])]
            return await route.fulfill(json=self.pagination(rows,query))
        payload=route.request.post_data_json
        if kind=='reports':
            self.data['reports']=[dict(id=REPORT,revision=1,is_effective=True,**payload)]
            return await route.fulfill(json={'data':{'report_id':REPORT}})
        if kind=='orders' and len(segments)==1:
            self.data['orders'][0].update(payload)
            return await route.fulfill(json={'data':{'order_id':ORDER}})
        action=segments[-1]
        if action=='diagnoses':
            self.data['diagnoses'].append(dict(id=DIAG,work_order_id=ORDER,revision=1,is_effective=True,**payload))
        elif action=='scopes':
            self.data['scopes'].append(dict(id=SCOPE,work_order_id=ORDER,is_applied=False,is_effective=False,**payload))
            self.data['authorization-requests'].append(dict(id=AUTH,work_order_id=ORDER,scope_id=SCOPE,type='repair',content=payload,registered_at='2026-10-13T12:00:00Z',requested_by_id=7,reason=payload['reason']))
            self.data['orders'][0]['status']='pending_authorization'
        elif action=='decisions':
            self.data['authorization-decisions'].append(dict(id=DECISION,request_id=AUTH,work_order_id=ORDER,registered_at='2026-10-13T12:30:00Z',**payload))
        elif action=='apply-scope':
            self.data['authorization-applications'].append(dict(id=REPORT,decision_id=DECISION,work_order_id=ORDER))
            self.data['orders'][0]['status']='authorized'
            self.data['orders'][0]['version']=2
            self.data['scopes'][0].update(is_applied=True,is_effective=True)
        return await route.fulfill(json={'data':{'id':DIAG}})
    async def enter(self):
        await self.page.goto(self.base+'/taller')
        await expect(self.page.get_by_role('heading',name='Inicio de Taller')).to_be_visible()
        await expect(self.page.get_by_text('Cargando información…')).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Inicio',exact=True)).to_have_attribute('aria-current','page')
    async def open_order(self):
        await self.enter()
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await self.page.get_by_role('button',name='Ver detalle',exact=False).click()
        await expect(self.page.get_by_role('heading',name='Detalle de orden')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Órdenes',exact=True)).to_have_attribute('aria-current','page')
        await expect(self.page.get_by_role('button',name='Inicio',exact=True)).not_to_have_attribute('aria-current','page')
    async def report_form(self):
        await self.page.get_by_role('button',name='Registrar avería',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar avería')
        await form.get_by_label('Vehículo',exact=True).select_option(support.ASSET)
        await form.get_by_label('Descripción de la avería').fill('Fuga en bomba')
        await form.get_by_label('Motivo / información recibida').fill('Aviso conductor')
        return form
    async def test_login_dashboard_real_counts_and_mobile_layout(self):
        self.role=None
        await self.page.goto(self.base+'/taller')
        await self.page.get_by_label('Usuario',exact=True).fill('administrator')
        await self.page.get_by_label('Contraseña',exact=True).fill('synthetic-only')
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Inicio de Taller')).to_be_visible()
        metric=self.page.locator('article').filter(has=self.page.get_by_text('Pendiente de diagnóstico',exact=True))
        await expect(metric.locator('strong')).to_have_text('1')
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':844})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
        await self.page.get_by_role('button',name='Cerrar sesión').click()
        await expect(self.page.get_by_role('heading',name='Acceso a Taller Mecánico')).to_be_visible()
    async def test_report_create_then_order_payload_and_detail(self):
        await self.enter();form=await self.report_form()
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Detalle de avería')).to_be_visible()
        await self.page.get_by_role('button',name='Crear orden para esta avería').click()
        form=self.page.get_by_role('form',name='Crear orden',exact=True)
        await expect(form.get_by_label('Descripción de la reparación')).to_have_value('Fuga en bomba')
        await form.get_by_label('Motivo / información recibida').fill('Reparación autorizable')
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Detalle de orden')).to_be_visible()
        self.assertEqual('corrective',self.sent[-1][1]['kind'])
        self.assertTrue(self.sent[0][1]['occurred_at'].endswith('-06:00'))
        self.assertNotEqual(self.sent[0][1]['request_key'],self.sent[-1][1]['request_key'])
    async def test_diagnosis_scope_decision_application_updates_without_reload(self):
        await self.open_order();await self.page.get_by_role('button',name='Registrar diagnóstico',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar diagnóstico')
        for label,value in [('Problema encontrado','Sello deteriorado'),('Causa probable','Desgaste'),('Reparación propuesta','Cambiar sello'),('Motivo / información recibida','Inspección')]: await form.get_by_label(label).fill(value)
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Sello deteriorado')).to_be_visible()
        await self.page.get_by_role('button',name='Solicitar autorización de alcance',exact=True).click()
        form=self.page.get_by_role('form',name='Solicitar autorización de alcance')
        await form.get_by_label('Diagnóstico',exact=True).select_option(DIAG)
        await form.get_by_label('Descripción del alcance').fill('Cambio de sello')
        await form.get_by_label('Trabajo 1',exact=True).fill('Reemplazar sello')
        await form.get_by_role('button',name='Añadir repuesto').click()
        await form.get_by_label('Repuesto 1',exact=True).select_option(REPORT)
        await form.get_by_label('Cantidad 1',exact=True).fill('1.000001')
        await form.get_by_label('Motivo / información recibida').fill('Propuesta técnica')
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await self.page.get_by_role('button',name='Revisar autorización').click()
        await self.page.get_by_role('button',name='Registrar decisión',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar decisión')
        await form.get_by_label('Decisión',exact=True).select_option('approved')
        await form.get_by_label('Motivo / información recibida').fill('Aprobado por administración')
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await self.page.get_by_role('button',name='Aplicar autorización',exact=True).click()
        await self.page.get_by_role('form',name='Aplicar autorización').get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Autorización aplicada.',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Consultar orden',exact=True).click()
        await expect(self.page.get_by_text('Autorizada',exact=True)).to_be_visible()
        scope_payload=next(p for path,p in self.sent if path.endswith('/scopes'))
        self.assertEqual([{'line_number':1,'description':'Reemplazar sello'}],scope_payload['tasks'])
        self.assertEqual([{'line_number':1,'spare_part_id':REPORT,'quantity':'1.000001'}],scope_payload['parts'])
        self.assertEqual(len(self.sent),len({p['request_key'] for _,p in self.sent}))
    async def test_secretary_cannot_decide(self):
        self.role='secretary'
        self.data['authorization-requests']=[dict(id=AUTH,type='cancellation',reason='Revisar',work_order_id=ORDER)]
        await self.enter();await self.page.get_by_role('button',name='Autorizaciones',exact=True).click()
        await self.page.get_by_role('button',name='Ver detalle',exact=False).click()
        await expect(self.page.get_by_text('Pendiente de decisión exclusiva del administrador.')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Registrar decisión')).to_have_count(0)
    async def test_uncertain_retry_preserves_exact_body_and_key(self):
        await self.enter();form=await self.report_form()
        endpoint=('POST','/api/workshop/reports');self.forced[endpoint]=(500,{'message':'private database details'})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_be_visible()
        await expect(form.get_by_label('Descripción de la avería')).to_have_value('Fuga en bomba')
        await expect(self.page.get_by_text('private database details')).to_have_count(0)
        self.forced.pop(endpoint)
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('heading',name='Detalle de avería')).to_be_visible()
        self.assertEqual(self.sent[0],self.sent[1])
    async def test_validation_csrf_conflict_and_access_errors(self):
        await self.enter();form=await self.report_form();endpoint=('POST','/api/workshop/reports')
        self.forced[endpoint]=(422,{'errors':{'description':['Descripción inválida.']}})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(form.get_by_text('Descripción inválida.',exact=True)).to_be_visible()
        await expect(form.get_by_label('Descripción de la avería')).to_have_value('Fuga en bomba')
        self.forced[endpoint]=(419,{})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await self.page.get_by_role('dialog').get_by_role('button',name='Actualizar sesión de seguridad').click()
        await expect(self.page.get_by_text('Sesión de seguridad actualizada. Revisa y envía el formulario.',exact=True)).to_be_visible()
        self.assertEqual(2,len(self.sent))
        self.forced[endpoint]=(409,{})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('El estado cambió.',exact=False)).to_be_visible()
        await self.page.get_by_role('button',name='Cerrar y revisar estado').click()
        self.forced.pop(endpoint)
        self.role='driver'
        self.forced[('GET','/api/workshop/orders')]=(403,{})
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Acceso no autorizado')).to_be_visible()
        self.forced.pop(('GET','/api/workshop/orders'));self.role='administrator'
        await self.page.reload()
        await expect(self.page.get_by_role('heading',name='Inicio de Taller')).to_be_visible()
        self.forced[('GET','/api/workshop/orders')]=(401,{})
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Acceso a Taller Mecánico')).to_be_visible()
        await expect(self.page.get_by_role('heading',name='Detalle de orden')).to_have_count(0)

    async def test_filters_search_missing_detail_and_empty_state(self):
        await self.enter()
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await self.page.get_by_label('Buscar en esta página').fill('Sin coincidencias')
        await expect(self.page.get_by_text('No hay registros para los criterios seleccionados.')).to_be_visible()
        await self.page.get_by_label('Buscar en esta página').fill('')
        self.forced[('GET',f'/api/workshop/orders/{ORDER}')]=(404,{})
        await self.page.get_by_role('button',name='Ver detalle',exact=False).click()
        await expect(self.page.get_by_text('El registro solicitado no está disponible.',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('heading',name='Reparar bomba')).to_have_count(0)
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await self.page.get_by_label(re.compile(r'^Estado')).select_option('finished')
        await self.page.get_by_role('button',name='Aplicar filtros').click()
        await expect(self.page.get_by_text('0 registros accesibles',exact=False)).to_be_visible()


    async def test_searchable_catalog_preserves_selection_and_empty_catalog(self):
        self.data['diagnoses']=[dict(id=DIAG,work_order_id=ORDER,is_effective=True,problem='Sello deteriorado')]
        await self.open_order()
        await self.page.get_by_role('button',name='Solicitar autorización de alcance',exact=True).click()
        form=self.page.get_by_role('form',name='Solicitar autorización de alcance')
        await form.get_by_role('button',name='Añadir repuesto').click()
        select=form.get_by_label('Repuesto 1',exact=True)
        await select.select_option(REPORT)
        await form.get_by_label('Buscar Repuesto 1',exact=True).fill('Filtro')
        await expect(select).to_have_value(REPORT)
        await expect(select.locator('option')).to_have_count(3)
        await form.get_by_label('Buscar Repuesto 1',exact=True).fill('No existe')
        await expect(select).to_have_value(REPORT)
        await expect(select.locator('option')).to_have_count(2)
        await form.get_by_role('button',name='Cancelar',exact=True).click()
        self.catalog_parts=[]
        await self.page.get_by_role('button',name='Actualizar datos',exact=True).click()
        await expect(self.page.get_by_text('Cargando información…')).to_have_count(0)
        await self.page.get_by_role('button',name='Solicitar autorización de alcance',exact=True).click()
        await form.get_by_role('button',name='Añadir repuesto').click()
        await expect(select.locator('..').get_by_text('No hay opciones disponibles.',exact=True)).to_be_visible()
        await expect(form.locator('input[pattern="[0-9a-fA-F-]{36}"]')).to_have_count(0)

    async def test_vehicle_code_search_and_permission_rejection_keeps_navigation(self):
        await self.enter()
        await self.page.get_by_role('button',name='Órdenes',exact=True).click()
        await self.page.get_by_label('Buscar en esta página').fill('CAB-01')
        await expect(self.page.get_by_role('button',name='Ver detalle',exact=False)).to_be_visible()
        self.forced[('GET','/api/workshop/orders')]=(403,{})
        await self.page.get_by_role('button',name='Actualizar datos',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Acceso no autorizado')).to_have_count(0)
        await self.page.get_by_role('button',name='Averías',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Reportes de averías',exact=True)).to_be_visible()

    async def test_confirmed_mutation_refresh_failure_is_not_successful_refresh(self):
        await self.enter()
        form=await self.report_form()
        self.forced[('GET',f'/api/workshop/reports/{REPORT}')]=(500,{})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada. No se pudo actualizar la información; consulta de nuevo sin repetir el envío.',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_have_count(0)
        self.assertEqual(1,len(self.sent))

    async def test_order_loading_hides_mutating_actions(self):
        await self.open_order()
        self.delay_order=True
        await self.page.get_by_role('button',name='Actualizar datos',exact=True).click()
        await expect(self.page.get_by_text('Cargando información…')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Registrar diagnóstico',exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Editar orden',exact=True)).to_have_count(0)
        await expect(self.page.get_by_text('Cargando información…')).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Registrar diagnóstico',exact=True)).to_be_visible()

    async def test_partial_refresh_failure_shows_unavailable_metric(self):
        await self.enter()
        self.forced[('GET','/api/workshop/authorization-requests')]=(500,{})
        await self.page.get_by_role('button',name='Actualizar datos',exact=True).click()
        await expect(self.page.get_by_text('Algunos indicadores no están disponibles.',exact=False)).to_be_visible()
        metric=self.page.locator('article').filter(has=self.page.get_by_text('Autorizaciones pendientes',exact=True))
        await expect(metric.locator('strong')).to_have_text('—')

if __name__=='__main__': unittest.main(verbosity=2)
