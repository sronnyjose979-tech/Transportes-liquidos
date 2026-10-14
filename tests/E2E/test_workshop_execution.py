"""Chromium -> real Vue/Laravel/session API -> exclusive PostgreSQL 18.
Run only: bash tests/run-postgres.sh --e2e-workshop-execution.
Synthetic fixtures and explicitly authorized initial counts; no mocked responses.
"""
import base64
import json
import subprocess
import unittest
import uuid
from datetime import datetime, timedelta

from playwright.async_api import expect
import test_document_module as support
import test_workshop_module as first


class WorkshopExecutionE2E(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        await first.WorkshopModuleE2E.asyncSetUp(self)
        self.command('reconcile-part', {'part_id': self.fixture['part']['id']})

    def execution_snapshot(self, order):
        result = subprocess.run(['php', str(support.ROOT / 'tests/E2E/workshop-execution-console.php'), 'snapshot'],
                                input=json.dumps({'order_id': order}), text=True, capture_output=True,
                                cwd=support.ROOT, check=True)
        return json.loads(result.stdout)

    async def authorized_order(self):
        await self.login('administrator')
        order, _ = await self.report_and_order()
        assignment = await self.assign(order)
        diagnosis = await self.diagnosis(order, assignment)
        scope = await self.scope(order, diagnosis, include_catalogs=True)
        decision = await self.api('authorization-requests/' + scope['request_id'] + '/decisions', {
            'request_key': str(uuid.uuid4()), 'decision': 'approved', 'reason': 'Autorización operativa E2E'})
        self.assertEqual(200, decision['status'], decision['body'])
        applied = await self.api('decisions/' + decision['body']['data']['decision_id'] + '/apply-scope', {
            'request_key': str(uuid.uuid4())})
        self.assertEqual(200, applied['status'], applied['body'])
        await self.open_order(order)
        return order, assignment

    async def operation(self, name, title=None):
        await self.page.get_by_role('button', name=name, exact=True).click()
        return self.page.get_by_role('form', name=title or name)

    async def operation_save(self, form, path, status=200):
        return await self.save(form, path, status=status)

    async def check_responsive(self, order):
        for width in [1440, 390]:
            await self.page.set_viewport_size({'width': width, 'height': 900})
            await expect(self.page.get_by_role('heading', name='Detalle de orden', exact=True)).to_be_visible()
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= innerWidth'), width)
            await self.page.screenshot(path=str(self.artifacts / f'execution-{order}-{width}.png'), full_page=True)

    async def tab(self, name):
        await self.page.get_by_role('navigation', name='Secciones operativas').get_by_role('button', name=name, exact=True).click()

    def local_at(self, minutes):
        return (datetime.fromisoformat(self.fixture['starts_local']) + timedelta(minutes=minutes)).isoformat(timespec='minutes')

    async def fill_common(self, form, minutes=5):
        await form.get_by_label('Fecha y hora (Costa Rica)', exact=True).fill(self.local_at(minutes))
        await form.get_by_label('Motivo del registro', exact=True).fill('Operación sintética E2E')

    async def attach_evidence(self, order):
        panel = self.page.get_by_role('region', name='Evidencias y documentos')
        image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN4sAAAAASUVORK5CYII=')
        await panel.get_by_label('Categoría', exact=True).select_option('photo')
        await panel.get_by_label('Motivo del adjunto', exact=True).fill('Evidencia privada sintética E2E')
        await panel.get_by_label('Archivo privado', exact=True).set_input_files({'name': 'evidence.png', 'mimeType': 'image/png', 'buffer': image})
        async with self.page.expect_response(lambda r: '/attachment-reservations/' in r.url and r.url.endswith('/confirm')) as event:
            await panel.get_by_role('button', name='Adjuntar evidencia', exact=True).click()
        response = await event.value
        self.assertEqual(200, response.status, await response.text())
        await expect(panel.get_by_text('Evidencia confirmada.', exact=True)).to_be_visible()
        async with self.page.expect_download() as event:
            await panel.get_by_role('button', name='Descargar archivo privado', exact=True).click()
        downloaded = await event.value
        target = self.artifacts / ('private-' + order + '.png')
        await downloaded.save_as(target)
        self.assertEqual(image, target.read_bytes())

    async def test_mechanic_operational_journey_and_private_evidence(self):
        order, assignment = await self.authorized_order()
        original = self.snapshot()
        task = original['tasks'][0]['id']
        part = original['parts'][0]['id']
        await self.tab('Materiales')
        form = await self.operation('Registrar entrega', 'Registrar entrega física')
        await self.choose(form, 'Repuesto autorizado', part, self.fixture['part']['code'])
        await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal', exact=True).fill('1.000001')
        await self.fill_common(form)
        delivery = await self.operation_save(form, 'orders/' + order + '/deliveries')
        await self.switch('mechanic')
        await self.page.set_viewport_size({'width': 390, 'height': 900})
        await self.open_order(order)
        form = await self.operation('Registrar actividad')
        await self.choose(form, 'Trabajo autorizado', task, 'Cambiar sello')
        await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
        await form.get_by_label('Actividad realizada', exact=True).fill('Sello sustituido con prueba de fuga E2E')
        await self.fill_common(form)
        await self.operation_save(form, 'orders/' + order + '/activities')
        for button, title, minute in [('Iniciar tiempo', 'Registrar inicio de tiempo', 5),
                                      ('Pausar', 'Registrar pausa de tiempo', 15),
                                      ('Reanudar', 'Registrar reanudación de tiempo', 25),
                                      ('Finalizar tiempo', 'Registrar fin de tiempo', 35)]:
            await self.tab('Tiempos')
            form = await self.operation(button, title)
            await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
            await self.fill_common(form, minute)
            if button == 'Iniciar tiempo':
                self.proxy.drop_response_statuses = (200, 201)
                self.proxy.drop_response_path = '/api/workshop/orders/' + order + '/time-events'
                await form.get_by_role('button', name='Guardar', exact=True).click()
                await expect(self.page.get_by_role('button', name='Reintentar la misma operación', exact=True)).to_be_visible()
                self.assertEqual(1, len(self.execution_snapshot(order)['times']))
                await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
                await expect(self.page.get_by_role('dialog')).not_to_be_visible()
                await self.ready()
                self.assertEqual(1, len(self.execution_snapshot(order)['times']))
                await self.tab('Tiempos')
                invalid = await self.operation('Reanudar', 'Registrar reanudación de tiempo')
                await self.choose(invalid, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
                await self.fill_common(invalid, 10)
                async with self.page.expect_response(lambda r: r.url.endswith('/time-events') and r.request.method == 'POST') as event:
                    await invalid.get_by_role('button', name='Guardar', exact=True).click()
                response = await event.value
                self.assertIn(response.status, [409, 422], await response.text())
                await expect(self.page.get_by_role('dialog')).to_be_visible()
                self.assertEqual(1, len(self.execution_snapshot(order)['times']))
                await invalid.get_by_role('button', name='Cancelar', exact=True).click()
            else:
                await self.operation_save(form, 'orders/' + order + '/time-events')
        await expect(self.page.get_by_text('1200', exact=False).first).to_be_visible()
        await self.tab('Materiales')
        await expect(self.page.get_by_role('button', name='Registrar entrega', exact=True)).not_to_be_visible()
        form = await self.operation('Registrar utilización')
        await self.choose(form, 'Entrega recibida', delivery['delivery_id'], '')
        await self.choose(form, 'Mecánico asignado', assignment['assignment_id'], self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal', exact=True).fill('0.750001')
        await self.fill_common(form, 30)
        await self.operation_save(form, 'orders/' + order + '/usages')
        await self.attach_evidence(order)
        await self.page.get_by_role('button', name='Actualizar datos', exact=True).click()
        await self.ready()
        await self.tab('Ejecución')
        await expect(self.page.get_by_text('Sello sustituido con prueba de fuga E2E', exact=True).first).to_be_visible()
        snapshot = self.execution_snapshot(order)
        self.assertEqual('in_repair', snapshot['order']['status'])
        self.assertEqual(1, len(snapshot['activities']))
        self.assertEqual(['start', 'pause', 'resume', 'finish'], [row['event_type'] for row in snapshot['times']])
        self.assertEqual('1.000001', snapshot['deliveries'][0]['quantity'])
        self.assertEqual('0.750001', snapshot['usages'][0]['quantity'])
        self.assertEqual(1, len(snapshot['attachments']))
        self.assertEqual(1, len(snapshot['reservations']))
        await self.check_responsive(order)
        await self.switch('administrator')
        await self.open_order(order)
        await self.tab('Tiempos')
        await expect(self.page.get_by_text('1200', exact=False).first).to_be_visible()
        await self.tab('Materiales')
        await expect(self.page.get_by_text('0.750001', exact=True).first).to_be_visible()

    async def test_administrative_decimal_costs_authorized_rectification_and_permissions(self):
        order, assignment = await self.authorized_order()
        task = self.snapshot()['tasks'][0]['id']
        await self.tab('Costos')
        form = await self.operation('Registrar reparación externa')
        await self.choose(form, 'Trabajo autorizado', task, 'Cambiar sello')
        await self.choose(form, 'Proveedor', self.fixture['supplier']['id'], self.fixture['supplier']['name'])
        await form.get_by_label('Reparación realizada', exact=True).fill('Servicio externo E2E')
        await form.get_by_label('Fecha de reparación (Costa Rica)', exact=True).fill(self.local_at(5))
        await form.get_by_label('Motivo del registro', exact=True).fill('Servicio aislado')
        external = await self.operation_save(form, 'orders/' + order + '/external-repairs')
        await self.tab('Costos')
        form = await self.operation('Registrar costo')
        await self.choose(form, 'Tipo de costo', 'external', 'Externo')
        await self.choose(form, 'Base', 'actual', 'Real')
        await form.get_by_label('Importe decimal (si corresponde)', exact=True).fill('12.123456')
        await form.get_by_label('Moneda ISO (por ejemplo CRC)', exact=True).fill('CRC')
        await self.choose(form, 'Reparación externa (opcional)', external['external_repair_id'], 'Servicio externo')
        await form.get_by_label('Concepto y detalle', exact=True).fill('Servicio contabilizado E2E')
        await self.fill_common(form, 10)
        cost = await self.operation_save(form, 'orders/' + order + '/costs')
        await self.tab('Costos')
        await expect(self.page.get_by_text('Real: 12.123456 CRC', exact=True)).to_be_visible()
        await self.page.get_by_role('button', name='Rectificar Servicio contabilizado E2E', exact=False).click()
        form = self.page.get_by_role('form', name='Solicitar rectificación auditable')
        await form.get_by_label('Importe decimal corregido', exact=True).fill('15.000001')
        await form.get_by_label('Motivo del registro', exact=True).fill('Corrección auditable E2E')
        correction = await self.operation_save(form, 'facts/cost/' + cost['cost_record_id'] + '/rectifications')
        await self.page.locator('article').filter(has_text='Corrección auditable E2E').get_by_role('button', name='Revisar autorización', exact=True).click()
        await self.ready()
        await self.page.get_by_role('button', name='Registrar decisión', exact=True).click()
        form = self.page.get_by_role('form', name='Registrar decisión')
        await form.get_by_label('Decisión', exact=True).select_option('approved')
        await form.get_by_label('Motivo / información recibida', exact=True).fill('Revisión administrativa E2E')
        decision = await self.save(form, 'authorization-requests/' + correction['request_id'] + '/decisions', status=200)
        await self.page.get_by_role('button', name='Aplicar autorización', exact=True).click()
        form = self.page.get_by_role('form', name='Aplicar autorización')
        await self.save(form, 'decisions/' + decision['decision_id'] + '/facts/cost/apply', status=200)
        await self.open_order(order)
        await self.page.get_by_role('button', name='Actualizar datos', exact=True).click()
        await self.ready()
        await self.tab('Costos')
        await expect(self.page.get_by_text('Real: 15.000001 CRC', exact=True)).to_be_visible()
        snapshot = self.execution_snapshot(order)
        self.assertEqual(2, len(snapshot['costs']))
        self.assertEqual(1, len(snapshot['external']))
        await self.check_responsive(order)
        await self.switch('mechanic')
        await self.open_order(order)
        await expect(self.page.get_by_role('navigation', name='Secciones operativas').get_by_role('button', name='Costos')).not_to_be_visible()
        denied = await self.api('orders/' + order + '/cost-totals')
        self.assertEqual(403, denied['status'])

    async def test_lost_attachment_reservation_reload_and_idempotent_retry(self):
        order, _ = await self.authorized_order()
        await self.switch('mechanic')
        await self.open_order(order)
        panel = self.page.get_by_role('region', name='Evidencias y documentos')
        image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN4sAAAAASUVORK5CYII=')
        file = {'name': 'recovery.png', 'mimeType': 'image/png', 'buffer': image}
        await panel.get_by_label('Motivo del adjunto', exact=True).fill('Recuperación E2E')
        await panel.get_by_label('Archivo privado', exact=True).set_input_files(file)
        self.proxy.drop_response_statuses = (200, 201)
        self.proxy.drop_response_path = '/api/workshop/orders/' + order + '/attachment-reservations'
        await panel.get_by_role('button', name='Adjuntar evidencia', exact=True).click()
        await expect(panel.get_by_role('button', name='Reintentar el mismo adjunto', exact=True)).to_be_visible()
        snapshot = self.execution_snapshot(order)
        self.assertEqual(1, len(snapshot['reservations']))
        self.assertEqual([], snapshot['attachments'])
        await self.page.reload()
        await self.ready()
        await self.open_order(order)
        await panel.get_by_label('Archivo privado', exact=True).set_input_files(file)
        await panel.get_by_role('button', name='Reintentar el mismo adjunto', exact=True).click()
        await expect(panel.get_by_text('Evidencia confirmada.', exact=True)).to_be_visible()
        snapshot = self.execution_snapshot(order)
        self.assertEqual(1, len(snapshot['reservations']))
        self.assertEqual(1, len(snapshot['attachments']))


# Reuse first-delivery browser bootstrap, authentication and setup helpers, without
# re-running its test cases or altering the original suite.
for _name, _value in first.WorkshopModuleE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in WorkshopExecutionE2E.__dict__:
        setattr(WorkshopExecutionE2E, _name, _value)


if __name__ == '__main__':
    unittest.main()
