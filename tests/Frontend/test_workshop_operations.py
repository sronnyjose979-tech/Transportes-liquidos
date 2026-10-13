"""Chromium integration for the second delivery using isolated synthetic HTTP fixtures."""
import copy
import unittest
from urllib.parse import urlparse
from playwright.async_api import expect
import test_workshop as workshop

TASK='70000000-0000-4000-8000-000000000001'
ASSIGNMENT='70000000-0000-4000-8000-000000000002'
PART='70000000-0000-4000-8000-000000000003'
DELIVERY='70000000-0000-4000-8000-000000000004'
ACTIVITY='70000000-0000-4000-8000-000000000005'
UNAPPLIED='70000000-0000-4000-8000-000000000006'

class WorkshopOperationsBrowserTest(unittest.IsolatedAsyncioTestCase):
    setUpClass=classmethod(workshop.WorkshopBrowserTest.setUpClass.__func__)
    tearDownClass=classmethod(workshop.WorkshopBrowserTest.tearDownClass.__func__)
    pagination=workshop.WorkshopBrowserTest.pagination
    raw_pagination=workshop.WorkshopBrowserTest.raw_pagination
    asyncTearDown=workshop.WorkshopBrowserTest.asyncTearDown
    enter=workshop.WorkshopBrowserTest.enter
    open_order=workshop.WorkshopBrowserTest.open_order

    async def asyncSetUp(self):
        await workshop.WorkshopBrowserTest.asyncSetUp(self)
        self.data['orders'][0].update(status='authorized',version=6)
        self.data['scopes']=[dict(id=workshop.SCOPE,work_order_id=workshop.ORDER,is_applied=True,is_effective=True),dict(id=UNAPPLIED,work_order_id=workshop.ORDER,is_applied=False,is_effective=True)]
        self.data['scope-tasks']=[dict(id=TASK,scope_id=workshop.SCOPE,work_order_id=workshop.ORDER,line_number=1,description='Reemplazar sello autorizado'),dict(id=UNAPPLIED,scope_id=UNAPPLIED,work_order_id=workshop.ORDER,line_number=1,description='Trabajo todavía no aplicado')]
        self.data['scope-parts']=[dict(id=PART,scope_id=workshop.SCOPE,work_order_id=workshop.ORDER,spare_part_id=workshop.REPORT,quantity='2.000000')]
        self.data['mechanic-assignments']=[dict(id=ASSIGNMENT,employee_id=workshop.DIAG,work_order_id=workshop.ORDER,employee_name='Mecánico prueba',is_current=True,is_current_actor=True)]
        self.data['deliveries']=[dict(id=DELIVERY,work_order_id=workshop.ORDER,scope_part_id=PART,delivered_to_assignment_id=ASSIGNMENT,quantity='2.000000',occurred_at='2026-10-13T12:00:00Z',is_effective=True)]
        self.data['activities']=[dict(id=ACTIVITY,work_order_id=workshop.ORDER,task_id=TASK,mechanic_assignment_id=ASSIGNMENT,description='Inspección inicial',occurred_at='2026-10-13T12:00:00Z',is_effective=True,revision=1)]
        for kind in ['time-events','usages','material-needs','external-repairs','costs']: self.data[kind]=[]
        self.time_data={'participants':{ASSIGNMENT:{'confirmed_seconds':'60.000001','open_since':None}},'person_seconds':'60.000001'}
        self.cost_data=[{'basis':'actual','currency':'CRC','amount':'999999999999999.123456'}]

    async def api(self,route):
        path=urlparse(route.request.url).path
        if route.request.method=='GET' and path==f'/api/workshop/orders/{workshop.ORDER}/times': return await route.fulfill(json={'data':self.time_data})
        if route.request.method=='GET' and path==f'/api/workshop/orders/{workshop.ORDER}/cost-totals': return await route.fulfill(json={'data':self.cost_data})
        await workshop.WorkshopBrowserTest.api(self,route)

    async def command(self,name):
        await self.page.get_by_role('button',name=name,exact=True).click()
        return self.page.get_by_role('dialog').get_by_role('form')

    async def save(self,form):
        await form.get_by_label('Motivo del registro',exact=True).fill('Prueba aislada de operación')
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('dialog')).to_have_count(0)

    async def test_cancelling_a_form_preserves_confirmed_time_and_cost_summaries(self):
        await self.open_order()
        await self.page.get_by_role('button',name='Costos',exact=True).click()
        await expect(self.page.get_by_text('Real: 999999999999999.123456 CRC',exact=True)).to_be_visible()
        form=await self.command('Registrar costo')
        await form.get_by_role('button',name='Cancelar',exact=True).click()
        await expect(self.page.get_by_text('Real: 999999999999999.123456 CRC',exact=True)).to_be_visible()
        await self.page.get_by_role('button',name='Tiempos',exact=True).click()
        await expect(self.page.get_by_text('60.000001 segundos-persona',exact=False)).to_be_visible()

    async def test_activity_uses_only_applied_work_and_retries_exact_payload(self):
        await self.open_order()
        await expect(self.page.get_by_text('Trabajo todavía no aplicado',exact=True)).to_have_count(0)
        form=await self.command('Registrar actividad')
        await expect(form.get_by_label('Trabajo autorizado',exact=True).locator('option')).to_have_count(2)
        await form.get_by_label('Trabajo autorizado',exact=True).select_option(TASK)
        await form.get_by_label('Mecánico asignado',exact=True).select_option(ASSIGNMENT)
        await form.get_by_label('Actividad realizada',exact=True).fill('Cambio de sello')
        await form.get_by_label('Motivo del registro',exact=True).fill('Trabajo autorizado')
        endpoint=('POST',f'/api/workshop/orders/{workshop.ORDER}/activities')
        self.forced[endpoint]=(500,{})
        await form.get_by_role('button',name='Guardar',exact=True).click()
        await expect(self.page.get_by_role('button',name='Reintentar la misma operación')).to_be_visible()
        first=copy.deepcopy(self.sent[-1])
        self.assertEqual(6,first[1]['version'])
        self.assertEqual(TASK,first[1]['task_id'])
        self.assertTrue(first[1]['occurred_at'].endswith('-06:00'))
        self.forced.pop(endpoint)
        await self.page.get_by_role('button',name='Reintentar la misma operación').click()
        await expect(self.page.get_by_role('dialog')).to_have_count(0)
        self.assertEqual(first,self.sent[-1])

    async def test_time_summary_decimal_and_event_payload(self):
        await self.open_order()
        await self.page.get_by_role('button',name='Tiempos',exact=True).click()
        await expect(self.page.get_by_text('60.000001 segundos-persona',exact=False)).to_be_visible()
        form=await self.command('Iniciar tiempo')
        await form.get_by_label('Mecánico asignado',exact=True).select_option(ASSIGNMENT)
        await self.save(form)
        payload=self.sent[-1][1]
        self.assertEqual('start',payload['event_type'])
        self.assertEqual(6,payload['version'])
        self.assertEqual(ASSIGNMENT,payload['mechanic_assignment_id'])

    async def test_material_selectors_keep_decimal_quantities_and_inventory_notice(self):
        await self.open_order()
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await expect(self.page.get_by_text('Todavía no existe descuento real de inventario',exact=False)).to_be_visible()
        for button,reference,label in [('Registrar entrega',PART,'Repuesto autorizado'),('Registrar utilización',DELIVERY,'Entrega recibida')]:
            form=await self.command(button)
            await form.get_by_label(label,exact=True).select_option(reference)
            await form.get_by_label('Mecánico asignado',exact=True).select_option(ASSIGNMENT)
            await form.get_by_label('Cantidad decimal',exact=True).fill('0.123456')
            await expect(form.locator('input[pattern="[0-9a-fA-F-]{36}"]')).to_have_count(0)
            await self.save(form)
            self.assertEqual('0.123456',self.sent[-1][1]['quantity'])
        form=await self.command('Solicitar repuesto')
        await form.get_by_label('Repuesto solicitado',exact=True).select_option(workshop.REPORT)
        await form.get_by_label('Cantidad decimal',exact=True).fill('1.000001')
        await form.get_by_label('Necesidad de material',exact=True).fill('Sello adicional')
        await self.save(form)
        self.assertEqual(workshop.REPORT,self.sent[-1][1]['spare_part_id'])

    async def test_costs_use_server_totals_and_preserve_large_decimal_string(self):
        await self.open_order()
        await self.page.get_by_role('button',name='Costos',exact=True).click()
        await expect(self.page.get_by_text('Real: 999999999999999.123456 CRC',exact=True)).to_be_visible()
        form=await self.command('Registrar costo')
        await form.get_by_label('Tipo de costo',exact=True).select_option('labor')
        await form.get_by_label('Base',exact=True).select_option('actual')
        await form.get_by_label('Importe decimal (si corresponde)',exact=True).fill('999999999999999.123456')
        await form.get_by_label('Moneda ISO (por ejemplo CRC)',exact=True).fill('CRC')
        await form.get_by_label('Trabajo (opcional)',exact=True).select_option(TASK)
        await form.get_by_label('Concepto y detalle',exact=True).fill('Mano de obra confirmada')
        await self.save(form)
        payload=self.sent[-1][1]
        self.assertEqual('999999999999999.123456',payload['amount'])
        self.assertIsInstance(payload['amount'],str)
        self.assertEqual(TASK,payload['scope_task_id'])

    async def test_admin_rectification_is_structured_and_preserves_fact(self):
        await self.open_order()
        await self.page.get_by_role('button',name='Rectificar Inspección inicial',exact=False).click()
        form=self.page.get_by_role('form',name='Solicitar rectificación auditable')
        await form.get_by_label('Actividad corregida',exact=True).fill('Inspección rectificada')
        await self.save(form)
        path,payload=self.sent[-1]
        self.assertEqual(f'/api/workshop/facts/activity/{ACTIVITY}/rectifications',path)
        self.assertEqual(6,payload['version'])
        self.assertEqual(7,payload['executor_id'])
        self.assertEqual({'description':'Inspección rectificada'},payload['changes'])
        self.assertEqual('Inspección inicial',self.data['activities'][0]['description'])

    async def test_mechanic_permissions_own_correction_and_mobile_operations(self):
        self.role='mechanic'
        self.data['activities'][0]['occurred_at']='2026-10-13 15:15:00.100001+00'
        await self.open_order()
        await expect(self.page.get_by_role('button',name='Costos',exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Rectificar Inspección inicial',exact=False)).to_have_count(0)
        await self.page.get_by_role('button',name='Corregir Inspección inicial',exact=False).click()
        form=self.page.get_by_role('form',name='Registrar corrección técnica auditable')
        await form.get_by_label('Actividad corregida',exact=True).fill('Inspección corregida por mecánico')
        await self.save(form)
        self.assertEqual(ACTIVITY,self.sent[-1][1]['previous_activity_id'])
        self.assertEqual('2026-10-13T15:15:00.100001+00:00',self.sent[-1][1]['occurred_at'])
        await self.page.get_by_role('button',name='Materiales',exact=True).click()
        await expect(self.page.get_by_role('button',name='Registrar entrega',exact=True)).to_have_count(0)
        await expect(self.page.get_by_role('button',name='Registrar utilización',exact=True)).to_be_visible()
        for width in [1440,390]:
            await self.page.set_viewport_size({'width':width,'height':844})
            self.assertTrue(await self.page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))

if __name__=='__main__': unittest.main(verbosity=2)
