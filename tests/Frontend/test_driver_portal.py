"""Synthetic HTTP contract tests. Real PostgreSQL flows are tested separately."""
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
        self.trip={'id':'trip-1','product':'Agua','execution_status':'in_progress','administrative_status':'open','scheduled_start_at':'2026-10-14T14:00:00Z','route':{'name':'Ruta móvil','origin':'Origen','destination':'Destino'},'assets':[{'id':'assignment-1','fleet_asset_id':'asset-1','type':'tractor_unit','code':'CAB-01','starts_at':'2026-10-14T14:00:00Z'},{'id':'assignment-2','fleet_asset_id':'asset-2','type':'tanker','code':'CIS-01','starts_at':'2026-10-14T14:00:00Z'}],'my_reports':[],'my_odometer':[]}
    async def api(self,route):
        path=urlparse(route.request.url).path
        if route.request.method=='POST' and path.startswith('/api/transport/my-trips/'):
            self.posts.append(json.loads(route.request.post_data))
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
