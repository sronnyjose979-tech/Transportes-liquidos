"""Chromium frontend tests with explicitly simulated API responses; no application DB."""
import asyncio
import copy
import unittest
from urllib.parse import parse_qs, urlparse
from playwright.async_api import expect
import test_fleet_documents as support

ID='40000000-0000-4000-8000-000000000001'
REQUEST='40000000-0000-4000-8000-000000000002'
COMP='40000000-0000-4000-8000-000000000003'
LOAD='40000000-0000-4000-8000-000000000004'
ITEM='40000000-0000-4000-8000-000000000005'

class TransportBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls): support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)
    @classmethod
    def tearDownClass(cls): support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)
    pagination=support.FleetDocumentsBrowserTest.pagination
    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.page.on('dialog',lambda dialog:asyncio.create_task(dialog.accept()))
        self.request_row=dict(id=REQUEST,customer_id=ID,liquid_product_id=ID,route_id=ID,requested_liters='1000.25',requested_at='2026-10-12T14:00:00.000000Z',registered_by_id=7,version=1,trips_count=1)
        self.trip=dict(id=ID,transport_request_id=REQUEST,product_name_snapshot='Agua',planning_status='scheduled',execution_status='pending',administrative_status='open',version=1,scheduled_start_at=None,scheduled_end_at=None,officially_started_at=None,officially_completed_at=None,cancelled_at=None,cancellation_reason=None,closed_at=None,request=self.request_row,load=None,unload=None,current_driver_assignments=[dict(id='driver-assignment',employee_id=ID)],current_asset_assignments=[dict(id='head-assignment',fleet_asset_id=support.ASSET,asset_type='tractor_unit'),dict(id='tank-assignment',fleet_asset_id=support.OTHER,asset_type='tanker')])
        self.auth=[]
        self.writes=[]
        self.sent=[]
        self.calls=[]
        self.conflict=False
        self.readings=[]
    async def asyncTearDown(self): await support.FleetDocumentsBrowserTest.asyncTearDown(self)
    async def api(self,route):
        p=urlparse(route.request.url);path=p.path;method=route.request.method
        if not path.startswith('/api/transport'):
            return await support.FleetDocumentsBrowserTest.api(self,route)
        query=parse_qs(p.query)
        self.calls.append((method,path,query))
        if method!='GET': self.sent.append((path,route.request.post_data_json))
        if (method,path) in self.forced:
            status,data=self.forced[(method,path)];await route.fulfill(status=status,json=data);return
        if method!='GET': self.writes.append((path,route.request.post_data_json))
        if '/lookups/' in path:
            kind=path.rsplit('/',1)[-1]
            rows=[dict(id=ID,name={'customers':'Cliente prueba','products':'Agua','sites':'Sitio prueba','routes':'Ruta prueba','drivers':'Conductor prueba'}.get(kind,kind))]
            if kind=='drivers': rows += [dict(id=REQUEST,name='Conductor alterno')]
            if kind=='assets': rows=[dict(id=support.ASSET,code='CAB-01',type='tractor_unit',service_status='available'),dict(id=support.OTHER,code='CIS-01',type='tanker',service_status='available',compartments=[dict(id=COMP,code='A',capacity_liters='1000.00',is_active=True)])]
            await route.fulfill(json=self.pagination(rows,query));return
        if path.endswith('/availability'):
            await route.fulfill(json={'data':dict(resource_kind=query['resource_kind'][0],resource_id=query['resource_id'][0],eligible=True,available=not self.conflict,conflicts=[dict(id='occupied-assignment')] if self.conflict else [])});return
        if path.endswith('/requests'):
            if method=='POST': self.request_row.update(route.request.post_data_json)
            await route.fulfill(status=201 if method=='POST' else 200,json={'data':self.request_row} if method=='POST' else self.pagination([self.request_row],query));return
        if path.endswith('/trips'):
            if method=='POST':
                await route.fulfill(status=201,json={'data':self.trip});return
            rows=[self.trip] if not query.get('execution_status') or query['execution_status'][0]==self.trip['execution_status'] else []
            await route.fulfill(json=self.pagination(rows,query));return
        if path.endswith('/volume-differences'): await route.fulfill(json={'data':{'difference':None}});return
        if path.endswith('/authorizations'):
            await route.fulfill(json=self.pagination(self.auth,query));return
        if path.endswith('/assignments') and method=='GET':
            await route.fulfill(json=self.pagination([dict(id='old-operation',assetAssignment=dict(id=ITEM,fleet_asset_id=support.ASSET,asset_type='tractor_unit'))],query));return
        if path.endswith('/odometer'):
            if method=='POST':
                row=dict(id=LOAD,trip_id=ID,recorded_by_id=7,revision=1,registered_at='2026-10-12T14:00:00Z',previous_reading_id=None,**route.request.post_data_json)
                self.readings.append(row);await route.fulfill(status=201,json={'data':row})
            else: await route.fulfill(json=self.pagination(self.readings,query))
            return
        if path.endswith('/corrections'):
            row=dict(self.readings[0]);row.update(route.request.post_data_json,id=COMP,revision=2,previous_reading_id=LOAD)
            self.readings.append(row);await route.fulfill(status=201,json={'data':row});return
        if path.endswith('/distance'): await route.fulfill(json={'data':{'distance':'50.50'}});return
        if path.endswith('/history') or path.endswith('/assignments') or path.endswith('/reports') or path.endswith('/warnings'):
            await route.fulfill(json=self.pagination([],query));return
        if path.endswith('/start'):
            self.trip.update(execution_status='in_progress',officially_started_at='2026-10-12T14:00:00Z',version=2)
        elif path.endswith('/schedule') or path.endswith('/reschedule'):
            self.trip.update(scheduled_start_at=route.request.post_data_json['scheduled_start_at'],version=self.trip['version']+1)
        elif path.endswith('/load') and method=='POST':
            self.trip['load']=dict(id=LOAD,items=[dict(id=ITEM,compartment_id=COMP,compartment_code_snapshot='A',loaded_liters='100.00',capacity_liters_snapshot='1000.00')],occurred_at='2026-10-12T14:00:00Z',operation_site_id=ID,recorded_by_id=7,reason='Carga')
        elif path.endswith('/unload') and method=='POST':
            self.trip['unload']=dict(id='unload',items=[dict(id='delivered',load_item_id=ITEM,delivered_liters='100.00')],occurred_at='2026-10-12T15:00:00Z',operation_site_id=ID,recorded_by_id=7,reason='Descarga')
        await route.fulfill(json={'data':self.trip})
    async def open_trip(self):
        await self.page.goto(self.base+'/transporte')
        await self.page.get_by_role('button',name='Viajes',exact=True).click()
        await self.page.get_by_role('button',name='Abrir viaje').click()
        await expect(self.page.get_by_role('heading',name='Viaje · Agua')).to_be_visible()
    async def test_navigation_request_form_validation_payload_and_csrf(self):
        await self.page.goto(self.base+'/transporte')
        await expect(self.page.get_by_role('heading',name='Panel general')).to_be_visible()
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        await form.get_by_role('button',name='Guardar').click()
        self.assertEqual([],self.writes)
        await form.get_by_label('Cliente',exact=True).select_option(ID)
        await form.get_by_label('Producto líquido').select_option(ID)
        await form.get_by_label('Ruta',exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_text('Operación confirmada y datos actualizados.')).to_be_visible()
        self.assertEqual(1,len(self.writes))
        self.assertEqual('200.25',self.writes[0][1]['requested_liters'])
        self.assertTrue(self.writes[0][1]['requested_at'].endswith('-06:00'))
        await self.page.get_by_role('button',name='Consultar viajes',exact=True).click()
        await expect(self.page.get_by_text('Viajes asociados a solicitud',exact=False)).to_be_visible()
        self.assertTrue(any(q.get('transport_request_id')==[REQUEST] for m,p,q in self.calls if p.endswith('/trips')))
    async def test_creation_retry_retains_key_and_values_and_new_intention_gets_new_key(self):
        await self.page.goto(self.base+'/transporte')
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        for label in ['Cliente','Producto líquido','Ruta']:
            await form.get_by_label(label,exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        self.forced[('POST','/api/transport/requests')]=(500,{'message':'Error'})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Consultar estado confirmado')).to_be_visible()
        first=self.sent[-1][1].copy()
        self.assertIn('request_key',first)
        self.assertEqual(1,len([p for p,b in self.sent if p=='/api/transport/requests']))
        await self.page.get_by_role('button',name='Consultar estado confirmado').click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        await expect(form.get_by_label('Litros solicitados')).to_have_value('200.25')
        await expect(form.get_by_label('Litros solicitados')).to_be_disabled()
        del self.forced[('POST','/api/transport/requests')]
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada y datos actualizados.')).to_be_visible()
        self.assertEqual(first,self.sent[-1][1])
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        for label in ['Cliente','Producto líquido','Ruta']:
            await form.get_by_label(label,exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada y datos actualizados.')).to_be_visible()
        self.assertNotEqual(first['request_key'],self.sent[-1][1]['request_key'])

    async def test_validation_correction_preserves_the_same_key(self):
        await self.page.goto(self.base+'/transporte')
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        for label in ['Cliente','Producto líquido','Ruta']:
            await form.get_by_label(label,exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        self.forced[('POST','/api/transport/requests')]=(422,{'message':'Revisa litros','errors':{'requested_liters':['Revisa litros']}})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(form.get_by_label('Litros solicitados')).to_be_enabled()
        await expect(self.page.get_by_text('Revisa litros',exact=True).first).to_be_visible()
        first=self.sent[-1][1].copy()
        await form.get_by_label('Litros solicitados').fill('201.25')
        del self.forced[('POST','/api/transport/requests')]
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada y datos actualizados.')).to_be_visible()
        self.assertEqual(first['request_key'],self.sent[-1][1]['request_key'])
        self.assertEqual('201.25',self.sent[-1][1]['requested_liters'])

    async def test_reload_retains_only_the_opaque_key_for_safe_retry(self):
        await self.page.goto(self.base+'/transporte')
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        for label in ['Cliente','Producto líquido','Ruta']:
            await form.get_by_label(label,exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        self.forced[('POST','/api/transport/requests')]=(500,{'message':'Error'})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Consultar estado confirmado')).to_be_visible()
        first=self.sent[-1][1].copy()
        stored=await self.page.evaluate("Object.entries(sessionStorage).filter(([k])=>k.startsWith('transport-creation:')).map(([k,v])=>v)")
        self.assertEqual([first['request_key']],stored)
        await self.page.reload()
        await self.page.get_by_role('button',name='Solicitudes',exact=True).click()
        await self.page.get_by_role('button',name='Registrar solicitud',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar solicitud')
        for label in ['Cliente','Producto líquido','Ruta']:
            await form.get_by_label(label,exact=True).select_option(ID)
        await form.get_by_label('Litros solicitados').fill('200.25')
        await form.get_by_label('Fecha solicitada · Costa Rica').fill(first['requested_at'][:-6])
        del self.forced[('POST','/api/transport/requests')]
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada y datos actualizados.')).to_be_visible()
        self.assertEqual(first,self.sent[-1][1])

    async def test_trip_creation_retry_reuses_the_same_intention(self):
        await self.page.goto(self.base+'/transporte')
        await self.page.get_by_role('button',name='Viajes',exact=True).click()
        await self.page.get_by_role('button',name='Crear viaje',exact=True).click()
        form=self.page.get_by_role('form',name='Crear viaje asociado')
        await form.get_by_label('Solicitud de transporte',exact=True).select_option(REQUEST)
        self.forced[('POST','/api/transport/trips')]=(500,{'message':'Error'})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Consultar estado confirmado')).to_be_visible()
        first=self.sent[-1][1].copy()
        await self.page.get_by_role('button',name='Consultar estado confirmado').click()
        await self.page.get_by_role('button',name='Crear viaje',exact=True).click()
        form=self.page.get_by_role('form',name='Crear viaje asociado')
        await expect(form.get_by_label('Solicitud de transporte',exact=True)).to_have_value(REQUEST)
        del self.forced[('POST','/api/transport/trips')]
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Viaje · Agua')).to_be_visible()
        self.assertEqual(first,self.sent[-1][1])
        self.assertEqual(2,len([p for p,b in self.sent if p=='/api/transport/trips']))

    async def test_state_transitions_confirmation_and_incomplete_completion_disabled(self):
        await self.open_trip()
        await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
        form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
        await form.get_by_label('Motivo / confirmación').fill('Inicio manual')
        await form.get_by_role('button',name='Guardar').dblclick()
        await expect(self.page.get_by_role('button',name='Finalizar oficialmente')).to_be_disabled()
        self.assertEqual(1,len([w for w in self.writes if w[0].endswith('/start')]))
        await expect(self.page.get_by_role('button',name='Iniciar oficialmente',exact=True)).to_have_count(0)
    async def test_load_unload_compartments_and_actual_liters(self):
        await self.open_trip()
        await self.page.get_by_role('button',name='Carga y descarga',exact=True).click()
        await self.page.get_by_label('A · capacidad').check()
        form=self.page.get_by_role('form',name='Registrar carga')
        await form.get_by_label('Sitio real de carga').select_option(ID)
        await form.get_by_label('Litros reales').fill('100.00')
        await form.get_by_label('Motivo / información recibida').fill('Carga')
        await form.get_by_role('button',name='Guardar').click()
        form=self.page.get_by_role('form',name='Registrar descarga')
        await form.get_by_label('Sitio real de descarga').select_option(ID)
        await form.get_by_label('Litros reales').fill('100.00')
        await form.get_by_label('Motivo / información recibida').fill('Descarga')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_role('heading',name='Descarga registrada')).to_be_visible()
        self.assertEqual(ITEM,[w for w in self.writes if w[0].endswith('/unload')][0][1]['items'][0]['load_item_id'])
    async def test_conflict_is_visible_and_secretary_cannot_approve(self):
        self.role='secretary';self.conflict=True
        self.auth=[dict(id='authorization',trip_id=ID,type='assignment_conflict',content={'resource_id':ID},target_version=1,reason='Conflicto informado',requested_by_id=7,registered_at='2026-10-12T14:00:00Z',decision=None)]
        await self.open_trip()
        await self.page.get_by_role('button',name='Recursos',exact=True).click()
        await self.page.get_by_label('Seleccionar recurso',exact=True).select_option(REQUEST)
        await self.page.get_by_role('button',name='Consultar disponibilidad').click()
        await expect(self.page.get_by_text('Recurso ocupado: requiere revisión y autorización administrativa.')).to_be_visible()
        await self.page.get_by_role('button',name='Autorizaciones',exact=True).click()
        await expect(self.page.get_by_text('Pendiente de decisión exclusiva del administrador.')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Revisar y decidir')).to_have_count(0)
    async def test_server_failure_requires_state_refresh_and_never_retries_automatically(self):
        self.forced[('POST',f'/api/transport/trips/{ID}/start')]=(500,{'message':'SQLSTATE private details','errors':{'operation':['SQLSTATE private details']}})
        await self.open_trip()
        await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
        form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
        await form.get_by_label('Motivo / confirmación').fill('Inicio')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_role('alert')).to_contain_text('No se pudo confirmar')
        await expect(form.get_by_role('button',name='Guardar')).to_be_disabled()
        await expect(self.page.get_by_text('SQLSTATE private details')).to_have_count(0)
        await self.page.get_by_role('button',name='Consultar estado confirmado').click()
        await expect(self.page.get_by_role('button',name='Iniciar oficialmente',exact=True)).to_be_enabled()
    async def test_validation_csrf_and_conflict_errors_never_resubmit(self):
        for status in [422,419,409]:
            before=len(self.sent)
            self.forced[('POST',f'/api/transport/trips/{ID}/start')]=(status,{'message':'Revisión requerida','errors':{'reason':['Motivo rechazado']}})
            await self.open_trip()
            await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
            form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
            await form.get_by_label('Motivo / confirmación').fill('Motivo conservado')
            await form.get_by_role('button',name='Guardar').click()
            await expect(self.page.get_by_role('alert')).to_be_visible()
            await expect(form.get_by_label('Motivo / confirmación')).to_have_value('Motivo conservado')
            if status==419:
                await self.page.get_by_role('button',name='Actualizar sesión de seguridad').click()
            if status==409:
                await expect(form.get_by_role('button',name='Guardar')).to_be_disabled()
            self.assertEqual(before+1,len(self.sent))
    async def test_expired_session_revoked_role_and_missing_trip_clear_private_screens(self):
        await self.open_trip()
        self.forced[('GET',f'/api/transport/trips/{ID}')]=(404,{})
        await self.page.get_by_role('button',name='Actualizar viaje').click()
        await expect(self.page.get_by_role('heading',name='Viaje · Agua')).to_have_count(0)
        del self.forced[('GET',f'/api/transport/trips/{ID}')]
        await self.open_trip()
        self.forced[('POST',f'/api/transport/trips/{ID}/start')]=(401,{})
        await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
        form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
        await form.get_by_label('Motivo / confirmación').fill('Inicio')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_role('heading',name='Acceso a Transporte y Despacho')).to_be_visible()
        await expect(self.page.get_by_role('heading',name='Viaje · Agua')).to_have_count(0)
        self.forced[('POST',f'/api/transport/trips/{ID}/start')]=(403,{})
        self.role='administrator';await self.open_trip()
        await self.page.get_by_role('button',name='Iniciar oficialmente',exact=True).click()
        self.role='driver'
        form=self.page.get_by_role('form',name='Iniciar viaje oficialmente')
        await form.get_by_label('Motivo / confirmación').fill('Inicio')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_role('heading',name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_role('heading',name='Viaje · Agua')).to_have_count(0)

    async def test_empty_historical_operation_is_unknown_not_zero_and_cannot_finalize(self):
        self.trip.update(execution_status='in_progress',officially_started_at='2026-10-12T14:00:00Z')
        self.trip['load']=dict(id=LOAD,items=[],occurred_at='2026-10-12T14:00:00Z',operation_site_id=ID,recorded_by_id=7,reason='Cabecera sin detalles')
        await self.open_trip()
        await expect(self.page.get_by_text('Cargado: — L',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Finalizar oficialmente')).to_be_disabled()

    async def test_kilometres_use_historical_assignment_and_corrections_append_new_revision(self):
        await self.open_trip()
        await self.page.get_by_role('button',name='Kilometraje',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar kilometraje')
        await form.get_by_label('Asignación de cabezal').select_option(ITEM)
        await form.get_by_label('Tipo de lectura',exact=True).select_option('final')
        await form.get_by_label('Lectura de kilometraje').fill('1050.50')
        await form.get_by_label('Motivo / información recibida').fill('Lectura histórica')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_role('button',name='Corregir',exact=True)).to_be_visible()
        self.assertEqual(ITEM,self.readings[0]['tractor_assignment_id'])
        await self.page.get_by_role('button',name='Corregir',exact=True).click()
        form=self.page.get_by_role('form',name='Corregir kilometraje')
        await form.get_by_label('Lectura de kilometraje').fill('1051.50')
        await form.get_by_label('Motivo / información recibida').fill('Corrección')
        await form.get_by_role('button',name='Guardar').click()
        await expect(self.page.get_by_text('Revisión 2',exact=False)).to_be_visible()
        self.assertEqual('1050.50',self.readings[0]['reading_value'])
        self.assertEqual(2,self.readings[1]['revision'])
        await self.page.get_by_label('Tramo de cabezal',exact=True).select_option(ITEM)
        await self.page.get_by_role('button',name='Consultar distancia',exact=True).click()
        await expect(self.page.get_by_text('Distancia calculada:',exact=False)).to_be_visible()
        await self.page.get_by_label('Tramo de cabezal',exact=True).select_option('head-assignment')
        await expect(self.page.get_by_text('Distancia calculada:',exact=False)).to_have_count(0)
        await expect(self.page.get_by_text('Sin distancia confirmada;',exact=False)).to_be_visible()

    async def test_driver_denied_and_tablet_layout(self):
        self.role='driver';await self.page.goto(self.base+'/transporte')
        await expect(self.page.get_by_role('heading',name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Solicitudes',exact=True)).to_have_count(0)
        self.role='administrator';await self.page.set_viewport_size({'width':768,'height':1024})
        await self.page.reload();await expect(self.page.get_by_role('heading',name='Panel general')).to_be_visible()
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
