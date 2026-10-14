"""Browser tests against the real Blade/Vite shell and intercepted synthetic API responses.
No database connection, migrations, extra dependencies, or persistent browser storage.
Run after npm run build: python3 -m unittest discover -s tests/Frontend -v
"""
import asyncio
import base64
from email import policy
from email.parser import BytesParser
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest
from urllib.parse import parse_qs, urlparse
from urllib.request import urlopen

from playwright.async_api import async_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
ASSET = '10000000-0000-4000-8000-000000000001'
OTHER = '10000000-0000-4000-8000-000000000002'
TYPE = '20000000-0000-4000-8000-000000000001'
DOC = '30000000-0000-4000-8000-000000000001'


def dtype(identifier=TYPE, name='Revisión técnica', expiration=True):
    return dict(id=identifier, name=name, tracks_expiration=expiration,
                required_for_tractor_units=True, required_for_tankers=True)


def document(identifier=DOC, asset=ASSET, validity='expiring_soon', current=True):
    configuration = dtype()
    configuration.pop('id')
    return dict(id=identifier, fleet_asset_id=asset, document_type_id=TYPE, configuration=configuration,
                issued_on='2026-01-01', expires_on='2026-10-20', registered_at='2026-10-11T10:30:00-06:00',
                uploaded_by_id=7, uploaded_by={'id': '7', 'name': 'Ana Documentos'},
                is_current=current, validity=validity)


class FleetDocumentsBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        if not (ROOT / 'public/build/manifest.json').exists():
            raise RuntimeError('Ejecuta npm run build antes de las pruebas de navegador.')
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        cls.port = sock.getsockname()[1]
        sock.close()
        cls.base = f'http://127.0.0.1:{cls.port}'
        cls.artifacts = Path(tempfile.mkdtemp(prefix='transporte-web-tests-', dir='/tmp'))
        (cls.artifacts / 'storage/framework/views').mkdir(parents=True)
        cls.log = (cls.artifacts / 'server.log').open('w')
        env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', SESSION_DRIVER='array',
                   CACHE_STORE='array', DB_CONNECTION='pgsql_testing',
                   TEST_DB_HOST='/tmp/transporte-tests-browser-no-server',
                   TRANSPORTE_BROWSER_ROOT=str(cls.artifacts),
                   TRANSPORTE_BROWSER_KEY='base64:' + base64.b64encode(os.urandom(32)).decode())
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{cls.port}',
                                      str(ROOT / 'tests/Frontend/server.php')],
                                     cwd=ROOT / 'public', env=env, stdout=cls.log, stderr=cls.log)
        import time
        for _ in range(50):
            try:
                with urlopen(cls.base + '/documentacion', timeout=1) as response:
                    if response.status == 200:
                        return
            except OSError:
                time.sleep(.1)
        cls.server.terminate()
        cls.log.close()
        raise RuntimeError(f'No inició el servidor de prueba: {cls.artifacts}')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=10)
        cls.log.close()
        print(f'\nArtefactos de navegador: {cls.artifacts}')

    async def asyncSetUp(self):
        self.playwright = await async_playwright().start()
        self.browser = await self.playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'),
                                                           args=['--no-sandbox', '--disable-dev-shm-usage'])
        self.context = await self.browser.new_context(viewport={'width': 1440, 'height': 1000})
        self.page = await self.context.new_page()
        self.errors = []
        self.page.on('pageerror', lambda error: self.errors.append(str(error)))
        self.role = 'administrator'
        self.assets = [dict(id=ASSET, code='CAB-01', type='tractor_unit'), dict(id=OTHER, code='CIS-01', type='tanker')]
        self.types = [dtype(), dtype('20000000-0000-4000-8000-000000000002', 'Permiso especial', False)]
        self.documents = [document()]
        self.requests = []
        self.forced = {}
        self.delay_asset = None
        self.delay_upload = False
        await self.context.route('**/api/**', self.api)
        await self.context.route('**/api/navigation', lambda route: FleetDocumentsBrowserTest.navigation(self, route))

    async def navigation(self, route):
        """Synthetic navigation contract only; actual Gates are covered by E2E."""
        links = [('dashboard','Inicio','/dashboard',['administrator']),
                 ('transport','Transporte','/transporte',['administrator','secretary']),
                 ('driver','Mis viajes','/mis-viajes',['administrator','driver']),
                 ('workshop','Taller','/taller',['administrator','secretary','mechanic']),
                 ('documents','Documentación','/documentacion',['administrator','secretary']),
                 ('fleet','Flota','/administracion/flota',['administrator']),
                 ('personnel','Personal','/administracion/personal',['administrator']),
                 ('catalogs','Catálogos','/administracion/catalogos',['administrator']),
                 ('inventory','Inventario','/inventario',['administrator','secretary','mechanic']),
                 ('purchasing','Compras','/compras',['administrator','secretary']),
                 ('billing','Facturación','/facturacion',['administrator','secretary']),
                 ('expenses','Gastos','/gastos',['administrator','secretary'])]
        if not self.role:
            await route.fulfill(status=401, json={'message':'Sesión expirada'})
            return
        home={'administrator':'/dashboard','secretary':'/transporte','mechanic':'/taller','driver':'/mis-viajes'}.get(self.role,'/login')
        await route.fulfill(json={'data':{'home':home,'modules':[{'key':k,'label':l,'href':h} for k,l,h,roles in links if self.role in roles]}})

    async def asyncTearDown(self):
        await self.page.screenshot(path=str(self.artifacts / f'{self._testMethodName}.png'), full_page=True)
        await self.context.close()
        await self.browser.close()
        await self.playwright.stop()
        self.assertEqual([], self.errors)

    def pagination(self, records, query):
        per_page = int(query.get('per_page', ['25'])[0])
        number = int(query.get('page', ['1'])[0])
        return {'data': records[(number - 1) * per_page:number * per_page], 'meta': {
            'current_page': number, 'last_page': max(1, (len(records) + per_page - 1) // per_page),
            'total': len(records), 'per_page': per_page}}

    async def api(self, route):
        request = route.request
        parsed = urlparse(request.url)
        path, method = parsed.path, request.method
        query = parse_qs(parsed.query)
        self.requests.append((method, path, request.headers))
        if (method, path) in self.forced:
            status, data = self.forced[(method, path)]
            await route.fulfill(status=status, json=data)
            return
        if path == '/api/csrf':
            await route.fulfill(status=204, headers={'Set-Cookie': 'XSRF-TOKEN=test-csrf; Path=/; SameSite=Lax'})
            return
        if path == '/api/session':
            await route.fulfill(status=200 if self.role else 401, json={'data': {'id': '7', 'login': 'ana', 'roles': [self.role]}} if self.role else {})
            return
        if path == '/api/login':
            payload = request.post_data_json
            self.role = payload['login']
            await route.fulfill(json={'data': {'id': '7', 'login': self.role, 'roles': [self.role]}})
            return
        if path == '/api/logout':
            self.role = None
            await route.fulfill(status=204)
            return
        if path == '/api/fleet-assets':
            await route.fulfill(json=self.pagination(self.assets, query))
            return
        if path.startswith('/api/document-types'):
            if method == 'GET':
                await route.fulfill(json=self.pagination(self.types, query))
                return
            if self.role != 'administrator':
                await route.fulfill(status=403, json={})
                return
            payload = request.post_data_json
            if not payload['name'].strip():
                await route.fulfill(status=422, json={'errors': {'name': ['El nombre no puede estar vacío.']}})
                return
            if method == 'POST':
                value = dict(id='20000000-0000-4000-8000-000000000009', **payload)
                self.types.append(value)
            else:
                value = next(t for t in self.types if t['id'] == path.split('/')[-1])
                value.update(payload)
            await route.fulfill(status=201 if method == 'POST' else 200, json={'data': value})
            return
        if path.endswith('/download'):
            await route.fulfill(body=(ROOT / 'tests/Fixtures/fleet-documents/valid.pdf').read_bytes(),
                                headers={'Content-Type': 'application/pdf', 'Cache-Control': 'no-store'})
            return
        if method == 'POST' and '/documents' in path:
            if self.delay_upload:
                await asyncio.sleep(.3)
            content_type = request.headers['content-type']
            message = BytesParser(policy=policy.default).parsebytes(
                f'Content-Type: {content_type}\r\nMIME-Version: 1.0\r\n\r\n'.encode() + request.post_data_buffer)
            payload = {part.get_param('name', header='content-disposition'): part.get_payload(decode=True)
                       for part in message.iter_parts()}
            renewed = path.endswith('/renew')
            if renewed:
                self.assertNotIn('document_type_id', payload)
                type_id = next(d['document_type_id'] for d in self.documents if d['id'] == path.split('/')[-2])
            else:
                type_id = payload['document_type_id'].decode()
            kind = next(t for t in self.types if t['id'] == type_id)
            if kind['tracks_expiration'] and not payload.get('expires_on'):
                await route.fulfill(status=422, json={'errors': {'expires_on': ['Vencimiento obligatorio.']}})
                return
            for item in self.documents:
                if item['fleet_asset_id'] == ASSET and item['document_type_id'] == type_id:
                    item['is_current'] = False
            value = document('30000000-0000-4000-8000-000000000009', validity='valid')
            value['configuration'] = {k: v for k, v in kind.items() if k != 'id'}
            value['document_type_id'] = type_id
            self.documents.append(value)
            await route.fulfill(status=201, json={'data': value})
            return
        if '/documents' in path:
            selected = path.split('/')[3] if path.startswith('/api/fleet-assets/') else query.get('fleet_asset_id', [None])[0]
            if self.delay_asset == selected:
                await asyncio.sleep(.3)
            if path.endswith('/missing'):
                present = {d['document_type_id'] for d in self.documents if d['fleet_asset_id'] == selected and d['is_current']}
                values = [t for t in self.types if t['id'] not in present]
            else:
                values = [d for d in self.documents if not selected or d['fleet_asset_id'] == selected]
                if not path.endswith('/history'):
                    values = [d for d in values if d['is_current']]
                if 'validity' in query:
                    values = [d for d in values if d['validity'] == query['validity'][0]]
                if 'document_type_id' in query:
                    values = [d for d in values if d['document_type_id'] == query['document_type_id'][0]]
            await route.fulfill(json=self.pagination(values, query))
            return
        raise AssertionError(f'Endpoint inesperado: {method} {path}')

    async def enter(self, role='administrator', width=1440, height=1000):
        self.role = role
        await self.page.set_viewport_size({'width': width, 'height': height})
        await self.page.goto(self.base + '/documentacion')
        if role:
            await expect(self.page.get_by_role('button', name='Cerrar sesión')).to_be_visible()
        else:
            await expect(self.page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()

    async def select_asset(self, code='CAB-01'):
        await self.page.get_by_role('button', name=f'{code} ').click()
        await expect(self.page.get_by_role('heading', name=f'Documentación de {code}')).to_be_visible()
        await expect(self.page.get_by_text('Cargando documentos…')).not_to_be_visible()

    async def upload_form(self, renewal=False):
        await self.page.get_by_role('button', name='Renovar Revisión técnica' if renewal else 'Registrar documento', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Renovar documento' if renewal else 'Registrar documento', exact=True)).to_be_visible()
        if not renewal:
            await self.page.get_by_label('Tipo documental', exact=True).select_option(TYPE)
        await self.page.get_by_label('Archivo documental').set_input_files(str(ROOT / 'tests/Fixtures/fleet-documents/valid.pdf'))
        await self.page.get_by_label('Fecha de vencimiento', exact=False).fill('2027-01-01')

    async def test_login_logout_and_role_restrictions(self):
        await self.enter(None)
        await self.page.get_by_label('Usuario', exact=True).fill('secretary')
        await self.page.get_by_label('Contraseña', exact=True).fill('synthetic-password')
        await self.page.get_by_role('button', name='Iniciar sesión').click()
        await expect(self.page.get_by_role('button', name='Documentos por activo')).to_be_visible()
        await self.page.get_by_role('button', name='Cerrar sesión').click()
        await expect(self.page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()
        self.assertTrue(any(headers.get('x-xsrf-token') == 'test-csrf' for method, _, headers in self.requests if method == 'POST'))
        await self.enter('driver')
        await expect(self.page.get_by_role('heading', name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Documentos por activo')).to_have_count(0)
        self.assertFalse(any(path == '/api/fleet-assets' for _, path, _ in self.requests[-2:]))

    async def test_type_creation_configuration_and_secretary_read_only(self):
        await self.enter()
        await self.page.get_by_role('button', name='Tipos documentales', exact=True).click()
        await self.page.get_by_role('button', name='Crear tipo documental').click()
        await self.page.get_by_label('Nombre', exact=True).fill(' ')
        await self.page.get_by_role('button', name='Guardar tipo').click()
        await expect(self.page.get_by_text('El nombre no puede estar vacío.')).to_be_visible()
        await self.page.get_by_label('Nombre', exact=True).fill('Póliza')
        await self.page.get_by_label('Requiere vencimiento').check()
        await self.page.get_by_role('button', name='Guardar tipo').click()
        await expect(self.page.get_by_text('Tipo documental creado.', exact=True)).to_be_visible()
        await self.page.get_by_role('button', name='Modificar Póliza').click()
        await self.page.get_by_label('Nombre', exact=True).fill('Póliza actualizada')
        await self.page.get_by_role('button', name='Guardar tipo').click()
        await expect(self.page.get_by_role('button', name='Modificar Póliza actualizada')).to_be_visible()
        await self.enter('secretary')
        await self.page.get_by_role('button', name='Tipos documentales', exact=True).click()
        await expect(self.page.get_by_role('rowheader', name='Póliza actualizada', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button', name='Crear tipo documental')).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Modificar', exact=True)).to_have_count(0)

    async def test_secretary_renewal_history_download_and_current_distinction(self):
        self.documents[0]['validity'] = 'expired'
        await self.enter('secretary')
        await self.select_asset()
        await expect(self.page.get_by_role('cell', name='Actual', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Vencido', exact=True)).to_be_visible()
        await self.upload_form(renewal=True)
        await self.page.get_by_role('button', name='Guardar renovación').click()
        await expect(self.page.get_by_text('Renovación registrada. La versión anterior permanece en el historial.')).to_be_visible()
        await self.page.get_by_role('button', name='Historial de Revisión técnica').click()
        await expect(self.page.get_by_role('cell', name='Anterior', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button', name='Renovar Revisión técnica')).to_have_count(0)
        self.assertFalse(any(method in ['DELETE', 'PUT'] for method, _, _ in self.requests))
        async with self.page.expect_download() as event:
            await self.page.get_by_role('button', name='Descargar Revisión técnica').last.click()
        received = await event.value
        self.assertTrue(received.suggested_filename.endswith('.pdf'))
        self.assertEqual((ROOT / 'tests/Fixtures/fleet-documents/valid.pdf').read_bytes(), Path(await received.path()).read_bytes())
        await expect(self.page.get_by_role('cell', name='Ana Documentos', exact=False).first).to_be_visible()

    async def test_registration_and_422_preserve_file_and_form(self):
        await self.enter('secretary')
        await self.select_asset()
        await self.upload_form()
        endpoint = ('POST', f'/api/fleet-assets/{ASSET}/documents')
        self.forced[endpoint] = (422, {'errors': {'file': ['El contenido del archivo no es válido.']}})
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_text('El contenido del archivo no es válido.')).to_be_visible()
        self.assertEqual('valid.pdf', await self.page.get_by_label('Archivo documental').evaluate('(e) => e.files[0].name'))
        await expect(self.page.get_by_label('Fecha de vencimiento', exact=False)).to_have_value('2027-01-01')
        self.forced.pop(endpoint)
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_text('Documento registrado correctamente.', exact=True)).to_be_visible()
        self.assertEqual(2, len(self.documents))

    async def test_alert_filters_and_missing_documents(self):
        self.documents += [dict(document('30000000-0000-4000-8000-000000000002', validity='expired'), document_type_id=self.types[1]['id'])]
        await self.enter()
        await self.select_asset()
        await self.page.get_by_label('Vigencia', exact=True).select_option('expired')
        await expect(self.page.get_by_role('cell', name='Vencido', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Próximo a vencer', exact=True)).to_have_count(0)
        await self.page.get_by_label('Vigencia', exact=True).select_option('expiring_soon')
        await expect(self.page.get_by_role('cell', name='Próximo a vencer', exact=True)).to_be_visible()
        await expect(self.page.get_by_text('No faltan documentos obligatorios.')).to_be_visible()
        self.documents = []
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_role('button', name='Registrar Permiso especial')).to_be_visible()
        await self.page.get_by_role('button', name='Registrar Permiso especial').click()
        await expect(self.page.get_by_label('Tipo documental', exact=True)).to_have_value(self.types[1]['id'])

    async def test_session_expiration_clears_documental_content(self):
        await self.enter()
        await self.select_asset()
        self.forced[('GET', f'/api/fleet-assets/{ASSET}/documents')] = (401, {})
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Documentación de CAB-01')).to_have_count(0)
        await expect(self.page.get_by_text('Tu sesión terminó. Inicia sesión nuevamente.')).to_be_visible()

    async def test_role_revocation_removes_privileged_screens(self):
        await self.enter()
        await self.select_asset()
        self.role = 'driver'
        self.forced[('GET', f'/api/fleet-assets/{ASSET}/documents')] = (403, {})
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Registrar documento', exact=True)).to_have_count(0)

    async def test_csrf_expiration_requires_explicit_refresh_without_auto_resubmit(self):
        await self.enter()
        await self.select_asset()
        await self.upload_form()
        endpoint = ('POST', f'/api/fleet-assets/{ASSET}/documents')
        self.forced[endpoint] = (419, {})
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await self.page.get_by_role('button', name='Actualizar sesión de seguridad').click()
        await expect(self.page.get_by_text('Sesión de seguridad actualizada. Puedes volver a enviar el formulario.')).to_be_visible()
        self.assertEqual(1, sum((method, path) == endpoint for method, path, _ in self.requests))
        await expect(self.page.get_by_label('Fecha de vencimiento', exact=False)).to_have_value('2027-01-01')
        self.forced.pop(endpoint)
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_text('Documento registrado correctamente.', exact=True)).to_be_visible()

    async def test_missing_download_and_server_errors_do_not_expose_private_details(self):
        await self.enter()
        await self.select_asset()
        self.forced[('GET', f'/api/documents/{DOC}/download')] = (404, {'storage_key': 'private/secret.pdf'})
        await self.page.get_by_role('button', name='Descargar Revisión técnica').click()
        await expect(self.page.get_by_text('El activo, documento o archivo solicitado ya no está disponible.')).to_be_visible()
        await expect(self.page.get_by_text('private/secret.pdf')).to_have_count(0)
        await self.upload_form()
        self.forced[('POST', f'/api/fleet-assets/{ASSET}/documents')] = (500, {'message': 'SQL secret path /storage/private'})
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_text('No se pudo completar la operación. Consulta los documentos actuales antes de volver a enviar una carga.').first).to_be_visible()
        await expect(self.page.get_by_text('SQL secret path')).to_have_count(0)
        self.assertEqual(1, len(self.documents))

    async def test_loading_prevents_duplicate_submission(self):
        await self.enter()
        await self.select_asset()
        await self.upload_form()
        self.delay_upload = True
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_role('button', name='Guardando documento…')).to_be_disabled()
        await expect(self.page.get_by_role('button', name='Tipos documentales', exact=True)).to_be_disabled()
        await expect(self.page.get_by_text('Documento registrado correctamente.', exact=True)).to_be_visible()
        self.assertEqual(1, sum(method == 'POST' and path == f'/api/fleet-assets/{ASSET}/documents' for method, path, _ in self.requests))

    async def test_late_responses_do_not_replace_new_asset(self):
        self.documents.append(document('30000000-0000-4000-8000-000000000002', asset=OTHER, validity='no_expiration'))
        self.delay_asset = ASSET
        await self.enter()
        await self.page.get_by_role('button', name='CAB-01 ').click()
        await self.page.get_by_role('button', name='CIS-01 ').click()
        await expect(self.page.get_by_role('heading', name='Documentación de CIS-01')).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Sin vencimiento', exact=True)).to_be_visible()
        await asyncio.sleep(.4)
        await expect(self.page.get_by_role('cell', name='Próximo a vencer', exact=True)).to_have_count(0)

    async def test_asset_and_type_pagination(self):
        self.assets += [dict(id=f'10000000-0000-4000-8000-{number:012}', code=f'CAB-{number:02}', type='tractor_unit') for number in range(3, 14)]
        self.types += [dtype(f'20000000-0000-4000-8000-{number:012}', f'Tipo {number}') for number in range(3, 29)]
        await self.enter()
        navigation = self.page.get_by_role('navigation', name='Páginas de activos')
        await navigation.get_by_role('button', name='Siguiente').click()
        await expect(self.page.get_by_role('button', name='CAB-13 ')).to_be_visible()
        await self.page.get_by_role('button', name='Tipos documentales', exact=True).click()
        await self.page.get_by_role('navigation', name='Páginas de tipos documentales').get_by_role('button', name='Siguiente').click()
        await expect(self.page.get_by_role('rowheader', name='Tipo 28', exact=True)).to_be_visible()

    async def test_responsive_keyboard_and_no_persistent_private_storage(self):
        for width, height in [(390, 844), (768, 1024), (1440, 1000)]:
            await self.enter('secretary', width, height)
            await self.select_asset()
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
            await self.page.screenshot(path=str(self.artifacts / f'responsive-{width}.png'), full_page=True)
            await self.page.get_by_role('button', name='Registrar documento', exact=True).click()
            await expect(self.page.get_by_label('Tipo documental', exact=True)).to_be_focused()
            await self.page.keyboard.press('Tab')
            await expect(self.page.get_by_label('Archivo documental')).to_be_focused()
            await self.page.get_by_role('button', name='Cancelar', exact=True).click()
            await expect(self.page.get_by_role('button', name='Registrar documento', exact=True)).to_be_focused()
        self.assertEqual(0, await self.page.evaluate('localStorage.length'))
        self.assertEqual(0, await self.page.evaluate('sessionStorage.length'))
        self.assertEqual([], await self.page.evaluate('indexedDB.databases()'))

    async def test_history_uses_frozen_type_configuration(self):
        old = self.documents[0]
        old['configuration']['name'] = 'Nombre histórico'
        old['configuration']['tracks_expiration'] = False
        old['validity'] = 'no_expiration'
        old['is_current'] = False
        self.documents.append(document('30000000-0000-4000-8000-000000000002'))
        self.types[0]['name'] = 'Nombre actual'
        await self.enter()
        await self.select_asset()
        await self.page.get_by_role('button', name='Historial completo').click()
        await expect(self.page.get_by_role('rowheader', name='Nombre histórico', exact=False)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Sin vencimiento', exact=True)).to_be_visible()
        historical = self.page.get_by_role('rowheader', name='Nombre histórico', exact=False)
        await historical.get_by_text('Requisitos al registro').click()
        await expect(historical.get_by_text('Vencimiento: opcional', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button', name='Modificar', exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Eliminar', exact=True)).to_have_count(0)


if __name__ == '__main__':
    unittest.main(verbosity=2)
