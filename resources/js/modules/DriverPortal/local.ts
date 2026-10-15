import type {Session} from '../FleetAndDocuments/contracts';
import type {DriverTrip, DriverExpense} from './contracts';

export type Status = 'pendiente' | 'sincronizando' | 'sincronizado' | 'rechazado' | 'en conflicto';
export interface Capture {
    id: string; actor: string; title: string; path: string; body: Record<string, unknown>;
    file: File | null; dependency?: string; status: Status; message: string; attempts: number; next: number;
    result?: string; created: number;
}
export interface CachedTrip {actor: string; trip: DriverTrip; expenses: DriverExpense[]; consulted: number}
const ACTIVE = 'driver-offline-active-v1';
export const LOCK = 'driver-offline-session-v1';
const DAY = 86400000;
let database: Promise<IDBDatabase> | undefined;
function db(): Promise<IDBDatabase> {
    return database ??= new Promise((resolve, reject) => {
        const open = indexedDB.open('driver-portal', 1);
        open.onupgradeneeded = () => {
            open.result.createObjectStore('trips', {keyPath: ['actor', 'trip.id']});
            open.result.createObjectStore('captures', {keyPath: 'id'});
        };
        open.onsuccess = () => {open.result.onversionchange = () => {open.result.close(); database = undefined;}; resolve(open.result);};
        open.onerror = () => {database = undefined; reject(open.error);};
        open.onblocked = () => reject(new Error('Cierra las otras pestañas para actualizar el almacenamiento local.'));
    });
}
async function transaction<T>(store: string, mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const database = await db();
    return new Promise((resolve, reject) => {
        const tx = database.transaction(store, mode), operation = action(tx.objectStore(store));
        tx.oncomplete = () => resolve(operation.result);
        tx.onerror = tx.onabort = () => reject(tx.error ?? new Error('No se guardó la captura. Revisa el espacio disponible.'));
    });
}
export async function captures(actor: string): Promise<Capture[]> {
    return (await transaction<Capture[]>('captures', 'readonly', s => s.getAll())).filter(c => c.actor === actor).sort((a,b) => Number(!!a.dependency)-Number(!!b.dependency) || a.created-b.created || a.id.localeCompare(b.id));
}
export async function saveCapture(capture: Capture): Promise<void> {
    await transaction('captures', 'readwrite', s => s.put(capture));
}
export async function saveCaptures(rows: Capture[]): Promise<void> {
    const database = await db();
    await new Promise<void>((resolve,reject) => {
        const tx=database.transaction('captures','readwrite');
        for(const row of rows)tx.objectStore('captures').put(row);
        tx.oncomplete=()=>resolve();tx.onerror=tx.onabort=()=>reject(tx.error);
    });
}
export async function cachedTrips(actor: string): Promise<CachedTrip[]> {
    const rows=await transaction<CachedTrip[]>('trips','readonly',s=>s.getAll());
    for(const row of rows.filter(c=>Date.now()-c.consulted>=DAY))await transaction('trips','readwrite',s=>s.delete([row.actor,row.trip.id]));
    return rows.filter(c=>c.actor===actor&&Date.now()-c.consulted<DAY);
}
export async function cacheTrip(actor: string, trip: DriverTrip, expenses: DriverExpense[]): Promise<void> {
    // Explicit allowlist: no private history, receipts, credentials or entire API envelopes.
    const minimal: DriverTrip = {id: trip.id, ...(trip.offline_context?{offline_context:trip.offline_context}:{}), product: trip.product, execution_status: trip.execution_status,
        administrative_status: trip.administrative_status, scheduled_start_at: trip.scheduled_start_at,
        route: {...trip.route}, assets: trip.assets.map(a => ({id:a.id,fleet_asset_id:a.fleet_asset_id,type:a.type,code:a.code,starts_at:a.starts_at}))};
    const previous=await transaction<CachedTrip|undefined>('trips','readonly',s=>s.get([actor,trip.id]));
    await transaction('trips', 'readwrite', s => s.put({actor, trip: minimal, expenses: (expenses.length?expenses:previous?.expenses??[]).map(e => ({id:e.id,concept:e.concept,category:e.category,amount:e.amount,currency:e.currency,status:e.status,occurred_at:e.occurred_at,receipts:[]})), consulted: Date.now()}));
}
export async function forgetReadCache(actor: string): Promise<void> {
    const rows = await transaction<CachedTrip[]>('trips', 'readonly', s => s.getAll());
    for (const row of rows.filter(r => r.actor === actor)) await transaction('trips', 'readwrite', s => s.delete([actor,row.trip.id]));
}
export function rememberActor(actor: Session): void {
    localStorage.setItem(ACTIVE, JSON.stringify({id: actor.id, roles: actor.roles, expires: Date.now()+DAY}));
}
export function offlineActor(): Session | null {
    try {
        const value: unknown = JSON.parse(localStorage.getItem(ACTIVE) ?? 'null');
        if (!value || typeof value !== 'object' || !('id' in value) || typeof value.id !== 'string' || !('expires' in value) || typeof value.expires !== 'number' || value.expires <= Date.now() || !('roles' in value) || !Array.isArray(value.roles) || !value.roles.every(r => typeof r === 'string')) return null;
        return {id: value.id, roles: value.roles, login: null};
    } catch {return null;}
}
export function lockLocal(): void {localStorage.removeItem(ACTIVE);}
export async function sessionLock<T>(work: () => Promise<T>): Promise<T> {
    if (!navigator.locks) throw new Error('Este navegador requiere Web Locks para sincronizar con seguridad. Usa Chromium actualizado.');
    return navigator.locks.request(LOCK, work);
}
export function newCapture(actor: string, title: string, path: string, body: Record<string, unknown>, file: File | null = null, dependency?: string): Capture {
    const id = crypto.randomUUID();
    return {id, actor, title, path, body: {...body, request_key: id}, file, ...(dependency ? {dependency} : {}), status: 'pendiente', message: 'Captura local; aún no confirmada por el servidor.', attempts: 0, next: 0, created: Date.now()};
}
