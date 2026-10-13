import type { RecordRow } from './api';
export const states: Record<string, string> = { pending_diagnosis: 'Pendiente de diagnóstico', pending_authorization: 'Pendiente de autorización', authorized: 'Autorizada', in_repair: 'En reparación', finished: 'Terminada', closed: 'Cerrada', cancelled: 'Cancelada' };
export const labels: Record<string, string> = { ...states, low: 'Baja', normal: 'Normal', high: 'Alta', urgent: 'Urgente', corrective: 'Correctivo', preventive: 'Preventivo', approved: 'Aprobada', rejected: 'Rechazada', repair: 'Reparación', extension: 'Ampliación', rectification: 'Rectificación', cancellation: 'Cancelación', reopening: 'Reapertura', exit: 'Salida', maintenance: 'Mantenimiento', out_of_service: 'Fuera de servicio', initial: 'Inicial', revision: 'Revisión' };
export function text(value: unknown): string { if (value === null || value === undefined)
    return '—'; if (typeof value === 'boolean')
    return value ? 'Sí' : 'No'; if (typeof value === 'object')
    return JSON.stringify(value, null, 2); return String(value); }
export const options = (values: Record<string, string>) => Object.entries(values).map(([value, label]) => ({ value, label }));
export const terminal = (row: RecordRow) => ['finished', 'closed', 'cancelled'].includes(String(row.status));
export function localInstant(): string { const parts = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Costa_Rica', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date()); return parts.replace(' ', 'T'); }
export function instant(value: string): string { return `${value}:00-06:00`; }
