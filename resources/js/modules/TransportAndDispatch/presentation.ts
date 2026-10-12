import type { Authorization, Json, Trip } from './contracts';
export const labels: Record<string, string> = { pending: 'Pendiente', in_progress: 'En curso', completed: 'Finalizado', cancelled: 'Cancelado', open: 'Abierto', closed: 'Cerrado', scheduled: 'Programado', rescheduled: 'Reprogramado', driver: 'Conductor', tractor_unit: 'Cabezal', tanker: 'Cisterna', approved: 'Aprobada', rejected: 'Rechazada', assignment_conflict: 'Conflicto de asignación', driver_substitution: 'Sustitución de conductor', tractor_substitution: 'Sustitución de cabezal', volume_difference: 'Diferencia de litros' };
export const date = (value: string | null | undefined): string => value ? new Intl.DateTimeFormat('es-CR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Costa_Rica' }).format(new Date(value)) : 'Sin registrar';
export const number = (value: string | number | null | undefined): string => { if (value === null || value === undefined || value === '')
    return '—'; if (typeof value === 'number')
    return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 6 }).format(value); if (!/^-?[0-9]+(?:\.[0-9]+)?$/.test(value))
    return value; const [whole, fraction] = value.split('.'); const format = new Intl.NumberFormat('es-CR'); const decimal = format.formatToParts(1.1).find(p => p.type === 'decimal')?.value ?? '.'; return format.format(BigInt(whole!)) + (fraction ? decimal + fraction : ''); };
export const instant = (local: string): string => `${local.length === 16 ? local + ':00' : local}-06:00`;
export const localDate = (value: string): string => new Intl.DateTimeFormat('sv-SE', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'America/Costa_Rica', hour12: false }).format(new Date(value)).replace(' ', 'T');
export function same(left: Json, right: Json): boolean { if (Array.isArray(left) && Array.isArray(right))
    return left.length === right.length && left.every((v, i) => same(v, right[i]!)); if (left && right && typeof left === 'object' && typeof right === 'object' && !Array.isArray(left) && !Array.isArray(right)) {
    const keys = Object.keys(left).sort();
    return keys.length === Object.keys(right).length && keys.every(k => Object.hasOwn(right, k) && same(left[k]!, right[k]!));
} return left === right; }
export function approvalMatches(a: Authorization, trip: Trip, content: Json, type: string): boolean { return a.trip_id === trip.id && a.target_version === trip.version && a.type === type && a.decision?.decision === 'approved' && !a.decision.application && same(a.content, content); }
export function captured(trip: Trip): boolean { return !!trip.officially_started_at && !!trip.load?.items.length && !!trip.unload && trip.load.items.every(i => trip.unload!.items.some(u => u.load_item_id === i.id)); }
export function sum(values: (string | undefined)[]): string { if (!values.length) return ''; let total = 0n; for (const value of values) {
    if (value === undefined)
        return '';
    const parts = value.split('.');
    total += BigInt(parts[0]!) * 100n + BigInt((parts[1] ?? '').padEnd(2, '0'));
} return `${total / 100n}.${(total % 100n).toString().padStart(2, '0')}`; }
