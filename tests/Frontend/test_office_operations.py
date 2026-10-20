"""Exercise existing write contracts inside the persistent root, without a database."""
import os
import unittest
import test_fleet_documents as support
from unittest.mock import patch
import test_supply
import test_billing
import test_expenses
import test_workshop
from urllib.parse import urlparse
from playwright.async_api import expect
from test_office_navigation import PATHS

class OfficeHarness:
    @classmethod
    def setUpClass(cls):
        with patch.dict(os.environ, TRANSPORTE_BROWSER_OFFICE='1'):
            support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    async def asyncSetUp(self):
        await self.contract.asyncSetUp(self)
        async def document(route):
            path=urlparse(route.request.url).path
            if route.request.headers.get('x-office-navigation')=='1' and path in PATHS:
                await route.fulfill(headers={'X-Session-User':'7'},json={'data':{'page':PATHS[path],'session':{'id':'7','login':'synthetic','roles':[self.role]},'navigation':{'home':'/dashboard','modules':[{'key':'purchasing','label':'Compras','href':'/compras'}]}}})
                return
            if route.request.resource_type != 'document' or urlparse(route.request.url).path not in PATHS:
                await route.fallback()
                return
            response=await route.fetch(url=route.request.url+('\u0026' if '?' in route.request.url else '?')+'office_role='+self.role)
            await route.fulfill(response=response)
        await self.context.route('**/*',document)

    @classmethod
    def tearDownClass(cls):
        support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncTearDown(self):
        await self.contract.asyncTearDown(self)

    async def api(self,route):
        await self.contract.api(self,route)

    pagination=support.FleetDocumentsBrowserTest.pagination

class OfficeSupplyBrowserTest(OfficeHarness, unittest.IsolatedAsyncioTestCase):
    contract=test_supply.SupplyBrowserTest
    prepare_correction=test_supply.SupplyBrowserTest.prepare_correction
    test_uncertain_write_blocks_navigation_and_retry_preserves_exact_payload=test_supply.SupplyBrowserTest.test_uncertain_write_blocks_navigation_and_retry_preserves_exact_payload
    test_pending_operation_shows_progress_and_prevents_second_submission=test_supply.SupplyBrowserTest.test_pending_operation_shows_progress_and_prevents_second_submission
    test_purchase_validation_is_attached_to_the_requested_quantity=test_supply.SupplyBrowserTest.test_purchase_validation_is_attached_to_the_requested_quantity
    test_mechanic_sees_only_custody_and_can_report_actual_use=test_supply.SupplyBrowserTest.test_mechanic_sees_only_custody_and_can_report_actual_use
    test_secretary_cannot_decide_inventory_or_purchase_authorizations=test_supply.SupplyBrowserTest.test_secretary_cannot_decide_inventory_or_purchase_authorizations

    async def test_uncertain_operation_survives_expired_security_and_remount(self):
        self.lose=True
        await self.prepare_correction()
        await self.page.get_by_role('button',name='Guardar operación',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_be_visible()
        self.status=419
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_text('El contenido privado se ha ocultado.',exact=True)).to_be_visible()
        self.status=201
        await self.page.get_by_role('button',name='Volver a comprobar',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_be_visible()
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_have_count(0)
        self.assertEqual(3,len(self.writes))
        self.assertEqual(self.writes[0],self.writes[1])
        self.assertEqual(self.writes[0],self.writes[2])

class OfficeBillingBrowserTest(OfficeHarness, unittest.IsolatedAsyncioTestCase):
    contract=test_billing.BillingBrowserTest
    test_lost_invoice_response_requires_manual_identical_retry=test_billing.BillingBrowserTest.test_lost_invoice_response_requires_manual_identical_retry
    test_secretary_cannot_approve_amount_and_can_propose=test_billing.BillingBrowserTest.test_secretary_cannot_approve_amount_and_can_propose
    test_confirmed_partial_payment_clears_form_and_blocks_second_click=test_billing.BillingBrowserTest.test_confirmed_partial_payment_clears_form_and_blocks_second_click

class OfficeExpensesBrowserTest(OfficeHarness, unittest.IsolatedAsyncioTestCase):
    contract=test_expenses.ExpensesBrowserTest
    fill_expense=test_expenses.ExpensesBrowserTest.fill_expense
    test_lost_response_explicit_retry_same_key_no_double_click=test_expenses.ExpensesBrowserTest.test_lost_response_explicit_retry_same_key_no_double_click
    test_secretary_cannot_decide_but_can_correct=test_expenses.ExpensesBrowserTest.test_secretary_cannot_decide_but_can_correct

class OfficeWorkshopBrowserTest(OfficeHarness, unittest.IsolatedAsyncioTestCase):
    contract=test_workshop.WorkshopBrowserTest
    enter=test_workshop.WorkshopBrowserTest.enter
    open_order=test_workshop.WorkshopBrowserTest.open_order
    report_form=test_workshop.WorkshopBrowserTest.report_form
    raw_pagination=test_workshop.WorkshopBrowserTest.raw_pagination
    test_uncertain_retry_preserves_exact_body_and_key=test_workshop.WorkshopBrowserTest.test_uncertain_retry_preserves_exact_body_and_key
    test_secretary_cannot_decide=test_workshop.WorkshopBrowserTest.test_secretary_cannot_decide
    test_mechanic_has_no_office_admission_actions=test_workshop.WorkshopBrowserTest.test_mechanic_has_no_office_admission_actions

    async def test_uncertain_command_survives_authorized_remount(self):
        await self.enter()
        form=await self.report_form()
        endpoint=('POST','/api/workshop/reports')
        self.forced[endpoint]=(500,{})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_be_visible()
        self.forced[endpoint]=(419,{})
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_text('El contenido privado se ha ocultado.',exact=True)).to_be_visible()
        self.forced.pop(endpoint)
        await self.page.get_by_role('button',name='Volver a comprobar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_be_visible()
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('heading',name='Detalle de avería')).to_be_visible()
        self.assertEqual(self.sent[0],self.sent[1])
        self.assertEqual(self.sent[0],self.sent[2])

