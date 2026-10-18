import { sessionFetch as fetch } from '../System/sessionIdentity';
import { ApiError, refreshCsrf } from '../FleetAndDocuments/api';
import { authorization, entry, lookup, object, page, requestRecord, single, tripRecord } from './contracts';
import type { Authorization, Availability, Entry, Json, Lookup, Page, TransportRequest, Trip } from './contracts';
export { ApiError };
export function failure(error: unknown): ApiError { return error instanceof ApiError ? error : new ApiError(0, 'No se pudo confirmar la operación. Consulta el estado antes de volver a enviarla.'); }
const messages: Record<number, string> = { 401: 'Tu sesión terminó. Inicia sesión nuevamente.', 403: 'No tienes permiso para esta operación.', 404: 'El recurso solicitado no está disponible.', 409: 'El estado cambió o existe un conflicto. Actualiza y revisa antes de confirmar.', 419: 'La sesión de seguridad venció. Actualízala antes de reenviar.', 422: 'Revisa los datos indicados.', 500: 'No se pudo confirmar la operación. Consulta el estado antes de reenviar.' };
async function request(path: string, method = 'GET', body?: unknown, signal?: AbortSignal): Promise<unknown> {
    const headers = new Headers({ Accept: 'application/json' });
    if (method !== 'GET') {
        const token = () => decodeURIComponent(document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
        if (!token())
            await refreshCsrf();
        headers.set('X-XSRF-TOKEN', token());
        headers.set('Content-Type', 'application/json');
    }
    let response: Response;
    try {
        response = await fetch(`/api/transport${path}`, { method, headers, credentials: 'same-origin', cache: 'no-store', ...(body === undefined ? {} : { body: JSON.stringify(body) }), ...(signal ? { signal } : {}) });
    }
    catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError')
            throw error;
        throw failure(error);
    }
    const value: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const v = value && typeof value === 'object' ? object(value) : {};
        const fields: Record<string, string[]> = {};
        if ([409,422].includes(response.status) && v.errors && typeof v.errors === 'object')
            for (const [key, items] of Object.entries(v.errors))
                if (Array.isArray(items) && items.every(item => typeof item === 'string'))
                    fields[key] = items as string[];
        throw new ApiError(response.status, [409, 422].includes(response.status) && typeof v.message === 'string' ? v.message : messages[response.status] ?? messages[500]!, fields);
    }
    return value;
}
const query = (values: Record<string, string | number>) => new URLSearchParams(Object.entries(values).map(([k, v]) => [k, String(v)])).toString();
export const getRequests = async (number = 1, signal?: AbortSignal): Promise<Page<TransportRequest>> => page(await request(`/requests?${query({ page: number, per_page: 10 })}`, 'GET', undefined, signal), requestRecord);
export const getTrips = async (filters: Record<string, string | number>, signal?: AbortSignal): Promise<Page<Trip>> => page(await request(`/trips?${query({ per_page: 10, ...filters })}`, 'GET', undefined, signal), tripRecord);
export const getTrip = async (id: string, signal?: AbortSignal): Promise<Trip> => single(await request(`/trips/${encodeURIComponent(id)}`, 'GET', undefined, signal), tripRecord);
export const records = async (path: string, number = 1, signal?: AbortSignal): Promise<Page<Entry>> => page(await request(`${path}?${query({ page: number, per_page: 10 })}`, 'GET', undefined, signal), entry);
export const write = async (path: string, body: unknown, method = 'POST'): Promise<Entry> => single(await request(path, method, body), entry);
export async function all<T>(path: string, decode: (v: unknown) => T, signal?: AbortSignal): Promise<T[]> { const result: T[] = []; for (let number = 1;; number++) {
    const batch = page(await request(`${path}?${query({ page: number, per_page: 100 })}`, 'GET', undefined, signal), decode);
    result.push(...batch.data);
    if (number >= batch.meta.last_page)
        return result;
} }
export const getLookups = (kind: string, signal?: AbortSignal): Promise<Lookup[]> => all(`/lookups/${kind}`, lookup, signal);
export const getAuthorizations = (id: string, signal?: AbortSignal): Promise<Authorization[]> => all(`/trips/${encodeURIComponent(id)}/authorizations`, authorization, signal);
export const availability = async (kind: string, id: string): Promise<Availability> => { const v = object(object(await request(`/availability?${query({ resource_kind: kind, resource_id: id })}`)).data); if (typeof v.available !== 'boolean' || typeof v.eligible !== 'boolean' || !Array.isArray(v.conflicts))
    throw new Error('Disponibilidad inválida.'); return v as unknown as Availability; };
export const differences = async (id: string): Promise<Json> => { const v = object(object(await request(`/trips/${encodeURIComponent(id)}/volume-differences`)).data); return v.difference as Json; };
export const distance = async (id: string, assignment: string): Promise<string | null> => { const v = object(object(await request(`/trips/${encodeURIComponent(id)}/distance?${query({ tractor_assignment_id: assignment })}`)).data); if (v.distance !== null && typeof v.distance !== 'string')
    throw new Error('Distancia inválida.'); return v.distance; };
