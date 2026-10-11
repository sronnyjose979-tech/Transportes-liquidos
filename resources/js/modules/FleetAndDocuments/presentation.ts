import type { Validity } from './contracts';
export const validityLabels: Record<Validity, string> = { valid: 'Vigente', expiring_soon: 'Próximo a vencer', expired: 'Vencido', no_expiration: 'Sin vencimiento' };
export function calendarDate(value: string | null): string {
    if (!value) return 'No indicada';
    const [year, month, day] = value.split('-');
    return `${day}/${month}/${year}`;
}
export function registrationDate(value: string): string {
    return new Intl.DateTimeFormat('es-CR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Costa_Rica' }).format(new Date(value));
}
export const assetLabel = (type: 'tractor_unit' | 'tanker'): string => type === 'tractor_unit' ? 'Cabezal' : 'Cisterna';
