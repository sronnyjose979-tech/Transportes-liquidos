"""Chromium -> real Laravel session/CSRF -> isolated PostgreSQL18 administrative money.
No mocked API responses. FaultProxy only drops already-confirmed server responses.
"""
import asyncio
import json
import subprocess
import unittest
import uuid
from decimal import Decimal
from urllib.parse import unquote
from playwright.async_api import expect
import test_document_module as support
import test_system_navigation as navigation


class BillingOperationsE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def billing(cls,action,data=None):
        result=subprocess.run(['php',str(support.ROOT/'tests/E2E/billing-console.php'),action],input=json.dumps(data or {}),capture_output=True,text=True,cwd=support.ROOT,check=True)
        return json.loads(result.stdout)

    async def asyncSetUp(self):
        await navigation.SystemNavigationE2E.asyncSetUp(self)
        self.fixture=self.billing('fixture')
        self.page.on('dialog',lambda d:asyncio.create_task(d.accept()))

    async def office(self,role):
        await self.login(role)
        await self.page.goto(self.base+'/facturacion')
        await expect(self.page.get_by_role('heading',name='Importes autorizados y viajes')).to_be_visible()
        await expect(self.page.get_by_role('checkbox',name='Seleccionar viaje '+self.fixture['trip_id'])).to_be_visible()

    async def out(self):
        async with self.page.expect_response(lambda r:r.url.endswith('/api/logout')) as event:
            await self.page.get_by_role('button',name='Cerrar sesión',exact=True).click()
        self.assertEqual(204,(await event.value).status)
        await self.page.wait_for_url('**/login')

    async def price(self,amount,currency='CRC',reason='Importe manual acordado'):
        row=self.page.get_by_role('row').filter(has_text=self.fixture['trip_id'])
        await row.get_by_role('button',name='Ver importe e historial',exact=True).click()
        await self.page.get_by_label('Importe acordado',exact=True).fill(amount)
        await self.page.get_by_label('Moneda',exact=True).select_option(currency)
        await self.page.get_by_label('Motivo del importe o corrección',exact=True).fill(reason)
        async with self.page.expect_response(lambda r:r.url.endswith('/amounts') and r.request.method=='POST') as event:
            await self.page.get_by_role('button',name='Registrar importe para aprobación',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        await expect(self.page.get_by_role('status').filter(has_text='Operación confirmada')).to_be_visible()
        return (await response.json())['data']['amount_id']

    async def approve(self):
        row=self.page.get_by_role('row').filter(has_text=self.fixture['trip_id'])
        await row.get_by_role('button',name='Ver importe e historial',exact=True).click()
        await self.page.get_by_label('Motivo de decisión administrativa',exact=True).fill('Administrador revisó el importe exacto')
        async with self.page.expect_response(lambda r:r.url.endswith('/decisions') and r.request.method=='POST') as event:
            await self.page.get_by_role('button',name='Aprobar importe',exact=True).click()
        response=await event.value
        self.assertEqual(200,response.status,await response.text())
        await expect(self.page.get_by_role('checkbox',name='Seleccionar viaje '+self.fixture['trip_id'])).to_be_enabled()

    async def invoice(self):
        await self.page.get_by_role('checkbox',name='Seleccionar viaje '+self.fixture['trip_id']).check()
        await self.page.get_by_label('Concepto del viaje '+self.fixture['trip_id'],exact=True).fill('Servicio de transporte acordado')
        await self.page.get_by_label('Motivo de facturación',exact=True).fill('Factura administrativa interna sin impuestos')
        async with self.page.expect_response(lambda r:r.url.endswith('/api/billing/invoices') and r.request.method=='POST') as event:
            await self.page.get_by_role('button',name='Crear factura administrativa',exact=True).click()
        response=await event.value
        self.assertEqual(201,response.status,await response.text())
        invoice=(await response.json())['data']['invoice_id']
        await expect(self.page.get_by_role('checkbox',name='Seleccionar viaje '+self.fixture['trip_id'])).to_be_disabled()
        return invoice

    async def detail(self,invoice):
        await self.page.get_by_role('button',name='Facturas y pagos',exact=True).click()
        row=self.page.get_by_role('row').filter(has_text=invoice)
        await row.get_by_role('button',name='Ver factura y pagos',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Factura administrativa interna',exact=True)).to_be_visible()

    async def fill_payment(self,amount):
        await self.page.get_by_label('Importe del pago',exact=True).fill(amount)
        await self.page.get_by_label('Fecha y hora del pago · Costa Rica',exact=True).fill('2026-10-14T08:30')
        await self.page.get_by_label('Medio de pago · descripción opcional',exact=True).fill('Recepción administrativa')
        await self.page.get_by_label('Motivo o referencia administrativa',exact=True).fill('Pago recibido sin requisitos bancarios inventados')

    async def checked(self,path,body,status=201):
        result=await self.api(path,body)
        self.assertEqual(status,result['status'],result)
        return result['body']['data'] if status<400 else result['body']

    async def test_real_approval_invoice_partial_payment_lost_response_expiration_and_total(self):
        await self.office('secretary')
        amount=await self.price('123.45')
        await expect(self.page.get_by_role('button',name='Aprobar importe')).to_have_count(0)
        await expect(self.page.get_by_role('checkbox',name='Seleccionar viaje '+self.fixture['trip_id'])).to_be_disabled()
        await self.checked('/billing/amounts/'+amount+'/decisions',{'request_key':str(uuid.uuid4()),'decision':'approved','reason':'Intento no autorizado'},403)
        await self.out();await self.office('administrator');await self.approve()
        await self.out();await self.office('secretary')
        invoice=await self.invoice()
        duplicate=await self.api('/billing/invoices',{'request_key':str(uuid.uuid4()),'customer_id':self.fixture['customer_id'],'reason':'Duplicación rechazada','items':[{'trip_id':self.fixture['trip_id'],'price_proposal_id':amount,'description':'Servicio de transporte'}]})
        self.assertEqual(409,duplicate['status'],duplicate)
        await self.detail(invoice)
        await self.fill_payment('23.45')
        writes=[]
        self.page.on('request',lambda r:writes.append(r.post_data_json) if r.method=='POST' and r.url.endswith('/payments') else None)
        self.proxy.drop_response_statuses=(201,)
        self.proxy.drop_response_path='/api/billing/invoices/'+invoice+'/payments'
        await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_be_visible()
        self.assertEqual(1,len(writes))
        confirmed=await self.api('/billing/invoices/'+invoice)
        self.assertEqual('23.45',confirmed['body']['data']['paid'])
        self.assertEqual('100.00',confirmed['body']['data']['balance'])
        self.assertEqual(1,self.billing('snapshot',{'invoice_id':invoice,'trip_id':self.fixture['trip_id']})['payments'])
        # A 401 during explicit retry must preserve the original intent/key through
        # the existing embedded login, without navigating away and losing it.
        await self.page.wait_for_load_state('networkidle')
        self.assertGreater(self.command('expire-sessions',{'role':'secretary'})['expired'],0)
        await self.page.get_by_role('button',name='Reintentar la misma operación',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible()
        self.assertEqual(1,len(writes)) # identity preflight rejected before POST
        await self.page.get_by_label('Usuario',exact=True).fill('e2e-secretary')
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_be_visible()
        await expect(self.page).to_have_url(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Reintentar la misma operación',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_have_count(0)
        self.assertEqual(2,len(writes));self.assertEqual(writes[0],writes[1])
        self.assertEqual(1,self.billing('snapshot',{'invoice_id':invoice,'trip_id':self.fixture['trip_id']})['payments'])
        await self.detail(invoice)
        excess=await self.api('/billing/invoices/'+invoice+'/payments',{'request_key':str(uuid.uuid4()),'amount':'100.01','currency':'CRC','paid_at':'2026-10-14T08:30:00-06:00','reason':'Sobrepago rechazado'})
        self.assertEqual(422,excess['status'],excess)
        await self.fill_payment('100.00')
        async with self.page.expect_response(lambda r:r.url.endswith('/payments') and r.request.method=='POST') as event:
            await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        self.assertEqual(201,(await event.value).status)
        await expect(self.page.get_by_role('button',name='Registrar pago',exact=True)).to_have_count(0)
        final=await self.api('/billing/invoices/'+invoice)
        self.assertEqual('paid',final['body']['data']['status'])
        self.assertEqual('123.45',final['body']['data']['paid'])
        self.assertEqual('0.00',final['body']['data']['balance'])
        self.assertEqual(2,len(final['body']['data']['payments']))
        for width in [1440,768,390]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_usd_invoice_exact_cents_and_currency_mismatch_rejected(self):
        await self.office('administrator');await self.price('0.30','USD');await self.approve()
        invoice=await self.invoice();await self.detail(invoice)
        mismatch=await self.api('/billing/invoices/'+invoice+'/payments',{'request_key':str(uuid.uuid4()),'amount':'0.10','currency':'CRC','paid_at':'2026-10-14T08:30:00-06:00','reason':'Moneda ajena rechazada'})
        self.assertEqual(422,mismatch['status'],mismatch)
        for value in ['0.10','0.20']:
            await self.fill_payment(value)
            async with self.page.expect_response(lambda r:r.url.endswith('/payments') and r.request.method=='POST') as event:
                await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
            self.assertEqual(201,(await event.value).status)
            await expect(self.page.get_by_role('status').filter(has_text='Operación confirmada')).to_be_visible()
            await self.page.wait_for_load_state('networkidle')
        result=await self.api('/billing/invoices/'+invoice)
        self.assertEqual('0.30',result['body']['data']['total'])
        self.assertEqual('0.30',result['body']['data']['paid'])
        self.assertEqual('0.00',result['body']['data']['balance'])
        self.assertEqual('USD',result['body']['data']['currency'])
        self.assertEqual(Decimal('0.30'),sum(Decimal(p['amount']) for p in result['body']['data']['payments']))

    async def test_two_real_sessions_cannot_overpay_the_same_invoice(self):
        await self.office('administrator');await self.price('0.30','USD');await self.approve()
        invoice=await self.invoice()
        second=await self.browser.new_context()
        try:
            async def headers(context):
                self.assertEqual(204,(await context.request.get(self.base+'/api/csrf')).status)
                cookies=await context.cookies()
                token=next(c['value'] for c in cookies if c['name']=='XSRF-TOKEN')
                return {'Accept':'application/json','X-XSRF-TOKEN':unquote(token)}
            login=await second.request.post(self.base+'/api/login',data={'login':'e2e-secretary','password':support.PASSWORD},headers=await headers(second))
            self.assertEqual(200,login.status,await login.text())
            first_headers=await headers(self.context)
            second_headers=await headers(second)
            body={'amount':'0.20','currency':'USD','paid_at':'2026-10-14T08:30:00-06:00','reason':'Pago real simultáneo de prueba'}
            responses=await asyncio.gather(
                self.context.request.post(self.base+'/api/billing/invoices/'+invoice+'/payments',data={**body,'request_key':str(uuid.uuid4())},headers=first_headers),
                second.request.post(self.base+'/api/billing/invoices/'+invoice+'/payments',data={**body,'request_key':str(uuid.uuid4())},headers=second_headers))
            self.assertEqual([201,422],sorted(r.status for r in responses),[await r.text() for r in responses])
            result=await self.api('/billing/invoices/'+invoice)
            self.assertEqual('0.20',result['body']['data']['paid'])
            self.assertEqual('0.10',result['body']['data']['balance'])
            self.assertEqual(1,len(result['body']['data']['payments']))
        finally:
            await second.close()

for _name,_value in navigation.SystemNavigationE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in BillingOperationsE2E.__dict__:
        setattr(BillingOperationsE2E,_name,_value)

if __name__=='__main__':unittest.main()
