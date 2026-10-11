import { createApp } from 'vue';
import FleetDocumentsApp from './modules/FleetAndDocuments/FleetDocumentsApp.vue';

const root = document.getElementById('fleet-documents-app');
if (root) createApp(FleetDocumentsApp).mount(root);
