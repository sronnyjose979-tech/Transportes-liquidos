"""Expense UI contract checks use intercepted APIs; persistence is verified separately."""
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_fleet_documents as support


class ExpensesBrowserTest(unittest.IsolatedAsyncioTestCase):
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
        self.expense={'id':'expense-1','concept':'Combustible registrado','category':'Operación','amount':'100.10','currency':'CRC','occurred_at':'2026-10-14T08:00:00-06:00','description':'Registro recibido','trip_id':'trip-1','status':'pending','created_by_id':1,'registered_at':'2026-10-14T14:00:00Z','current_revision_id':'revision-1','versions':[],'decisions':[],'receipts':[]}
        self.page.on('dialog',lambda dialog:dialog.accept())

    async def asyncTearDown(self):
        await support.FleetDocumentsBrowserTest.asyncTearDown(self)

    async def api(self,route):
        path=urlparse(route.request.url).path
        if path in ['/api/session','/api/csrf','/api/login','/api/logout']:
            await support.FleetDocumentsBrowserTest.api(self,route)
            return
        if route.request.method=='POST':
            self.posts.append((path,route.request.post_data_json))
            if self.lost:
                self.lost=False
                await route.abort('failed')
                return
            if self.invalid:
                await route.fulfill(status=422,json={'errors':{'amount':['El importe debe ser mayor que cero.']}})
                return
            await route.fulfill(json={'data':{'expense_id':'expense-1','revision_id':'revision-1'}})
            return
        if path=='/api/expenses/expense-1':
            await route.fulfill(json={'data':self.expense})
        else:
            data=[self.expense] if path=='/api/expenses' else []
            await route.fulfill(json={'data':data,'meta':{'current_page':1,'last_page':1}})

    async def fill_expense(self):
        await self.page.get_by_label('Concepto',exact=True).fill('Peaje confirmado')
        await self.page.get_by_label('Categoría',exact=True).fill('Peajes')
        await self.page.get_by_label('Importe del gasto',exact=True).fill('0.10')

    async def test_expense_money_exact_no_automatic_approval(self):
        await self.page.goto(self.base+'/gastos')
        await self.fill_expense()
        await self.page.get_by_role('button',name='Registrar gasto',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual(self.posts[0][1]['amount'],'0.10')
        self.assertEqual(self.posts[0][1]['currency'],'CRC')
        self.assertNotIn('created_by_id',self.posts[0][1])
        self.assertTrue(self.posts[0][1]['occurred_at'].endswith('-06:00'))

    async def test_lost_response_explicit_retry_same_key_no_double_click(self):
        await self.page.goto(self.base+'/gastos')
        await self.fill_expense()
        self.lost=True
        await self.page.get_by_role('button',name='Registrar gasto',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_be_visible()
        await expect(self.page.get_by_role('button',name='Registrar gasto',exact=True)).to_be_disabled()
        self.assertEqual(len(self.posts),1)
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual(self.posts[0][1],self.posts[1][1])

    async def test_validation_error_does_not_claim_success(self):
        await self.page.goto(self.base+'/gastos')
        await self.fill_expense()
        self.invalid=True
        await self.page.get_by_role('button',name='Registrar gasto',exact=True).click()
        await expect(self.page.get_by_text('amount: El importe debe ser mayor que cero.')).to_be_visible()
        control=self.page.get_by_label('Importe del gasto',exact=True)
        await expect(control).to_have_attribute('aria-invalid','true')
        error_id=await control.get_attribute('aria-describedby')
        self.assertTrue(error_id)
        await expect(self.page.locator('[id="'+error_id+'"]')).to_be_visible()
        await expect(control).not_to_be_empty()

        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_have_count(0)

    async def test_secretary_cannot_decide_but_can_correct(self):
        self.role='secretary'
        await self.page.goto(self.base+'/gastos')
        await self.page.get_by_role('button',name='Ver gasto',exact=True).click()
        await expect(self.page.get_by_role('button',name='Registrar decisión')).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Corregir con historial')).to_be_visible()

    async def test_administrator_decision_pins_revision(self):
        await self.page.goto(self.base+'/gastos')
        await self.page.get_by_role('button',name='Ver gasto',exact=True).click()
        await self.page.get_by_label('Motivo de decisión').fill('Comprobante revisado')
        await self.page.get_by_role('button',name='Registrar decisión',exact=True).click()
        await expect(self.page.get_by_text('Operación confirmada.',exact=False)).to_be_visible()
        self.assertEqual(self.posts[0][1]['expected_revision_id'],'revision-1')
        self.assertEqual(self.posts[0][1]['decision'],'approved')
