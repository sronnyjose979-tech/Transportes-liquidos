"""Synthetic API contracts in real Chromium; PostgreSQL E2E is separate."""
import unittest
from urllib.parse import urlparse,parse_qs
from playwright.async_api import expect
import test_fleet_documents as support

class ReportsBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass=classmethod(support.FleetDocumentsBrowserTest.setUpClass.__func__)
    tearDownClass=classmethod(support.FleetDocumentsBrowserTest.tearDownClass.__func__)
    asyncTearDown=support.FleetDocumentsBrowserTest.asyncTearDown

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.report_requests=[];self.report_status=200;self.empty=False

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route);return
        if '/api/reports/' in path:
            self.report_requests.append(route.request)
            if self.report_status!=200:
                await route.fulfill(status=self.report_status,json={'message':'Error sintético','errors':{'from':['Fecha inválida']}});return
            kind=path.rsplit('/',1)[1]
            rows=[] if self.empty else [{'id':'source-1','currency':'CRC','amount':'0.03','status':'pending','concept':'pending'}]
            summary=[] if self.empty else [{'currency':'CRC','status':'pending','amount':'0.03','count':1},{'currency':'USD','status':'approved','amount':'0.02','count':1}]
            if kind=='billing':summary={'invoices':[{'currency':'CRC','invoiced':'0.03','paid_to_date':'0.01','balance':'0.02'}],'receipts':[{'currency':'CRC','received':'0.01'}]}
            await route.fulfill(json={'data':{'kind':kind,'rows':rows,'summary':summary,'meta':{'current_page':int(parse_qs(urlparse(route.request.url).query)['page'][0]),'last_page':2,'total':0 if self.empty else 2},'note':'Solo datos persistidos.','module_href':'/gastos','timezone':'America/Costa_Rica'}});return
        await route.fulfill(json={'data':[],'meta':{'current_page':1,'last_page':1}})

    async def test_filters_exact_currency_and_read_only_table_pagination(self):
        await self.page.goto(self.base+'/reportes')
        await self.page.get_by_label('Reporte',exact=True).select_option('expenses')
        await self.page.get_by_label('Desde',exact=True).fill('2026-10-14')
        await self.page.get_by_label('Hasta',exact=True).fill('2026-10-16')
        await self.page.get_by_label('Estado o movimiento',exact=True).select_option('pending')
        await self.page.get_by_label('Moneda',exact=True).select_option('CRC')
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('cell',name='0.03',exact=True)).to_have_count(2)
        await expect(self.page.get_by_role('cell',name='pending',exact=True)).to_be_visible()
        query=parse_qs(urlparse(self.report_requests[-1].url).query)
        self.assertEqual(['CRC'],query['currency']);self.assertEqual(['pending'],query['status']);self.assertEqual(['2026-10-14'],query['from'])
        await self.page.get_by_role('button',name='Siguiente',exact=True).click()
        await expect(self.page.get_by_text('2 registros · página 2 de 2',exact=True)).to_be_visible()
        self.assertTrue(all(r.method=='GET' for r in self.report_requests))
        await expect(self.page.get_by_role('link',name='Abrir módulo original para consultar detalles')).to_have_attribute('href','/gastos')

    async def test_stock_omits_dates_and_empty_results_are_explicit(self):
        await self.page.goto(self.base+'/reportes');self.empty=True
        await self.page.get_by_label('Reporte',exact=True).select_option('stock')
        await expect(self.page.get_by_label('Desde',exact=True)).to_have_count(0)
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_text('0 registros · página 1 de 2',exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Existencias · registros originales: sin registros para estos filtros.',exact=True)).to_be_visible()
        self.assertNotIn('from',parse_qs(urlparse(self.report_requests[-1].url).query))

    async def test_billing_distinguishes_invoicing_receipts_and_balance(self):
        await self.page.goto(self.base+'/reportes')
        await self.page.get_by_label('Reporte',exact=True).select_option('billing')
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('table',name='Cobros efectivamente registrados en el período',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('columnheader',name='Saldo actual',exact=True)).to_be_visible()
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_server_error_clears_old_results_and_validation_is_visible(self):
        await self.page.goto(self.base+'/reportes')
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('table')).to_have_count(2)
        self.report_status=500
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('alert')).to_be_visible()
        await expect(self.page.get_by_role('table')).to_have_count(0)
        self.report_status=422
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_text('from: Fecha inválida',exact=True)).to_be_visible()

    async def test_secretary_does_not_get_report_controls(self):
        self.role='secretary'
        await self.page.goto(self.base+'/reportes')
        await expect(self.page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Consultar reporte',exact=True)).to_have_count(0)
        self.assertEqual([],self.report_requests)
