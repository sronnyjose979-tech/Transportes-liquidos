"""Real Chromium/Laravel/isolated PostgreSQL: need -> purchase request -> decision.
Run: bash tests/run-postgres.sh --e2e-purchase-requests.
Receipts and stock are deliberately excluded pending approved business definitions.
"""
import unittest
import uuid
from datetime import datetime, timezone, timedelta
from playwright.async_api import expect
import test_workshop_module as first


class PurchaseRequestsE2E(unittest.IsolatedAsyncioTestCase):
    async def purchasing(self, path, body=None):
        return await self.page.evaluate("""async ({path,body})=>{
          const headers={Accept:'application/json'};
          if(body!==null){headers['Content-Type']='application/json';headers['X-XSRF-TOKEN']=decodeURIComponent(document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice(11)??'');}
          const r=await fetch('/api/purchasing/'+path,{method:body===null?'GET':'POST',credentials:'same-origin',headers,...(body===null?{}:{body:JSON.stringify(body)})});
          return {status:r.status,body:await r.json()};
        }""", {'path':path,'body':body})

    async def test_real_need_request_decision_and_lost_response(self):
        await self.login('administrator')
        order, _ = await self.report_and_order()
        need = await self.api('orders/'+order+'/material-needs', {
            'request_key':str(uuid.uuid4()), 'description':'Necesidad de compra '+self.fixture['tag'],
            'quantity':'2.000001','spare_part_id':self.fixture['part']['id'],
            'occurred_at':datetime.fromisoformat(self.fixture['starts_local']).replace(tzinfo=timezone(timedelta(hours=-6))).astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),'reason':'Repuesto requerido E2E'})
        self.assertEqual(200,need['status'],need['body'])
        await self.page.goto(self.base+'/administracion/compras')
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.get_by_role('button',name='Registrar solicitud de compra',exact=True).click()
        form=self.page.get_by_role('form',name='Solicitud de compra')
        await self.choose(form,'Proveedor',self.fixture['supplier']['id'],self.fixture['supplier']['name'])
        await self.choose(form,'Repuesto 1',self.fixture['part']['id'],self.fixture['part']['code'])
        await form.get_by_label('Cantidad 1',exact=True).fill('2.000001')
        await form.get_by_label('Buscar Necesidad de Taller 1 (opcional)',exact=True).fill(self.fixture['tag'])
        need_select=form.get_by_label('Necesidad de Taller 1 (opcional)',exact=True)
        need_id=await need_select.locator('option').last.get_attribute('value')
        self.assertTrue(need_id)
        await need_select.select_option(need_id)
        reason='Compra real E2E '+self.fixture['tag']
        await form.get_by_label('Motivo de compra',exact=True).fill(reason)
        self.proxy.drop_response_statuses=(200,201)
        self.proxy.drop_response_path='/api/purchasing/requests'
        await form.get_by_role('button',name='Guardar solicitud',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación',exact=True)).to_be_visible()
        before=await self.purchasing('requests')
        self.assertEqual(200,before['status'],before['body'])
        matches=[r for r in before['body']['data'] if r['reason']==reason]
        self.assertEqual(1,len(matches))
        record=matches[0]
        self.assertEqual(need_id,record['items'][0]['workshop_material_need_id'])
        await self.page.get_by_role('button',name='Reintentar la misma operación',exact=True).click()
        await expect(self.page.get_by_text('Solicitud o decisión confirmada. No representa compra ni recepción.',exact=True)).to_be_visible()
        after=await self.purchasing('requests')
        self.assertEqual(1,len([r for r in after['body']['data'] if r['reason']==reason]))
        row=self.page.get_by_role('row').filter(has_text=reason)
        await row.get_by_role('button',name='Consultar solicitud',exact=True).click()
        await self.page.get_by_role('button',name='Registrar decisión administrativa',exact=True).click()
        form=self.page.get_by_role('form',name='Decisión de compra')
        await form.get_by_label('Motivo de la decisión',exact=True).fill('Contenido exacto autorizado E2E')
        async with self.page.expect_response(lambda r:r.url.endswith('/decisions') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Guardar decisión',exact=True).click()
        response=await event.value
        self.assertIn(response.status,[200,201],await response.text())
        await expect(self.page.get_by_role('heading',name='Historial de decisión',exact=True)).to_be_visible()
        detail=await self.purchasing('requests/'+record['id'])
        self.assertEqual('approved',detail['body']['data']['decision']['decision'])
        self.assertEqual('2.000001',detail['body']['data']['items'][0]['quantity'])
        for width in [1440,768]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
        await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        await self.login('secretary')
        forbidden=await self.purchasing('requests/'+record['id']+'/decisions', {
            'request_key':str(uuid.uuid4()),'decision':'rejected','reason':'Intento no autorizado'})
        self.assertEqual(403,forbidden['status'],forbidden['body'])


for _name,_value in first.WorkshopModuleE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in PurchaseRequestsE2E.__dict__:
        setattr(PurchaseRequestsE2E,_name,_value)

if __name__=='__main__':
    unittest.main()
