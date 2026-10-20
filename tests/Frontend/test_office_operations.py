"""Exercise existing write contracts inside the persistent root, without a database."""
import os
from unittest.mock import patch
import test_supply
import test_billing
import test_expenses
from urllib.parse import urlparse
from playwright.async_api import expect
from test_office_navigation import PATHS

class OfficeHarness:
    @classmethod
    def setUpClass(cls):
        with patch.dict(os.environ, TRANSPORTE_BROWSER_OFFICE='1'):
            super().setUpClass()

    async def asyncSetUp(self):
        await super().asyncSetUp()
        async def document(route):
            if route.request.resource_type != 'document' or urlparse(route.request.url).path not in PATHS:
                await route.fallback()
                return
            response=await route.fetch(url=route.request.url+('\u0026' if '?' in route.request.url else '?')+'office_role='+self.role)
            await route.fulfill(response=response)
        await self.context.route('**/*',document)

class OfficeSupplyBrowserTest(OfficeHarness, test_supply.SupplyBrowserTest):
    pass

class OfficeBillingBrowserTest(OfficeHarness, test_billing.BillingBrowserTest):
    pass

class OfficeExpensesBrowserTest(OfficeHarness, test_expenses.ExpensesBrowserTest):
    pass
