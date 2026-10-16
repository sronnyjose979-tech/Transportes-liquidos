"""Real Chromium + Laravel + isolated PostgreSQL: documents, reviews and renewal."""
import asyncio
import unittest
from playwright.async_api import expect
import test_document_module as support
import test_system_navigation as navigation

class DocumentAlertsE2E(unittest.IsolatedAsyncioTestCase):
    counter=7000
    async def asyncSetUp(self):
        await navigation.SystemNavigationE2E.asyncSetUp(self)
        type(self).counter+=1
        self.fixture=self.command('fixture',{'tag':f'E2E-{self.counter:04}'})
        self.command('real-clock') # Avoid expired browser cookies from an old boundary fixture.
        self.page.on('dialog',lambda d:asyncio.create_task(d.accept()))

    async def upload(self,expiration='2026-10-10',previous=None):
        asset=self.fixture['tractor']['id']
        path='/api/fleet-assets/'+asset+'/documents'+('/'+previous+'/renew' if previous else '')
        data={'expires_on':expiration,'file':{'name':'document.pdf','mimeType':'application/pdf','buffer':(support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf').read_bytes()}}
        if previous is None:data['document_type_id']=self.fixture['types'][0]['id']
        token=await self.page.evaluate("decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'')")
        response=await self.context.request.post(self.base+path,headers={'Accept':'application/json','X-XSRF-TOKEN':token},multipart=data)
        self.assertEqual(201,response.status,await response.text())
        return (await response.json())['data']['id']

    async def test_secretary_reviews_without_modification_and_renewal_removes_old_alert(self):
        await self.login('secretary')
        document=await self.upload()
        source=self.command('inspect',{'asset_id':self.fixture['tractor']['id']})
        await self.page.goto(self.base+'/alertas')
        row=self.page.get_by_role('row').filter(has_text=self.fixture['tractor']['code'])
        await expect(row.get_by_role('link',name='Ver registro original',exact=True)).to_have_attribute('href','/api/documents/'+document)
        await row.get_by_role('button',name='Marcar revisada',exact=True).click()
        await expect(row.get_by_text('Revisada por',exact=False)).to_be_visible()
        self.assertEqual(source,self.command('inspect',{'asset_id':self.fixture['tractor']['id']}))
        await self.page.reload()
        await expect(row.get_by_text('Revisada por',exact=False)).to_be_visible()
        renewed=await self.upload('2027-01-01',document)
        await self.page.get_by_role('button',name='Consultar vencimientos',exact=True).click()
        await expect(self.page.get_by_role('row').filter(has_text=self.fixture['tractor']['code'])).to_have_count(0)
        old=await self.api('/documents/'+document)
        self.assertEqual('2026-10-10',old['body']['data']['expires_on']);self.assertFalse(old['body']['data']['is_current'])
        self.assertEqual(renewed,self.command('inspect',{'asset_id':self.fixture['tractor']['id']})['current'][0]['document_id'])
        self.assertEqual(409,(await self.api('/document-alerts/'+renewed+'/review',{}))['status'])

    async def test_concurrent_review_lost_response_permissions_and_dashboard(self):
        await self.login('administrator')
        document=await self.upload()
        await self.page.goto(self.base+'/dashboard')
        await expect(self.page.get_by_role('link',name='Vencidos:',exact=False)).to_be_visible()
        await self.page.goto(self.base+'/alertas')
        path='/api/document-alerts/'+document+'/review'
        self.proxy.drop_response_statuses=(200,)
        self.proxy.drop_response_path=path
        row=self.page.get_by_role('row').filter(has_text=self.fixture['tractor']['code'])
        await row.get_by_role('button',name='Marcar revisada',exact=True).click()
        await expect(self.page.get_by_role('button',name='Confirmar la misma revisión',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Confirmar la misma revisión',exact=True).click()
        await expect(row.get_by_text('Revisada por',exact=False)).to_be_visible()
        document=await self.upload('2026-10-10',document)
        responses=await asyncio.gather(self.api('/document-alerts/'+document+'/review',{}),self.api('/document-alerts/'+document+'/review',{}))
        self.assertEqual([200,200],[r['status'] for r in responses])
        self.assertEqual(responses[0]['body']['data'],responses[1]['body']['data'])
        summary=(await self.api('/document-alerts?fleet_asset_id='+self.fixture['tractor']['id']))['body']
        self.assertEqual(1,summary['meta']['total'])
        for width in [768,390]:
            await self.page.set_viewport_size({'width':width,'height':900})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.page.wait_for_url('**/login')
        await self.login('driver')
        self.assertEqual(403,(await self.api('/document-alerts'))['status'])
        self.assertEqual(403,(await self.context.request.get(self.base+'/alertas')).status)

for _name,_value in navigation.SystemNavigationE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in DocumentAlertsE2E.__dict__:
        setattr(DocumentAlertsE2E,_name,_value)

if __name__=='__main__':unittest.main()
