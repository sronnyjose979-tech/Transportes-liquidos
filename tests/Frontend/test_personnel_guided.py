"""Guided personnel UX against synthetic API; no real database."""
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_fleet_documents as support


class GuidedPersonnelBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass = classmethod(support.FleetDocumentsBrowserTest.setUpClass.__func__)
    tearDownClass = classmethod(support.FleetDocumentsBrowserTest.tearDownClass.__func__)

    async def asyncSetUp(self):
        self.accounts, self.employees, self.writes = [], [], []
        self.roles = [{'id': '10000000-0000-4000-8000-000000000011', 'name': 'driver'}]
        self.rejection = False
        self.lost = False
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.page.on('dialog', lambda d: d.accept())

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self, route):
        path = urlparse(route.request.url).path
        if path in ['/api/session', '/api/csrf', '/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self, route)
            return
        if route.request.method == 'POST':
            self.writes.append((path, route.request.post_data_json))
            if self.lost:
                await route.abort('failed')
                return
            if self.rejection:
                await route.fulfill(status=422, json={'errors': {'login': ['Este usuario ya está en uso.']}})
                return
            data = route.request.post_data_json
            account = {k: v for k, v in data.items() if k not in ['password', 'password_confirmation', 'employee']}
            account.update(id='42', employee_id='employee-42', roles=self.roles)
            self.accounts.append(account)
            self.employees.append({'id': 'employee-42', 'user_id': 42, **data['employee']})
            await route.fulfill(status=201, json={'data': account})
            return
        await route.fulfill(json={'data': {'/api/users': self.accounts, '/api/employees': self.employees, '/api/roles': self.roles}.get(path, []), 'current_page': 1, 'last_page': 1})

    async def prepare(self):
        await self.page.goto(self.base + '/administracion/personal')
        await self.page.get_by_role('button', name='Registrar personal con acceso', exact=True).click()
        form = self.page.get_by_role('form', name='Alta guiada de personal')
        await form.get_by_label('Nombre completo', exact=True).fill('Persona de prueba')
        await form.get_by_role('button', name='Continuar', exact=True).click()
        await form.get_by_label('Usuario de acceso', exact=True).fill('persona-prueba')
        await form.get_by_label('Correo electrónico', exact=True).fill('persona@example.test')
        await form.get_by_label('Contraseña', exact=True).fill('SyntheticPassword123!')
        await form.get_by_label('Confirmar contraseña', exact=True).fill('SyntheticPassword123!')
        await form.get_by_role('button', name='Continuar', exact=True).click()
        return form

    async def test_review_creates_person_and_account_with_one_atomic_request(self):
        form = await self.prepare()
        await expect(self.page.get_by_label('Revisión del personal')).to_contain_text('Chofer')
        await expect(self.page.get_by_label('Revisión del personal')).not_to_contain_text('SyntheticPassword')
        await form.get_by_role('button', name='Registrar persona y acceso').click()
        await expect(self.page.get_by_role('form', name='Alta guiada de personal')).to_have_count(0)
        self.assertEqual(1, len(self.writes))
        path, data = self.writes[0]
        self.assertEqual('/api/users', path)
        self.assertEqual({'name': 'Persona de prueba', 'is_active': True}, data['employee'])
        await expect(self.page.get_by_role('cell', name='Persona de prueba', exact=True)).to_be_visible()

    async def test_validation_returns_to_access_step_without_automatic_repeated_write(self):
        self.rejection = True
        form = await self.prepare()
        await form.get_by_role('button', name='Registrar persona y acceso').click()
        await expect(form.get_by_label('Usuario de acceso', exact=True)).to_have_value('persona-prueba')
        await expect(form).to_contain_text('Este usuario ya está en uso.')
        self.assertEqual(1, len(self.writes))

    async def test_lost_response_freezes_registration_and_requires_consulting_confirmed_state(self):
        self.lost = True
        form = await self.prepare()
        await form.get_by_role('button', name='Registrar persona y acceso').click()
        await expect(form).to_contain_text('Consulta el estado confirmado')
        await expect(form.get_by_role('button', name='Registrar persona y acceso')).to_be_disabled()
        self.assertEqual(1, len(self.writes))

    async def test_mobile_review_and_cancel_preserve_existing_management_actions(self):
        form = await self.prepare()
        for width in [320, 390, 768]:
            await self.page.set_viewport_size({'width': width, 'height': 1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
        await self.page.get_by_role('button', name='Cancelar registro', exact=True).click()
        await expect(form).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Nuevo empleado', exact=True)).to_be_visible()
        self.assertEqual([], self.writes)

    async def test_missing_profile_does_not_invent_or_create_roles(self):
        self.roles = []
        await self.page.goto(self.base + '/administracion/personal')
        await self.page.get_by_role('button', name='Registrar personal con acceso', exact=True).click()
        await expect(self.page.get_by_role('form', name='Alta guiada de personal').get_by_role('button', name='Continuar')).to_be_disabled()
        await expect(self.page.get_by_text('El perfil Chofer no está disponible.', exact=False)).to_be_visible()
        self.assertEqual([], self.writes)

    async def test_existing_personnel_filter_and_account_tab_survive_reload(self):
        self.employees = [{'id': 'employee-a', 'name': 'Persona activa', 'is_active': True, 'user_id': None},
                          {'id': 'employee-b', 'name': 'Persona inactiva', 'is_active': False, 'user_id': None}]
        await self.page.goto(self.base + '/administracion/personal')
        await self.page.get_by_label('Estado del personal', exact=True).select_option('inactive')
        await expect(self.page.get_by_role('cell', name='Persona inactiva', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Persona activa', exact=True)).to_have_count(0)
        await self.page.reload()
        await expect(self.page.get_by_label('Estado del personal', exact=True)).to_have_value('inactive')
        await self.page.get_by_role('button', name='Cuentas y roles', exact=True).click()
        await self.page.reload()
        await expect(self.page.get_by_role('button', name='Cuentas y roles', exact=True)).to_have_attribute('aria-pressed', 'true')
        self.assertEqual([], self.writes)
