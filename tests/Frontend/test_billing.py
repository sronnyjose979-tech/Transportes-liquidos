"""Billing UI contracts with synthetic intercepted APIs; real persistence is E2E-tested separately."""
import asyncio
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_fleet_documents as support


class BillingBrowserTest(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        support.FleetDocumentsBrowserTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        support.FleetDocumentsBrowserTest.tearDownClass.__func__(cls)

    async def asyncSetUp(self):
        await support.FleetDocumentsBrowserTest.asyncSetUp(self)
        self.posts=[]
        self.lost=False
        self.invalid=False
        self.trip={'id':'trip-1','customer':{'id':'customer-1','name':'Cliente real de contrato'},'product':'Agua','execution_status':'completed','administrative_status':'closed','price_proposals':[],'approved_price':{'id':'price-1','amount':'1000.10','currency':'CRC'},'invoice_id':None,'billing_eligibility':True}
        self.invoice={'id':'invoice-1','customer':self.trip['customer'],'currency':'CRC','total':'1000.10','paid':'0.00','balance':'1000.10','status':'pending','registered_at':'2026-10-14T14:00:00Z','created_by_id':1,'reason':'Transporte confirmado','items':[{'id':'item-1','trip_id':'trip-1','price_proposal_id':'price-1','concept':'Transporte Agua','amount':'1000.10'}],'payments':[]}

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route)
            return
        if route.request.method=='POST':
            self.posts.append((path,route.request.post_data_json))
            if self.invalid:
                await route.fulfill(status=422,json={'errors':{'amount':['Importe superior al saldo']}})
                return
            if self.lost:
                self.lost=False
                await route.abort('failed')
                return
            await route.fulfill(json={'data':self.invoice})
            return
        if path=='/api/transport/lookups/customers':
            data=[self.trip['customer']]
        elif path=='/api/billing/trips':
            data=[self.trip]
        elif path=='/api/billing/invoices/invoice-1':
            await route.fulfill(json={'data':self.invoice})
            return
        elif path=='/api/billing/invoices':
            data=[self.invoice]
        else:
            data=[]
        await route.fulfill(json={'data':data,'meta':{'current_page':1,'last_page':1}})

    async def test_invoice_uses_selected_exact_approved_price_and_internal_label(self):
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/facturacion')
        await expect(self.page.get_by_text('No son facturas fiscales',exact=False)).to_be_visible()
        await self.page.get_by_label('Seleccionar viaje trip-1').check()
        await self.page.get_by_label('Concepto del viaje trip-1').fill('Viaje aprobado completo')
        await self.page.get_by_label('Motivo de facturación',exact=True).fill('Crear documento interno')
        await self.page.get_by_role('button',name='Crear factura administrativa',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        body=self.posts[0][1]
        self.assertEqual(body['items'],[{'trip_id':'trip-1','price_proposal_id':'price-1','description':'Viaje aprobado completo'}])
        self.assertTrue(body['request_key'])
        for width in [1440,768]:
            await self.page.set_viewport_size({'width':width,'height':1000})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

    async def test_secretary_cannot_approve_amount_and_can_propose(self):
        self.role='secretary'
        self.trip['price_proposals']=[{'id':'pending-1','amount':'250.00','currency':'USD','supersedes_id':None,'reason':'Acuerdo','requested_by_id':2,'registered_at':'2026-10-14T14:00:00Z','decision':None}]
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Ver importe e historial').click()
        await expect(self.page.get_by_role('button',name='Aprobar importe',exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Registrar importe para aprobación')).to_be_visible()

    async def test_partial_payment_preserves_strings_currency_and_offset(self):
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Facturas y pagos',exact=True).click()
        await self.page.get_by_role('button',name='Ver factura y pagos').click()
        await self.page.get_by_label('Importe del pago',exact=False).fill('0.10')
        await self.page.get_by_label('Fecha y hora del pago',exact=False).fill('2026-10-14T09:00')
        await self.page.get_by_label('Motivo o referencia administrativa').fill('Pago parcial')
        await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        body=self.posts[0][1]
        self.assertEqual(body['amount'],'0.10')
        self.assertEqual(body['currency'],'CRC')
        self.assertEqual(body['paid_at'],'2026-10-14T09:00:00-06:00')

    async def test_lost_invoice_response_requires_manual_identical_retry(self):
        self.lost=True
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_label('Seleccionar viaje trip-1').check()
        await self.page.get_by_label('Motivo de facturación',exact=True).fill('Crear documento interno')
        await self.page.get_by_role('button',name='Crear factura administrativa',exact=True).click()
        await expect(self.page.get_by_role('heading',name='Operación pendiente de confirmación')).to_be_visible()
        self.assertEqual(len(self.posts),1)
        await expect(self.page.get_by_role('button',name='Cerrar sesión')).to_be_disabled()
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual(self.posts[0],self.posts[1])

    async def test_validation_error_displays_fields_without_success(self):
        self.invalid=True
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Facturas y pagos',exact=True).click()
        await self.page.get_by_role('button',name='Ver factura y pagos').click()
        await self.page.get_by_label('Importe del pago',exact=False).fill('9999.00')
        await self.page.get_by_label('Fecha y hora del pago',exact=False).fill('2026-10-14T09:00')
        await self.page.get_by_label('Motivo o referencia administrativa').fill('Pago inválido')
        await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        await expect(self.page.get_by_text('amount: Importe superior al saldo')).to_be_visible()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_have_count(0)
        self.assertEqual(len(self.posts),1)

    async def test_rejected_correction_uses_latest_leaf_preserves_approved_parent(self):
        self.page.on('dialog',lambda dialog:dialog.accept())
        parent={'id':'price-1','amount':'1000.10','currency':'CRC','supersedes_id':None,'reason':'Aprobado','requested_by_id':2,'registered_at':'2026-10-14T14:00:00Z','decision':{'decision':'approved','reason':'Inicial','decided_by_id':1,'decided_at':'2026-10-14T14:01:00Z'}}
        child={**parent,'id':'correction-1','amount':'1200.00','supersedes_id':'price-1','reason':'Corrección rechazada','decision':{'decision':'rejected','reason':'Revisar','decided_by_id':1,'decided_at':'2026-10-14T14:02:00Z'}}
        self.trip['price_proposals']=[parent,child]
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Ver importe e historial').click()
        await self.page.get_by_label('Importe acordado').fill('1100.00')
        await self.page.get_by_label('Registrar nueva versión del importe',exact=False).check()
        await self.page.get_by_label('Motivo del importe o corrección').fill('Versión corregida')
        await self.page.get_by_role('button',name='Registrar importe para aprobación').click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual(self.posts[0][1]['supersedes_id'],'correction-1')
        self.assertEqual(self.trip['approved_price']['id'],'price-1')

    async def test_usd_amount_selector_has_unambiguous_accessible_name(self):
        self.page.on('dialog',lambda dialog:dialog.accept())
        self.trip['approved_price']=None
        self.trip['billing_eligibility']=False
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Ver importe e historial').click()
        await self.page.get_by_label('Importe acordado',exact=True).fill('0.30')
        await self.page.get_by_label('Moneda',exact=True).select_option('USD')
        await self.page.get_by_label('Motivo del importe o corrección',exact=True).fill('Importe exacto USD')
        await self.page.get_by_role('button',name='Registrar importe para aprobación').click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual('USD',self.posts[0][1]['currency'])
        self.assertEqual('0.30',self.posts[0][1]['amount'])

    async def test_confirmed_partial_payment_clears_form_and_blocks_second_click(self):
        self.page.on('dialog',lambda dialog:dialog.accept())
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Facturas y pagos',exact=True).click()
        await self.page.get_by_role('button',name='Ver factura y pagos').click()
        await self.page.get_by_label('Importe del pago',exact=True).fill('0.10')
        await self.page.get_by_label('Fecha y hora del pago',exact=False).fill('2026-10-14T09:00')
        await self.page.get_by_label('Motivo o referencia administrativa').fill('Un único pago confirmado')
        await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        await expect(self.page.get_by_label('Importe del pago',exact=True)).to_have_value('')
        await self.page.get_by_role('button',name='Registrar pago',exact=True).click()
        await asyncio.sleep(.2)
        self.assertEqual(1,len(self.posts))
