"""Purchase-request browser contracts with intercepted synthetic APIs, not E2E persistence."""
import asyncio
import unittest
from urllib.parse import urlparse

import test_fleet_documents as browser_support
from playwright.async_api import expect

PART = 'a0000000-0000-4000-8000-000000000001'
SUPPLIER = 'b0000000-0000-4000-8000-000000000001'
NEED = 'c0000000-0000-4000-8000-000000000001'
PURCHASE = 'd0000000-0000-4000-8000-000000000001'


class PurchaseRequestsBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        browser_support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        browser_support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncSetUp(self):
        self.records = []
        self.writes = []
        self.reject = False
        self.lose_response = False
        await browser_support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.accept_dialog = lambda dialog: dialog.accept()
        self.page.on('dialog', self.accept_dialog)

    async def asyncTearDown(self):
        await browser_support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self, route):
        req = route.request
        path = urlparse(req.url).path
        if path == '/api/session':
            await route.fulfill(json={'data': {'id': '7', 'login': 'admin', 'roles': [self.role]}})
            return
        catalogs = {
            '/api/catalogs/spare-parts': [{'id': PART, 'code': 'FILT-01', 'description': 'Filtro', 'unit': 'unidad'}],
            '/api/catalogs/suppliers': [{'id': SUPPLIER, 'name': 'Proveedor real'}],
            '/api/workshop/material-needs': [{'id': NEED, 'description': 'Filtro necesario', 'quantity': '2.000001', 'spare_part_id': PART, 'is_effective': True, 'work_order_id': 'OT-01'}],
        }
        if path in catalogs:
            await route.fulfill(json={'data': catalogs[path], 'meta': {'current_page': 1, 'last_page': 1}})
            return
        if path.startswith('/api/purchasing/requests'):
            if req.method == 'GET':
                data = self.records if path == '/api/purchasing/requests' else self.records[0]
                await route.fulfill(json={'data': data, 'meta': {'current_page': 1, 'last_page': 1}})
                return
            body = req.post_data_json
            self.writes.append((path, body))
            if self.reject:
                await route.fulfill(status=422, json={'message': 'Cantidad inválida.', 'errors': {'items.0.quantity': ['Cantidad inválida.']}})
                return
            if path.endswith('/decisions'):
                self.records[0]['decision'] = {'id': 'decision-1', 'decision': body['decision'], 'reason': body['reason'], 'decided_by': {'id': '7', 'name': 'Administrador'}, 'registered_at': '2026-10-13T10:00:00-06:00'}
            elif not self.records:
                self.records.append({'id': PURCHASE, 'supplier': {'id': SUPPLIER, 'name': 'Proveedor real'}, 'reason': body['reason'], 'requested_by': {'id': '7', 'name': 'Administrador'}, 'registered_at': '2026-10-13T10:00:00-06:00', 'decision': None, 'items': [{'id': 'item-1', 'spare_part_id': PART, 'code': 'FILT-01', 'description': 'Filtro', 'unit': 'unidad', 'quantity': body['items'][0]['quantity'], 'workshop_material_need_id': body['items'][0].get('workshop_material_need_id')}]})
            if self.lose_response:
                self.lose_response = False
                await route.abort('connectionfailed')
                return
            await route.fulfill(status=201, json={'data': self.records[0]})
            return
        await route.fulfill(status=404, json={'message': 'Contrato no esperado.'})

    async def prepare_request(self, linked=True):
        await self.page.goto(self.base + '/administracion/compras')
        await self.page.get_by_role('button', name='Registrar solicitud de compra', exact=True).click()
        await self.page.get_by_label('Proveedor', exact=True).select_option(SUPPLIER)
        await self.page.get_by_label('Repuesto 1', exact=True).select_option(PART)
        await self.page.get_by_label('Cantidad 1', exact=True).fill('2.000001')
        if linked:
            await self.page.get_by_label('Necesidad de Taller 1 (opcional)', exact=True).select_option(NEED)
        await self.page.get_by_label('Motivo de compra', exact=True).fill('Atender reparación')

    async def test_create_linked_need_and_administrator_decision_preserve_exact_quantities(self):
        await self.prepare_request()
        await self.page.get_by_role('button', name='Guardar solicitud', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Atender reparación', exact=True)).to_be_visible()
        body = self.writes[0][1]
        self.assertEqual([{'spare_part_id': PART, 'quantity': '2.000001', 'workshop_material_need_id': NEED}], body['items'])
        self.assertEqual(SUPPLIER, body['supplier_id'])
        self.assertEqual(36, len(body['request_key']))
        await self.page.get_by_role('button', name='Consultar solicitud', exact=True).click()
        await self.page.get_by_role('button', name='Registrar decisión administrativa', exact=True).click()
        await self.page.get_by_label('Motivo de la decisión', exact=True).fill('Alcance revisado')
        await self.page.get_by_role('button', name='Guardar decisión', exact=True).click()
        await expect(self.page.get_by_role('cell', name='Aprobada', exact=True)).to_be_visible()
        self.assertEqual({'decision', 'reason', 'request_key'}, set(self.writes[1][1]))
        self.assertNotEqual(body['request_key'], self.writes[1][1]['request_key'])
        await self.page.get_by_role('button', name='Consultar solicitud', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Historial de decisión')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Registrar decisión administrativa', exact=True)).to_have_count(0)

    async def test_lost_confirmed_response_requires_manual_exact_retry_without_duplicate(self):
        self.lose_response = True
        await self.prepare_request(linked=False)
        await self.page.get_by_role('button', name='Guardar solicitud', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        await asyncio.sleep(.25)
        self.assertEqual(1, len(self.writes))
        await expect(self.page.get_by_role('button', name='Registrar solicitud de compra', exact=True)).to_be_disabled()
        self.page.remove_listener('dialog', self.accept_dialog)
        dismiss_dialog = lambda dialog: dialog.dismiss()
        self.page.on('dialog', dismiss_dialog)
        await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Registrar solicitud de compra', exact=True)).to_be_disabled()
        self.assertEqual(1, len(self.writes))
        self.page.remove_listener('dialog', dismiss_dialog)
        self.page.on('dialog', self.accept_dialog)
        await self.page.get_by_role('button', name='Consultar estado confirmado', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Registrar solicitud de compra', exact=True)).to_be_disabled()
        await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)
        await expect(self.page.get_by_role('cell', name='Atender reparación', exact=True)).to_be_visible()
        self.assertEqual(self.writes[0], self.writes[1])
        self.assertEqual(1, len(self.records))
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)

    async def test_validation_keeps_form_and_does_not_claim_confirmation(self):
        self.reject = True
        await self.prepare_request()
        await self.page.get_by_role('button', name='Guardar solicitud', exact=True).click()
        await expect(self.page.get_by_role('alert')).to_contain_text('Cantidad inválida.')
        await expect(self.page.get_by_label('Solicitud de compra', exact=True)).to_be_visible()
        await expect(self.page.get_by_role('heading', name='Operación pendiente de confirmación')).to_have_count(0)
        self.assertEqual(1, len(self.writes))
        self.assertEqual([], self.records)

    async def test_secretary_cannot_manage_purchase_requests(self):
        self.role = 'secretary'
        await self.page.goto(self.base + '/administracion/compras')
        await expect(self.page.get_by_role('heading', name='Acceso no autorizado')).to_be_visible()
        await expect(self.page.get_by_role('button', name='Registrar solicitud de compra', exact=True)).to_have_count(0)
        self.assertEqual([], self.writes)
