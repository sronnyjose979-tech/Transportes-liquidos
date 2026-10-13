"""Real Chromium -> Laravel/session/CSRF -> isolated PostgreSQL 18.
Run only: bash tests/run-postgres.sh --e2e-workshop-circuit.
No simulated API responses, inventory or production data.
"""
import unittest
import uuid

from playwright.async_api import expect
import test_workshop_execution as execution
import test_workshop_module as first


class WorkshopCircuitE2E(unittest.IsolatedAsyncioTestCase):
    async def login(self, role='administrator'):
        await first.WorkshopModuleE2E.login(self, role)
        self.session_cookies = getattr(self, 'session_cookies', {})
        self.session_cookies[role] = await self.context.cookies()
        self.current_role = role

    async def switch(self, role):
        # Independent real sessions for three concurrent operators. Cookies stay
        # only in test memory; no production cache or authentication bypass.
        self.session_cookies[self.current_role] = await self.context.cookies()
        await self.context.clear_cookies()
        if role in self.session_cookies:
            await self.context.add_cookies(self.session_cookies[role])
            self.current_role = role
            await self.page.goto(self.base + '/taller')
            await self.ready()
        else:
            await self.login(role)

    async def transport_availability(self):
        result = await self.page.evaluate("""async id => {
            const r=await fetch('/api/transport/availability?resource_kind=tractor_unit&resource_id='+id,
                {headers:{Accept:'application/json'},credentials:'same-origin'});
            return {status:r.status,body:await r.json()};
        }""", self.fixture['asset']['id'])
        self.assertEqual(200, result['status'], result['body'])
        return result['body']['data']

    async def ingress_list(self):
        await self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Ingresos y salidas', exact=False).click()
        await self.ready()

    async def open_admission(self, admission):
        await self.ingress_list()
        await self.page.get_by_role('button', name='Ver detalle del registro ' + admission, exact=True).click()
        await self.ready()

    async def admit(self, lose_response=False):
        await self.ingress_list()
        await self.page.get_by_role('button', name='Registrar ingreso', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar ingreso')
        await self.choose(form, 'Vehículo', self.fixture['asset']['id'], self.fixture['asset']['code'])
        await form.get_by_label('Tipo de mantenimiento', exact=True).select_option('corrective')
        await form.get_by_label('Fecha de ingreso (Costa Rica)', exact=True).fill(self.local_at(0))
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Ingreso físico E2E circuito')
        if lose_response:
            self.proxy.drop_response_statuses = (200, 201)
            self.proxy.drop_response_path = '/api/workshop/assets/' + self.fixture['asset']['id'] + '/admissions'
            await form.get_by_role('button', name='Guardar', exact=True).click()
            await expect(self.page.get_by_role('button', name='Reintentar la misma operación', exact=True)).to_be_visible()
            records = await self.api('admissions?fleet_asset_id=' + self.fixture['asset']['id'])
            self.assertEqual(1, len(records['body']['data']))
            await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
            await expect(self.page.get_by_role('dialog')).not_to_be_visible()
            await self.ready()
            records = await self.api('admissions?fleet_asset_id=' + self.fixture['asset']['id'])
            self.assertEqual(1, len(records['body']['data']))
            causes = await self.api('impediments?fleet_asset_id=' + self.fixture['asset']['id'] + '&open=1')
            return {'admission_id': records['body']['data'][0]['id'], 'impediment_id': causes['body']['data'][0]['id']}
        return await self.save(form, 'assets/' + self.fixture['asset']['id'] + '/admissions', status=200)

    async def approve_visible(self, request, apply_path):
        await self.page.get_by_role('button', name='Registrar decisión', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar decisión')
        await form.get_by_label('Decisión', exact=True).select_option('approved')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Revisión administrativa circuito E2E')
        decision = await self.save(form, 'authorization-requests/' + request + '/decisions', status=200)
        await self.page.get_by_role('button', name='Aplicar autorización', exact=True).click()
        await self.save(self.page.get_by_role('form', name='Aplicar autorización'), 'decisions/' + decision['decision_id'] + '/' + apply_path, status=200)
        return decision

    async def test_vehicle_ingress_repair_resolution_exit_and_transport_availability(self):
        await self.login('administrator')
        self.page.on('dialog', lambda dialog: dialog.accept())
        # Create the operational resource through the real existing Fleet screen.
        await self.page.goto(self.base + '/administracion/flota')
        await self.page.get_by_role('button', name='Registrar cabezal', exact=True).click()
        code = self.fixture['tag'] + '-UI'
        await self.page.get_by_label('Código del activo', exact=True).fill(code)
        async with self.page.expect_response(lambda r: r.url.endswith('/api/tractor-units') and r.request.method == 'POST') as event:
            await self.page.get_by_role('button', name='Guardar activo', exact=True).click()
        response = await event.value
        self.assertEqual(201, response.status, await response.text())
        asset = (await response.json())['data']
        self.fixture['asset'] = {'id': asset['id'], 'code': code}
        await self.page.goto(self.base + '/taller')
        await self.ready()
        self.assertTrue((await self.transport_availability())['available'])
        await self.switch('secretary')
        admission = await self.admit()
        self.assertFalse((await self.transport_availability())['eligible'])
        order, _ = await self.report_and_order()
        await self.page.get_by_role('button', name='Vincular ingreso', exact=True).click()
        form = self.page.get_by_role('form', name='Vincular ingreso')
        await self.choose(form, 'Ingreso al taller', admission['admission_id'], '')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Orden vinculada al ingreso E2E')
        await self.save(form, 'orders/' + order + '/admission-links')
        # The secretary must find an eligible mechanic without previous assignments.
        assignment = await self.assign(order)
        await self.switch('mechanic')
        await self.open_order(order)
        diagnosis = await self.diagnosis(order, assignment)
        scope = await self.scope(order, diagnosis)
        await self.switch('administrator')
        await self.open_order(order)
        await self.page.get_by_role('button', name='Revisar autorización', exact=True).click()
        await self.ready()
        await self.approve_visible(scope['request_id'], 'apply-scope')
        await self.switch('mechanic')
        await self.open_order(order)
        task = self.snapshot()['tasks'][0]['id']
        form = await self.operation('Registrar actividad')
        await self.choose(form, 'Trabajo autorizado', task, 'Cambiar sello')
        await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
        await form.get_by_label('Actividad realizada', exact=True).fill('Reparación completada circuito E2E')
        await self.fill_common(form, 10)
        await self.save(form, 'orders/' + order + '/activities', status=200)
        form = await self.operation('Finalizar reparación', 'Finalizar reparación de la orden')
        await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
        await self.fill_common(form, 20)
        await self.save(form, 'orders/' + order + '/finish', status=200)
        await self.switch('administrator')
        await self.open_order(order)
        form = await self.operation('Revisar y cerrar orden', 'Cerrar orden con revisión administrativa')
        await form.get_by_label('Revisión: trabajos, repuestos, tiempos y pendientes (un punto por línea)', exact=True).fill('Trabajo verificado\nSin materiales consumidos\nSin pendientes')
        await self.fill_common(form, 25)
        await self.save(form, 'orders/' + order + '/close', status=200)
        self.assertFalse((await self.transport_availability())['available'])
        # An additional order-linked impediment is resolved explicitly, never by closing.
        cause = await self.api('assets/' + asset['id'] + '/impediments', {
            'request_key': str(uuid.uuid4()), 'admission_id': admission['admission_id'], 'work_order_id': order,
            'effect': 'out_of_service', 'description': 'Comprobación final E2E',
            'occurred_at': self.local_at(0) + ':00-06:00', 'reason': 'Restricción explícita sintética'})
        self.assertEqual(200, cause['status'], cause['body'])
        await self.page.get_by_role('navigation', name='Navegación Taller').get_by_role('button', name='Impedimentos', exact=True).click()
        await self.ready()
        await self.page.get_by_role('button', name='Ver detalle del registro ' + cause['body']['data']['impediment_id'], exact=True).click()
        await self.ready()
        await self.page.get_by_role('button', name='Resolver impedimento', exact=True).click()
        form = self.page.get_by_role('form', name='Resolver impedimento')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Verificación concluida por administrador E2E')
        await self.save(form, 'impediments/' + cause['body']['data']['impediment_id'] + '/resolve', status=200)
        self.assertFalse((await self.transport_availability())['available'])
        await self.open_admission(admission['admission_id'])
        await self.page.get_by_role('button', name='Solicitar salida', exact=True).click()
        form = self.page.get_by_role('form', name='Solicitar salida')
        await form.get_by_role('checkbox').check()
        await form.get_by_label('Fecha de salida (Costa Rica)', exact=True).fill(self.local_at(30))
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Salida revisada E2E')
        request = await self.save(form, 'admissions/' + admission['admission_id'] + '/exit-requests', status=200)
        await self.page.get_by_role('button', name='Revisar autorización', exact=True).click()
        await self.ready()
        await self.approve_visible(request['request_id'], 'exit')
        self.assertTrue((await self.transport_availability())['available'])
        await self.open_admission(admission['admission_id'])
        await expect(self.page.get_by_role('button', name='Solicitar salida', exact=True)).not_to_be_visible()
        for width in [1440, 768, 390]:
            await self.page.set_viewport_size({'width': width, 'height': 1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= innerWidth'), width)
        snapshot = self.snapshot()
        self.assertEqual('closed', snapshot['orders'][0]['status'])
        self.assertEqual(1, len(snapshot['assignments']))
        self.assertEqual(1, len(snapshot['applications']))  # Order scope only in this fixture snapshot.
        applications = await self.api('authorization-applications?fleet_asset_id=' + asset['id'])
        self.assertEqual(200, applications['status'], applications['body'])
        self.assertEqual(2, len(applications['body']['data']))

    async def test_secretary_releases_mechanic_preserving_history_and_no_exit_permission(self):
        await self.login('secretary')
        admission = await self.admit(lose_response=True)
        await expect(self.page.get_by_role('button', name='Solicitar salida', exact=True)).not_to_be_visible()
        denied = await self.api('admissions/' + admission['admission_id'] + '/exit-requests', {
            'request_key': str(uuid.uuid4()), 'resolve_ids': [admission['impediment_id']],
            'exited_at': self.local_at(30) + ':00-06:00', 'reason': 'Prueba denegación secretaria'})
        self.assertEqual(403, denied['status'])
        order, _ = await self.report_and_order()
        assignment = await self.assign(order)
        await self.page.get_by_role('button', name='Liberar mecánico', exact=False).click()
        form = self.page.get_by_role('form', name='Liberar mecánico')
        await form.get_by_label('Fecha de liberación (Costa Rica)', exact=True).fill(self.local_at(10))
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Liberación manual auditada E2E')
        await self.save(form, 'mechanic-assignments/' + assignment['assignment_id'] + '/release')
        await self.switch('mechanic')
        await self.open_order(order)
        await expect(self.page.get_by_role('button', name='Registrar diagnóstico', exact=True)).not_to_be_visible()
        self.assertEqual(1, len(self.snapshot()['assignments']))
        released = await self.api('mechanic-releases?work_order_id=' + order)
        self.assertEqual(200, released['status'])
        self.assertEqual(1, len(released['body']['data']))


for _source in [first.WorkshopModuleE2E, execution.WorkshopExecutionE2E]:
    for _name, _value in _source.__dict__.items():
        if not _name.startswith('test_') and not _name.startswith('__') and _name not in WorkshopCircuitE2E.__dict__:
            setattr(WorkshopCircuitE2E, _name, _value)

del _source

if __name__ == '__main__':
    unittest.main()
