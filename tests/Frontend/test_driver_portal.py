"""Synthetic HTTP contract tests. Real PostgreSQL flows are tested separately."""
import asyncio
import re
import json
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_system as system

class DriverPortalBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass=classmethod(system.SystemBrowserTest.setUpClass.__func__)
    tearDownClass=classmethod(system.SystemBrowserTest.tearDownClass.__func__)
    asyncTearDown=system.SystemBrowserTest.asyncTearDown
    async def asyncSetUp(self):
        await system.SystemBrowserTest.asyncSetUp(self)
        self.role='driver'
        self.posts=[]
        self.lost=False
        self.rejection=0
        self.hold=None
        self.started=asyncio.Event()
        self.trip={'id':'trip-1','product':'Agua','execution_status':'in_progress','administrative_status':'open','scheduled_start_at':'2026-10-14T14:00:00Z','route':{'name':'Ruta móvil','origin':'Origen','destination':'Destino'},'assets':[{'id':'assignment-1','fleet_asset_id':'asset-1','type':'tractor_unit','code':'CAB-01','starts_at':'2026-10-14T14:00:00Z'},{'id':'assignment-2','fleet_asset_id':'asset-2','type':'tanker','code':'CIS-01','starts_at':'2026-10-14T14:00:00Z'}],'my_reports':[],'my_odometer':[]}
    async def api(self,route):
        path=urlparse(route.request.url).path
        if route.request.method=='POST' and path.startswith('/api/transport/my-trips/'):
            self.posts.append(json.loads(route.request.post_data))
            self.started.set()
            if self.hold: await self.hold.wait()
            if self.rejection:
                await route.fulfill(status=self.rejection,json={'message':'La asignación cambió. Conserva la captura.'});return
            if self.lost:
                self.lost=False
                await route.abort('failed')
            else:
                await route.fulfill(json={'data':{'id':'record-1'}})
            return
        if path=='/api/transport/my-trips':
            await route.fulfill(json={'data':[self.trip],'meta':{'current_page':1,'last_page':1}});return
        if path=='/api/transport/my-trips/trip-1':
            await route.fulfill(json={'data':self.trip});return
        if path=='/api/transport/my-records':
            await route.fulfill(json={'data':{'reports':[],'odometer':[]}});return
        if path=='/api/expenses':
            await route.fulfill(json={'data':[],'meta':{'current_page':1,'last_page':1}});return
        await system.SystemBrowserTest.api(self,route)
    async def open_trip(self):
        await self.page.goto(self.base+'/mis-viajes')
        await self.page.get_by_role('button',name='Consultar viaje y registrar información').click()
        await expect(self.page.get_by_role('heading',name='Operación del viaje: Ruta móvil')).to_be_visible()
    async def offline(self):
        await self.page.evaluate("Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>false});window.dispatchEvent(new Event('offline'))")
        await expect(self.page.get_by_role('status').filter(has_text=re.compile(r'^Sin conexión'))).to_be_visible()

    async def test_offline_report_preserves_other_drafts_and_small_phone_has_no_overflow(self):
        self.trip['route']['name']='Ruta'+('ExtensaSinEspacios'*20)
        await self.page.goto(self.base+'/mis-viajes')
        await self.page.get_by_role('button',name='Consultar viaje y registrar información').click()
        await self.offline()
        await self.page.get_by_label('Kilometraje',exact=True).fill('1300.25')
        await self.page.get_by_label('Motivo de lectura').fill('Borrador de lectura')
        await self.page.get_by_label('Concepto',exact=True).fill('Borrador de combustible')
        await self.page.get_by_label('Categoría',exact=True).fill('Operación')
        await self.page.get_by_label('Monto',exact=True).fill('12.50')
        proof=self.page.get_by_label('Comprobante del nuevo gasto',exact=False)
        await proof.set_input_files({'name':'comprobante-'+('x'*180)+'.pdf','mimeType':'application/pdf','buffer':b'%PDF-synthetic'})
        await self.page.get_by_label('Observación',exact=True).fill('Incidencia'+('X'*450))
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='Captura guardada localmente')).to_be_visible()
        self.assertEqual([],self.posts)
        await expect(self.page.get_by_role('button',name='Sincronizar pendientes')).to_be_disabled()
        await expect(self.page.get_by_label('Kilometraje',exact=True)).to_have_value('1300.25')
        await expect(self.page.get_by_label('Concepto',exact=True)).to_have_value('Borrador de combustible')
        self.assertEqual(1,await proof.evaluate('e=>e.files.length'))
        await self.page.get_by_text('Ver datos de esta captura',exact=True).click()
        for width in [320,360,390]:
            await self.page.set_viewport_size({'width':width,'height':740})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'Overflow at {width}px')
        await expect(self.page.get_by_text('Para sincronizar, recupera conexión',exact=False)).to_be_visible()

    async def test_old_confirmed_capture_does_not_claim_success_for_a_new_conflict(self):
        await self.open_trip()
        self.page.on('dialog',lambda d:d.accept())
        await self.page.get_by_label('Observación',exact=True).fill('Primer reporte')
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_text('sincronizado',exact=True)).to_be_visible()
        self.rejection=409
        await self.page.get_by_label('Observación',exact=True).fill('Reporte con contexto distinto')
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('status').filter(has_text=re.compile(r'confirmados? por el servidor'))).to_have_count(0)
        await expect(self.page.get_by_text('Requiere revisión con oficina.',exact=False)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Sincronizar pendientes')).to_be_disabled()
        self.assertEqual(2,len(self.posts))

    async def test_syncing_state_is_visible_until_server_confirmation(self):
        await self.open_trip()
        self.hold=asyncio.Event()
        await self.page.get_by_label('Observación',exact=True).fill('Reporte en envío')
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await self.started.wait()
        try:
            await expect(self.page.get_by_text('sincronizando',exact=True)).to_be_visible()
            await expect(self.page.get_by_role('button',name='Sincronizando…',exact=True)).to_be_disabled()
            await expect(self.page.get_by_role('status').filter(has_text=re.compile(r'confirmados? por el servidor'))).to_have_count(0)
        finally:
            self.hold.set()
        await expect(self.page.get_by_text('sincronizado',exact=True)).to_be_visible()

    async def test_storage_failure_retains_input_and_does_not_claim_local_success(self):
        await self.open_trip()
        await self.page.get_by_label('Observación',exact=True).fill('No perder esta observación')
        await self.page.evaluate("""()=>{const put=IDBObjectStore.prototype.put;IDBObjectStore.prototype.put=function(...args){if(this.name==='captures')throw new DOMException('Synthetic quota','QuotaExceededError');return put.apply(this,args);};}""")
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_text('No se pudo guardar toda la captura.',exact=False)).to_be_visible()
        await expect(self.page.get_by_label('Observación',exact=True)).to_have_value('No perder esta observación')
        await expect(self.page.get_by_role('status').filter(has_text='Captura guardada')).to_have_count(0)
        self.assertEqual([],self.posts)

    async def test_pending_logout_warns_and_offline_exit_does_not_wait_or_erase_queue(self):
        await self.open_trip()
        await self.offline()
        await self.page.get_by_label('Observación',exact=True).fill('Pendiente antes de salir')
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_text('pendiente',exact=True)).to_be_visible()
        dialogs=[]
        async def accept(dialog):
            dialogs.append(dialog.message);await dialog.accept()
        self.page.once('dialog',accept)
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await expect(self.page.get_by_text('Sin conexión: no se puede confirmar el cierre de sesión',exact=False)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_enabled()
        await expect(self.page.get_by_text('pendiente',exact=True)).to_be_visible()
        self.assertIn('1 capturas sin confirmar',dialogs[0])
        self.assertIn('No es obligatorio sincronizarlas ahora',dialogs[0])

    async def test_mobile_forms_use_real_contract_and_do_not_offer_official_transitions(self):
        await self.open_trip()
        await self.page.set_viewport_size({'width':390,'height':844})
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        await expect(self.page.get_by_role('button',name='Finalizar viaje',exact=True)).to_have_count(0)
        await self.page.get_by_label('Kilometraje',exact=True).fill('1200.25')
        await self.page.get_by_label('Motivo de lectura').fill('Inicio registrado')
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar kilometraje',exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmado')).to_be_visible()
        self.assertEqual('1200.25',self.posts[0]['reading_value'])
        self.assertEqual('assignment-1',self.posts[0]['tractor_assignment_id'])
        self.assertTrue(self.posts[0]['occurred_at'].endswith('-06:00'))
    async def test_lost_response_retains_same_key_for_manual_retry(self):
        await self.open_trip()
        await self.page.get_by_label('Observación',exact=True).fill('Incidencia registrada')
        self.lost=True
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Capturas de este dispositivo')).to_be_visible()
        await expect(self.page.get_by_text('No se pudo confirmar la operación.',exact=False)).to_be_visible()
        self.assertEqual(1,len(self.posts))
        await expect(self.page.get_by_role('button',name='Registrar reporte',exact=True)).to_be_enabled()
        self.page.once('dialog',lambda d:d.accept())
        await self.page.get_by_role('button',name='Sincronizar pendientes').click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmado')).to_be_visible()
        self.assertEqual(self.posts[0],self.posts[1])
