import { sessionFetch as fetch } from '../System/sessionIdentity';
import type {Session} from '../FleetAndDocuments/contracts';
import {getSession, refreshCsrf} from '../FleetAndDocuments/api';
import {ApiError, failure} from '../Administration/api';
import {sessionLock,offlineActor,lockLocal,captures,saveCapture} from './local';
import type {Capture} from './local';
export {cacheTrip,cachedTrips,captures,newCapture,saveCaptures,saveCapture,rememberActor,offlineActor,lockLocal,forgetReadCache,sessionLock} from './local';
export type {Capture,Status,CachedTrip} from './local';

async function transmit(capture: Capture, path: string): Promise<string> {
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
    try {
        const headers = new Headers({Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '')});
        let body: BodyInit;
        if (capture.file) {
            const form = new FormData();
            for (const [key,value] of Object.entries(capture.body)) if (value !== null && value !== undefined) form.append(key,String(value));
            form.append('file', capture.file, capture.file.name); body = form;
        } else {headers.set('Content-Type','application/json'); body = JSON.stringify(capture.body);}
        const response = await fetch('/api'+path, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers, body, signal: controller.signal});
        const value: unknown = await response.json().catch(() => null);
        if (!response.ok) {
            let message = 'El servidor no confirmó el registro.';
            if (value && typeof value === 'object' && 'message' in value && typeof value.message === 'string') message = value.message;
            throw new ApiError(response.status,message);
        }
        if (!value || typeof value !== 'object' || !('data' in value) || !value.data || typeof value.data !== 'object') throw new ApiError(0,'Respuesta incompleta; se conserva la clave para reintentar.');
        const data = value.data as Record<string,unknown>;
        const id = capture.file ? data.receipt_id : capture.path === '/expenses' ? data.expense_id : data.id;
        if (typeof id !== 'string') throw new ApiError(0,'Respuesta sin confirmación identificable.');
        return id;
    } finally {clearTimeout(timer);}
}
/** Called only by an open page. Every attempt reuses the immutable payload and key. */
export async function synchronize(actor: string, changed: () => Promise<void>, manual = false): Promise<void> {
    await sessionLock(async () => {
        if (offlineActor()?.id !== actor) return;
        let identity: Session;
        try {identity = await getSession();} catch (e) {
            if ([401,403,419].includes(failure(e).status)) lockLocal();
            throw e;
        }
        if (identity.id !== actor) {lockLocal(); throw new ApiError(401,'La cuenta cambió. Inicia sesión con la cuenta original para recuperar sus capturas.');}
        await refreshCsrf();
        const rows = await captures(actor);
        for (const capture of rows) {
            if (offlineActor()?.id !== actor) break;
            if (capture.status === 'sincronizado' || capture.status === 'rechazado' || capture.status === 'en conflicto') continue;
            if (!manual && (capture.next > Date.now() || capture.attempts >= 5)) continue;
            let path = capture.path;
            if (capture.dependency) {
                const parent = rows.find(r => r.id === capture.dependency);
                if (!parent?.result || parent.status !== 'sincronizado') continue;
                path = `/expenses/${parent.result}/receipts`;
            }
            capture.status = 'sincronizando'; capture.message = 'Esperando confirmación del servidor.';
            await saveCapture(capture); await changed();
            try {
                // The session lock also serializes logout and all tabs' sends.
                const current = await getSession();
                if (current.id !== actor) throw new ApiError(401,'La cuenta cambió; conserva la captura para su cuenta original.');
                capture.result = await transmit(capture,path);
                capture.status = 'sincronizado'; capture.message = 'Registro confirmado por el servidor.';
                // Only release file bytes after an unambiguous acknowledgement.
                capture.file = null;
            } catch (e) {
                const problem = failure(e); capture.attempts++;
                capture.status = [403,404,409].includes(problem.status) ? 'en conflicto' : problem.status === 422 ? 'rechazado' : 'pendiente';
                capture.message = problem.message;
                capture.next = Date.now()+Math.min(60000,2000*2**Math.min(capture.attempts,5));
                await saveCapture(capture); await changed();
                if ([401,419].includes(problem.status)) {lockLocal(); throw problem;}
                if (capture.status === 'pendiente') break;
                continue;
            }
            await saveCapture(capture); await changed();
        }
    });
}
