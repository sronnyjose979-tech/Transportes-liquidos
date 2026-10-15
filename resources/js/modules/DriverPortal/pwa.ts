/** No forced activation: installed updates wait until previous application windows close. */
export async function registerDriverPwa(notify: (message: string) => void): Promise<void> {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        notify('No se pudo habilitar la reapertura offline en este navegador.'); return;
    }
    try {
        const registration=await navigator.serviceWorker.register('/driver-sw.js', {scope: '/mis-viajes', updateViaCache: 'none'});
        const observe=():void=>{
            const worker=registration.installing;
            if(!worker)return;
            notify('Preparando los archivos públicos para reabrir sin conexión…');
            worker.addEventListener('statechange',()=>{
                if(worker.state==='activated')notify('PWA preparada para reabrir sin conexión en este dispositivo.');
                if(worker.state==='installed'&&registration.active)notify('Actualización disponible. Cierra todas las ventanas de la PWA para aplicarla; las capturas se conservarán.');
                if(worker.state==='redundant')notify('No se pudo preparar esta versión de la PWA. Conserva tus capturas y vuelve a abrir con conexión.');
            });
        };
        if(registration.waiting)notify('Actualización disponible. Cierra todas las ventanas de la PWA para aplicarla; las capturas se conservarán.');
        else if(registration.active)notify('PWA preparada para reabrir sin conexión en este dispositivo.');
        observe();registration.addEventListener('updatefound',observe);
    } catch {notify('No se pudo preparar la PWA para reabrir sin conexión. Las capturas locales se conservan.');}
}
