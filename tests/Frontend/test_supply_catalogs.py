"""Administration catalog UI tests with synthetic API contracts, not real persistence."""
import asyncio
import unittest
from urllib.parse import urlparse

import test_fleet_documents as browser_support
from playwright.async_api import expect


class SupplyCatalogBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        browser_support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        browser_support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncSetUp(self):
        self.records = {key: [] for key in ['customers','spare-parts','suppliers','routes','operation-sites','liquid-products']}
        self.writes = []
        self.rejection = None
        self.hold = None
        self.started = asyncio.Event()
        await browser_support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.page.on('dialog', lambda dialog: dialog.accept())

    async def asyncTearDown(self):
        await browser_support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self, route):
        req = route.request
        path = urlparse(req.url).path
        if path == '/api/session':
            await route.fulfill(json={'data': {'id': '7', 'login': 'admin', 'roles': [self.role]}})
            return
        if path in ['/api/tractor-units','/api/tankers']:
            if req.method=='POST':
                self.writes.append((req.method,path,req.post_data_json))
                self.started.set()
                if self.hold: await self.hold.wait()
                await route.fulfill(status=422,json={'message':'Revisa el código','errors':{'code':['Código ya registrado.']}})
            else:
                await route.fulfill(json={'data':[],'meta':{'current_page':1,'last_page':1}})
            return
        if path.startswith('/api/catalogs/'):
            segments = path.split('/')
            kind = segments[3]
            records = self.records[kind]
            identifier = segments[4] if len(segments) > 4 else None
            if req.method == 'GET':
                if identifier:
                    await route.fulfill(json={'data': next(r for r in records if r['id'] == identifier)})
                else:
                    await route.fulfill(json={'data': records, 'current_page': 1, 'last_page': 1})
                return
            self.writes.append((req.method, path, req.post_data_json))
            if self.rejection:
                await route.fulfill(status=422, json={'message': 'Unidad inválida.', 'errors': {'unit': ['Unidad inválida.']}})
                return
            payload = req.post_data_json
            record = {'id': identifier or '50000000-0000-4000-8000-000000000001', **payload}
            if identifier:
                records[records.index(next(r for r in records if r['id'] == identifier))] = record
            else:
                records.append(record)
            await route.fulfill(status=201 if req.method == 'POST' else 200, json={'data': record})
            return
        await route.fulfill(status=404, json={'message': 'Contrato no esperado.'})

    async def open_catalog(self, kind):
        await self.page.goto(self.base + '/administracion/catalogos')
        await self.page.get_by_label('Catálogo', exact=True).select_option(kind)

    async def test_routes_entry_explains_missing_sites_and_preserves_selected_catalog(self):
        await self.page.goto(self.base+'/administracion/catalogos?catalogo=routes')
        await expect(self.page.get_by_role('heading',name='Rutas de transporte',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Registrar ruta',exact=True)).to_be_disabled()
        await expect(self.page.get_by_role('status').filter(has_text='Todavía no hay sitios')).to_be_visible()
        await self.page.get_by_role('button',name='Registrar sitios de origen y destino',exact=True).click()
        await expect(self.page.get_by_role('button',name='Registrar sitio',exact=True)).to_be_visible()
        self.assertIn('catalogo=operation-sites',self.page.url)
        await self.page.reload()
        await expect(self.page.get_by_role('heading',name='Sitios operativos',exact=True)).to_be_visible()
        await self.page.set_viewport_size({'width':390,'height':844})
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        self.assertEqual([],self.writes)

    async def test_fleet_pending_write_cannot_close_form_and_validation_retains_data(self):
        self.hold=asyncio.Event()
        await self.page.goto(self.base+'/administracion/flota')
        await self.page.get_by_role('button',name='Registrar cabezal',exact=True).click()
        await self.page.get_by_label('Código del activo',exact=True).fill('CAB-DUPLICADO')
        await self.page.get_by_role('button',name='Guardar activo',exact=True).click()
        await self.started.wait()
        try:
            await expect(self.page.get_by_label('Buscar por código',exact=True)).to_be_disabled()
            await expect(self.page.get_by_role('button',name='Guardar activo',exact=True)).to_be_disabled()
            await self.page.get_by_label('Código del activo',exact=True).evaluate("e=>e.form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))")
            self.assertEqual(1,len(self.writes))
        finally:
            self.hold.set()
        await expect(self.page.locator('#fleet-code-errors')).to_have_text('Código ya registrado.')
        await expect(self.page.get_by_label('Código del activo',exact=True)).to_have_value('CAB-DUPLICADO')
        await expect(self.page.get_by_role('button',name='Guardar activo',exact=True)).to_be_enabled()
        await expect(self.page.get_by_text('Operación confirmada y registros actualizados.',exact=True)).to_have_count(0)

    async def test_spare_part_create_search_and_edit_uses_real_fields(self):
        await self.open_catalog('spare-parts')
        await self.page.get_by_role('button', name='Registrar repuesto', exact=True).click()
        await self.page.get_by_label('Código', exact=True).fill('FILT-01')
        await self.page.get_by_label('Descripción', exact=True).fill('Filtro de aceite')
        await self.page.get_by_label('Unidad', exact=True).fill('unidad')
        await self.page.get_by_role('button', name='Guardar catálogo', exact=True).click()
        await expect(self.page.get_by_role('cell', name='FILT-01', exact=True)).to_be_visible()
        self.assertEqual({'code': 'FILT-01', 'description': 'Filtro de aceite', 'unit': 'unidad'}, self.writes[0][2])
        await self.page.get_by_label('Buscar catálogo', exact=True).fill('aceite')
        await self.page.get_by_role('button', name='Consultar / editar', exact=True).click()
        await self.page.get_by_label('Descripción', exact=True).fill('Filtro de aceite actualizado')
        await self.page.get_by_role('button', name='Guardar catálogo', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Filtro de aceite actualizado · unidad', exact=True)).to_be_visible()
        self.assertEqual('PUT', self.writes[1][0])
        self.assertEqual(2, len(self.writes))

    async def test_supplier_create_and_edit_only_sends_name(self):
        await self.open_catalog('suppliers')
        await self.page.get_by_role('button', name='Registrar proveedor', exact=True).click()
        await self.page.get_by_label('Nombre', exact=True).fill('Repuestos del taller')
        await self.page.get_by_role('button', name='Guardar catálogo', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Repuestos del taller', exact=True)).to_be_visible()
        await self.page.get_by_role('button', name='Consultar / editar', exact=True).click()
        await self.page.get_by_label('Nombre', exact=True).fill('Repuestos del taller CR')
        await self.page.get_by_role('button', name='Guardar catálogo', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Repuestos del taller CR', exact=True)).to_be_visible()
        self.assertEqual([{'name': 'Repuestos del taller'}, {'name': 'Repuestos del taller CR'}], [w[2] for w in self.writes])

    async def test_validation_does_not_claim_success_or_repeat_write(self):
        self.rejection = True
        await self.open_catalog('spare-parts')
        await self.page.get_by_role('button', name='Registrar repuesto', exact=True).click()
        await self.page.get_by_label('Código', exact=True).fill('FILT-02')
        await self.page.get_by_label('Descripción', exact=True).fill('Filtro')
        await self.page.get_by_label('Unidad', exact=True).fill('unidad inválida')
        await self.page.get_by_role('button', name='Guardar catálogo', exact=True).click()
        await expect(self.page.get_by_role('alert')).to_contain_text('Unidad inválida.')
        await expect(self.page.get_by_label('Registro de catálogo', exact=True)).to_be_visible()
        self.assertEqual(1, len(self.writes))
        self.assertEqual([], self.records['spare-parts'])

    async def test_secretary_has_no_catalog_actions(self):
        self.role = 'secretary'
        await self.page.goto(self.base + '/administracion/catalogos')
        await expect(self.page.get_by_role('heading', name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_label('Catálogo', exact=True)).to_have_count(0)
        self.assertEqual([], self.writes)
