"""Real Chromium -> Blade/Vue -> Laravel/session/CSRF -> PostgreSQL 18/private disk.
Run only with: bash tests/run-postgres.sh --e2e
No route.fulfill, mock API, SQLite, development .env, or persistent application database.
"""
import asyncio
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import time
import unittest
from urllib.parse import unquote
from urllib.request import urlopen

from playwright.async_api import async_playwright, expect

from network import FaultProxy

ROOT = Path(__file__).resolve().parents[2]
PASSWORD = 'E2e-only-password-42'  # Public, synthetic test credentials, never application credentials.
UNKNOWN = '00000000-0000-4000-8000-000000000000'


class DocumentModuleE2E(unittest.IsolatedAsyncioTestCase):
    counter = 0

    @classmethod
    def command(cls, action, data=None):
        result = subprocess.run(['php', str(ROOT / 'tests/E2E/console.php'), action],
                                input=json.dumps(data or {}), text=True, capture_output=True,
                                cwd=ROOT, check=True)
        return json.loads(result.stdout)

    @classmethod
    def setUpClass(cls):
        host = os.environ.get('TEST_DB_HOST', '')
        cls.workspace = Path(os.environ.get('TRANSPORTE_E2E_ROOT', ''))
        if os.environ.get('TEST_DB_ISOLATED') != '1' or not host.startswith('/tmp/transporte-tests-') or cls.workspace != Path(host) / 'e2e':
            raise RuntimeError('Ejecuta exclusivamente bash tests/run-postgres.sh --e2e.')
        if not (ROOT / 'public/build/manifest.json').exists() or (ROOT / 'public/hot').exists():
            raise RuntimeError('Ejecuta npm run build y detén Vite dev antes de la suite E2E.')
        cls.artifacts = cls.workspace / 'browser'
        cls.artifacts.mkdir()
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        cls.port = sock.getsockname()[1]
        sock.close()
        cls.base = f'http://127.0.0.1:{cls.port}'
        cls.log = (cls.workspace / 'http.log').open('w')
        env = dict(os.environ, PHP_CLI_SERVER_WORKERS='4')
        cls.server = subprocess.Popen(['php', '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=14M',
                                      '-S', f'127.0.0.1:{cls.port}', str(ROOT / 'tests/E2E/server.php')],
                                     cwd=ROOT / 'public', env=env, stdout=cls.log, stderr=cls.log,
                                     start_new_session=True)
        try:
            for _ in range(50):
                try:
                    with urlopen(cls.base + '/documentacion', timeout=1) as response:
                        if response.status == 200 and b'fleet-documents-app' in response.read():
                            cls.proxy = FaultProxy(cls.port)
                            cls.base = f'http://127.0.0.1:{cls.proxy.server_port}'
                            return
                except OSError:
                    time.sleep(.1)
            raise RuntimeError(f'No inició Laravel E2E. Registro: {cls.workspace / "http.log"}')
        except BaseException:
            os.killpg(cls.server.pid, signal.SIGTERM)
            cls.server.wait(timeout=10)
            cls.log.close()
            if hasattr(cls, "proxy"):
                cls.proxy.close()
            raise

    @classmethod
    def tearDownClass(cls):
        os.killpg(cls.server.pid, signal.SIGTERM)
        cls.server.wait(timeout=10)
        cls.log.close()
        cls.proxy.close()
        print(f'\nCapturas y trazas E2E reales: {cls.artifacts}')

    async def asyncSetUp(self):
        asyncio.get_running_loop().slow_callback_duration = 2.0
        type(self).counter += 1
        self.fixture = self.command('fixture', {'tag': f'E2E-{9999 - self.counter:04}'})
        self.asset = self.fixture['tractor']
        self.tanker = self.fixture['tanker']
        self.kind = self.fixture['types'][0]
        self.no_expiration = self.fixture['types'][1]
        self.playwright = await async_playwright().start()
        self.browser = await self.playwright.chromium.launch(executable_path='/usr/bin/chromium',
                                                           args=['--no-sandbox', '--disable-dev-shm-usage'])
        self.context = await self.browser.new_context(viewport={'width': 1440, 'height': 1000})
        await self.context.tracing.start(screenshots=True, snapshots=True)
        self.page = await self.context.new_page()
        self.errors = []
        self.posts = []
        self.page.on('pageerror', lambda error: self.errors.append(str(error)))
        self.page.on('request', lambda req: self.posts.append(req.url) if req.method == 'POST' and '/documents' in req.url else None)

    async def asyncTearDown(self):
        try:
            await self.page.screenshot(path=str(self.artifacts / f'{self._testMethodName}.png'), full_page=True)
            await self.context.tracing.stop(path=str(self.artifacts / f'{self._testMethodName}.zip'))
        finally:
            await self.context.close()
            await self.browser.close()
            await self.playwright.stop()
            self.proxy.drop_response_path = None
            self.proxy.strip_origin_path = None
            self.proxy.missing_download_path = None
        self.assertEqual([], self.errors)

    async def sign_in(self, role='administrator', context=None, page=None):
        context = context or self.context
        page = page or self.page
        await page.goto(self.base + '/documentacion')
        await expect(page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()
        await page.get_by_label('Usuario', exact=True).fill('e2e-' + role)
        await page.get_by_label('Contraseña', exact=True).fill(PASSWORD)
        async with page.expect_response(lambda r: r.url.endswith('/api/login') and r.request.method == 'POST') as event:
            await page.get_by_role('button', name='Iniciar sesión').click()
        response = await event.value
        self.assertEqual(200, response.status, await response.text())
        await expect(page.get_by_role('button', name='Cerrar sesión')).to_be_visible()
        return response

    async def headers(self, context=None):
        context = context or self.context
        response = await context.request.get(self.base + '/api/csrf')
        self.assertEqual(204, response.status)
        cookies = await context.cookies()
        token = next(cookie['value'] for cookie in cookies if cookie['name'] == 'XSRF-TOKEN')
        return {'Accept': 'application/json', 'X-XSRF-TOKEN': unquote(token)}

    async def select(self, asset=None):
        asset = asset or self.asset
        await self.page.get_by_role('button', name=asset['code'] + ' ').click()
        await expect(self.page.get_by_role('heading', name='Documentación de ' + asset['code'])).to_be_visible()
        await expect(self.page.get_by_text('Cargando documentos…')).not_to_be_visible()

    async def form(self, kind=None, expires='2026-10-27', extension='pdf', renewal=False):
        kind = kind or self.kind
        if renewal:
            await self.page.get_by_role('button', name='Renovar ' + kind['name'], exact=True).click()
        else:
            await self.page.get_by_role('button', name='Registrar documento', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Renovar documento' if renewal else 'Registrar documento', exact=True)).to_be_visible()
        if not renewal:
            await self.page.get_by_label('Tipo documental', exact=True).select_option(kind['id'])
        await self.page.get_by_label('Archivo documental').set_input_files(str(ROOT / f'tests/Fixtures/fleet-documents/valid.{extension}'))
        if expires:
            await self.page.get_by_label('Fecha de vencimiento', exact=False).fill(expires)

    async def submit(self, renewal=False):
        async with self.page.expect_response(lambda r: '/documents' in r.url and r.request.method == 'POST') as event:
            await self.page.get_by_role('button', name='Guardar renovación' if renewal else 'Guardar documento', exact=True).click()
        response = await event.value
        self.assertEqual(201, response.status, await response.text())
        await expect(self.page.get_by_text('Renovación registrada. La versión anterior permanece en el historial.' if renewal else 'Documento registrado correctamente.', exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Cargando documentos…')).not_to_be_visible()
        return (await response.json())['data']

    def inspect(self, asset=None):
        return self.command('inspect', {'asset_id': (asset or self.asset)['id']})

    async def test_authentication_roles_logout_and_private_access(self):
        unauthorized = await self.context.request.get(self.base + '/api/documents', headers={'Accept': 'application/json'})
        self.assertEqual(401, unauthorized.status)
        await self.sign_in()
        await self.select()
        await self.form()
        created = await self.submit()
        async with self.page.expect_response(lambda r: r.url.endswith('/api/logout')) as event:
            await self.page.get_by_role('button', name='Cerrar sesión').click()
        self.assertEqual(204, (await event.value).status)
        await expect(self.page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()
        denied = await self.context.request.get(self.base + '/api/documents/' + created['id'] + '/download')
        self.assertEqual(401, denied.status)
        for role in ['driver', 'mechanic']:
            context = await self.browser.new_context()
            page = await context.new_page()
            await self.sign_in(role, context, page)
            await expect(page.get_by_role('heading', name='Acceso no autorizado')).to_be_visible()
            self.assertEqual(403, (await context.request.get(self.base + '/api/fleet-assets')).status)
            self.assertEqual(403, (await context.request.get(self.base + '/api/documents/' + created['id'] + '/download')).status)
            await context.close()
        cookies = await self.context.cookies()
        self.assertTrue(any(c['name'] == 'transporte_e2e_session' and c['httpOnly'] for c in cookies))

    async def test_administrator_creates_types_secretary_consults_without_write_permissions(self):
        await self.sign_in()
        await self.page.get_by_role('button', name='Tipos documentales', exact=True).click()
        await self.page.get_by_role('button', name='Crear tipo documental').click()
        name = self.asset['code'] + '-POLIZA'
        await self.page.get_by_label('Nombre', exact=True).fill(name)
        await self.page.get_by_label('Requiere vencimiento').check()
        await self.page.get_by_label('Obligatorio para cabezales').check()
        async with self.page.expect_response(lambda r: r.url.endswith('/api/document-types') and r.request.method == 'POST') as event:
            await self.page.get_by_role('button', name='Guardar tipo').click()
        self.assertEqual(201, (await event.value).status)
        await expect(self.page.get_by_text('Tipo documental creado.', exact=True)).to_be_visible()
        await self.page.get_by_role('button', name='Cerrar sesión').click()
        await self.sign_in('secretary')
        await self.page.get_by_role('button', name='Tipos documentales', exact=True).click()
        await expect(self.page.get_by_role('rowheader', name=name, exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button', name='Crear tipo documental')).to_have_count(0)
        denied = await self.context.request.post(self.base + '/api/document-types', headers=await self.headers(), data={})
        self.assertEqual(403, denied.status)
        denied = await self.context.request.post(self.base + '/api/tractor-units', headers=await self.headers(), data={'code': 'FORBIDDEN'})
        self.assertEqual(403, denied.status)

    async def test_secretary_uploads_pdf_jpg_png_in_tractor_and_tanker(self):
        await self.sign_in('secretary')
        for asset, extension, kind in [(self.asset, 'pdf', self.kind), (self.tanker, 'jpg', self.kind), (self.tanker, 'png', self.no_expiration)]:
            await self.select(asset)
            await self.form(kind, expires='2026-10-27' if kind['tracks_expiration'] else None, extension=extension)
            created = await self.submit()
            self.assertEqual(asset['id'], created['fleet_asset_id'])
            self.assertTrue(created['is_current'])
            snapshot = self.inspect(asset)
            self.assertEqual(hashlib.sha256((ROOT / f'tests/Fixtures/fleet-documents/valid.{extension}').read_bytes()).hexdigest(), snapshot['files'][created['id']])
            self.assertEqual(0, snapshot['pending'])
            self.assertNotIn('storage_key', created)

    async def test_renewal_history_snapshot_configuration_and_authorized_download(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form(self.no_expiration, expires=None)
        previous = await self.submit()
        original = self.inspect()
        admin_context = await self.browser.new_context()
        admin_page = await admin_context.new_page()
        await self.sign_in('administrator', admin_context, admin_page)
        response = await admin_context.request.patch(self.base + '/api/document-types/' + self.no_expiration['id'],
                                                    headers=await self.headers(admin_context),
                                                    data={'name': self.no_expiration['name'] + '-ACTUAL', 'tracks_expiration': True})
        self.assertEqual(200, response.status)
        await admin_context.close()
        await self.form(self.no_expiration, expires='2026-10-27', renewal=True)
        current = await self.submit(renewal=True)
        self.assertTrue(current['configuration']['tracks_expiration'])
        snapshot = self.inspect()
        self.assertEqual(1, len(snapshot['current']))
        self.assertEqual(current['id'], snapshot['current'][0]['document_id'])
        self.assertEqual(original['documents'][0], next(d for d in snapshot['documents'] if d['id'] == previous['id']))
        self.assertEqual(original['snapshots'][0], next(d for d in snapshot['snapshots'] if d['document_id'] == previous['id']))
        await self.page.get_by_role('button', name='Historial completo').click()
        await expect(self.page.get_by_role('cell', name='Anterior', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Sin vencimiento', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('rowheader', name=self.no_expiration['name'] + '-ACTUAL', exact=False)).to_be_visible()
        async with self.page.expect_download() as event:
            await self.page.get_by_role('button', name='Descargar ' + self.no_expiration['name'], exact=True).click()
        downloaded = await event.value
        self.assertEqual((ROOT / 'tests/Fixtures/fleet-documents/valid.pdf').read_bytes(), Path(await downloaded.path()).read_bytes())
        self.assertEqual({'documents': '23514', 'document_configuration_snapshots': '23514'}, self.command('guards', {'id': previous['id']}))
        private_key = original['documents'][0]['storage_key']
        for path in ['/storage/' + private_key, '/storage/fleet-documents-private/' + private_key, '/build/../storage/' + private_key]:
            self.assertIn((await self.context.request.get(self.base + path)).status, [403, 404])

    async def test_alert_boundaries_and_costa_rica_calendar_end_of_expiration_day(self):
        await self.sign_in()
        await self.select()
        # Frozen server time is 2026-10-12 05:59:59 UTC = Oct 11 23:59:59 Costa Rica.
        await self.form(expires='2026-10-26')  # exactly +15 days
        edge = await self.submit()
        self.assertEqual('expiring_soon', edge['validity'])
        await self.form(self.no_expiration, expires=None)
        none = await self.submit()
        self.assertEqual('no_expiration', none['validity'])
        await self.page.get_by_label('Vigencia', exact=True).select_option('expiring_soon')
        await expect(self.page.get_by_role('cell', name='Próximo a vencer', exact=True)).to_be_visible()
        await self.form(expires='2026-10-27', renewal=True)  # +16 days
        self.assertEqual('valid', (await self.submit(renewal=True))['validity'])
        await self.page.get_by_label('Vigencia', exact=True).select_option('')
        await self.form(expires='2026-10-11', renewal=True)  # valid for the entire local date
        today = await self.submit(renewal=True)
        self.assertEqual('expiring_soon', today['validity'])
        self.command('clock', {'at': '2026-10-12T06:00:00+00:00'})
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await self.page.get_by_label('Vigencia', exact=True).select_option('expired')
        await expect(self.page.get_by_role('cell', name='Vencido', exact=True)).to_be_visible()
        response = await self.context.request.get(self.base + '/api/documents/' + today['id'])
        self.assertEqual('expired', (await response.json())['data']['validity'])
        await self.page.get_by_label('Vigencia', exact=True).select_option('no_expiration')
        await expect(self.page.get_by_role('cell', name='Sin vencimiento', exact=True)).to_be_visible()
        # Empty tanker still lists both compulsory types; an expired current is not missing.
        missing = await self.context.request.get(self.base + '/api/fleet-assets/' + self.asset['id'] + '/documents/missing')
        self.assertFalse(any(t['id'] in [self.kind['id'], self.no_expiration['id']] for t in (await missing.json())['data']))
        await self.select(self.tanker)
        await expect(self.page.get_by_role('button', name='Registrar ' + self.kind['name'], exact=True)).to_be_visible()

    async def test_invalid_file_oversize_and_dates_return_real_422_without_side_effects(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        file = self.page.get_by_label('Archivo documental')
        await file.set_input_files({'name': 'invalid.pdf', 'mimeType': 'application/pdf', 'buffer': b'not a PDF'})
        async with self.page.expect_response(lambda r: r.request.method == 'POST' and '/documents' in r.url) as event:
            await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        self.assertEqual(422, (await event.value).status)
        await expect(file).to_have_attribute('aria-invalid', 'true')
        content = b'%PDF-1.4\n' + b' ' * 10_000_000 + b'%%EOF'
        await file.set_input_files({'name': 'oversize.pdf', 'mimeType': 'application/pdf', 'buffer': content})
        async with self.page.expect_response(lambda r: r.request.method == 'POST' and '/documents' in r.url) as event:
            await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        self.assertEqual(422, (await event.value).status)
        await expect(self.page.get_by_text('El archivo debe contener entre 1 y 10.000.000 bytes.')).to_be_visible()
        await file.set_input_files(str(ROOT / 'tests/Fixtures/fleet-documents/valid.pdf'))
        await self.page.get_by_label('Fecha de emisión', exact=False).fill('2027-04-01')
        await self.page.get_by_label('Fecha de vencimiento', exact=False).fill('2027-03-01')
        async with self.page.expect_response(lambda r: r.request.method == 'POST' and '/documents' in r.url) as event:
            await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        self.assertEqual(422, (await event.value).status)
        await expect(self.page.get_by_text('El vencimiento no puede ser anterior a la emisión.')).to_be_visible()
        invalid_calendar = await self.page.evaluate('''async ({asset, type}) => {
            const body = new FormData();
            body.append('document_type_id', type);
            body.append('expires_on', '2027-02-30');
            body.append('file', document.getElementById('upload-file').files[0]);
            const token = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN=')).slice(11);
            const response = await fetch(`/api/fleet-assets/${asset}/documents`, {method: 'POST', body,
                headers: {Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token)}});
            return {status: response.status, body: await response.json()};
        }''', {'asset': self.asset['id'], 'type': self.kind['id']})
        self.assertEqual(422, invalid_calendar['status'])
        self.assertIn('expires_on', invalid_calendar['body']['errors'])
        self.assertEqual([], self.inspect()['documents'])
        self.assertEqual(0, self.inspect()['pending'])

    async def test_exact_ten_million_byte_file_reaches_real_private_storage(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        content = (ROOT / 'tests/Fixtures/fleet-documents/valid.pdf').read_bytes()
        end = content.rfind(b'%%EOF')
        content = content[:end] + b' ' * (10_000_000 - len(content)) + content[end:]
        self.assertEqual(10_000_000, len(content))
        await self.page.get_by_label('Archivo documental').set_input_files({
            'name': 'exact-limit.pdf', 'mimeType': 'application/pdf', 'buffer': content})
        created = await self.submit()
        self.assertEqual(hashlib.sha256(content).hexdigest(), self.inspect()['files'][created['id']])

    async def test_expired_real_session_removes_private_content_and_requires_login(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        await self.submit()
        # Wait for outstanding summary requests, which legitimately refresh session activity.
        await self.page.wait_for_load_state('networkidle')
        self.assertGreater(self.command('expire-sessions', {'role': 'secretary'})['expired'], 0)
        async with self.page.expect_response(lambda r: '/documents?' in r.url and r.status == 401) as event:
            await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        self.assertEqual(401, (await event.value).status)
        await expect(self.page.get_by_role('heading', name='Acceso a documentación')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Documentación de ' + self.asset['code'])).to_have_count(0)
        await self.sign_in('secretary')
        await self.select()
        await expect(self.page.get_by_role('cell', name='Vigente', exact=True)).to_be_visible()

    async def test_csrf_real_419_and_explicit_refresh_preserves_inputs(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        url = self.base + '/api/fleet-assets/' + self.asset['id'] + '/documents'
        # A request without origin metadata and token must be rejected by real middleware.
        forbidden = await self.context.request.post(url, headers={'Accept': 'application/json'}, data={})
        self.assertEqual(419, forbidden.status)
        await self.context.add_cookies([{'name': 'XSRF-TOKEN', 'value': 'invalid-token', 'url': self.base}])
        self.proxy.strip_origin_path = '/api/fleet-assets/' + self.asset['id'] + '/documents'
        async with self.page.expect_response(lambda r: r.url == url and r.request.method == 'POST') as event:
            await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        self.assertEqual(419, (await event.value).status)
        self.assertEqual([], self.inspect()['documents'])
        await self.page.get_by_role('button', name='Actualizar sesión de seguridad').click()
        await expect(self.page.get_by_text('Sesión de seguridad actualizada. Puedes volver a enviar el formulario.')).to_be_visible()
        self.assertEqual(1, len(self.posts))
        await expect(self.page.get_by_label('Fecha de vencimiento', exact=False)).to_have_value('2026-10-27')
        await self.submit()

    async def test_unknown_resource_returns_real_404_and_ui_handles_missing_download(self):
        await self.sign_in()
        await self.select()
        await self.form()
        created = await self.submit()
        self.assertEqual(404, (await self.context.request.get(self.base + '/api/documents/' + UNKNOWN)).status)
        self.proxy.missing_download_path = '/api/documents/' + created['id'] + '/download'
        await self.page.get_by_role('button', name='Descargar ' + self.kind['name'], exact=True).click()
        await expect(self.page.get_by_text('El activo, documento o archivo solicitado ya no está disponible.')).to_be_visible()
        self.assertEqual(1, len(self.inspect()['documents']))

    async def test_connection_interruption_does_not_retry_or_assume_persistence(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        before = self.inspect()
        await self.context.set_offline(True)
        await self.page.get_by_role('button', name='Guardar documento', exact=True).click()
        await expect(self.page.get_by_text('No se pudo confirmar la operación por un problema de conexión. Consulta los documentos actuales antes de reenviar una carga.').first).to_be_visible()
        self.assertEqual(before, self.inspect())  # Verify absence; don't infer it from the network error.
        self.assertEqual(1, len(self.posts))
        await self.context.set_offline(False)
        await self.page.get_by_role('button', name='Cancelar', exact=True).click()
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_text('No hay documentos para esta consulta.')).to_be_visible()

    async def test_lost_response_after_real_commit_preserves_one_current_and_history(self):
        await self.sign_in('secretary')
        await self.select()
        await self.form()
        old = await self.submit()
        before = self.inspect()
        await self.form(renewal=True)
        committed_before = self.proxy.committed_uploads
        self.proxy.drop_response_path = '/api/fleet-assets/' + self.asset['id'] + '/documents/' + old['id'] + '/renew'
        await self.page.get_by_role('button', name='Guardar renovación', exact=True).click()
        await expect(self.page.get_by_text('No se pudo confirmar la operación por un problema de conexión. Consulta los documentos actuales antes de reenviar una carga.').first).to_be_visible()
        self.assertEqual(committed_before + 1, self.proxy.committed_uploads)
        after = self.inspect()
        self.assertEqual(2, len(after['documents']))
        self.assertEqual(1, len(after['current']))
        self.assertNotEqual(old['id'], after['current'][0]['document_id'])
        self.assertEqual(before['documents'][0], next(d for d in after['documents'] if d['id'] == old['id']))
        self.assertEqual(before['files'][old['id']], after['files'][old['id']])
        self.assertEqual(2, len(self.posts))  # Initial upload + one renewal, no automatic retry.
        await self.page.get_by_role('button', name='Cancelar', exact=True).click()
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_text('Cargando documentos…')).not_to_be_visible()
        await self.page.get_by_role('button', name='Historial completo').click()
        await expect(self.page.get_by_role('cell', name='Anterior', exact=True)).to_be_visible()

    async def test_failed_persistence_and_manual_recovery_use_real_private_disk(self):
        await self.sign_in()
        await self.select()
        await self.form()
        historical = await self.submit()
        before = self.inspect()
        result = self.command('fail-persistence', {'asset_id': self.asset['id'], 'type_id': self.kind['id']})
        self.assertEqual({'rejected': True, 'files_unchanged': True, 'pending': 0}, result)
        self.assertEqual(before, self.inspect())
        recovered = self.command('reconcile', {'id': historical['id']})
        self.assertEqual({'exit_codes': [0, 0], 'historical_exists': True, 'orphan_exists': False, 'pending': 0}, recovered)
        self.assertEqual(before, self.inspect())
        await self.page.get_by_role('button', name='Actualizar', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Vigente', exact=True)).to_be_visible()

    async def test_concurrent_real_http_registrations_preserve_unique_current_and_files(self):
        await self.sign_in()
        await self.select()
        await self.form()
        old = await self.submit()
        original = self.inspect()
        second = await self.browser.new_context()
        second_page = await second.new_page()
        await self.sign_in('secretary', second, second_page)
        headers_a, headers_b = await self.headers(), await self.headers(second)
        lock_file, release_file = self.workspace / 'lock-held', self.workspace / 'lock-release'
        for file in [lock_file, release_file]:
            file.unlink(missing_ok=True)
        holder = subprocess.Popen(['php', str(ROOT / 'tests/E2E/console.php'), 'hold'], cwd=ROOT,
                                  stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            for _ in range(100):
                if lock_file.exists():
                    break
                await asyncio.sleep(.02)
            self.assertTrue(lock_file.exists())
            url = self.base + '/api/fleet-assets/' + self.asset['id'] + '/documents/' + old['id'] + '/renew'
            async def renew(context, headers, expiration):
                return await context.request.post(url, headers=headers, multipart={
                    'expires_on': expiration, 'file': {'name': 'valid.pdf', 'mimeType': 'application/pdf',
                                                       'buffer': (ROOT / 'tests/Fixtures/fleet-documents/valid.pdf').read_bytes()}})
            tasks = [asyncio.create_task(renew(self.context, headers_a, '2027-01-01')),
                     asyncio.create_task(renew(second, headers_b, '2027-02-01'))]
            for _ in range(100):
                waiting = self.command('waiting')['requests']
                if waiting >= 1:
                    break
                await asyncio.sleep(.02)
            self.assertGreaterEqual(waiting, 1)  # PostgreSQL confirms a live blocked HTTP transaction.
            self.assertTrue(all(not task.done() for task in tasks))
            # Pending transactions must not change the reference visible to a different connection.
            self.assertEqual(old['id'], self.inspect()['current'][0]['document_id'])
            release_file.write_text('release')
            responses = await asyncio.gather(*tasks)
            for response in responses:
                self.assertEqual(201, response.status, await response.text())
            new_ids = {(await response.json())['data']['id'] for response in responses}
            after = self.inspect()
            self.assertEqual(3, len(after['documents']))
            self.assertEqual(1, len(after['current']))
            self.assertIn(after['current'][0]['document_id'], new_ids)
            self.assertEqual(3, len(after['snapshots']))
            self.assertTrue(all(value for value in after['files'].values()))
            self.assertEqual(original['documents'][0], next(d for d in after['documents'] if d['id'] == old['id']))
            self.assertEqual(0, after['pending'])
        finally:
            release_file.write_text('release')
            await asyncio.to_thread(holder.communicate, timeout=20)
            await second.close()


if __name__ == '__main__':
    unittest.main(verbosity=2)
