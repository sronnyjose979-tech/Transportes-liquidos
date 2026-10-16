"""Real Chromium, Laravel sessions and isolated PostgreSQL18; no API mocks."""
import unittest
from playwright.async_api import expect
import test_system_navigation as navigation
import test_reports as reports

class AuditE2E(unittest.IsolatedAsyncioTestCase):
    reports=classmethod(reports.ReportsE2E.reports.__func__)

    async def asyncSetUp(self):
        await navigation.SystemNavigationE2E.asyncSetUp(self)
        self.fixture=self.reports('fixture')

    async def consult(self,module=''):
        await self.page.get_by_label('Módulo',exact=True).select_option(module)
        async with self.page.expect_response(lambda r:'/api/audit?' in r.url) as event:
            await self.page.get_by_role('button',name='Consultar auditoría',exact=True).click()
        response=await event.value
        self.assertEqual(200,response.status,await response.text())
        return (await response.json())['data']

    async def test_real_ledgers_match_without_writes_or_duplicates_and_details_are_safe(self):
        before=self.reports('snapshot')
        await self.login('administrator')
        await self.page.get_by_role('link',name='Auditoría',exact=True).click()
        await expect(self.page).to_have_url(self.base+'/auditoria')
        for module,table in [('billing','billing_operations'),('expenses','expense_operations'),('workshop','workshop_operations')]:
            data=await self.consult(module)
            self.assertEqual(before[table],data['meta']['total'])
            self.assertEqual(len(data['rows']),len({(r['source'],r['id']) for r in data['rows']}))
            row=data['rows'][0]
            async with self.page.expect_response(lambda r:'/api/audit/'+row['source']+'/' in r.url) as event:
                await self.page.get_by_role('button',name='Ver detalle '+row['id'],exact=True).click()
            response=await event.value;self.assertEqual(200,response.status)
            detail=(await response.json())['data']
            self.assertEqual(row,detail['event'])
            text=await response.text()
            for forbidden in ['storage_key','password','fingerprint','request_key','confirmed_result','email']:
                self.assertNotIn('"'+forbidden+'"',text)
        await self.consult('transport')
        await self.page.get_by_label('Tipo de operación',exact=True).fill('trip_registered')
        data=await self.consult('transport')
        self.assertTrue(any(r['record_id']==self.fixture['trip_id'] for r in data['rows']))
        self.assertEqual(before,self.reports('snapshot'))
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        self.assertEqual([],self.errors)

    async def test_role_restrictions_and_expired_session(self):
        for role in ['secretary','mechanic','driver']:
            await self.login(role)
            self.assertEqual(403,(await self.context.request.get(self.base+'/auditoria')).status)
            self.assertEqual(403,(await self.api('/audit'))['status'])
            await expect(self.page.get_by_role('link',name='Auditoría',exact=True)).to_have_count(0)
            async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')) as event:
                await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
            self.assertEqual(204,(await event.value).status)
            await self.page.goto(self.base+'/login')
        await self.login('administrator');await self.page.goto(self.base+'/auditoria');await self.consult('billing')
        self.command('expire-sessions',{'role':'administrator'})
        await self.page.get_by_role('button',name='Consultar auditoría',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('table')).to_have_count(0)
        self.assertEqual(401,(await self.api('/audit'))['status'])

for _name,_value in navigation.SystemNavigationE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in AuditE2E.__dict__:
        setattr(AuditE2E,_name,_value)

if __name__=='__main__':unittest.main()
