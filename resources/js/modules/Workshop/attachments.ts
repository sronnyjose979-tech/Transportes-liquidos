import { ApiError, request, write } from './api';
import type { RecordRow } from './api';
import { refreshCsrf } from '../FleetAndDocuments/api';
import { object, page } from '../TransportAndDispatch/contracts';
export async function listAttachments(order: string, number = 1) {
    return page(await request(`/workshop/orders/${encodeURIComponent(order)}/attachments?page=${number}`), value => {
        const row = object(value); if (typeof row.id !== 'string') throw new Error('Adjunto inválido.'); return row as RecordRow;
    });
}

export interface UploadMetadata {
    category: string; extension: string; mime: string; size_bytes: number; checksum: string; reason: string;
    external_repair_id?: string;
    previous_attachment_id?: string;
    decision_id?: string;
}
export interface ApprovedAttachmentCorrection { decisionId: string; factId: string; reason: string; after: Record<string, unknown> }
export function attachmentChanges(metadata: UploadMetadata): Record<string, unknown> {
    return { category: metadata.category, external_repair_id: metadata.external_repair_id ?? null, mime: metadata.mime, extension: metadata.extension, size_bytes: metadata.size_bytes, checksum: metadata.checksum };
}
export function authorizedAttachmentMetadata(metadata: UploadMetadata, correction: ApprovedAttachmentCorrection): UploadMetadata {
    const actual = attachmentChanges(metadata);
    for (const [field, value] of Object.entries(actual)) if (correction.after[field] !== value)
        throw new ApiError(422, 'El archivo y sus datos deben coincidir exactamente con la rectificación aprobada.', { file: ['Selecciona los mismos bytes, categoría y reparación autorizados.'] });
    return { ...metadata, reason: correction.reason, previous_attachment_id: correction.factId, decision_id: correction.decisionId };
}
export interface AttachmentRecovery {
    actor: string; roles: string; order: string; metadata: UploadMetadata; reserveKey: string; confirmKey: string;
    releaseKey: string; reservation?: string; releaseReason?: string;
    rectificationRequest?: { factId: string; version: number; executor: number };
}
const recoveryPrefix = 'workshop-attachment-v1:';
export function saveAttachmentRecovery(operation: AttachmentRecovery): void {
    try { sessionStorage.setItem(`${recoveryPrefix}${operation.actor}:${operation.order}`, JSON.stringify(operation)); } catch { /* The in-memory operation remains available. */ }
}
export function readAttachmentRecovery(actor: string, order: string, roles: string): AttachmentRecovery | undefined {
    try {
        const value: unknown = JSON.parse(sessionStorage.getItem(`${recoveryPrefix}${actor}:${order}`) ?? 'null');
        if (!value || typeof value !== 'object') return;
        const row = object(value), metadata = object(row.metadata);
        if (row.actor !== actor || row.order !== order || row.roles !== roles || !['reserveKey', 'confirmKey', 'releaseKey'].every(key => typeof row[key] === 'string') || typeof metadata.checksum !== 'string' || !/^[0-9a-f]{64}$/.test(metadata.checksum) || typeof metadata.size_bytes !== 'number' || typeof metadata.reason !== 'string' || typeof metadata.category !== 'string' || typeof metadata.mime !== 'string' || typeof metadata.extension !== 'string' || (row.reservation !== undefined && typeof row.reservation !== 'string')) return;
        if (row.rectificationRequest !== undefined) {
            const command = object(row.rectificationRequest);
            if (typeof command.factId !== 'string' || !Number.isSafeInteger(command.version) || !Number.isSafeInteger(command.executor)) return;
        }
        return row as unknown as AttachmentRecovery;
    } catch { return; }
}
export function clearAttachmentRecovery(actor?: string, order?: string): void {
    try { for (const key of Object.keys(sessionStorage)) if (key.startsWith(recoveryPrefix) && (!actor || key.startsWith(`${recoveryPrefix}${actor}:`)) && (!order || key === `${recoveryPrefix}${actor}:${order}`)) sessionStorage.removeItem(key); } catch { /* No credentials or files were stored. */ }
}
export async function describeFile(file: File, category: string, reason: string, externalRepair = ''): Promise<UploadMetadata> {
    const extension = file.name.split('.').at(-1)?.toLowerCase() ?? '';
    const formats: Record<string, string> = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png' };
    if (!formats[extension] || formats[extension] !== file.type || file.size < 1 || file.size > 10000000)
        throw new ApiError(422, 'Selecciona PDF, JPEG o PNG de hasta 10 MB.', { file: ['Formato o tamaño no permitido.'] });
    if (!reason.trim()) throw new ApiError(422, 'Indica el motivo del adjunto.', { reason: ['Motivo requerido.'] });
    const checksum = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())), v => v.toString(16).padStart(2, '0')).join('');
    return { category, extension, mime: file.type, size_bytes: file.size, checksum, reason, ...(externalRepair ? { external_repair_id: externalRepair } : {}) };
}
export const reserveAttachment = (order: string, key: string, metadata: UploadMetadata) => write(`orders/${encodeURIComponent(order)}/attachment-reservations`, 'POST', { ...metadata, request_key: key });
export function requestAttachmentCorrection(operation: AttachmentRecovery): Promise<Record<string, unknown>> {
    if (!operation.rectificationRequest) throw new Error('Solicitud ausente.');
    return write(`facts/attachment/${encodeURIComponent(operation.rectificationRequest.factId)}/rectifications`, 'POST', {
        request_key: operation.reserveKey, version: operation.rectificationRequest.version, executor_id: operation.rectificationRequest.executor,
        reason: operation.metadata.reason, changes: attachmentChanges(operation.metadata),
    });
}
export async function attachmentReceipt(reservation: string): Promise<Record<string, unknown>> {
    return object(object(await request(`/workshop/attachment-reservations/${encodeURIComponent(reservation)}`)).data);
}
export const releaseAttachment = (reservation: string, key: string, reason: string) => write(`attachment-reservations/${encodeURIComponent(reservation)}/release`, 'POST', { request_key: key, reason });

/** Progress reports transmitted bytes only; completion requires a successful server receipt. */
export async function confirmAttachment(reservation: string, key: string, file: File | undefined, progress: (value: number) => void): Promise<Record<string, unknown>> {
    const token = () => decodeURIComponent(document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
    if (!token()) await refreshCsrf();
    const data = new FormData(); data.append('request_key', key); if (file) data.append('file', file);
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `/api/workshop/attachment-reservations/${encodeURIComponent(reservation)}/confirm`);
        xhr.withCredentials = true; xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-XSRF-TOKEN', token());
        xhr.upload.onprogress = event => { if (event.lengthComputable) progress(Math.floor(event.loaded / event.total * 100)); };
        xhr.onerror = () => reject(new ApiError(0, 'Carga sin confirmación. Consulta el recibo antes de reintentar.'));
        xhr.onabort = xhr.onerror;
        xhr.onload = () => {
            let value: Record<string, unknown>;
            try { value = object(JSON.parse(xhr.responseText) as unknown); } catch { reject(new ApiError(0, 'Respuesta no confirmada. Consulta el recibo.')); return; }
            if (xhr.status < 200 || xhr.status >= 300) {
                const errors: Record<string, string[]> = {};
                if ([409,422].includes(xhr.status) && value.errors && typeof value.errors === 'object') for (const [name, messages] of Object.entries(value.errors))
                    if (Array.isArray(messages) && messages.every(message => typeof message === 'string')) errors[name] = messages as string[];
                const messages: Record<number, string> = { 401: 'Tu sesión terminó.', 403: 'No tienes permiso para confirmar este archivo.', 404: 'Reserva no disponible.', 409: 'Conflicto: consulta el recibo.', 419: 'Actualiza la sesión de seguridad antes de reintentar.', 422: 'Revisa el archivo y los campos indicados.' };
                reject(new ApiError(xhr.status, messages[xhr.status] ?? 'Carga no confirmada. Consulta el recibo.', errors)); return;
            }
            try { resolve(object(value.data)); } catch { reject(new ApiError(0, 'Respuesta no confirmada. Consulta el recibo.')); }
        };
        xhr.send(data);
    });
}

export async function downloadAttachment(id: string, extension: string): Promise<void> {
    const response = await fetch(`/api/workshop/attachments/${encodeURIComponent(id)}/download`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/octet-stream' } });
    if (!response.ok) throw new ApiError(response.status, response.status === 403 ? 'No tienes permiso para descargar este archivo.' : response.status === 401 ? 'Tu sesión terminó.' : 'Archivo no disponible.');
    const url = URL.createObjectURL(await response.blob());
    try { const anchor = document.createElement('a'); anchor.href = url; anchor.download = `${id}.${extension}`; anchor.click(); }
    finally { setTimeout(() => URL.revokeObjectURL(url), 1000); }
}
/** A new identity never retains another user's recovery metadata in this tab. */
export function clearOtherAttachmentRecovery(actor: string): void {
    try { for (const key of Object.keys(sessionStorage)) if (key.startsWith(recoveryPrefix) && !key.startsWith(`${recoveryPrefix}${actor}:`)) sessionStorage.removeItem(key); } catch { /* In-memory operations still enforce identity. */ }
}
