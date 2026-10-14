"""Real Chromium, session/CSRF Laravel and isolated PostgreSQL 18 supply circuit.
Run exclusively: bash tests/run-postgres.sh --e2e-supply.
No API response fixtures, production records or invented initial balances.
"""
import unittest
import uuid
from datetime import datetime, timezone, timedelta
from decimal import Decimal
from playwright.async_api import expect
import test_workshop_module as first


class SupplyOperationsE2E(unittest.IsolatedAsyncioTestCase):
    async def supply(self, path, body=None):
        return await self.page.evaluate("""async ({path,body})=>{
          const headers={Accept:'application/json'};
          if(body!==null){headers['Content-Type']='application/json';headers['X-XSRF-TOKEN']=decodeURIComponent(document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice(11)??'');}
          const r=await fetch('/api/'+path,{method:body===null?'GET':'POST',credentials:'same-origin',headers,...(body===null?{}:{body:JSON.stringify(body)})});
          return {status:r.status,body:await r.json()};
        }""", {'path':path,'body':body})

    async def confirmed(self, path, body, statuses=(200,201)):
        response=await self.supply(path,body)
        self.assertIn(response['status'],statuses,response)
        return response['body']['data']

    def operation(self, **data):
        return {'request_key':str(uuid.uuid4()),**data}

    def instant(self):
        return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

    async def prepare_order(self):
        await self.login('administrator')
        # This part is created through the real catalog. Legacy fixture reconciliation
        # never seeds this record: only physical purchase receipts can create balance.
        self.fixture['part']=await self.confirmed('catalogs/spare-parts', {
            'code':'SUPPLY-'+str(uuid.uuid4()),'description':'Repuesto real E2E aislado','unit':'unidad'})
        order,_=await self.report_and_order()
        assignment=await self.assign(order)
        diagnosis=await self.diagnosis(order,assignment)
        scope=await self.confirmed('workshop/orders/'+order+'/scopes',self.operation(
            diagnosis_id=diagnosis['diagnosis_id'],description='Alcance de cinco repuestos E2E',reason='Alcance exacto',
            tasks=[{'line_number':1,'description':'Reparación sintética'}],
            parts=[{'line_number':1,'task_line_number':1,'spare_part_id':self.fixture['part']['id'],'quantity':'5.000000'}]))
        decision=await self.confirmed('workshop/authorization-requests/'+scope['request_id']+'/decisions',self.operation(decision='approved',reason='Autorizar alcance exacto'))
        await self.confirmed('workshop/decisions/'+decision['decision_id']+'/apply-scope',self.operation())
        parts=await self.supply('workshop/scope-parts?work_order_id='+order)
        self.assertEqual(200,parts['status'],parts)
        part=next(p for p in parts['body']['data'] if p['spare_part_id']==self.fixture['part']['id'])
        need=await self.confirmed('workshop/orders/'+order+'/material-needs',self.operation(
            spare_part_id=self.fixture['part']['id'],scope_part_id=part['id'],quantity='5.000000',description='Necesidad para compra '+self.fixture['tag'],occurred_at=self.instant(),reason='Repuesto requerido'))
        return order,assignment,part,need


    async def stock(self, warehouse, custody, consumed):
        result=await self.supply('inventory/stock?per_page=100')
        self.assertEqual(200,result['status'],result)
        row=next(r for r in result['body']['data'] if r['spare_part_id']==self.fixture['part']['id'])
        from decimal import Decimal
        for field,value in [('available_quantity',warehouse),('custody_quantity',custody),('consumed_quantity',consumed)]:
            self.assertEqual(Decimal(str(value)),Decimal(row[field]),row)

    async def test_real_partial_receipts_delivery_usage_return_and_retries(self):
        order,assignment,part,need=await self.prepare_order()
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/inventario')
        await self.page.get_by_role('button',name='Solicitar conciliación o ajuste',exact=True).click()
        form=self.page.get_by_role('form',name='Corrección de inventario')
        await self.choose(form,'Repuesto',self.fixture['part']['id'],self.fixture['part']['code'])
        await form.get_by_label('Cantidad',exact=True).fill('0')
        await form.get_by_label('Motivo',exact=True).fill('Conteo físico inicial cero')
        async with self.page.expect_response(lambda r:r.url.endswith('/api/inventory/corrections') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Guardar operación',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        correction=(await response.json())['data']
        await expect(form).not_to_be_visible()
        article=self.page.locator('article').filter(has_text='Conteo físico inicial cero')
        await article.get_by_role('button',name='Decidir corrección',exact=True).click()
        form=self.page.get_by_role('form',name='Corrección de inventario')
        await form.get_by_label('Motivo',exact=True).fill('Conciliación física verificada')
        async with self.page.expect_response(lambda r:r.url.endswith('/decisions') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Guardar operación',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        await expect(form).not_to_be_visible()
        async with self.page.expect_response(lambda r:r.url.endswith('/apply') and r.request.method=='POST') as event:
            await article.get_by_role('button',name='Aplicar corrección autorizada',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        await self.stock(0,0,0)
        purchase=await self.confirmed('purchasing/requests',self.operation(supplier_id=self.fixture['supplier']['id'],reason='Compra supply '+self.fixture['tag'],items=[{'spare_part_id':self.fixture['part']['id'],'quantity':'5','workshop_material_need_id':need['material_need_id']}]))
        await self.confirmed('purchasing/requests/'+purchase['id']+'/decisions',self.operation(decision='approved',reason='Autorizar cinco unidades exactas'))
        await self.page.goto(self.base+'/compras')
        row=self.page.get_by_role('row').filter(has_text='Compra supply '+self.fixture['tag'])
        await row.get_by_role('button',name='Generar orden de compra',exact=True).click()
        form=self.page.get_by_role('form',name='Operación de compra')
        await form.get_by_label('Cantidad ordenada '+self.fixture['part']['code'],exact=True).fill('5')
        await form.get_by_label('Motivo',exact=True).fill('Orden de compra E2E física')
        async with self.page.expect_response(lambda r:r.url.endswith('/api/purchasing/orders') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Guardar operación',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        po=(await response.json())['data']
        await expect(form).not_to_be_visible()
        # Physical partial receipt through the real UI; drop only the already-committed
        # server response at the real forwarding proxy, then retry the same UUID.
        await self.page.get_by_role('button',name='Registrar recepción física',exact=True).click()
        form=self.page.get_by_role('form',name='Recepción física')
        await form.get_by_label('Cantidad recibida '+self.fixture['part']['code'],exact=True).fill('3')
        await form.get_by_label('Motivo',exact=True).fill('Primera recepción física parcial')
        self.proxy.drop_response_statuses=(200,201)
        self.proxy.drop_response_path='/api/purchasing/orders/'+po['id']+'/receipts'
        await form.get_by_role('button',name='Confirmar recepción física',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación',exact=True)).to_be_visible()
        await self.stock(3,0,0)
        await self.page.get_by_role('button',name='Reintentar la misma operación',exact=True).click()
        await expect(form).not_to_be_visible()
        await self.stock(3,0,0)
        detail=await self.supply('purchasing/orders/'+po['id'])
        self.assertEqual(1,len(detail['body']['data']['receipts']))
        self.assertEqual(Decimal('2'),Decimal(detail['body']['data']['items'][0]['pending_quantity']))
        await self.page.get_by_role('button',name='Registrar recepción física',exact=True).click()
        form=self.page.get_by_role('form',name='Recepción física')
        await form.get_by_label('Cantidad recibida '+self.fixture['part']['code'],exact=True).fill('2')
        await form.get_by_label('Motivo',exact=True).fill('Segunda recepción física completa')
        async with self.page.expect_response(lambda r:r.url.endswith('/receipts') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Confirmar recepción física',exact=True).click()
        response=await event.value
        self.assertEqual(200,response.status,await response.text())
        await expect(form).not_to_be_visible()
        await self.stock(5,0,0)
        excess=await self.supply('purchasing/orders/'+po['id']+'/receipts',self.operation(received_at=self.instant(),reason='Exceso rechazado',items=[{'purchase_order_item_id':po['items'][0]['id'],'quantity':'1'}]))
        self.assertEqual(422,excess['status'],excess)
        await self.stock(5,0,0)
        await self.page.goto(self.base+'/taller')
        await self.open_order(order)
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await self.page.get_by_role('button',name='Registrar entrega',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar entrega física')
        await self.choose(form,'Repuesto autorizado',part['id'],self.fixture['part']['code'])
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal',exact=True).fill('4')
        await form.get_by_label('Fecha y hora (Costa Rica)',exact=True).fill(datetime.now(timezone(timedelta(hours=-6))).strftime('%Y-%m-%dT%H:%M'))
        await form.get_by_label('Motivo del registro',exact=True).fill('Entrega física a mecánico')
        delivery=await self.save(form,'orders/'+order+'/deliveries',status=200)
        await self.stock(1,4,0)
        await self.switch('mechanic')
        await self.open_order(order)
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await self.page.get_by_role('button',name='Registrar utilización',exact=True).click()
        form=self.page.get_by_role('form',name='Registrar utilización')
        await self.choose(form,'Entrega recibida',delivery['delivery_id'],'')
        await self.choose(form,'Mecánico asignado',assignment['assignment_id'],self.fixture['employee']['name'])
        await form.get_by_label('Cantidad decimal',exact=True).fill('2')
        await form.get_by_label('Fecha y hora (Costa Rica)',exact=True).fill(datetime.now(timezone(timedelta(hours=-6))).strftime('%Y-%m-%dT%H:%M'))
        await form.get_by_label('Motivo del registro',exact=True).fill('Uso confirmado directamente por mecánico')
        await self.save(form,'orders/'+order+'/usages',status=200)
        excessive=await self.api('orders/'+order+'/usages',self.operation(delivery_id=delivery['delivery_id'],mechanic_assignment_id=assignment['assignment_id'],quantity='3',occurred_at=self.instant(),reason='Exceso rechazado'))
        self.assertEqual(422,excessive['status'],excessive)
        unauthorized=await self.supply('inventory/returns',self.operation(delivery_id=delivery['delivery_id'],quantity='2',occurred_at=self.instant(),reason='Mecánico no recibe bodega'))
        self.assertEqual(403,unauthorized['status'],unauthorized)
        await self.switch('secretary')
        await self.stock(1,2,2)
        # The return must represent warehouse physical reception, not mechanic self-return.
        await self.page.goto(self.base+'/inventario')
        await self.page.get_by_role('button',name='Recibir devolución física',exact=True).click()
        form=self.page.get_by_role('form',name='Devolución física')
        await form.get_by_label('Cantidad',exact=True).fill('2')
        await form.get_by_label('Motivo',exact=True).fill('Bodega recibió dos unidades no utilizadas')
        async with self.page.expect_response(lambda r:r.url.endswith('/api/inventory/returns') and r.request.method=='POST') as event:
            await form.get_by_role('button',name='Confirmar recepción de devolución',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        self.assertTrue((await response.json())['data']['movement_id'])
        await expect(form).not_to_be_visible()
        await self.stock(3,0,2)
        forbidden=await self.supply('inventory/corrections/'+correction['correction_id']+'/decisions',self.operation(decision='approved',reason='Secretaria no aprueba',occurred_at=self.instant()))
        self.assertEqual(403,forbidden['status'],forbidden)
        detail=await self.supply('purchasing/orders/'+po['id'])
        self.assertEqual(2,len(detail['body']['data']['receipts']))
        self.assertEqual(Decimal('0'),Decimal(detail['body']['data']['items'][0]['pending_quantity']))
        for width in [1440,768]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= innerWidth'))


for _name,_value in first.WorkshopModuleE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in SupplyOperationsE2E.__dict__:
        setattr(SupplyOperationsE2E,_name,_value)

if __name__=='__main__':
    unittest.main()
