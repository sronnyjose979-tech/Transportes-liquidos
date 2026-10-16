"""UI contracts with synthetic responses; real PostgreSQL E2E lives separately."""
import unittest
from urllib.parse import urlparse,parse_qs
from playwright.async_api import expect
import test_fleet_documents as support

class AuditBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass=classmethod(support.FleetDocumentsBrowserTest.setUpClass.__func__)
    tearDownClass=classmethod(support.FleetDocumentsBrowserTest.tearDownClass.__func__)
    asyncTearDown=support.FleetDocumentsBrowserTest.asyncTearDown

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.audit_requests=[];self.audit_status=200;self.empty=False
        self.event={'id':'event-1','source':'changes','module':'transport','operation':'official_start','actor_id':1,'actor_name':'Administrador','registered_at':'2026-10-16T06:00:00Z','record_type':'trip','record_id':'trip-1'}

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route);return
        if path.startswith('/api/audit'):
            self.audit_requests.append(route.request)
            if self.audit_status!=200:
                await route.fulfill(status=self.audit_status,json={'message':'Error sintético','errors':{'from':['Fecha inválida']}});return
            if path=='/api/audit':
                query=parse_qs(urlparse(route.request.url).query)
                await route.fulfill(json={'data':{'rows':[] if self.empty else [self.event],'meta':{'current_page':int(query['page'][0]),'last_page':2,'total':0 if self.empty else 2},'timezone':'America/Costa_Rica'}})
            else:await route.fulfill(json={'data':{'event':self.event,'before':{'execution_status':'pending'},'after':{'execution_status':'in_progress'}}})
            return
        if path=='/api/users':
            await route.fulfill(json={'data':[{'id':1,'name':'Administrador'}],'meta':{'current_page':1,'last_page':1}});return
        await route.fulfill(json={'data':[],'meta':{'current_page':1,'last_page':1}})

    async def test_filters_pagination_details_and_read_only_responsive_view(self):
        await self.page.goto(self.base+'/auditoria')
        await self.page.get_by_label('Desde',exact=True).fill('2026-10-14')
        await self.page.get_by_label('Hasta',exact=True).fill('2026-10-16')
        await self.page.get_by_label('Módulo',exact=True).select_option('transport')
        await self.page.get_by_label('Usuario',exact=True).select_option('1')
        await self.page.get_by_label('Tipo de operación',exact=True).fill('official_start')
        await self.page.get_by_role('button',name='Consultar auditoría',exact=True).click()
        await expect(self.page.get_by_role('table',name='Operaciones administrativas',exact=True)).to_be_visible()
        query=parse_qs(urlparse(self.audit_requests[-1].url).query)
        for key,value in {'from':'2026-10-14','to':'2026-10-16','actor_id':'1','module':'transport','operation':'official_start'}.items():self.assertEqual([value],query[key])
        await self.page.get_by_role('button',name='Siguiente',exact=True).click()
        await expect(self.page.get_by_text('Página 2 de 2',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Ver detalle event-1',exact=True).click()
        await expect(self.page.get_by_role('table',name='Cambios registrados',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('cell',name='pending',exact=True)).to_be_visible()
        self.assertTrue(all(r.method=='GET' for r in self.audit_requests))
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_errors_clear_results_and_validation_and_empty_states_are_visible(self):
        await self.page.goto(self.base+'/auditoria')
        button=self.page.get_by_role('button',name='Consultar auditoría',exact=True)
        await button.click();await expect(self.page.get_by_role('table')).to_have_count(1)
        self.audit_status=500;await button.click()
        await expect(self.page.get_by_role('alert')).to_be_visible();await expect(self.page.get_by_role('table')).to_have_count(0)
        self.audit_status=422;await button.click();await expect(self.page.get_by_text('from: Fecha inválida',exact=True)).to_be_visible()
        self.audit_status=200;self.empty=True;await button.click();await expect(self.page.get_by_text('Sin operaciones para estos filtros.',exact=True)).to_be_visible()

    async def test_session_expiry_removes_private_results(self):
        await self.page.goto(self.base+'/auditoria')
        await self.page.get_by_role('button',name='Consultar auditoría',exact=True).click()
        await expect(self.page.get_by_role('table')).to_have_count(1)
        self.audit_status=401
        await self.page.get_by_role('button',name='Consultar auditoría',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('table')).to_have_count(0)

    async def test_secretary_cannot_consult_audit(self):
        self.role='secretary';await self.page.goto(self.base+'/auditoria')
        await expect(self.page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('button',name='Consultar auditoría',exact=True)).to_have_count(0)
        self.assertEqual([],self.audit_requests)
