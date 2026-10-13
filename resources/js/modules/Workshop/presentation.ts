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
export function instant(value: string): string { return `${value}${/T[0-9]{2}:[0-9]{2}$/.test(value) ? ':00' : ''}-06:00`; }
/** Convert PostgreSQL timestamp text to API ISO input without dropping fractional precision. */
export function serverInstant(value: string): string {
    const match=/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)(Z|[+-]\d{2}(?::?\d{2})?)$/.exec(value);
    if(!match)throw new Error('Fecha de servidor inválida.');
    const zone=match[3]!;
    const offset=zone==='Z'||zone.includes(':')?zone:zone.length===3?`${zone}:00`:`${zone.slice(0,3)}:${zone.slice(3)}`;
    return `${match[1]}T${match[2]}${offset}`;
}
