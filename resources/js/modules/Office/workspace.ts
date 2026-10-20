import { nextTick, ref, shallowRef } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import type { Component } from 'vue';
import type { OfficeContext, OfficeHost, OfficePage } from './context';
import { decodeOfficeContext, officePaths } from './context';
import { rememberSession, sessionFetch } from '../System/sessionIdentity';
import { logout } from '../FleetAndDocuments/api';

/** No cached authorization: each change of module requests its authorized route. */
export function createOfficeWorkspace(initial: OfficeContext, components: Record<OfficePage, Component>) {
    const context = shallowRef<OfficeContext | null>(initial);
    const pending = ref(false), error = ref(''), dirty = ref(false);
    const blockers = new Set<() => boolean>();
    let sequence = 0, controller: AbortController | null = null, leaving = false;
    const verified = new WeakMap<object, OfficeContext>();
    const router = createRouter({
        history: createWebHistory(),
        routes: Object.entries(officePaths).map(([page, path]) => ({ path, name: page, component: components[page as OfficePage] })),
        scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
    });
    function mayLeave(): boolean {
        if ([...blockers].some(blocked => blocked())) {
            error.value = 'Hay una operación pendiente o sin confirmar. Atiéndela antes de salir.';
            return false;
        }
        return !dirty.value || window.confirm('Hay cambios de formulario sin guardar. ¿Descartarlos y salir?');
    }
    router.beforeEach(async (to, from) => {
        // The initial document was already authorized by Laravel.
        if (!from.matched.length && to.path === officePaths[initial.page]) return true;
        // Query changes are views within the already active module, not module entry.
        if (to.path === from.path && context.value) return ![...blockers].some(blocked => blocked());
        if (!mayLeave()) return false;
        const run = ++sequence;
        controller?.abort();
        controller = new AbortController();
        pending.value = true; error.value = '';
        try {
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
            verified.set(to, next);
            return true;
        } catch (problem) {
            if (run === sequence) error.value = problem instanceof Error ? problem.message : 'No se pudo consultar el destino.';
            return false;
        } finally {
            if (run === sequence) pending.value = false;
        }
    });
    router.afterEach((to, from, failure) => {
        if (failure) return;
        const next = verified.get(to);
        if (next) { context.value = next; dirty.value = false; }
        if (to.path !== from.path) {
            document.title = `${to.name === 'fleet' ? 'Flota' : to.name === 'transport' ? 'Transporte' : 'Inicio'} · Transportes Líquidos`;
            void nextTick(() => document.getElementById('office-content')?.focus());
        }
    });
    const host: OfficeHost = {
        context,
        registerBlocker(blocked) { blockers.add(blocked); return () => blockers.delete(blocked); },
        navigate(path, replace = false) { void (replace ? router.replace(path) : router.push(path)); },
        invalidate() { context.value = null; },
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
        else leaving = true;
    }
    function input(event: Event): void {
        if (event.target instanceof Element && event.target.closest('form')) dirty.value = true;
    }
    function beforeUnload(event: BeforeUnloadEvent): void {
        if (!leaving && (dirty.value || [...blockers].some(blocked => blocked()))) { event.preventDefault(); event.returnValue = ''; }
    }
    function dispose(): void { sequence++; controller?.abort(); blockers.clear(); }
    async function signOut(): Promise<void> {
        if (pending.value || !mayLeave()) return;
        pending.value = true;
        try { await logout(); context.value = null; leaving = true; window.location.assign('/login'); }
        catch { error.value = 'No se pudo confirmar el cierre de sesión. Vuelve a intentarlo.'; }
        finally { pending.value = false; }
    }
    return { router, host, context, pending, error, click, input, beforeUnload, dispose, signOut };
}
