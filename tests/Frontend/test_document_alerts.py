"""Synthetic API UI checks, separate from PostgreSQL tests and real E2E."""
import unittest
from urllib.parse import urlparse,parse_qs
from playwright.async_api import expect
import test_fleet_documents as support

class DocumentAlertsBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass=classmethod(support.FleetDocumentsBrowserTest.setUpClass.__func__)
    tearDownClass=classmethod(support.FleetDocumentsBrowserTest.tearDownClass.__func__)
    asyncTearDown=support.FleetDocumentsBrowserTest.asyncTearDown

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.lost=False;self.status=200;self.writes=[];self.queries=[];self.review=None
        self.page.on('dialog',lambda d:d.accept())

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route);return
        if path=='/api/document-alerts/summary':
            await route.fulfill(json={'data':{'expired':1,'expiring_soon':2,'unreviewed':3}});return
        if path.endswith('/review'):
            self.writes.append((path,route.request.post_data_json))
            self.review={'at':'2026-10-16T14:00:00Z','by':{'id':'1','name':'Responsable'},'validity_at_review':'expired'}
            if self.lost:self.lost=False;await route.abort('failed');return
            await route.fulfill(json={'data':{'id':'review-1'}});return
        if path=='/api/document-alerts':
            self.queries.append(parse_qs(urlparse(route.request.url).query))
            if self.status!=200:await route.fulfill(status=self.status,json={'message':'Rechazo sintético'});return
            row={'id':'doc-1','document_type_id':'type-1','document_type':'DEKRA','asset':{'id':'asset-1','code':'CAB-01','type':'tractor_unit'},'expires_on':'2026-10-15','days_remaining':-1,'status':'expired','review':self.review,'original_href':'/api/documents/doc-1'}
            await route.fulfill(json={'data':[row], 'meta':{'current_page':1,'last_page':1,'total':1}});return
        await route.fulfill(json={'data':[],'meta':{'current_page':1,'last_page':1,'total':0}})

    async def test_filters_status_days_original_link_and_review_refresh(self):
        self.role='secretary'
        await self.page.goto(self.base+'/alertas')
        await expect(self.page.get_by_role('cell',name='1 días de atraso',exact=True)).to_be_visible()
        await self.page.get_by_label('Vencimiento',exact=True).select_option('expired')
        await self.page.get_by_label('Vence desde',exact=True).fill('2026-10-10')
        await self.page.get_by_role('button',name='Consultar vencimientos',exact=True).click()
        await expect(self.page.get_by_role('link',name='Ver registro original',exact=True)).to_have_attribute('href','/api/documents/doc-1')
        self.assertEqual(['expired'],self.queries[-1]['status'])
        self.assertEqual(['2026-10-10'],self.queries[-1]['from'])
        await self.page.get_by_role('button',name='Marcar revisada',exact=True).click()
        await expect(self.page.get_by_text('Revisada por Responsable',exact=False)).to_be_visible()
        await expect(self.page.get_by_role('cell',name='Vencido',exact=True)).to_be_visible()
        self.assertEqual({},self.writes[0][1])

    async def test_unknown_response_requires_same_explicit_review_without_auto_retry(self):
        await self.page.goto(self.base+'/alertas');self.lost=True
        await self.page.get_by_role('button',name='Marcar revisada',exact=True).click()
        await expect(self.page.get_by_role('button',name='Confirmar la misma revisión',exact=True)).to_be_visible()
        self.assertEqual(1,len(self.writes))
        await self.page.get_by_role('button',name='Confirmar la misma revisión',exact=True).click()
        await expect(self.page.get_by_text('Revisada por Responsable',exact=False)).to_be_visible()
        self.assertEqual(self.writes[0],self.writes[1])

    async def test_errors_remove_previous_results_and_unauthorized_roles_have_no_actions(self):
        await self.page.goto(self.base+'/alertas')
        await expect(self.page.get_by_role('button',name='Marcar revisada',exact=True)).to_be_visible()
        self.status=500
        await self.page.get_by_role('button',name='Consultar vencimientos',exact=True).click()
        await expect(self.page.get_by_role('alert')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Marcar revisada',exact=True)).to_have_count(0)
        self.role='driver';await self.page.reload()
        await expect(self.page.get_by_role('button',name='Consultar vencimientos',exact=True)).to_have_count(0)

    async def test_dashboard_indicators_link_to_filtered_alerts(self):
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_role('link',name='Vencidos: 1',exact=True)).to_have_attribute('href','/alertas?status=expired')
        await expect(self.page.get_by_role('link',name='Próximos a vencer: 2',exact=True)).to_be_visible()
        for width in [768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
