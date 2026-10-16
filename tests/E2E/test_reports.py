"""Real Chromium -> read-only Vue -> Laravel/session -> isolated PostgreSQL 18."""
import json
import subprocess
import unittest
from playwright.async_api import expect
import test_document_module as support
import test_system_navigation as navigation

class ReportsE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def reports(cls,action):
        result=subprocess.run(['php',str(support.ROOT/'tests/E2E/reports-console.php'),action],input='{}',text=True,capture_output=True,check=True,cwd=support.ROOT)
        return json.loads(result.stdout)

    async def asyncSetUp(self):
        await navigation.SystemNavigationE2E.asyncSetUp(self)
        self.fixture=self.reports('fixture')

    async def choose(self,kind):
        await self.page.get_by_label('Reporte',exact=True).select_option(kind)
        async with self.page.expect_response(lambda r:'/api/reports/'+kind+'?' in r.url) as event:
            await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        response=await event.value
        self.assertEqual(200,response.status,await response.text())
        return (await response.json())['data']

    async def test_reports_match_original_records_without_writes_or_duplicate_totals(self):
        before=self.reports('snapshot')
        await self.login('administrator')
        await self.page.get_by_role('link',name='Reportes',exact=True).click()
        await expect(self.page).to_have_url(self.base+'/reportes')
        trip=await self.choose('transport')
        self.assertTrue(any(r['id']==self.fixture['trip_id'] and r['status']=='completed' for r in trip['rows']))
        await self.page.get_by_label('Cliente',exact=True).select_option(self.fixture['customer_id'])
        billing=await self.choose('billing')
        self.assertEqual(1,billing['meta']['total'])
        self.assertEqual('100.03',billing['summary']['invoices'][0]['invoiced'])
        self.assertEqual('0.03',billing['summary']['invoices'][0]['paid_to_date'])
        self.assertEqual('100.00',billing['summary']['invoices'][0]['balance'])
        original=await self.api('/billing/invoices/'+self.fixture['invoice_id'])
        self.assertEqual(original['body']['data']['balance'],billing['rows'][0]['balance'])
        expenses=await self.choose('expenses')
        self.assertEqual({'CRC','USD'},{r['currency'] for r in expenses['summary']})
        self.assertTrue(all(r['status']=='pending' for r in expenses['summary']))
        workshop=await self.choose('workshop')
        self.assertEqual(2,next(r for r in workshop['rows'] if r['id']==self.fixture['order_id'])['activities'])
        await self.page.get_by_label('Vehículo',exact=True).select_option(self.fixture['asset_id'])
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('table').last.get_by_role('cell',name=self.fixture['order_id'],exact=True)).to_be_visible()
        stock=await self.choose('stock')
        self.assertEqual('2.123456',next(r for r in stock['rows'] if r['id']==self.fixture['part_id'])['available'])
        movements=await self.choose('movements')
        self.assertTrue(any(r['warehouse_delta']=='2.123456' for r in movements['rows']))
        self.assertEqual(before,self.reports('snapshot'))
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        await self.page.get_by_role('link',name='Abrir módulo original para consultar detalles',exact=True).click()
        await expect(self.page).to_have_url(self.base+'/inventario')

    async def test_server_denies_other_roles_and_guest_and_expired_session(self):
        for role in ['secretary','mechanic','driver']:
            await self.login(role)
            self.assertEqual(403,(await self.context.request.get(self.base+'/reportes')).status)
            self.assertEqual(403,(await self.api('/reports/billing'))['status'])
            await expect(self.page.get_by_role('link',name='Reportes',exact=True)).to_have_count(0)
            async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')) as event:
                await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
            self.assertEqual(204,(await event.value).status)
            await self.page.goto(self.base+'/login')
        self.assertEqual(401,(await self.api('/reports/expenses'))['status'])
        await self.login('administrator');await self.page.goto(self.base+'/reportes')
        await self.choose('expenses')
        self.command('expire-sessions',{'role':'administrator'})
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('table')).to_have_count(0)

for _name,_value in navigation.SystemNavigationE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in ReportsE2E.__dict__:
        setattr(ReportsE2E,_name,_value)

if __name__=='__main__':unittest.main()
