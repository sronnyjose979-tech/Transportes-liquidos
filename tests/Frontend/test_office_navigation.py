"""Persistent office with real Vue/Router and intercepted synthetic server contexts."""
import asyncio
import os
import unittest
from unittest.mock import patch
from urllib.parse import urlparse
from playwright.async_api import expect
import test_fleet_documents as support
import test_transport as transport

PATHS = {'/dashboard': 'dashboard', '/transporte': 'transport', '/administracion/flota': 'fleet', '/administracion/personal':'personnel', '/administracion/catalogos':'catalogs', '/administracion/compras':'adminPurchasing', '/auditoria':'audit', '/reportes':'reports', '/inventario':'inventory', '/compras':'purchasing', '/facturacion':'billing', '/gastos':'expenses', '/alertas':'alerts', '/taller':'workshop'}


class OfficeNavigationBrowserTest(unittest.IsolatedAsyncioTestCase):
    office_dialog_control = True
    @classmethod
    def setUpClass(cls):
        with patch.dict(os.environ, TRANSPORTE_BROWSER_OFFICE='1'):
            support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    pagination = support.FleetDocumentsBrowserTest.pagination

    async def asyncSetUp(self):
        await transport.TransportBrowserTest.asyncSetUp(self)
        # Individual tests choose whether to confirm discarding form contents.
        self.accept_dialogs = False
        self.page.on('dialog', lambda dialog: asyncio.create_task(dialog.accept() if self.accept_dialogs else dialog.dismiss()))
        self.transitions = []
        self.documents_seen = []
        self.session_reads = []
        self.context_status = 200
        self.actor_id = '7'
        self.write_gate = None
        self.context_gate = None
        self.lookup_gate = None
        self.lookup_started = asyncio.Event()
        self.page.on('request', lambda request: self.documents_seen.append(urlparse(request.url).path) if request.is_navigation_request() else None)
        await self.context.route('**/*', self.office_request)

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    def context_data(self, path):
        links = [dict(key='dashboard', label='Inicio', href='/dashboard'), dict(key='transport', label='Transporte', href='/transporte'),
                 dict(key='fleet', label='Flota', href='/administracion/flota'), dict(key='driver', label='Mis viajes', href='/mis-viajes'),
                 dict(key='personnel', label='Personal', href='/administracion/personal'), dict(key='purchasing',label='Compras',href='/compras')]
        if self.role == 'secretary':
            links = [links[1]]
        return dict(page=PATHS[path], session=dict(id=self.actor_id, login='synthetic', roles=[self.role]),
                    navigation=dict(home=links[0]['href'], modules=links))

    async def office_request(self, route):
        path = urlparse(route.request.url).path
        if path in ['/api/session', '/api/navigation']:
            self.session_reads.append(path)
        if route.request.headers.get('x-office-navigation') == '1':
            self.transitions.append((path, route.request.headers.get('x-session-user')))
            if self.context_gate and path == '/administracion/flota':
                await self.context_gate.wait()
            status = self.context_status
            if self.role == 'secretary' and path != '/transporte':
                status = 403
            if self.actor_id != route.request.headers.get('x-session-user'):
                status = 409
            await route.fulfill(status=status, headers={'X-Session-User': self.actor_id}, json={'data': self.context_data(path)} if status == 200 else {'message': 'Acceso rechazado'})
            return
        await route.fallback()

    async def api(self, route):
        path = urlparse(route.request.url).path
        if path == '/api/transport/lookups/customers' and self.lookup_gate:
            self.lookup_started.set()
            await self.lookup_gate.wait()
            await route.fulfill(status=403, json={'message': 'Acceso rechazado'})
        elif path == '/api/dashboard':
            await route.fulfill(json={'data': {'counters': [dict(key='trips', label='Viajes', value=3, href='/transporte')]}})
        elif path in ['/api/tractor-units', '/api/tankers']:
            if route.request.method == 'POST':
                if self.write_gate:
                    await self.write_gate.wait()
                await route.fulfill(status=500, json={'message': 'Resultado incierto'})
            else:
                await route.fulfill(json=self.pagination([], {'per_page': ['100'], 'page': ['1']}))
        elif path in ['/api/workshop/lookups/mechanics','/api/workshop/orders','/api/workshop/authorization-requests','/api/workshop/impediments']:
            await route.fulfill(json=self.pagination([],{'per_page':['100'],'page':['1']}))
        elif path=='/api/purchasing/lookups':
            await route.fulfill(json={'data':{'parts':[],'suppliers':[]}})
        elif path in ['/api/users','/api/employees','/api/roles','/api/catalogs/liquid-products','/api/catalogs/customers','/api/catalogs/spare-parts','/api/catalogs/suppliers','/api/workshop/material-needs','/api/purchasing/requests','/api/reports/trips','/api/inventory/stock','/api/inventory/custody','/api/inventory/corrections','/api/inventory/movements','/api/purchasing/orders','/api/billing/trips','/api/billing/invoices','/api/expenses','/api/document-alerts']:
            await route.fulfill(json=self.pagination([],{'per_page':['100'],'page':['1']}))
        else:
            await transport.TransportBrowserTest.api(self, route)

    async def link(self, name):
        await self.page.get_by_role('navigation', name='Módulos del sistema').get_by_role('link', name=name, exact=True).click()

    async def fleet_ready(self):
        await expect(self.page.get_by_role('heading', name='Administración de Flota', level=1)).to_be_visible()

    async def test_modules_and_history_preserve_document_and_avoid_session_navigation_requests(self):
        await self.page.goto(self.base + '/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_contain_text('3')
        await self.page.evaluate('window.officeSentinel=crypto.randomUUID()')
        marker = await self.page.evaluate('window.officeSentinel')
        await self.link('Transporte')
        await expect(self.page.get_by_role('heading', name='Solicitudes registradas')).to_be_visible()
        await self.link('Flota')
        await self.fleet_ready()
        await self.page.go_back()
        await expect(self.page.get_by_role('heading', name='Solicitudes registradas')).to_be_visible()
        await self.page.go_forward()
        await self.fleet_ready()
        self.assertEqual(marker, await self.page.evaluate('window.officeSentinel'))
        self.assertEqual(['/dashboard'], self.documents_seen)
        self.assertEqual([], self.session_reads)
        self.assertEqual(['/transporte', '/administracion/flota', '/transporte', '/administracion/flota'], [v[0] for v in self.transitions])
        self.assertTrue(all(expected == '7' for _, expected in self.transitions))
        await self.page.reload()
        await self.fleet_ready()

    async def test_expired_session_and_denied_transition_hide_private_content(self):
        for status in [401, 403]:
            await self.page.goto(self.base + '/dashboard')
            self.context_status = status
            await self.link('Flota')
            await expect(self.page.get_by_text('El contenido privado se ha ocultado.', exact=True)).to_be_visible()
            await expect(self.page.get_by_label('Resumen general')).to_have_count(0)
            self.assertEqual('/dashboard', urlparse(self.page.url).path)
        self.assertEqual([], self.session_reads)

    async def test_changed_account_cannot_install_destination_context(self):
        await self.page.goto(self.base + '/dashboard')
        self.actor_id = '8'
        await self.link('Flota')
        await expect(self.page.get_by_role('heading', name='La sesión cambió en este navegador')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Administración de Flota', level=1)).to_have_count(0)

    async def test_dirty_form_cancel_protects_click_and_back_then_can_be_discarded(self):
        await self.page.goto(self.base + '/dashboard')
        await self.link('Flota')
        await self.fleet_ready()
        await self.page.get_by_role('button', name='Registrar cabezal', exact=True).click()
        await self.page.get_by_label('Código del activo', exact=True).fill('PENDIENTE')
        self.accept_dialogs = False
        await self.link('Inicio')
        await expect(self.page.get_by_label('Código del activo', exact=True)).to_have_value('PENDIENTE')
        await self.page.go_back()
        await expect(self.page.get_by_label('Código del activo', exact=True)).to_have_value('PENDIENTE')
        await expect(self.page).to_have_url(self.base + '/administracion/flota')
        self.accept_dialogs = True
        await self.link('Inicio')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()

    async def test_busy_and_uncertain_write_block_leaving_without_losing_form(self):
        self.write_gate = asyncio.Event()
        await self.page.goto(self.base + '/administracion/flota')
        await self.fleet_ready()
        await self.page.get_by_role('button', name='Registrar cabezal', exact=True).click()
        await self.page.get_by_label('Código del activo', exact=True).fill('PENDIENTE')
        self.accept_dialogs = True
        await self.page.get_by_role('button', name='Guardar activo', exact=True).click()
        await expect(self.page.get_by_role('link',name='Inicio',exact=True)).to_have_attribute('aria-disabled','true')
        await self.page.locator('#office-content').evaluate("el=>{const a=document.createElement('a');a.href='/dashboard';el.append(a);a.click();a.remove();}")
        await expect(self.page.get_by_role('alert')).to_contain_text('operación pendiente')
        self.write_gate.set()
        await expect(self.page.get_by_role('button', name='Consultar estado confirmado')).to_be_visible()
        await self.page.get_by_role('link',name='Inicio',exact=True).click(force=True)
        self.assertEqual('/administracion/flota', urlparse(self.page.url).path)
        self.assertEqual([], self.transitions)

    async def test_transport_query_history_and_driver_document_boundary(self):
        await self.page.goto(self.base + '/transporte')
        await self.page.get_by_role('button', name='Viajes', exact=True).click()
        await expect(self.page).to_have_url(self.base + '/transporte?vista=trips')
        await self.page.go_back()
        await expect(self.page.get_by_role('heading', name='Solicitudes registradas')).to_be_visible()
        await self.link('Mis viajes')
        await expect(self.page).to_have_url(self.base + '/mis-viajes')
        self.assertEqual(['/transporte', '/mis-viajes'], self.documents_seen)

    async def test_network_failure_preserves_source_and_allows_retry(self):
        await self.page.goto(self.base + '/dashboard')
        self.context_status = 503
        await self.link('Flota')
        await expect(self.page.get_by_role('alert')).to_contain_text('No se pudo autorizar')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        self.context_status = 200
        await self.link('Flota')
        await self.fleet_ready()
        self.assertEqual(['/dashboard'], self.documents_seen)

    async def test_late_context_cannot_replace_a_newer_history_destination(self):
        await self.page.goto(self.base + '/dashboard')
        await self.link('Transporte')
        await expect(self.page.get_by_role('heading', name='Solicitudes registradas')).to_be_visible()
        self.context_gate = asyncio.Event()
        await self.link('Flota')
        await expect(self.page.get_by_text('Consultando destino autorizado…', exact=True)).to_be_visible()
        await self.page.go_back()
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        self.context_gate.set()
        await expect(self.page.get_by_role('link', name='Inicio', exact=True)).to_have_attribute('aria-current', 'page')
        self.assertEqual('/dashboard', urlparse(self.page.url).path)

    async def test_focus_verification_is_still_active_after_module_changes(self):
        await self.page.goto(self.base + '/dashboard')
        await self.link('Flota')
        await self.fleet_ready()
        self.role = 'driver'
        await self.page.evaluate("window.dispatchEvent(new Event('focus'))")
        await expect(self.page.get_by_role('heading', name='La sesión cambió en este navegador')).to_be_visible()
        self.assertEqual(['/api/session'], self.session_reads)

    async def test_late_error_from_unmounted_module_cannot_hide_new_module(self):
        await self.page.goto(self.base + '/dashboard')
        self.lookup_gate = asyncio.Event()
        await self.link('Transporte')
        await self.lookup_started.wait()
        await self.link('Flota')
        await self.fleet_ready()
        async with self.page.expect_response(lambda response: urlparse(response.url).path == '/api/transport/lookups/customers' and response.status == 403):
            self.lookup_gate.set()
        await self.page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
        await self.fleet_ready()
        await expect(self.page.get_by_text('El contenido privado se ha ocultado.', exact=True)).to_have_count(0)

    async def test_transport_query_updates_preserve_request_filter_and_creation_context(self):
        await self.page.goto(self.base + '/transporte')
        await self.page.get_by_role('button', name='Solicitudes', exact=True).click()
        await self.page.get_by_role('button', name='Consultar viajes', exact=True).click()
        await expect(self.page.get_by_text('Viajes asociados a solicitud', exact=False)).to_be_visible()
        await self.page.get_by_role('button', name='Solicitudes', exact=True).click()
        await self.page.get_by_role('button', name='Crear viaje', exact=True).click()
        await expect(self.page.get_by_role('form', name='Crear viaje asociado').get_by_label('Solicitud de transporte', exact=True)).to_have_value(transport.REQUEST)
        self.assertEqual([], self.transitions)

    async def enter_path(self, path):
        await self.page.locator('#office-content').evaluate("(element,path)=>{const link=document.createElement('a');link.href=path;link.textContent='Destino de prueba';element.append(link);link.click();link.remove();}", path)
        await expect(self.page).to_have_url(self.base + path)
        await expect(self.page.locator('#office-content')).to_have_attribute('aria-busy', 'false')

    async def test_administration_and_queries_share_shell_and_load_only_requested_modules(self):
        chunks=[]
        self.page.on('request', lambda request: chunks.append(request.url) if '.js' in urlparse(request.url).path else None)
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        self.assertFalse(any('WorkshopApp-' in url or 'AuditApp-' in url or 'ReportsApp-' in url for url in chunks))
        for path in ['/administracion/personal','/administracion/catalogos','/administracion/compras','/auditoria','/reportes']:
            await self.enter_path(path)
            await expect(self.page.get_by_role('navigation',name='Módulos del sistema')).to_have_count(1)
        self.assertEqual(['/dashboard'],self.documents_seen)
        self.assertEqual([],self.session_reads)
        self.assertTrue(any('AuditApp-' in url for url in chunks))
        self.assertTrue(any('ReportsApp-' in url for url in chunks))

    async def test_supply_finance_and_alerts_share_navigation_and_skip_boot_session(self):
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        for path in ['/inventario','/compras','/facturacion','/gastos','/alertas']:
            await self.enter_path(path)
            await expect(self.page.get_by_role('navigation',name='Módulos del sistema')).to_have_count(1)
        self.assertEqual(['/dashboard'],self.documents_seen)
        self.assertEqual([],self.session_reads)

    async def test_workshop_entry_is_persistent_and_does_not_boot_session_twice(self):
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await self.enter_path('/taller')
        await expect(self.page.get_by_role('navigation',name='Módulos del sistema')).to_have_count(1)
        await expect(self.page.get_by_role('navigation',name='Navegación Taller')).to_be_visible()
        await self.page.locator('.workshop-brand').click()
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await self.enter_path('/transporte')
        self.assertEqual(['/dashboard'],self.documents_seen)
        self.assertEqual([],self.session_reads)

    async def test_every_office_pair_preserves_one_document_and_authorizes_destination(self):
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        pairs=set()
        for source in PATHS:
            await self.enter_path(source)
            for destination in PATHS:
                await self.enter_path(destination)
                pairs.add((source,destination))
                await self.enter_path(source)
        self.assertEqual(len(PATHS)**2,len(pairs))
        self.assertEqual(['/dashboard'],self.documents_seen)
        self.assertEqual([],self.session_reads)
        self.assertTrue(set(PATHS)-{'/dashboard'} <= {path for path,_ in self.transitions})

    async def test_failed_lazy_download_preserves_source_and_history(self):
        blocked=[]
        async def fail_chunk(route):
            if 'ReportsApp-' in route.request.url:
                blocked.append(route.request.url)
                await route.abort('failed')
            else:
                await route.fallback()
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await self.page.route('**/*.js',fail_chunk)
        await self.page.locator('#office-content').evaluate("el=>{const a=document.createElement('a');a.href='/reportes';el.append(a);a.click();a.remove();}")
        await expect(self.page.get_by_role('alert')).to_contain_text('módulo')
        self.assertTrue(blocked)
        self.assertEqual('/dashboard',urlparse(self.page.url).path)
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await expect(self.page.locator('#office-content')).to_have_attribute('aria-busy','false')
        await self.page.unroute('**/*.js',fail_chunk)
        await self.enter_path('/transporte')
        await self.page.go_back()
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        self.assertEqual(['/dashboard'],self.documents_seen)

    async def test_catalog_query_and_filter_navigation_keep_router_history(self):
        await self.page.goto(self.base+'/administracion/catalogos?catalogo=liquid-products')
        await expect(self.page.get_by_role('heading',name='Catálogos operativos',exact=True)).to_be_visible()
        await self.enter_path('/administracion/catalogos?catalogo=customers')
        await self.enter_path('/reportes')
        await self.page.go_back()
        await expect(self.page).to_have_url(self.base+'/administracion/catalogos?catalogo=customers')
        await self.page.reload()
        await expect(self.page).to_have_url(self.base+'/administracion/catalogos?catalogo=customers')

    async def test_abandoned_audit_read_is_cancelled_without_hiding_new_module(self):
        started=asyncio.Event()
        release=asyncio.Event()
        cancelled=asyncio.Event()
        async def delayed(route):
            started.set()
            await release.wait()
            await route.fulfill(status=403,json={'message':'Acceso rechazado'})
        self.page.on('requestfailed',lambda request:cancelled.set() if urlparse(request.url).path=='/api/users' else None)
        await self.page.route('**/api/users?*',delayed)
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_label('Resumen general')).to_be_visible()
        await self.enter_path('/auditoria')
        await asyncio.wait_for(started.wait(),10)
        await self.enter_path('/transporte')
        await asyncio.wait_for(cancelled.wait(),10)
        release.set()
        await expect(self.page.get_by_role('heading',name='Solicitudes registradas')).to_be_visible()
        await expect(self.page.get_by_text('El contenido privado se ha ocultado.',exact=True)).to_have_count(0)

    async def test_catalog_query_cannot_discard_modified_form_before_confirmation(self):
        await self.page.goto(self.base+'/administracion/catalogos?catalogo=customers')
        await self.page.get_by_role('button',name='Registrar cliente',exact=True).click()
        await self.page.get_by_role('form',name='Registro de catálogo').get_by_label('Nombre',exact=True).fill('Sin guardar')
        await self.page.get_by_label('Catálogo',exact=True).select_option('liquid-products')
        await expect(self.page.get_by_role('form',name='Registro de catálogo').get_by_label('Nombre',exact=True)).to_have_value('Sin guardar')
        await expect(self.page).to_have_url(self.base+'/administracion/catalogos?catalogo=customers')
        self.accept_dialogs=True
        await self.page.get_by_label('Catálogo',exact=True).select_option('liquid-products')
        await expect(self.page).to_have_url(self.base+'/administracion/catalogos?catalogo=liquid-products')
        await expect(self.page.get_by_role('form',name='Registro de catálogo')).to_have_count(0)

    async def test_download_does_not_disable_later_dirty_or_uncertain_unload_guards(self):
        await self.page.route('**/receipt-test',lambda route:route.fulfill(body=b'%PDF-1.4 synthetic',headers={'Content-Type':'application/pdf','Content-Disposition':'attachment; filename="receipt.pdf"'}))
        await self.page.goto(self.base+'/administracion/flota')
        await self.fleet_ready()
        async with self.page.expect_download():
            await self.page.locator('#office-content').evaluate("el=>{const a=document.createElement('a');a.href='/receipt-test';el.append(a);a.click();a.remove();}")
        await self.page.get_by_role('button',name='Registrar cabezal',exact=True).click()
        await self.page.get_by_label('Código del activo',exact=True).fill('CONSERVAR')
        guarded="()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;}"
        self.assertTrue(await self.page.evaluate(guarded))
        self.accept_dialogs=True
        await self.page.get_by_role('button',name='Guardar activo',exact=True).click()
        await expect(self.page.get_by_role('button',name='Consultar estado confirmado')).to_be_visible()
        self.assertTrue(await self.page.evaluate(guarded))

    async def test_transport_history_requires_confirmation_before_discarding_dirty_form(self):
        await self.page.goto(self.base+'/transporte?vista=requests')
        await self.page.get_by_role('button',name='Viajes',exact=True).click()
        await expect(self.page).to_have_url(self.base+'/transporte?vista=trips')
        await self.page.get_by_role('button',name='Crear viaje',exact=True).click()
        form=self.page.get_by_role('form',name='Crear viaje asociado')
        await form.get_by_label('Solicitud de transporte',exact=True).select_option(transport.REQUEST)
        await self.page.go_back()
        await expect(form.get_by_label('Solicitud de transporte',exact=True)).to_have_value(transport.REQUEST)
        await expect(self.page).to_have_url(self.base+'/transporte?vista=trips')
        self.accept_dialogs=True
        await self.page.go_back()
        await expect(self.page).to_have_url(self.base+'/transporte?vista=requests')
        await self.page.go_forward()
        await expect(self.page).to_have_url(self.base+'/transporte?vista=trips')

    async def personnel_fixture(self):
        role={'id':'role-driver','name':'driver'}
        account={'id':'44','name':'Persona sintética','login':'persona-sintetica','email':'persona@example.test','is_active':True,'roles':[role],'employee_id':'employee-44'}
        employee={'id':'employee-44','name':'Persona sintética','is_active':True,'user_id':44}
        async def records(route):
            path=urlparse(route.request.url).path
            self.assertEqual('GET',route.request.method)
            if path=='/api/users/44':
                await route.fulfill(json={'data':account})
            elif path=='/api/employees/employee-44':
                await route.fulfill(json={'data':employee})
            else:
                rows={'/api/users':[account],'/api/employees':[employee],'/api/roles':[role]}[path]
                await route.fulfill(json=self.pagination(rows,{'per_page':['100'],'page':['1']}))
        for pattern in ['**/api/users**','**/api/employees**','**/api/roles**']:
            await self.page.route(pattern,records)
        self.personnel_dialogs=[]
        self.page.on('dialog',lambda dialog:self.personnel_dialogs.append(dialog.type))

    async def edit_personnel_account(self):
        await self.page.get_by_role('button',name='Consultar / editar',exact=True).click()
        form=self.page.get_by_role('form',name='Registro de personal')
        await form.get_by_label('Nombre visible',exact=True).fill('Cambios por conservar')
        await form.get_by_label('Nueva contraseña (opcional)',exact=True).fill('SyntheticPassword456!')
        return form

    async def personnel_query_link(self,path):
        await self.page.locator('#office-content').evaluate("(el,path)=>{const a=document.createElement('a');a.href=path;el.append(a);a.click();a.remove();}",path)

    async def test_personnel_general_link_requires_confirming_dirty_account_discard(self):
        await self.personnel_fixture()
        original=self.base+'/administracion/personal?vista=cuentas'
        await self.page.goto(original)
        form=await self.edit_personnel_account()
        await self.link('Personal')
        await expect(self.page).to_have_url(original)
        await expect(form.get_by_label('Nombre visible',exact=True)).to_have_value('Cambios por conservar')
        await expect(form.get_by_label('Nueva contraseña (opcional)',exact=True)).to_have_value('SyntheticPassword456!')
        self.assertEqual(['confirm'],self.personnel_dialogs)
        self.accept_dialogs=True
        await self.link('Personal')
        await expect(form).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Empleados',exact=True)).to_have_attribute('aria-pressed','true')
        self.assertEqual(['confirm','confirm'],self.personnel_dialogs)
        self.assertEqual(['/administracion/personal'],self.documents_seen)

    async def test_personnel_back_and_forward_preserve_dirty_forms_when_cancelled(self):
        await self.personnel_fixture()
        await self.page.goto(self.base+'/administracion/personal')
        await self.personnel_query_link('/administracion/personal?vista=cuentas')
        await expect(self.page.get_by_role('button',name='Cuentas y roles',exact=True)).to_have_attribute('aria-pressed','true')
        await self.page.wait_for_function("new URL(location.href).searchParams.get('estado')==='all'")
        account_url=self.page.url
        form=await self.edit_personnel_account()
        await self.page.go_back()
        await expect(self.page).to_have_url(account_url)
        await expect(form.get_by_label('Nueva contraseña (opcional)',exact=True)).to_have_value('SyntheticPassword456!')
        self.assertEqual(['confirm'],self.personnel_dialogs)
        self.accept_dialogs=True
        await self.page.go_back()
        await expect(self.page.get_by_role('button',name='Empleados',exact=True)).to_have_attribute('aria-pressed','true')
        await expect(form).to_have_count(0)
        await self.page.wait_for_function("new URL(location.href).searchParams.get('vista')==='empleados'")
        employee_url=self.page.url
        await self.page.get_by_role('button',name='Consultar / editar',exact=True).click()
        await form.get_by_label('Nombre del empleado',exact=True).fill('Empleado sin guardar')
        self.accept_dialogs=False
        await self.page.go_forward()
        await expect(self.page).to_have_url(employee_url)
        await expect(form.get_by_label('Nombre del empleado',exact=True)).to_have_value('Empleado sin guardar')
        self.accept_dialogs=True
        await self.page.go_forward()
        await expect(self.page.get_by_role('button',name='Cuentas y roles',exact=True)).to_have_attribute('aria-pressed','true')
        await expect(form).to_have_count(0)
        self.assertEqual(['confirm']*4,self.personnel_dialogs)
        self.assertEqual(['/administracion/personal'],self.documents_seen)

    async def test_personnel_guards_status_changes_but_preserves_forms_for_unrelated_query(self):
        await self.personnel_fixture()
        await self.page.goto(self.base+'/administracion/personal?vista=cuentas')
        form=await self.edit_personnel_account()
        unchanged=self.base+'/administracion/personal?vista=cuentas&origen=prueba'
        await self.personnel_query_link('/administracion/personal?vista=cuentas&origen=prueba')
        await expect(self.page).to_have_url(unchanged)
        await expect(form.get_by_label('Nombre visible',exact=True)).to_have_value('Cambios por conservar')
        self.assertEqual([],self.personnel_dialogs)
        await self.personnel_query_link('/administracion/personal?vista=cuentas&estado=inactive')
        await expect(self.page).to_have_url(unchanged)
        await expect(form.get_by_label('Nueva contraseña (opcional)',exact=True)).to_have_value('SyntheticPassword456!')
        self.assertEqual(['confirm'],self.personnel_dialogs)
        await self.personnel_query_link('/administracion/personal?vista=cuentas&estado=inactive&estado=active')
        await expect(self.page).to_have_url(unchanged)
        await expect(form.get_by_label('Nueva contraseña (opcional)',exact=True)).to_have_value('SyntheticPassword456!')
        self.assertEqual(['confirm','confirm'],self.personnel_dialogs)
        self.accept_dialogs=True
        await self.personnel_query_link('/administracion/personal?vista=cuentas&estado=inactive')
        await expect(self.page.get_by_label('Estado del personal',exact=True)).to_have_value('inactive')
        await expect(form).to_have_count(0)
        self.assertEqual(['confirm']*3,self.personnel_dialogs)
