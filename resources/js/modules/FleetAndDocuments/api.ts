import { documentType, fleetAsset, fleetDocument, page, session, single } from './contracts';
import type { FieldErrors, Page, FleetAsset, DocumentType, FleetDocument, Session, TypeConfiguration, Validity } from './contracts';

export class ApiError extends Error {
    constructor(public readonly status: number, message: string, public readonly fields: FieldErrors = {}) { super(message); }
}
const messages: Record<number, string> = {
    401: 'Tu sesión terminó. Inicia sesión nuevamente.',
    403: 'No tienes permiso para realizar esta operación.',
    404: 'El activo, documento o archivo solicitado ya no está disponible.',
    419: 'La sesión de seguridad venció. Actualízala antes de volver a enviar el formulario.',
    422: 'Revisa los campos indicados.',
    413: 'La carga supera el tamaño admitido por el servidor.',
    429: 'Demasiados intentos. Espera un momento antes de continuar.',
};
async function checked(response: Response): Promise<Response> {
    if (response.ok) return response;
    const fields: FieldErrors = {};
    if (response.status === 422) {
        const body: unknown = await response.json().catch(() => null);
        if (body && typeof body === 'object' && 'errors' in body && body.errors && typeof body.errors === 'object') {
            for (const [key, values] of Object.entries(body.errors)) {
                if (Array.isArray(values) && values.every((v: unknown) => typeof v === 'string')) fields[key] = values as string[];
            }
        }
    }
    throw new ApiError(response.status, messages[response.status] ?? 'No se pudo completar la operación. Consulta los documentos actuales antes de volver a enviar una carga.', fields);
}
function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
export async function refreshCsrf(): Promise<void> {
    await checked(await fetch('/api/csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }));
}
async function request(url: string, options: RequestInit = {}): Promise<unknown> {
    const method = options.method ?? 'GET';
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (method !== 'GET') {
        if (!csrfToken()) await refreshCsrf();
        headers.set('X-XSRF-TOKEN', csrfToken());
    }
    let response: Response;
    try {
        response = await fetch(url, { ...options, method, headers, credentials: 'same-origin', cache: 'no-store' });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') throw error;
        throw new ApiError(0, 'No se pudo confirmar la operación por un problema de conexión. Consulta los documentos actuales antes de reenviar una carga.');
    }
    return (await checked(response)).json();
}
function json(method: string, body: unknown): RequestInit {
    return { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
}
function query(values: Record<string, string | number>): string { return new URLSearchParams(Object.entries(values).map(([k, v]) => [k, String(v)])).toString(); }
export const getSession = async (): Promise<Session> => session(await request('/api/session'));
export async function login(username: string, password: string): Promise<Session> {
    const local = await import('../DriverPortal/local');
    return local.sessionLock(async () => {
        const actor = session(await request('/api/login', json('POST', { login: username, password })));
        const previous = local.offlineActor();
        if (previous && previous.id !== actor.id) {
            local.lockLocal();
            await local.forgetReadCache(previous.id);
        }
        return actor;
    });
}
export async function logout(): Promise<void> {
    const local = await import('../DriverPortal/local');
    await local.sessionLock(async () => {
        if (!csrfToken()) await refreshCsrf();
        await checked(await fetch('/api/logout', { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() } }));
        const actor = local.offlineActor();
        local.lockLocal();
        if (actor) await local.forgetReadCache(actor.id);
    });
}
export const getAssets = async (number: number, signal: AbortSignal): Promise<Page<FleetAsset>> => page(await request(`/api/fleet-assets?${query({ page: number, per_page: 10 })}`, { signal }), fleetAsset);
export const getTypes = async (number: number, signal?: AbortSignal): Promise<Page<DocumentType>> => page(await request(`/api/document-types?${query({ page: number, per_page: 25 })}`, signal ? { signal } : {}), documentType);
export async function getAllTypes(signal: AbortSignal): Promise<DocumentType[]> {
    const types: DocumentType[] = [];
    for (let number = 1; ; number++) {
        const result = await getTypes(number, signal);
        types.push(...result.data);
        if (number >= result.meta.last_page) return types;
    }
}
export const saveType = async (data: TypeConfiguration, id?: string): Promise<DocumentType> => single(await request(id ? `/api/document-types/${encodeURIComponent(id)}` : '/api/document-types', json(id ? 'PATCH' : 'POST', data)), documentType);
export async function getDocuments(assetId: string, number: number, history: boolean, validity: Validity | '', typeId: string, signal: AbortSignal): Promise<Page<FleetDocument>> {
    const params: Record<string, string | number> = { page: number, per_page: 10 };
    if (validity) params.validity = validity;
    if (typeId) params.document_type_id = typeId;
    return page(await request(`/api/fleet-assets/${encodeURIComponent(assetId)}/documents${history ? '/history' : ''}?${query(params)}`, { signal }), fleetDocument);
}
export const getMissing = async (id: string, number: number, signal: AbortSignal): Promise<Page<DocumentType>> => page(await request(`/api/fleet-assets/${encodeURIComponent(id)}/documents/missing?${query({ page: number, per_page: 10 })}`, { signal }), documentType);
export async function getCounts(assetId: string, signal: AbortSignal): Promise<Record<Validity, number>> {
    const states: Validity[] = ['valid', 'expiring_soon', 'expired', 'no_expiration'];
    const totals = await Promise.all(states.map(async (validity) => {
        const result = page(await request(`/api/documents?${query({ fleet_asset_id: assetId, validity, per_page: 1 })}`, { signal }), fleetDocument);
        return [validity, result.meta.total] as const;
    }));
    return Object.fromEntries(totals) as Record<Validity, number>;
}
export async function upload(assetId: string, body: FormData, renewedId?: string): Promise<FleetDocument> {
    const url = `/api/fleet-assets/${encodeURIComponent(assetId)}/documents${renewedId ? `/${encodeURIComponent(renewedId)}/renew` : ''}`;
    return single(await request(url, { method: 'POST', body }), fleetDocument);
}
export async function download(id: string, signal: AbortSignal): Promise<void> {
    const response = await checked(await fetch(`/api/documents/${encodeURIComponent(id)}/download`, {
        credentials: 'same-origin', cache: 'no-store', signal, headers: { Accept: 'application/json' },
    }));
    const blob = await response.blob();
    const extension = { 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png' }[blob.type];
    if (!extension) throw new ApiError(0, 'El servidor no devolvió un archivo documental válido.');
    const url = URL.createObjectURL(blob);
    try {
        const link = document.createElement('a');
        link.href = url;
        link.download = `documento-${id}.${extension}`;
        document.body.append(link);
        link.click();
        link.remove();
    } finally { setTimeout(() => URL.revokeObjectURL(url), 1000); }
}
export function failure(error: unknown): ApiError {
    return error instanceof ApiError ? error : new ApiError(0, 'No se pudo completar la operación. Comprueba tu conexión y vuelve a consultar los documentos.');
}
export function aborted(error: unknown): boolean { return error instanceof DOMException && error.name === 'AbortError'; }
