import {createHash} from 'node:crypto';

/** Public-only shell. Never cache Blade responses, API data or uploaded receipts. */
export function driverPwa() {
    return {
        name: 'driver-offline-shell',
        generateBundle: {order: 'post', handler(_options,bundle) {
            const entry=Object.values(bundle).find(item=>item.type==='chunk'&&item.facadeModuleId?.endsWith('/resources/js/app.ts'));
            if(!entry)throw new Error('Missing driver application entry');
            const assets=Object.keys(bundle).filter(name=>/\.(js|css|woff2?)$/.test(name));
            const styles=assets.filter(name=>name.endsWith('.css')).map(name=>`<link rel="stylesheet" href="/build/${name}">`).join('');
            const shell=`<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#1e40af"><link rel="manifest" href="/driver.webmanifest"><title>Mis viajes · Transportes Líquidos</title>${styles}<script type="module" src="/build/${entry.fileName}"></script></head><body><div id="system-app" data-section="driver"></div></body></html>`;
            const version=createHash('sha256').update(shell+assets.join(',')).digest('hex').slice(0,16);
            const urls=['/build/driver-shell.html','/driver.webmanifest','/driver-icon-192.png','/driver-icon-512.png',...assets.map(name=>'/build/'+name)];
            this.emitFile({type:'asset',fileName:'driver-shell.html',source:shell});
            this.emitFile({type:'asset',fileName:'driver-sw.js',source:`
const CACHE='driver-public-${version}';
const PUBLIC=${JSON.stringify(urls)};
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(PUBLIC))));
// Do not skipWaiting: the old application completes its writes before the update activates.
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('driver-public-')&&key!==CACHE).map(key=>caches.delete(key))))));
self.addEventListener('fetch',event=>{
 const url=new URL(event.request.url);
 if(event.request.method!=='GET'||url.origin!==self.location.origin)return;
 if(event.request.mode==='navigate'&&url.pathname==='/mis-viajes'){
  event.respondWith(fetch(event.request).catch(()=>caches.open(CACHE).then(cache=>cache.match('/build/driver-shell.html'))));return;
 }
 if(PUBLIC.includes(url.pathname)&&!url.search)event.respondWith(caches.open(CACHE).then(cache=>cache.match(url.pathname)).then(hit=>hit||fetch(event.request)));
});`});
        }},
    };
}
