"""Targeted Chromium viewport regressions; reuse synthetic module contracts, not E2E."""
import unittest
from playwright.async_api import expect
import test_supply_catalogs as administration
import test_transport as transport
import test_workshop as workshop
import test_supply as supply
import test_billing as billing
import test_expenses as expenses
import test_reports as reports
import test_document_alerts as alerts

async def screens(case):
    for width in [320,360,390,768,1280]:
        await case.page.set_viewport_size({'width':width,'height':1000})
        result=await case.page.evaluate("""()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,offenders:[...document.querySelectorAll('main,section,form,input,select,nav')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,6).map(e=>e.tagName+'.'+e.className)})""")
        case.assertLessEqual(result['scroll'],width,repr(result))
        nav=case.page.get_by_role('navigation',name='Módulos del sistema',exact=True)
        await expect(nav).to_be_visible()
        for link in await nav.get_by_role('link').all():
            bounds=await link.bounding_box()
            case.assertIsNotNone(bounds)
            case.assertGreaterEqual(bounds['x'],-1)
            case.assertLessEqual(bounds['x']+bounds['width'],width+1)


async def scroll_tables(case):
    await case.page.set_viewport_size({'width':320,'height':1000})
    boxes=case.page.locator('.overflow-x-auto, .workshop-table-scroll')
    for index in range(await boxes.count()):
        box=boxes.nth(index)
        if not await box.evaluate('e=>e.scrollWidth>e.clientWidth+1'): continue
        await box.focus()
        case.assertTrue(await box.evaluate('e=>document.activeElement===e'),'Wide table cannot receive keyboard focus')
        await box.press('ArrowRight')
        await case.page.wait_for_function('e=>e.scrollLeft>0',arg=await box.element_handle(),timeout=1500)
        case.assertGreater(await box.evaluate('e=>e.scrollLeft'),0,'Keyboard cannot reach the last table columns')
        await box.evaluate('e=>e.scrollLeft=e.scrollWidth')
        actions=box.locator('button,a')
        if await actions.count():
            visible=await actions.last.evaluate('e=>{const p=e.closest(".overflow-x-auto,.workshop-table-scroll").getBoundingClientRect(),r=e.getBoundingClientRect();return r.left>=p.left-1&&r.right<=p.right+1;}')
            case.assertTrue(visible,'Last-row action must remain reachable inside its table')

class AdministrationLayout(administration.SupplyCatalogBrowserTest):
    async def test_layout(self):
        self.records['customers']=[{'id':'customer-1','name':'Cliente'+('Extenso'*30)}]
        await self.open_catalog('customers')
        await expect(self.page.get_by_role('button',name='Consultar / editar')).to_be_visible()
        await screens(self)
        await scroll_tables(self)

class FleetLayout(administration.SupplyCatalogBrowserTest):
    async def test_layout(self):
        await self.page.goto(self.base+'/administracion/flota')
        await self.page.get_by_label('Tipo de activo',exact=True).select_option('tanker')
        await self.page.get_by_role('button',name='Registrar cisterna',exact=True).click()
        await expect(self.page.get_by_role('button',name='Guardar activo')).to_be_visible()
        await screens(self)

class TransportLayout(transport.TransportBrowserTest):
    async def test_layout(self):
        await self.open_trip()
        await screens(self)
        await self.page.get_by_role('button',name='Viajes',exact=True).click()
        await expect(self.page.get_by_role('button',name='Abrir viaje')).to_be_visible()
        await screens(self)
        await scroll_tables(self)

class WorkshopLayout(workshop.WorkshopBrowserTest):
    async def test_layout(self):
        self.role='mechanic'
        await self.open_order()
        await screens(self)
        nav=self.page.get_by_role('navigation',name='Módulos del sistema',exact=True)
        await expect(nav.get_by_role('link',name='Taller',exact=True)).to_be_visible()
        await expect(nav.get_by_role('link',name='Flota',exact=True)).to_have_count(0)
        await expect(nav.get_by_role('link',name='Facturación',exact=True)).to_have_count(0)

class InventoryLayout(supply.SupplyBrowserTest):
    async def test_layout(self):
        await self.prepare_correction()
        await screens(self)

class PurchasesLayout(supply.SupplyBrowserTest):
    async def test_layout(self):
        self.role='secretary'
        await self.page.goto(self.base+'/compras')
        await self.page.get_by_role('button',name='Registrar solicitud de compra',exact=True).click()
        await expect(self.page.get_by_label('Cantidad solicitada',exact=True)).to_be_visible()
        await screens(self)
        nav=self.page.get_by_role('navigation',name='Módulos del sistema',exact=True)
        await expect(nav.get_by_role('link',name='Transporte',exact=True)).to_be_visible()
        await expect(nav.get_by_role('link',name='Personal',exact=True)).to_have_count(0)

class BillingLayout(billing.BillingBrowserTest):
    async def test_layout(self):
        self.invoice['reason']='Referencia'+('Administrativa'*25)
        self.invoice['customer']['name']='Cliente'+('Extenso'*25)
        await self.page.goto(self.base+'/facturacion')
        await self.page.get_by_role('button',name='Facturas y pagos',exact=True).click()
        await self.page.get_by_role('button',name='Ver factura y pagos').click()
        await expect(self.page.get_by_role('button',name='Registrar pago',exact=True)).to_be_visible()
        await screens(self)
        await scroll_tables(self)

class ExpensesLayout(expenses.ExpensesBrowserTest):
    async def test_layout(self):
        self.role='secretary'
        self.expense['concept']='Gasto'+('Referencia'*20)
        self.expense['description']='Documento'+('Identificador'*30)
        await self.page.goto(self.base+'/gastos')
        await self.page.get_by_role('button',name='Ver gasto',exact=True).click()
        await expect(self.page.get_by_role('button',name='Corregir con historial')).to_be_visible()
        await screens(self)

class ReportsLayout(reports.ReportsBrowserTest):
    async def test_layout(self):
        await self.page.goto(self.base+'/reportes')
        await self.page.get_by_role('button',name='Consultar reporte',exact=True).click()
        await expect(self.page.get_by_role('table').first).to_be_visible()
        await screens(self)
        await scroll_tables(self)

class AlertsLayout(alerts.DocumentAlertsBrowserTest):
    async def test_layout(self):
        self.role='secretary'
        await self.page.goto(self.base+'/alertas')
        await expect(self.page.get_by_role('table')).to_be_visible()
        await screens(self)
        await scroll_tables(self)

# Run only the new layout cases, not every inherited module suite again.
def load_tests(loader,tests,pattern):
    return unittest.TestSuite(cls('test_layout') for cls in [AdministrationLayout,FleetLayout,TransportLayout,WorkshopLayout,InventoryLayout,PurchasesLayout,BillingLayout,ExpensesLayout,ReportsLayout,AlertsLayout])
