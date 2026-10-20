import { createApp, h } from 'vue';
import type { Component } from 'vue';
import SessionBoundary from './modules/System/SessionBoundary.vue';
import OfficeApp from './modules/Office/OfficeApp.vue';
import { createOfficeWorkspace } from './modules/Office/workspace';
import { decodeOfficeContext, officePaths } from './modules/Office/context';
import { rememberSession } from './modules/System/sessionIdentity';
function application(component: Component) { return createApp({ render: () => h(SessionBoundary, null, { default: () => h(component) }) }); }
import FleetDocumentsApp from './modules/FleetAndDocuments/FleetDocumentsApp.vue';

const root = document.getElementById('fleet-documents-app');
if (root) application(FleetDocumentsApp).mount(root);

import TransportApp from './modules/TransportAndDispatch/TransportApp.vue';
const transportRoot = document.getElementById('transport-app');
if (transportRoot) application(TransportApp).mount(transportRoot);

import WorkshopApp from './modules/Workshop/WorkshopApp.vue';
const workshopRoot = document.getElementById('workshop-app');
if (workshopRoot) application(WorkshopApp).mount(workshopRoot);

import AdminApp from './modules/Administration/AdminApp.vue';
const administrationRoot = document.getElementById('administration-app');
if (administrationRoot) application(AdminApp).mount(administrationRoot);

import SupplyApp from './modules/Supply/SupplyApp.vue';
const supplyRoot = document.getElementById('supply-app');
if (supplyRoot) application(SupplyApp).mount(supplyRoot);

import SystemApp from './modules/System/SystemApp.vue';
const systemRoot = document.getElementById('system-app');
if (systemRoot) application(SystemApp).mount(systemRoot);

const officeRoot = document.getElementById('office-app');
if (officeRoot) {
    try {
        const context = decodeOfficeContext(JSON.parse(document.getElementById('office-context')?.textContent ?? 'null'));
        if (officePaths[context.page] !== window.location.pathname) throw new Error('Destino inválido.');
        rememberSession(context.session);
        const workspace = createOfficeWorkspace(context, { dashboard: () => import('./modules/System/SystemApp.vue'), transport: () => import('./modules/TransportAndDispatch/TransportApp.vue'), fleet: () => import('./modules/Administration/AdminApp.vue') });
        createApp({ render: () => h(SessionBoundary, null, { default: () => h(OfficeApp, { workspace }) }) }).use(workspace.router).mount(officeRoot);
    } catch {
        officeRoot.textContent = 'No se pudo iniciar la oficina. Vuelve a cargar la página para comprobar la sesión.';
    }
}
