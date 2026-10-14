"""Real Chromium, Laravel session/CSRF and isolated PostgreSQL18; no API mocks."""
import asyncio
from datetime import datetime, timedelta, timezone
import json
import subprocess
import unittest
import uuid
from playwright.async_api import expect
import test_document_module as support
import test_system_navigation as navigation


class ExpensesDriverE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def expenses(cls, action, data=None):
        result = subprocess.run(['php', str(support.ROOT/'tests/E2E/expenses-driver-console.php'), action],
                                input=json.dumps(data or {}), capture_output=True, text=True, check=True, cwd=support.ROOT)
        return json.loads(result.stdout)

    async def asyncSetUp(self):
        await navigation.SystemNavigationE2E.asyncSetUp(self)
        self.fixture = self.expenses('fixture')
        self.page.on('dialog', lambda d: asyncio.create_task(d.accept()))

    async def out(self):
        await self.page.get_by_role('button', name='Cerrar sesión', exact=True).click()
        await self.page.wait_for_url('**/login')

    async def driver(self):
        await self.login('driver', self.fixture['driver_login'])
        await self.page.set_viewport_size({'width':390, 'height':900})
        await self.page.get_by_role('button', name='Consultar viaje y registrar información', exact=True).click()
        await self.page.get_by_label('Fecha y hora del registro (Costa Rica)', exact=True).fill(
            (datetime.now(timezone(timedelta(hours=-6)))+timedelta(seconds=2)).strftime('%Y-%m-%dT%H:%M:%S'))

    async def checked(self, path, body=None, status=201):
        result = await self.api(path, body)
        self.assertEqual(status, result['status'], result)
        return result['body']['data'] if status < 400 else result['body']

    async def test_mobile_report_odometer_expense_receipt_and_office_review(self):
        await self.driver()
        await self.page.get_by_label('Observación', exact=True).fill('Incidencia sintética del conductor')
        await self.page.get_by_role('button', name='Registrar reporte', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmado')).to_be_visible()
        await self.page.get_by_label('Kilometraje', exact=True).fill('100.25')
        await self.page.get_by_label('Motivo de lectura', exact=True).fill('Odómetro inicial sintético')
        await self.page.get_by_role('button', name='Registrar kilometraje', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmado')).to_be_visible()
        await self.page.get_by_label('Concepto', exact=True).fill('Gasto móvil '+self.fixture['trip_id'])
        await self.page.get_by_label('Categoría', exact=True).fill('Operación')
        await self.page.get_by_label('Monto', exact=True).fill('12.03')
        await self.page.get_by_label('Descripción', exact=True).fill('Pendiente de revisión administrativa')
        async with self.page.expect_response(lambda r:r.url.endswith('/api/expenses') and r.request.method=='POST') as event:
            await self.page.get_by_role('button', name='Registrar gasto pendiente', exact=True).click()
        response = await event.value
        self.assertEqual(201, response.status, await response.text())
        record = (await response.json())['data']
        await self.page.get_by_label('Gasto para adjuntar comprobante', exact=True).select_option(record['expense_id'])
        await self.page.get_by_label('Comprobante PDF, JPG o PNG (máximo 10 MB)', exact=True).set_input_files(
            str(support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf'))
        async with self.page.expect_response(lambda r:r.url.endswith('/receipts') and r.request.method=='POST') as event:
            await self.page.get_by_role('button', name='Adjuntar comprobante privado', exact=True).click()
        response = await event.value
        self.assertEqual(201, response.status, await response.text())
        receipt = (await response.json())['data']['receipt_id']
        own = await self.checked('/expenses/'+record['expense_id'], status=200)
        self.assertEqual('pending', own['status'])
        self.assertEqual('12.03', own['amount'])
        download = await self.context.request.get(self.base+'/api/expenses/receipts/'+receipt+'/download')
        self.assertEqual(200, download.status)
        self.assertEqual((support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf').read_bytes(), await download.body())
        self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        self.assertEqual('in_progress', self.expenses('snapshot', self.fixture)['execution'])
        self.assertEqual(403, (await self.context.request.get(self.base+'/gastos')).status)
        await self.out(); await self.login('secretary'); await self.page.goto(self.base+'/gastos')
        await self.page.get_by_role('row').filter(has_text='Gasto móvil '+self.fixture['trip_id']).get_by_role('button',name='Ver gasto',exact=True).click()
        await expect(self.page.get_by_role('button', name='Registrar decisión', exact=True)).to_have_count(0)
        await self.checked('/expenses/'+record['expense_id']+'/decisions', {
            'request_key':str(uuid.uuid4()), 'expected_revision_id':record['revision_id'], 'decision':'approved', 'reason':'Intento secretaria'}, status=403)
        await self.out(); await self.login('administrator'); await self.page.goto(self.base+'/gastos')
        await self.page.get_by_role('row').filter(has_text='Gasto móvil '+self.fixture['trip_id']).get_by_role('button',name='Ver gasto',exact=True).click()
        await self.page.get_by_label('Decisión', exact=True).select_option('approved')
        await self.page.get_by_label('Motivo de decisión', exact=True).fill('Revisión del comprobante y monto exacto')
        await self.page.get_by_role('button', name='Registrar decisión', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmada')).to_be_visible()
        approved = await self.checked('/expenses/'+record['expense_id'], status=200)
        self.assertEqual('approved', approved['status'])
        self.assertEqual(1, len(approved['decisions']))
        self.assertEqual(1, self.expenses('snapshot', self.fixture)['receipts'])

    async def test_lost_report_response_replays_after_release_without_creating_new_records(self):
        await self.driver()
        writes=[]
        path='/api/transport/my-trips/'+self.fixture['trip_id']+'/reports'
        self.page.on('request', lambda r:writes.append(r.post_data_json) if r.method=='POST' and r.url.endswith(path) else None)
        await self.page.get_by_label('Observación', exact=True).fill('Reporte confirmado con respuesta perdida')
        self.proxy.drop_response_path=path
        await self.page.get_by_role('button', name='Registrar reporte', exact=True).click()
        await expect(self.page.get_by_role('button', name='Reintentar la misma operación', exact=True)).to_be_visible()
        self.assertEqual(1, len(writes))
        self.expenses('release', self.fixture)
        before=self.expenses('snapshot', self.fixture)['reports']
        await self.page.get_by_role('button', name='Reintentar la misma operación', exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='confirmado')).to_be_visible()
        self.assertEqual(2, len(writes)); self.assertEqual(writes[0], writes[1])
        self.assertEqual(before, self.expenses('snapshot', self.fixture)['reports'])
        await self.checked('/transport/my-trips/'+self.fixture['trip_id']+'/reports', {
            **writes[0], 'request_key':str(uuid.uuid4())}, status=403)
        history=await self.checked('/transport/my-records', status=200)
        self.assertTrue(any(r['trip_id']==self.fixture['trip_id'] for r in history['reports']))
        await self.checked('/transport/my-trips/'+self.fixture['trip_id'], status=404)

    async def test_private_receipt_compensation_and_committed_retry_after_manual_release(self):
        await self.driver()
        body={'request_key':str(uuid.uuid4()), 'trip_id':self.fixture['trip_id'], 'concept':'Comprobante con liberación',
              'category':'Operación', 'amount':'1.02', 'currency':'CRC', 'occurred_at':datetime.now(timezone.utc).isoformat(), 'description':None}
        record=await self.checked('/expenses',body)
        key=str(uuid.uuid4())
        pdf=(support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf').read_bytes()
        token=await self.page.evaluate("decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'')")
        async def upload(request_key):
            return await self.context.request.post(self.base+'/api/expenses/'+record['expense_id']+'/receipts',
                headers={'Accept':'application/json','X-XSRF-TOKEN':token},
                multipart={'request_key':request_key,'file':{'name':'recibo.pdf','mimeType':'application/pdf','buffer':pdf}})
        first=await upload(key);self.assertEqual(201,first.status,await first.text())
        receipt=(await first.json())['data']['receipt_id']
        self.expenses('release',self.fixture)
        retry=await upload(key);self.assertEqual(201,retry.status,await retry.text())
        self.assertEqual(receipt,(await retry.json())['data']['receipt_id'])
        rejected=await upload(str(uuid.uuid4()));self.assertEqual(403,rejected.status,await rejected.text())
        state=self.expenses('snapshot',self.fixture)
        self.assertEqual(1,state['receipts']);self.assertEqual(0,state['orphan_files']);self.assertEqual(0,state['pending_files'])
        download=await self.context.request.get(self.base+'/api/expenses/receipts/'+receipt+'/download')
        self.assertEqual(200,download.status);self.assertEqual(pdf,await download.body())
        retry_expense=await self.checked('/expenses',body)
        self.assertEqual(record,retry_expense)
        await self.checked('/expenses',{**body,'request_key':str(uuid.uuid4())},status=403)

    async def test_concurrent_expenses_decisions_and_roles_use_real_server(self):
        await self.login('administrator')
        key=str(uuid.uuid4())
        body={'request_key':key, 'trip_id':self.fixture['trip_id'], 'concept':'Operación concurrente',
              'category':'General', 'amount':'0.03', 'currency':'USD', 'occurred_at':datetime.now(timezone.utc).isoformat(), 'description':'Centavos exactos'}
        second=await self.context.new_page(); await second.goto(self.base+'/dashboard')
        async def submit(page, path, data):
            return await page.evaluate('''async({path,data})=>{const r=await fetch('/api'+path,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'')},body:JSON.stringify(data)});return {status:r.status,body:await r.json()}}''',{'path':path,'data':data})
        try:
            results=await asyncio.gather(submit(self.page,'/expenses',body),submit(second,'/expenses',body))
            self.assertEqual([201,201], [r['status'] for r in results], results)
            record=results[0]['body']['data'];self.assertEqual(record,results[1]['body']['data'])
            self.assertEqual(1,self.expenses('snapshot',self.fixture)['expenses'])
            decisions=[{'request_key':str(uuid.uuid4()),'expected_revision_id':record['revision_id'],'decision':d,'reason':'Decisión concurrente'} for d in ['approved','rejected']]
            results=await asyncio.gather(submit(self.page,'/expenses/'+record['expense_id']+'/decisions',decisions[0]),submit(second,'/expenses/'+record['expense_id']+'/decisions',decisions[1]))
            self.assertEqual([200,409],sorted(r['status'] for r in results),results)
            current=await self.checked('/expenses/'+record['expense_id'],status=200)
            self.assertEqual('0.03',current['amount']);self.assertEqual(1,len(current['decisions']))
            await self.out();await self.login('mechanic')
            await self.checked('/expenses',status=403)
            await self.checked('/transport/my-records',status=403)
        finally:
            await second.close()

for _name,_value in navigation.SystemNavigationE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in ExpensesDriverE2E.__dict__:
        setattr(ExpensesDriverE2E,_name,_value)

if __name__=='__main__':unittest.main()
