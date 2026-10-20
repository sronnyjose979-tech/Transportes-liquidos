import type { Component } from 'vue';
import type { OfficePage } from './context';

export const pageLoaders: Record<OfficePage, () => Promise<{default: Component}>> = {
    dashboard: () => import('./OfficeDashboard.vue'),
    transport: () => import('../TransportAndDispatch/TransportApp.vue'),
    fleet: () => import('../Administration/AdminApp.vue'),
    personnel: () => import('../Administration/AdminApp.vue'),
    catalogs: () => import('../Administration/AdminApp.vue'),
    adminPurchasing: () => import('../Administration/AdminApp.vue'),
    audit: () => import('../AdministrativeAudit/AuditApp.vue'),
    reports: () => import('../AdministrativeReports/ReportsApp.vue'),
};
export const receivesSession = new Set<OfficePage>(['audit', 'reports']);
