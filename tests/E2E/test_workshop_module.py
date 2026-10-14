"""Real browser, session/CSRF API and isolated PostgreSQL 18; no API mocks.
Run exclusively: bash tests/run-postgres.sh --e2e-workshop.
"""
import asyncio
import json
import os
import subprocess
import unittest
import uuid

from playwright.async_api import async_playwright, expect
import test_document_module as support


class WorkshopModuleE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        support.DocumentModuleE2E.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        support.DocumentModuleE2E.tearDownClass.__func__(cls)

    @classmethod
    def command(cls, action, data=None):
        result = subprocess.run(['php', str(support.ROOT / 'tests/E2E/workshop-console.php'), action],
                                input=json.dumps(data or {}), text=True, capture_output=True,
                                cwd=support.ROOT, check=True)
        return json.loads(result.stdout)

    async def asyncSetUp(self):
        asyncio.get_running_loop().slow_callback_duration = 2
        self.fixture = self.command('fixture')
        self.playwright = await async_playwright().start()
        options = {'args': ['--no-sandbox', '--disable-dev-shm-usage']}
        if os.environ.get('CHROMIUM_EXECUTABLE'):
            options['executable_path'] = os.environ['CHROMIUM_EXECUTABLE']
        self.browser = await self.playwright.chromium.launch(**options)
        self.context = await self.browser.new_context(viewport={'width': 1440, 'height': 1000})
        await self.context.tracing.start(screenshots=True, snapshots=True)
        self.page = await self.context.new_page()
        self.errors = []
        self.page.on('pageerror', lambda error: self.errors.append(str(error)))

    async def asyncTearDown(self):
        try:
            await self.page.screenshot(path=str(self.artifacts / (self._testMethodName + '.png')), full_page=True)
            await self.context.tracing.stop(path=str(self.artifacts / (self._testMethodName + '.zip')))
        finally:
            await self.context.close()
            await self.browser.close()
            await self.playwright.stop()
            self.proxy.drop_response_path = None
            self.proxy.drop_response_statuses = (201,)
        self.assertEqual([], self.errors)

    async def login(self, role='administrator'):
        await self.page.goto(self.base + '/login')
        await expect(self.page.get_by_role('heading', name='Iniciar sesión', exact=True)).to_be_visible()
        await self.page.get_by_label('Usuario', exact=True).fill('e2e-' + role)
        await self.page.get_by_label('Contraseña', exact=True).fill(support.PASSWORD)
        async with self.page.expect_response(lambda r: r.url.endswith('/api/login')) as event:
            await self.page.get_by_role('button', name='Iniciar sesión', exact=True).click()
        response = await event.value
        self.assertEqual(200, response.status, await response.text())
        await self.page.wait_for_url('**'+{'administrator':'/dashboard','secretary':'/transporte','mechanic':'/taller','driver':'/mis-viajes'}[role])
        await self.page.goto(self.base + '/taller')
        await self.ready()
        await expect(self.page.get_by_role('heading', name='Inicio de Taller', exact=True)).to_be_visible()

    async def ready(self):
        await expect(self.page.get_by_role('button', name='Cerrar sesión', exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Cargando información…', exact=True)).not_to_be_visible()

    async def switch(self, role):
        async with self.page.expect_response(lambda r: r.url.endswith('/api/logout')) as event:
            await self.page.get_by_role('button', name='Cerrar sesión', exact=True).click()
        self.assertEqual(204, (await event.value).status)
        await self.login(role)

    async def choose(self, form, label, value, term):
        await form.get_by_label('Buscar ' + label, exact=True).fill(term)
        await form.get_by_label(label, exact=True).select_option(value)

    async def save(self, form, path, status=201):
        async with self.page.expect_response(lambda r: r.url.endswith('/api/workshop/' + path) and r.request.method != 'GET') as event:
            await form.get_by_role('button', name='Guardar', exact=True).click()
        response = await event.value
        self.assertEqual(status, response.status, await response.text())
        self.assertTrue(response.request.headers.get('x-xsrf-token'))
        if status < 400:
            await expect(self.page.get_by_role('dialog')).not_to_be_visible()
            await self.ready()
        return (await response.json()).get('data')

    async def open_order(self, order):
        await self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Órdenes', exact=False).click()
        await self.ready()
        # Search is deliberately scoped to the current authorized page.
        await self.page.get_by_role('button', name='Ver detalle del registro ' + order, exact=True).click()
        await self.ready()
        await expect(self.page.get_by_role('heading', name='Detalle de orden', exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Orden ' + order, exact=False)).to_be_visible()
        await expect(self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Órdenes', exact=False)).to_have_attribute('aria-current', 'page')

    def snapshot(self):
        return self.command('snapshot', {'asset_id': self.fixture['asset']['id']})

    async def api(self, path, body=None):
        return await self.page.evaluate("""async ({path,body}) => {
            const headers={Accept:'application/json'};
            if(body!==null){headers['Content-Type']='application/json';headers['X-XSRF-TOKEN']=decodeURIComponent(document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice(11)??'');}
            const r=await fetch('/api/workshop/'+path,{method:body===null?'GET':'POST',headers,credentials:'same-origin',...(body===null?{}:{body:JSON.stringify(body)})});
            return {status:r.status,body:await r.json()};
        }""", {'path': path, 'body': body})

    async def report_and_order(self):
        await self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Averías', exact=False).click()
        await self.ready()
        await self.page.get_by_role('button', name='Registrar avería', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar avería')
        await self.choose(form, 'Vehículo', self.fixture['asset']['id'], self.fixture['asset']['code'])
        await form.get_by_label('Descripción de la avería', exact=True).fill('Fuga sintética ' + self.fixture['tag'])
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Reporte E2E aislado')
        report = await self.save(form, 'reports')
        await self.page.get_by_role('button', name='Crear orden para esta avería', exact=True).click()
        form = self.page.get_by_role('form', name='Crear orden')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Orden E2E de reparación')
        order = await self.save(form, 'orders')
        await self.page.get_by_role('button', name='Vincular esta avería a la orden', exact=True).click()
        form = self.page.get_by_role('form', name='Vincular avería')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Vínculo E2E explícito')
        await self.save(form, 'orders/' + order['order_id'] + '/report-links')
        return order['order_id'], report['report_id']

    async def assign(self, order):
        await self.page.get_by_role('button', name='Asignar mecánico', exact=True).click()
        form = self.page.get_by_role('form', name='Asignar mecánico')
        await self.choose(form, 'Empleado mecánico', self.fixture['employee']['id'], self.fixture['employee']['name'])
        await form.get_by_label('Inicio de asignación (Costa Rica)', exact=True).fill(self.fixture['starts_local'])
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Participación real de prueba')
        return await self.save(form, 'orders/' + order + '/mechanic-assignments')

    async def diagnosis(self, order, assignment):
        await self.page.get_by_role('button', name='Registrar diagnóstico', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar diagnóstico')
        await form.get_by_label('Asignación mecánica vigente', exact=True).select_option(assignment['assignment_id'])
        for label, value in [('Problema encontrado', 'Fuga diagnosticada'), ('Causa probable', 'Sello deteriorado'), ('Reparación propuesta', 'Cambiar sello'), ('Motivo / información recibida', 'Inspección E2E')]:
            await form.get_by_label(label, exact=True).fill(value)
        return await self.save(form, 'orders/' + order + '/diagnoses')

    async def scope(self, order, diagnosis, include_catalogs=False):
        await self.page.get_by_role('button', name='Solicitar autorización de alcance', exact=True).click()
        form = self.page.get_by_role('form', name='Solicitar autorización de alcance')
        await self.choose(form, 'Diagnóstico', diagnosis['diagnosis_id'], 'Fuga diagnosticada')
        await form.get_by_label('Descripción del alcance', exact=True).fill('Reparación de sello E2E')
        await form.get_by_label('Trabajo 1', exact=True).fill('Cambiar sello y comprobar fuga')
        if include_catalogs:
            await self.choose(form, 'Proveedor externo 1 (opcional)', self.fixture['supplier']['id'], self.fixture['supplier']['name'])
            await form.get_by_role('button', name='Añadir repuesto', exact=True).click()
            await self.choose(form, 'Repuesto 1', self.fixture['part']['id'], self.fixture['part']['code'])
            await form.get_by_label('Cantidad 1', exact=True).fill('1.000001')
            await form.get_by_label('Trabajo asociado 1', exact=True).select_option('1')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Alcance concreto de prueba')
        return await self.save(form, 'orders/' + order + '/scopes', status=200)

    async def test_full_first_delivery_roles_and_precise_scope(self):
        await self.login('secretary')
        order, report = await self.report_and_order()
        await self.switch('administrator')
        await self.open_order(order)
        assignment = await self.assign(order)
        await self.switch('mechanic')
        await self.open_order(order)
        diagnosis = await self.diagnosis(order, assignment)
        await expect(self.page.get_by_role('button', name='Asignar mecánico', exact=True)).not_to_be_visible()
        await self.switch('administrator')
        await self.open_order(order)
        scope = await self.scope(order, diagnosis, include_catalogs=True)
        await self.page.get_by_role('button', name='Revisar autorización', exact=True).click()
        await self.ready()
        await self.page.get_by_role('button', name='Registrar decisión', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar decisión')
        await form.get_by_label('Decisión', exact=True).select_option('approved')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Aprobación E2E')
        await self.save(form, 'authorization-requests/' + scope['request_id'] + '/decisions', status=200)
        await self.page.get_by_role('button', name='Aplicar autorización', exact=True).click()
        form = self.page.get_by_role('form', name='Aplicar autorización')
        decision = self.snapshot()['decisions'][0]['id']
        await self.save(form, 'decisions/' + decision + '/apply-scope', status=200)
        await expect(self.page.get_by_text('Autorización aplicada.', exact=True)).to_be_visible()
        snapshot = self.snapshot()
        self.assertEqual('authorized', snapshot['orders'][0]['status'])
        self.assertGreater(snapshot['orders'][0]['version'], 1)
        self.assertEqual(report, snapshot['links'][0]['report_id'])
        self.assertEqual('1.000001', snapshot['parts'][0]['quantity'])
        self.assertEqual(self.fixture['supplier']['id'], snapshot['tasks'][0]['supplier_id'])
        for name in ['reports', 'orders', 'diagnoses', 'scopes', 'requests', 'decisions', 'applications']:
            self.assertEqual(1, len(snapshot[name]), name)
        self.assertGreaterEqual(len(snapshot['receipts']), 6)
        await self.open_order(order)
        for width in [1440, 768, 390]:
            await self.page.set_viewport_size({'width': width, 'height': 900})
            await self.page.screenshot(path=str(self.artifacts / f'workshop-order-{width}.png'), full_page=True)
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), width)
            await expect(self.page.get_by_role('heading', name='Detalle de orden', exact=True)).to_be_visible()

    async def test_current_mechanic_can_propose_scope_without_catalog_access(self):
        await self.login('administrator')
        order, _ = await self.report_and_order()
        assignment = await self.assign(order)
        await self.switch('mechanic')
        await self.open_order(order)
        diagnosis = await self.diagnosis(order, assignment)
        scope = await self.scope(order, diagnosis)
        snapshot = self.snapshot()
        self.assertEqual(scope['request_id'], snapshot['requests'][0]['id'])
        self.assertEqual('pending_authorization', snapshot['orders'][0]['status'])
        self.assertEqual([], snapshot['parts'])
        self.assertEqual([], snapshot['decisions'])
        await self.page.get_by_role('button', name='Revisar autorización', exact=True).click()
        await self.ready()
        await expect(self.page.get_by_role('button', name='Registrar decisión', exact=True)).not_to_be_visible()
        denied = await self.api('authorization-requests/' + scope['request_id'] + '/decisions',
                                {'request_key': str(uuid.uuid4()), 'decision': 'approved', 'reason': 'Prueba permisos'})
        self.assertEqual(403, denied['status'], denied['body'])
        self.assertEqual([], self.snapshot()['decisions'])

    async def test_server_validation_lost_response_and_idempotent_retry(self):
        await self.login('administrator')
        await self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Averías', exact=False).click()
        await self.ready()
        await self.page.get_by_role('button', name='Registrar avería', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar avería')
        await self.choose(form, 'Vehículo', self.fixture['asset']['id'], self.fixture['asset']['code'])
        await form.get_by_label('Descripción de la avería', exact=True).fill('   ')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Motivo conservado')
        await self.save(form, 'reports', status=422)
        await expect(form.get_by_label('Motivo / información recibida', exact=True)).to_have_value('Motivo conservado')
        self.assertEqual([], self.snapshot()['reports'])
        await form.get_by_label('Descripción de la avería', exact=True).fill('Reporte confirmado pese a respuesta perdida')
        self.proxy.drop_response_path = '/api/workshop/reports'
        await form.get_by_role('button', name='Guardar', exact=True).click()
        await expect(self.page.get_by_role('button', name='Reintentar la misma operación', exact=True)).to_be_visible()
        self.assertEqual(1, len(self.snapshot()['reports']))
        await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
        await expect(self.page.get_by_role('dialog')).not_to_be_visible()
        await self.ready()
        self.assertEqual(1, len(self.snapshot()['reports']))
        await expect(self.page.get_by_role('heading', name='Reporte confirmado pese a respuesta perdida', exact=True)).to_be_visible()

    async def test_expired_session_clears_private_order_and_requires_login(self):
        await self.login('administrator')
        order, _ = await self.report_and_order()
        await expect(self.page.get_by_role('heading', name='Detalle de orden', exact=True)).to_be_visible()
        support.DocumentModuleE2E.command('expire-sessions', {'role': 'administrator'})
        await self.page.get_by_role('button', name='Actualizar datos', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Acceso a Taller Mecánico', exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Orden ' + order, exact=False)).not_to_be_visible()
        self.assertEqual(1, len(self.snapshot()['orders']))

    async def test_mechanic_historical_visibility_without_write_permission(self):
        await self.login('administrator')
        order, _ = await self.report_and_order()
        assignment = await self.assign(order)
        release = await self.api('mechanic-assignments/' + assignment['assignment_id'] + '/release',
                                 {'request_key': str(uuid.uuid4()), 'ends_at': self.fixture['starts_local'] + ':00-06:00', 'reason': 'Liberación E2E'})
        self.assertEqual(201, release['status'], release['body'])
        await self.switch('mechanic')
        await self.open_order(order)
        await expect(self.page.get_by_role('button', name='Registrar diagnóstico', exact=True)).not_to_be_visible()
        await expect(self.page.get_by_role('button', name='Solicitar autorización de alcance', exact=True)).not_to_be_visible()
        denied = await self.api('orders/' + order + '/diagnoses', {'request_key': str(uuid.uuid4()),
            'mechanic_assignment_id': assignment['assignment_id'], 'problem': 'No autorizado',
            'probable_cause': 'Histórico', 'proposed_repair': 'Ninguna',
            'occurred_at': self.fixture['starts_local'] + ':00-06:00', 'reason': 'Prueba negativa'})
        self.assertIn(denied['status'], [403, 422], denied['body'])
        self.assertEqual([], self.snapshot()['diagnoses'])


if __name__ == '__main__':
    unittest.main()
