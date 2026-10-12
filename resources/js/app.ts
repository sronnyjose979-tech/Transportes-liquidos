import { createApp } from 'vue';
import FleetDocumentsApp from './modules/FleetAndDocuments/FleetDocumentsApp.vue';

const root = document.getElementById('fleet-documents-app');
if (root) createApp(FleetDocumentsApp).mount(root);

import TransportApp from './modules/TransportAndDispatch/TransportApp.vue';
const transportRoot = document.getElementById('transport-app');
if (transportRoot) createApp(TransportApp).mount(transportRoot);
