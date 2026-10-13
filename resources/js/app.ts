import { createApp } from 'vue';
import FleetDocumentsApp from './modules/FleetAndDocuments/FleetDocumentsApp.vue';

const root = document.getElementById('fleet-documents-app');
if (root) createApp(FleetDocumentsApp).mount(root);

import TransportApp from './modules/TransportAndDispatch/TransportApp.vue';
const transportRoot = document.getElementById('transport-app');
if (transportRoot) createApp(TransportApp).mount(transportRoot);

import WorkshopApp from './modules/Workshop/WorkshopApp.vue';
const workshopRoot = document.getElementById('workshop-app');
if (workshopRoot) createApp(WorkshopApp).mount(workshopRoot);

import AdminApp from './modules/Administration/AdminApp.vue';
const administrationRoot = document.getElementById('administration-app');
if (administrationRoot) createApp(AdminApp).mount(administrationRoot);
