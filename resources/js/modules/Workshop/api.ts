import { sessionFetch as fetch } from '../System/sessionIdentity';
import { ApiError, refreshCsrf } from '../FleetAndDocuments/api';
import { object, page } from '../TransportAndDispatch/contracts';
import type { Page } from '../TransportAndDispatch/contracts';
export { ApiError };
export interface RecordRow {
    id: string;
    [key: string]: unknown;
}
function entry(value: unknown): RecordRow { const v = object(value); if (typeof v.id !== 'string')
    throw new Error('Registro inválido.'); return v as RecordRow; }
const messages: Record<number, string> = { 401: 'Tu sesión terminó. Inicia sesión nuevamente.', 403: 'No tienes permiso para esta operación.', 404: 'El registro solicitado no está disponible.', 409: 'El registro cambió. Actualiza y revisa los datos.', 419: 'La sesión de seguridad venció. Actualízala antes de reenviar.', 422: 'Revisa los campos indicados.', 429: 'Demasiadas solicitudes. Espera antes de continuar.' };
export async function request(path: string, method = 'GET', body?: unknown, signal?: AbortSignal): Promise<unknown> {
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
        response = await fetch(`/api${path}`, { method, headers, credentials: 'same-origin', cache: 'no-store', ...(body === undefined ? {} : { body: JSON.stringify(body) }), ...(signal ? { signal } : {}) });
    }
    catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError')
            throw e;
        throw new ApiError(0, 'No se pudo confirmar la operación. Consulta el estado o reintenta la misma operación.');
    }
    const value: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const v = value && typeof value === 'object' ? object(value) : {}, fields: Record<string, string[]> = {};
        if ([409, 422].includes(response.status) && v.errors && typeof v.errors === 'object')
            for (const [k, items] of Object.entries(v.errors))
                if (Array.isArray(items) && items.every(i => typeof i === 'string'))
                    fields[k] = items as string[];
        throw new ApiError(response.status, messages[response.status] ?? 'No se pudo confirmar la operación. Consulta el estado antes de continuar.', fields);
    }
    return value;
}
export async function list(kind: string, filters: Record<string, string | number> = {}, signal?: AbortSignal): Promise<Page<RecordRow>> {
    const query = new URLSearchParams(Object.entries(filters.per_page ? filters : { ...filters, per_page: 15 }).filter(([, v]) => v !== '').map(([k, v]) => [k, String(v)]));
    return page(await request(`/workshop/${kind}?${query}`, 'GET', undefined, signal), entry);
}
export async function all(kind: string, filters: Record<string, string | number> = {}, signal?: AbortSignal): Promise<RecordRow[]> {
    const rows: RecordRow[] = [];
    for (let number = 1;; number++) {
        const batch = await list(kind, { ...filters, per_page: 100, page: number }, signal);
        rows.push(...batch.data);
        if (number >= batch.meta.last_page)
            return rows;
    }
}
export async function detail(kind: string, id: string, signal?: AbortSignal): Promise<RecordRow> { return entry(object(await request(`/workshop/${kind}/${encodeURIComponent(id)}`, 'GET', undefined, signal)).data); }
export async function write(path: string, method: string, body: Record<string, unknown>): Promise<Record<string, unknown>> { return object(object(await request(`/workshop/${path}`, method, body)).data); }
export function failure(e: unknown): ApiError { return e instanceof ApiError ? e : new ApiError(0, 'No se pudo leer una respuesta válida. Actualiza para consultar el estado confirmado.'); }

/** Existing catalogs use both Resource collections and Laravel's raw paginator. */
export async function catalog(path: string, signal?: AbortSignal): Promise<RecordRow[]> {
    const rows: RecordRow[] = [];
    for (let number = 1;; number++) {
        const value = object(await request(`${path}?per_page=100&page=${number}`, 'GET', undefined, signal));
        const result = page(value.meta ? value : { data: value.data, meta: value }, entry);
        rows.push(...result.data);
        if (number >= result.meta.last_page) return rows;
    }
}
export async function catalogAsset(id: string, signal?: AbortSignal): Promise<RecordRow> {
    return entry(object(await request(`/workshop/assets/${encodeURIComponent(id)}/availability`, 'GET', undefined, signal)).data);
}
