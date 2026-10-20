import { createApp, h } from 'vue';
import type { Component } from 'vue';
import SessionBoundary from './modules/System/SessionBoundary.vue';

const officeRoot = document.getElementById('office-app');
if (officeRoot) {
    void import('./modules/Office/bootstrap').then(module => module.mountOffice(officeRoot)).catch(() => {
        officeRoot.textContent = 'No se pudo cargar la oficina. Vuelve a cargar la página.';
    });
}
// Compatibility for login/driver and remaining document shells. Only the active root loads.
const legacy = [
    ['system-app', () => import('./modules/System/SystemApp.vue')],
    ['transport-app', () => import('./modules/TransportAndDispatch/TransportApp.vue')],
    ['administration-app', () => import('./modules/Administration/AdminApp.vue')],
    ['workshop-app', () => import('./modules/Workshop/WorkshopApp.vue')],
    ['fleet-documents-app', () => import('./modules/FleetAndDocuments/FleetDocumentsApp.vue')],
    ['supply-app', () => import('./modules/Supply/SupplyApp.vue')],
] as const;
for (const [id, load] of legacy) {
    const root = document.getElementById(id);
    if (root) void load().then(module => {
        const component: Component = module.default;
        createApp({ render: () => h(SessionBoundary, null, { default: () => h(component) }) }).mount(root);
    }).catch(() => { root.textContent = 'No se pudo cargar el módulo. Vuelve a cargar la página.'; });
}
