import { computed, nextTick, ref, shallowRef } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import type { Component } from 'vue';
import type { OfficeContext, OfficeHost, OfficePage } from './context';
import { decodeOfficeContext, officePaths, officeTitles } from './context';
import { rememberSession, sessionFetch } from '../System/sessionIdentity';
import { logout } from '../FleetAndDocuments/api';

/** No cached authorization: each change of module requests its authorized route. */
export function createOfficeWorkspace(initial: OfficeContext, components: Record<OfficePage, () => Promise<{ default: Component }>>) {
    const context = shallowRef<OfficeContext | null>(initial);
    const pending = ref(false), error = ref(''), dirty = ref(false);
    const failedDestination=ref<string|null>(null);
    const blockers = new Set<() => boolean>();
    const blockerVersion=ref(0);
    const blocked=computed(()=>{void blockerVersion.value;return [...blockers].some(check=>check());});
    let sequence = 0, controller: AbortController | null = null;
    const verified = new WeakMap<object, OfficeContext>();
    const runs = new WeakMap<object, number>();
    const recoveryStores = new Map<string, Map<string, unknown>>();
    const loaded = new Map<OfficePage, Promise<{ default: Component }>>();
    let firstEntry = true;
    function load(page: OfficePage): Promise<{ default: Component }> {
        let result = loaded.get(page);
        if (!result) {
            result = components[page]().catch(problem => { loaded.delete(page); throw problem; });
            loaded.set(page, result);
        }
        return result;
    }
    const router = createRouter({
        history: createWebHistory(),
        routes: Object.entries(officePaths).map(([page, path]) => ({ path, name: page, component: () => load(page as OfficePage) })),
        scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
    });
    function hasRecovery(): boolean { return [...recoveryStores.values()].some(store => store.size > 0); }
    function mayLeave(): boolean {
        if (hasRecovery() || [...blockers].some(blocked => blocked())) {
            error.value = 'Hay una operación pendiente o sin confirmar. Atiéndela antes de salir.';
            return false;
        }
        return !dirty.value || window.confirm('Hay cambios de formulario sin guardar. ¿Descartarlos y salir?');
    }
    router.beforeEach(async (to, from) => {
        const run = ++sequence;
        runs.set(to, run);
        controller?.abort();
        pending.value = false;
        const initialEntry = firstEntry && !from.matched.length && to.path === officePaths[initial.page];
        firstEntry = false;
        if (to.path === from.path && context.value) {
            if(to.name==='catalogs'&&to.query.catalogo!==from.query.catalogo){if(!mayLeave())return false;dirty.value=false;}
            return ![...blockers].some(blocked => blocked());
        }
        if (!initialEntry && !(to.path === from.path && !context.value) && !mayLeave()) return false;
        controller = new AbortController();
        pending.value = true; error.value = '';failedDestination.value=null;
        try {
            if (initialEntry) {
                await load(initial.page);
                return run === sequence;
            }
            const response = await sessionFetch(to.fullPath, {
                credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Office-Navigation': '1' },
            });
            if (run !== sequence) return false;
            if (!response.ok) {
                if ([401, 403, 409, 419].includes(response.status)) context.value = null;
                throw new Error(response.status === 401 || response.status === 419
                    ? 'La sesión terminó. Inicia sesión nuevamente.'
                    : response.status === 403 ? 'No tienes acceso a este destino.' : 'No se pudo autorizar la navegación. Vuelve a intentarlo.');
            }
            const body: unknown = await response.json();
            if (!body || typeof body !== 'object' || !('data' in body)) throw new Error('Respuesta de navegación inválida.');
            const next = decodeOfficeContext(body.data);
            if (officePaths[next.page] !== to.path) throw new Error('El destino recibido no corresponde a la navegación.');
            if (run !== sequence) return false;
            rememberSession(next.session);
            await load(next.page);
            if (run !== sequence) return false;
            verified.set(to, next);
            return true;
        } catch (problem) {
            if (run === sequence) { failedDestination.value=to.fullPath;pending.value = false; error.value = problem instanceof Error && !problem.message.includes('import') ? problem.message : 'No se pudo cargar el módulo. Reintenta sin abandonar el contenido actual.'; }
            return false;
        }
    });
    router.afterEach((to, from, failure) => {
        if (runs.get(to) === sequence) pending.value = false;
        if (failure) return;
        const next = verified.get(to);
        if (next) { context.value = next; dirty.value = false; }
        if (to.path !== from.path) {
            document.title = `${officeTitles[to.name as OfficePage]} · Transportes Líquidos`;
            void nextTick(() => document.getElementById('office-content')?.focus());
        }
    });
    router.onError(() => { pending.value = false; error.value = 'No se pudo cargar el módulo. Vuelve a intentarlo.'; });
    const host: OfficeHost = {
        context,
        registerBlocker(blocked) { blockers.add(blocked);blockerVersion.value++; return () => {blockers.delete(blocked);blockerVersion.value++;}; },
        navigate(path, replace = false) { const target=router.resolve(path);void router.push({path:target.path,query:target.query,hash:target.hash,replace,force:!context.value}).catch(() => {}); },
        invalidate() { context.value = null; },
        markClean() { dirty.value = false; },
        confirmDiscard() { if (!mayLeave()) return false; dirty.value = false; return true; },
        recovery<T>(scope: string): Map<string, T> {
            if (!recoveryStores.has(scope)) recoveryStores.set(scope, new Map());
            return recoveryStores.get(scope) as Map<string, T>;
        },
    };
    function click(event: MouseEvent): void {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a') : null;
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search && url.hash) return;
        if (url.origin === location.origin && Object.values(officePaths).some(path => path === url.pathname)) {
            event.preventDefault();
            if (!pending.value) host.navigate(url.pathname + url.search + url.hash);
        } else if (!mayLeave()) event.preventDefault();
    }
    function input(event: Event): void {
        if (event.target instanceof Element && !event.target.closest('[data-office-filter]') && event.target.closest('form,[data-office-dirty]')) dirty.value = true;
    }
    function beforeUnload(event: BeforeUnloadEvent): void {
        if (dirty.value || [...blockers].some(blocked => blocked()) || hasRecovery()) { event.preventDefault(); event.returnValue = ''; }
    }
    function dispose(): void { sequence++; controller?.abort(); blockers.clear(); }
    async function signOut(): Promise<void> {
        if (pending.value || !mayLeave()) return;
        pending.value = true;
        try { await logout(); context.value = null; dirty.value = false; window.location.assign('/login'); }
        catch { error.value = 'No se pudo confirmar el cierre de sesión. Vuelve a intentarlo.'; }
        finally { pending.value = false; }
    }
    return { router, host, context, pending, blocked, error, failedDestination, click, input, beforeUnload, dispose, signOut };
}
