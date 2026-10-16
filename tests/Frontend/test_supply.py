"""Supply UI contracts with intercepted APIs; real persistence is covered by E2E."""
import asyncio
import unittest
from urllib.parse import urlparse

import test_fleet_documents as support
from playwright.async_api import expect

PART = 'a0000000-0000-4000-8000-000000000001'


class SupplyBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.writes = []
        self.paths = []
        self.lose = False
        self.status = 201
        self.hold = None
        self.started = asyncio.Event()
        self.accept = lambda dialog: dialog.accept()
        self.page.on('dialog', self.accept)

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self, route):
        req = route.request
        path = urlparse(req.url).path
        self.paths.append(path)
        if path == '/api/session':
            await route.fulfill(json={'data': {'id': '7', 'login': 'test', 'roles': [self.role]}})
            return
        if req.method == 'POST':
            self.writes.append((path, req.post_data_json))
            self.started.set()
            if self.hold: await self.hold.wait()
            if self.lose:
                self.lose = False
                await route.abort('connectionfailed')
            elif self.status != 201:
                await route.fulfill(status=self.status, json={'message': 'Operación rechazada.', 'errors': {('items.0.quantity' if path=='/api/purchasing/requests' else 'quantity'): ['Cantidad inválida.']}})
            else:
                await route.fulfill(status=201, json={'data': {'id': 'confirmed'}})
            return
        stock = [{'spare_part_id': PART, 'code': 'FILT-01', 'description': 'Filtro', 'unit': 'unidad', 'initialized': False, 'available_quantity': None, 'custody_quantity': None, 'consumed_quantity': None}]
        custody = [{'delivery_id': 'delivery-1', 'mechanic_assignment_id': 'assignment-1', 'work_order_id': 'order-1', 'spare_part_id': PART, 'unit_snapshot': 'unidad', 'employee_id': 'mechanic-1', 'held_quantity': '2.000001', 'consumed_quantity': '0.000000'}]
        correction = [{'id': 'correction-1', 'spare_part_id': PART, 'kind': 'initial', 'quantity': '2.000001', 'reason': 'Conteo físico', 'decision_id': None, 'decision': None, 'application_id': None}]
        requests = [{'id': 'request-1', 'supplier': {'id': 'supplier-1', 'name': 'Proveedor'}, 'reason': 'Reparación', 'items': [], 'decision': None}]
        data = {'/api/inventory/stock': stock, '/api/inventory/custody': custody, '/api/inventory/corrections': correction, '/api/inventory/movements': [], '/api/purchasing/requests': requests, '/api/purchasing/orders': [], '/api/workshop/material-needs': []}
        if path == '/api/purchasing/lookups':
            await route.fulfill(json={'data': {'parts': [{'id':PART,'code':'FILT-01','description':'Filtro','unit':'unidad'}], 'suppliers': [{'id':'supplier-1','name':'Proveedor'}]}})
        elif path in data:
            await route.fulfill(json={'data': data[path], 'meta': {'current_page': 1, 'last_page': 1}})
        else:
            await route.fulfill(status=404, json={'message': 'Contrato inesperado.'})

    async def prepare_correction(self):
        await self.page.goto(self.base + '/inventario')
        await self.page.get_by_role('button', name='Solicitar conciliación o ajuste', exact=True).click()
        await self.page.get_by_label('Repuesto', exact=True).select_option(PART)
        await self.page.get_by_label('Cantidad', exact=True).fill('2.000001')
        await self.page.get_by_label('Motivo', exact=True).fill('Conteo físico documentado')

    async def test_pending_operation_shows_progress_and_prevents_second_submission(self):
        self.hold=asyncio.Event()
        await self.prepare_correction()
        await self.page.get_by_role('button',name='Guardar operación',exact=True).click()
        await self.started.wait()
        try:
            await expect(self.page.get_by_role('status').filter(has_text='Confirmando operación')).to_be_visible()
            await expect(self.page.get_by_role('button',name='Guardar operación',exact=True)).to_be_disabled()
            await self.page.get_by_label('Cantidad',exact=True).evaluate("e=>e.form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))")
            self.assertEqual(1,len(self.writes))
            await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_have_count(0)
        finally:
            self.hold.set()
        await expect(self.page.get_by_role('status').filter(has_text='Operación confirmada')).to_be_visible()
        await expect(self.page.get_by_role('status').filter(has_text='Confirmando operación')).to_have_count(0)

    async def test_purchase_validation_is_attached_to_the_requested_quantity(self):
        self.status=422
        await self.page.goto(self.base+'/compras')
        await self.page.get_by_role('button',name='Registrar solicitud de compra',exact=True).click()
        await self.page.get_by_label('Proveedor',exact=True).select_option('supplier-1')
        await self.page.get_by_label('Repuesto',exact=True).select_option(PART)
        control=self.page.get_by_label('Cantidad solicitada',exact=True)
        await control.fill('2.000001')
        await self.page.get_by_label('Motivo',exact=True).fill('Necesidad del taller')
        await self.page.get_by_role('button',name='Guardar operación',exact=True).click()
        await expect(control).to_have_attribute('aria-invalid','true')
        await expect(control).to_have_value('2.000001')
        error_id=await control.get_attribute('aria-describedby')
        await expect(self.page.locator('[id="'+error_id+'"]')).to_have_text('Cantidad inválida.')
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_have_count(0)
        self.assertEqual(1,len(self.writes))

    async def test_unknown_stock_is_not_presented_as_zero_and_exact_count_is_submitted(self):
        await self.prepare_correction()
        await expect(self.page.get_by_role('cell', name='Sin existencia confirmada', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell', name='Pendiente de conciliación', exact=True)).to_be_visible()
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='Operación confirmada')).to_be_visible()
        path, body = self.writes[0]
        self.assertEqual('/api/inventory/corrections', path)
        self.assertEqual('2.000001', body['quantity'])
        self.assertEqual('unidad', body['unit_snapshot'])
        self.assertEqual(36, len(body['request_key']))

    async def test_mechanic_sees_only_custody_and_can_report_actual_use(self):
        self.role = 'mechanic'
        await self.page.goto(self.base + '/inventario')
        await expect(self.page.get_by_role('heading', name='Entregas bajo responsabilidad')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Existencias reales')).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Solicitar conciliación o ajuste')).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Recibir devolución física')).to_have_count(0)
        await self.page.get_by_role('button', name='Registrar utilización').click()
        await self.page.get_by_label('Cantidad', exact=True).fill('1.000001')
        await self.page.get_by_label('Motivo', exact=True).fill('Uso real confirmado')
        await self.page.get_by_role('button', name='Confirmar uso real', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='Operación confirmada')).to_be_visible()
        self.assertNotIn('/api/inventory/stock', self.paths)
        self.assertEqual('/api/workshop/orders/order-1/usages', self.writes[0][0])
        self.assertEqual('delivery-1', self.writes[0][1]['delivery_id'])
        self.assertEqual('assignment-1', self.writes[0][1]['mechanic_assignment_id'])

    async def test_secretary_cannot_decide_inventory_or_purchase_authorizations(self):
        self.role = 'secretary'
        await self.page.goto(self.base + '/inventario')
        await expect(self.page.get_by_role('heading', name='Correcciones y autorizaciones')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Decidir corrección')).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Registrar utilización')).to_have_count(0)
        await self.page.goto(self.base + '/compras')
        await expect(self.page.get_by_role('heading', name='Solicitudes de compra', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button', name='Decidir solicitud')).to_have_count(0)
        await expect(self.page.get_by_role('button', name='Registrar solicitud de compra')).to_be_visible()

    async def test_uncertain_write_blocks_navigation_and_retry_preserves_exact_payload(self):
        self.lose = True
        await self.prepare_correction()
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        await asyncio.sleep(.2)
        self.assertEqual(1, len(self.writes))
        await expect(self.page.get_by_role('link', name='Compras', exact=True)).to_have_attribute('aria-disabled', 'true')
        await self.page.get_by_role('link', name='Compras', exact=True).click(force=True)
        self.assertTrue(self.page.url.endswith('/inventario'))
        self.page.remove_listener('dialog', self.accept)
        dismiss = lambda dialog: dialog.dismiss()
        self.page.on('dialog', dismiss)
        await self.page.get_by_role('button', name='Reintentar la misma operación').click()
        self.assertEqual(1, len(self.writes))
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        self.page.remove_listener('dialog', dismiss)
        self.page.on('dialog', self.accept)
        await self.page.get_by_role('button', name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)
        self.assertEqual(self.writes[0], self.writes[1])

    async def test_validation_preserves_form_without_claiming_success(self):
        self.status = 422
        await self.prepare_correction()
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_label('Corrección de inventario', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('alert').filter(has_text='Cantidad inválida.')).to_be_visible()
        control=self.page.get_by_label('Cantidad',exact=True)
        await expect(control).to_have_attribute('aria-invalid','true')
        error_id=await control.get_attribute('aria-describedby')
        self.assertTrue(error_id)
        await expect(self.page.locator('[id="'+error_id+'"]')).to_be_visible()
        await expect(control).to_have_value('2.000001')
        await self.page.get_by_role('button',name='Cancelar',exact=True).click()
        await self.page.get_by_role('button',name='Solicitar conciliación o ajuste',exact=True).click()
        await expect(self.page.get_by_label('Cantidad',exact=True)).to_have_attribute('aria-invalid','false')

        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)
        await expect(self.page.get_by_text('Operación confirmada.', exact=False)).to_have_count(0)

    async def test_expired_session_clears_private_panels_after_rejected_write(self):
        self.status = 401
        await self.prepare_correction()
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_text('Inicia sesión en el sistema para continuar.')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Existencias reales')).to_have_count(0)
        await expect(self.page.get_by_role('heading', name='Entregas bajo responsabilidad')).to_have_count(0)

    async def test_role_change_before_write_removes_private_views_and_sends_nothing(self):
        await self.prepare_correction()
        self.role = 'driver'
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_text('No tienes acceso a este módulo.')).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Existencias reales')).to_have_count(0)
        self.assertEqual([], self.writes)

    async def test_unknown_command_survives_session_expiration_during_retry(self):
        self.lose = True
        await self.prepare_correction()
        await self.page.get_by_role('button', name='Guardar operación', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        self.status = 401
        await self.page.get_by_role('button', name='Reintentar la misma operación').click()
        await expect(self.page.get_by_text('Inicia sesión en el sistema para continuar.')).to_be_visible()
        self.assertEqual(2, len(self.writes))
        self.status = 201
        await self.page.get_by_role('button', name='Comprobar sesión').click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        await self.page.get_by_role('button', name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)
        self.assertEqual(3, len(self.writes))
        self.assertEqual(self.writes[0], self.writes[1])
        self.assertEqual(self.writes[0], self.writes[2])
