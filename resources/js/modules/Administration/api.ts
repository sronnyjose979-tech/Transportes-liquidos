import { sessionFetch as fetch } from '../System/sessionIdentity';
import { ApiError, refreshCsrf } from '../FleetAndDocuments/api';
export { ApiError };
const messages: Record<number, string> = {
    401: 'Tu sesión terminó. Inicia sesión nuevamente.', 403: 'No tienes permiso para administrar estos recursos.',
    404: 'El registro solicitado no está disponible.', 409: 'El recurso cambió. Consulta su estado antes de continuar.',
    419: 'Actualiza la sesión de seguridad antes de volver a enviar.', 422: 'Revisa los datos indicados.',
};
export const failure = (error: unknown): ApiError => error instanceof ApiError ? error : new ApiError(0, 'No se pudo confirmar la operación. Consulta los registros antes de volver a enviarla.');
function object(value: unknown): Record<string, unknown> {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) throw failure(value);
    return value as Record<string, unknown>;
}
async function fetchValue(path: string, method = 'GET', body?: unknown): Promise<unknown> {
    const headers = new Headers({ Accept: 'application/json' });
    if (method !== 'GET') {
        const token = () => decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
        if (!token()) await refreshCsrf();
        headers.set('X-XSRF-TOKEN', token()); headers.set('Content-Type', 'application/json');
    }
    let response: Response;
    try { response = await fetch(`/api${path}`, { method, headers, credentials: 'same-origin', cache: 'no-store', ...(body === undefined ? {} : { body: JSON.stringify(body) }) }); }
    catch (error) { throw failure(error); }
    const value: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const fields: Record<string, string[]> = {};
        const parsed = value && typeof value === 'object' && !Array.isArray(value) ? object(value) : {};
        if ([409,422].includes(response.status) && parsed.errors && typeof parsed.errors === 'object')
            for (const [key, errors] of Object.entries(parsed.errors)) if (Array.isArray(errors) && errors.every(e => typeof e === 'string')) fields[key] = errors as string[];
        throw new ApiError(response.status, messages[response.status] ?? 'No se pudo confirmar la operación. Consulta su estado antes de reenviar.', fields);
    }
    return value;
}
/** Laravel Resources and raw paginator endpoints both expose data. */
export async function request<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    const value = object(await fetchValue(path, method, body));
    if (!('data' in value)) throw failure(value);
    return value.data as T;
}
export async function all<T>(path: string): Promise<T[]> {
    const result: T[] = [];
    for (let page = 1; ; page++) {
        const value = object(await fetchValue(`${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`));
        if (!Array.isArray(value.data) || value.data.some(v => !v || typeof v !== 'object' || !['string','number'].includes(typeof v.id))) throw failure(value);
        result.push(...value.data as T[]);
        const last = value.meta ? object(value.meta).last_page : value.last_page ?? 1;
        if (typeof last !== 'number' || !Number.isInteger(last) || last < 1) throw failure(value);
        if (page >= last) return result;
    }
}

/** Read the complete paginator envelope without losing server pagination. */
export async function paginated<T>(path: string): Promise<{data:T[];meta:{current_page:number;last_page:number;total?:number}}> {
    const value = object(await fetchValue(path));
    const meta = object(value.meta);
    if (!Array.isArray(value.data) || !Number.isInteger(meta.current_page) || !Number.isInteger(meta.last_page)) throw failure(value);
    return {data:value.data as T[],meta:{current_page:meta.current_page as number,last_page:meta.last_page as number,...(typeof meta.total==='number'?{total:meta.total}:{})}};
}
