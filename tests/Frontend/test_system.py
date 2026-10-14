"""UI contracts with intercepted synthetic API responses; real roles are tested E2E."""
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_fleet_documents as support


class SystemBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.status=200

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route)
            return
        if self.status!=200:
            await route.fulfill(status=self.status,json={'message':'Sesión expirada'})
            return
        data=[]
        if path=='/api/dashboard':
            await route.fulfill(json={'data':{'counters':[{'key':'trips','label':'Viajes','value':3,'href':'/transporte'}]}})
            return
        if path=='/api/transport/my-trips':
            data=[{'id':'trip-1','product':'Agua','execution_status':'pending','administrative_status':'open','scheduled_start_at':'2026-10-14T14:00:00Z','route':{'name':'Ruta real de contrato','origin':'Origen','destination':'Destino'},'assets':[{'type':'tractor_unit','code':'CAB-01'}]}]
        if path=='/api/billing/trips':
            data=[{'id':'trip-1','customer':{'id':'customer-1','name':'Cliente de contrato'},'product':'Agua','execution_status':'completed','administrative_status':'closed'}]
        await route.fulfill(json={'data':data,'meta':{'current_page':1,'last_page':1,'per_page':25,'total':len(data)}})

    async def test_admin_dashboard_and_shared_navigation_use_contract_counts(self):
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_role('heading',name='Panel general',exact=True)).to_be_visible()
        await expect(self.page.get_by_label('Resumen general')).to_contain_text('3')
        nav=self.page.get_by_role('navigation',name='Módulos del sistema')
        for label in ['Flota','Personal','Catálogos','Taller','Inventario','Compras','Facturación · consulta']:
            await expect(nav.get_by_role('link',name=label,exact=True)).to_be_visible()
        self.assertNotIn('Ingresos',await self.page.locator('main').inner_text())

    async def test_driver_has_assigned_trips_but_no_office_navigation(self):
        self.role='driver'
        await self.page.goto(self.base+'/mis-viajes')
        await expect(self.page.get_by_role('heading',name='Mis viajes asignados')).to_be_visible()
        await expect(self.page.get_by_text('CAB-01',exact=False)).to_be_visible()
        nav=self.page.get_by_role('navigation',name='Módulos del sistema')
        await expect(nav.get_by_role('link',name='Mis viajes',exact=True)).to_be_visible()
        await expect(nav.get_by_role('link',name='Taller',exact=True)).to_have_count(0)
        await expect(nav.get_by_role('link',name='Facturación · consulta')).to_have_count(0)
        for width in [768,390]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_billing_is_read_only_and_does_not_claim_fiscal_or_payment_success(self):
        self.role='secretary'
        await self.page.goto(self.base+'/facturacion')
        await expect(self.page.get_by_role('cell',name='Cliente de contrato')).to_be_visible()
        await expect(self.page.get_by_text('Consulta administrativa.',exact=False)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Crear factura')).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Registrar pago')).to_have_count(0)
        await expect(self.page.get_by_role('navigation',name='Módulos del sistema').get_by_role('link',name='Personal')).to_have_count(0)

    async def test_expired_dashboard_removes_private_data_and_allows_login(self):
        self.status=401
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_label('Resumen general')).to_have_count(0)
        await expect(self.page.get_by_role('navigation',name='Módulos del sistema')).to_have_count(0)
