"""Real PWA offline navigations and synchronization against Laravel/PostgreSQL 18.
Run: bash tests/run-postgres.sh --e2e-driver-offline (after a production build).
No API success mocks; the existing fault proxy can discard a committed response.
"""
import asyncio
import unittest
from playwright.async_api import expect
import test_expenses_driver as expenses_driver
import test_document_module as support


class DriverOfflineE2E(unittest.IsolatedAsyncioTestCase):
    async def ready(self):
        await self.driver()
        await self.page.wait_for_function('async()=>!!(await navigator.serviceWorker.getRegistration("/mis-viajes"))?.active',timeout=20000)
        await self.page.reload()
        await self.page.wait_for_function('navigator.serviceWorker.controller!==null')
        cdp=await self.context.new_cdp_session(self.page)
        self.assertEqual([], (await cdp.send('Page.getAppManifest'))['errors'])
        self.assertEqual([], (await cdp.send('Page.getInstallabilityErrors'))['installabilityErrors'])
        await cdp.detach()
        await self.page.get_by_role('button',name='Consultar viaje y registrar información',exact=True).click()

    async def report_offline(self,text):
        await self.context.set_offline(True)
        await self.page.get_by_label('Observación',exact=True).fill(text)
        await self.page.get_by_role('button',name='Registrar reporte',exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='Captura guardada')).to_be_visible()

    async def test_offline_reload_reopen_and_automatic_sync_with_receipt(self):
        await self.ready()
        before=self.expenses('snapshot',self.fixture)
        await self.report_offline('Reporte offline durable')
        await self.page.get_by_label('Kilometraje',exact=True).fill('100.25')
        await self.page.get_by_label('Motivo de lectura',exact=True).fill('Lectura offline')
        await self.page.get_by_role('button',name='Registrar kilometraje',exact=True).click()
        await self.page.get_by_label('Concepto',exact=True).fill('Gasto offline durable')
        await self.page.get_by_label('Categoría',exact=True).fill('Operación')
        await self.page.get_by_label('Monto',exact=True).fill('1.02')
        await self.page.get_by_label('Comprobante del nuevo gasto',exact=False).set_input_files(str(support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf'))
        await self.page.get_by_role('button',name='Registrar gasto pendiente',exact=True).click()
        await expect(self.page.get_by_role('status').filter(has_text='4 capturas sin confirmar')).to_be_visible()
        self.assertEqual(before,self.expenses('snapshot',self.fixture))
        await self.page.reload()
        await expect(self.page.get_by_role('heading',name='Capturas de este dispositivo',exact=True)).to_be_visible()
        await expect(self.page.get_by_role('status').filter(has_text='4 capturas sin confirmar')).to_be_visible()
        await self.page.close();self.page=await self.context.new_page()
        await self.page.goto(self.base+'/mis-viajes')
        await expect(self.page.get_by_role('status').filter(has_text='4 capturas sin confirmar')).to_be_visible()
        await self.context.set_offline(False)
        await expect(self.page.get_by_role('status').filter(has_text='0 capturas sin confirmar')).to_be_visible(timeout=30000)
        after=self.expenses('snapshot',self.fixture)
        self.assertEqual(before['reports']+1,after['reports']);self.assertEqual(1,after['readings']);self.assertEqual(1,after['expenses']);self.assertEqual(1,after['receipts'])
        expenses=(await self.api('/expenses'))['body']['data']
        expense=next(e for e in expenses if e['concept']=='Gasto offline durable')
        self.assertEqual('pending',expense['status'])
        download=await self.context.request.get(self.base+'/api/expenses/receipts/'+expense['receipts'][0]['id']+'/download')
        self.assertEqual((support.ROOT/'tests/Fixtures/fleet-documents/valid.pdf').read_bytes(),await download.body())
        # Only static resources and the public empty shell may enter Cache Storage.
        keys=await self.page.evaluate('''async()=>{let urls=[];for(const name of await caches.keys())for(const r of await (await caches.open(name)).keys())urls.push(new URL(r.url).pathname);return urls;}''')
        self.assertFalse(any(p.startswith('/api/') or p=='/mis-viajes' for p in keys))

    async def test_session_expiry_account_change_and_reauthentication_preserve_queue(self):
        await self.ready();await self.report_offline('Reporte privado de cuenta original')
        # End server session without invoking UI cleanup, simulating session expiry.
        # Clear cookies while offline, then resume so the first sync sees expiry.
        await self.context.clear_cookies()
        await self.context.set_offline(False)
        await expect(self.page.get_by_role('heading',name='Iniciar sesión',exact=True)).to_be_visible(timeout=30000)
        await self.page.get_by_label('Usuario',exact=True).fill('e2e-driver')
        await self.page.get_by_label('Contraseña',exact=True).fill(support.PASSWORD)
        await self.page.get_by_role('button',name='Iniciar sesión',exact=True).click()
        await expect(self.page.get_by_role('button',name='Cerrar sesión',exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Reporte privado de cuenta original',exact=False)).to_have_count(0)
        await self.out();await self.login('driver',self.fixture['driver_login'])
        await expect(self.page.get_by_role('status').filter(has_text='Registros confirmados por el servidor')).to_be_visible(timeout=30000)
        history=await self.api('/transport/my-records')
        self.assertTrue(any(r['information'].get('observation')=='Reporte privado de cuenta original' for r in history['body']['data']['reports']))

    async def test_cancelled_reassigned_trip_keeps_capture_as_conflict(self):
        await self.ready();await self.report_offline('Conservar ante cancelación y liberación')
        self.expenses('release',self.fixture)
        before=self.expenses('snapshot',self.fixture)
        await self.context.set_offline(False)
        await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible(timeout=30000)
        await self.page.reload()
        await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible()
        await expect(self.page.get_by_text('Conservar ante cancelación y liberación',exact=False)).to_be_visible()
        self.assertEqual(before,self.expenses('snapshot',self.fixture))
        worker=support.ROOT/'public/build/driver-sw.js'
        original=worker.read_text()
        try:
            worker.write_text(original.replace("const CACHE='driver-public-", "const CACHE='driver-public-update-"))
            await self.page.evaluate('async()=>{const r=await navigator.serviceWorker.getRegistration();await r.update();}')
            await self.page.wait_for_function('async()=>!!(await navigator.serviceWorker.getRegistration())?.waiting',timeout=20000)
            # An update must wait; it must not reload the open form or erase IndexedDB.
            await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible()
            await self.page.close();self.page=await self.context.new_page()
            self.page.on('dialog',lambda d:asyncio.create_task(d.accept()))
            await self.page.goto(self.base+'/mis-viajes')
            await self.page.wait_for_function('async()=>!!(await navigator.serviceWorker.getRegistration())?.active&&!((await navigator.serviceWorker.getRegistration())?.waiting)',timeout=20000)
            await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible()
            await self.out();await self.login('driver',self.fixture['driver_login'])
            await expect(self.page.get_by_text('en conflicto',exact=True)).to_be_visible()
            self.assertEqual(before,self.expenses('snapshot',self.fixture))
        finally:
            worker.write_text(original)

for _name,_value in expenses_driver.ExpensesDriverE2E.__dict__.items():
    if not _name.startswith('test_') and not _name.startswith('__') and _name not in DriverOfflineE2E.__dict__:
        setattr(DriverOfflineE2E,_name,_value)

if __name__=='__main__':unittest.main()
