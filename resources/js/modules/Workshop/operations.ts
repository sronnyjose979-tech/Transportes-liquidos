import type { Field } from './components/CommandForm.vue';
import type { RecordRow } from './api';
import { instant, localInstant } from './presentation';
export interface OperationCommand { title: string; path: string; method?: string; fields: Field[]; extra?: Record<string, unknown>; transform?: (values: Record<string, string>) => Record<string, unknown> }
export type Choice = { value: string; label: string };
export const selectField = (key: string, label: string, options: Choice[], required = true): Field => ({ key, label, options, searchable: true, required });
export const noteField = (key: string, label: string, required = true): Field => ({ key, label, required, type: 'textarea' });
export const dateField = (key = 'occurred_at', label = 'Fecha y hora (Costa Rica)'): Field => ({ key, label, required: true, type: 'datetime-local', value: localInstant() });
export const reasonField = (): Field => noteField('reason', 'Motivo del registro');
export function operationValues(values: Record<string, string>): Record<string, unknown> {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '').map(([key, value]) => [key, ['occurred_at', 'performed_at'].includes(key) ? instant(value) : value]));
}
export function choices(rows: RecordRow[], label: (row: RecordRow) => string): Choice[] { return rows.map(row => ({ value: row.id, label: label(row) })); }
export function effective(rows: RecordRow[]): RecordRow[] { return rows.filter(row => row.is_effective === true); }
export function timeSummary(value: unknown): { total: string; participants: { id: string; seconds: string; open: string | null }[] } | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const row = value as Record<string, unknown>;
    if (!['string', 'number'].includes(typeof row.person_seconds) || !row.participants || typeof row.participants !== 'object') return null;
    const participants = Object.entries(row.participants).flatMap(([id, item]) => {
        if (!item || typeof item !== 'object') return [];
        const p = item as Record<string, unknown>;
        return ['string', 'number'].includes(typeof p.confirmed_seconds) ? [{ id, seconds: String(p.confirmed_seconds), open: typeof p.open_since === 'string' ? p.open_since : null }] : [];
    });
    return { total: String(row.person_seconds), participants };
}
export function costSummary(value: unknown): { basis: string; currency: string; amount: string }[] {
    return Array.isArray(value) ? value.flatMap(item => {
        if (!item || typeof item !== 'object') return [];
        const row = item as Record<string, unknown>;
        return typeof row.basis === 'string' && typeof row.currency === 'string' && typeof row.amount === 'string' ? [{ basis: row.basis, currency: row.currency, amount: row.amount }] : [];
    }) : [];
}
