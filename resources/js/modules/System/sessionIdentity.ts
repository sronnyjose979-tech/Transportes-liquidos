/** Per-document UI identity. Cookies remain Laravel's only authentication mechanism. */
interface Actor { id: string; roles: string[] }
interface State { checking: boolean; changed: boolean }
const SIGNAL = 'transport-session-changed-v1';
let actor: Actor | null = null;
let state: State = { checking: false, changed: false };
let generation = 0;
const listeners = new Set<(value: State) => void>();
function publish(value: State): void { state = value; listeners.forEach(listener => listener(state)); }
function invalidate(): void { generation++; publish({ checking: false, changed: true }); }
function sameActor(other: Actor | null): boolean {
    return actor?.id === other?.id && [...actor?.roles ?? []].sort().join('|') === [...other?.roles ?? []].sort().join('|');
}
export function rememberSession(value: Actor | null, ownChange = false): void {
    if (ownChange) generation++;
    if (!ownChange && actor && !sameActor(value)) { invalidate(); throw new Error('La sesión cambió.'); }
    if (state.changed && !ownChange) throw new Error('Vuelve a cargar para consultar la sesión actual.');
    actor = value ? { id: value.id, roles: [...value.roles] } : null;
}
export function announceSessionChange(): void {
    // A notification only: no identity, token, cookie or password is persisted here.
    try { localStorage.setItem(SIGNAL, crypto.randomUUID()); } catch { /* Focus verification remains available. */ }
}
export async function sessionFetch(input: string, options: RequestInit = {}): Promise<Response> {
    const publicEndpoint = ['/api/login', '/api/csrf'].includes(input);
    if (!publicEndpoint && state.checking) await new Promise<void>(resolve => {
        const ready = (value: State): void => { if (!value.checking) { listeners.delete(ready); resolve(); } };
        listeners.add(ready);
    });
    if (!publicEndpoint && state.changed) throw new Error('Comprueba la sesión antes de continuar.');
    const current = generation, expected = actor?.id;
    const headers = new Headers(options.headers);
    if (!publicEndpoint && expected) headers.set('X-Session-User', expected);
    const response = await globalThis.fetch(input, { ...options, headers });
    if (!publicEndpoint && (current !== generation || state.changed)) throw new Error('La sesión cambió durante la consulta.');
    const actual = response.headers.get('X-Session-User');
    if (!publicEndpoint && expected && actual && actual !== expected) {
        invalidate();
        throw new Error('La sesión cambió. No se utilizará esta respuesta.');
    }
    return response;
}
export function watchSession(listener: (value: State) => void): () => void {
    listeners.add(listener); listener(state);
    let check: Promise<void> | null = null;
    let notified = false;
    let again = false;
    async function verify(signal = false): Promise<void> {
        if (state.changed) return;
        notified ||= signal;
        if (check) { again ||= signal; return check; }
        if (!signal && (!actor || !navigator.onLine)) return;
        publish({ checking: true, changed: false });
        const current = generation;
        check = (async () => {
            try {
                const response = await globalThis.fetch('/api/session', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
                if (current !== generation) return;
                if ([401, 403].includes(response.status)) { if (actor || notified) invalidate(); return; }
                if (!response.ok) throw new Error('No se pudo comprobar la sesión.');
                const body: unknown = await response.json();
                if (current !== generation) return;
                if (!body || typeof body !== 'object' || !('data' in body)) throw new Error('Sesión inválida.');
                const value = body.data;
                if (!value || typeof value !== 'object' || !('id' in value) || typeof value.id !== 'string' || !('roles' in value) || !Array.isArray(value.roles) || !value.roles.every(role => typeof role === 'string')) throw new Error('Sesión inválida.');
                if (!sameActor({ id: value.id, roles: value.roles })) invalidate();
            } catch {
                // A known cross-tab change must hide old data even when verification is offline.
                // An ordinary network interruption preserves the existing offline PWA behavior.
                if (notified) invalidate();
            } finally {
                check = null; notified = false;
                if (again && !state.changed) { again = false; void verify(true); }
                else if (!state.changed) publish({ checking: false, changed: false });
            }
        })();
        return check;
    }
    const storage = (event: StorageEvent): void => { if (event.key === SIGNAL) void verify(true); };
    const focus = (): void => { if (document.visibilityState !== 'hidden') void verify(); };
    const pageshow = (event: PageTransitionEvent): void => { if (event.persisted) void verify(); };
    window.addEventListener('storage', storage);
    window.addEventListener('focus', focus);
    document.addEventListener('visibilitychange', focus);
    window.addEventListener('pageshow', pageshow);
    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', storage);
        window.removeEventListener('focus', focus);
        document.removeEventListener('visibilitychange', focus);
        window.removeEventListener('pageshow', pageshow);
    };
}
