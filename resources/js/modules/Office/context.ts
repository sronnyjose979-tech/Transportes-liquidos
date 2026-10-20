import { inject, onBeforeUnmount } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import type { Session } from '../FleetAndDocuments/contracts';
import { session as decodeSession } from '../FleetAndDocuments/contracts';
import type { Navigation } from '../System/navigation';

export const officePaths = { dashboard: '/dashboard', transport: '/transporte', fleet: '/administracion/flota' } as const;
export type OfficePage = keyof typeof officePaths;
export interface OfficeContext { page: OfficePage; session: Session; navigation: Navigation }
export interface OfficeHost {
    context: Ref<OfficeContext | null>;
    registerBlocker: (blocked: () => boolean) => () => void;
    navigate: (path: string, replace?: boolean) => void;
    invalidate: () => void;
}
export const officeKey: InjectionKey<OfficeHost> = Symbol('office');
export function useOffice(blocked?: () => boolean): OfficeHost | null {
    const host = inject(officeKey, null);
    if (!host) return null;
    let active = true;
    onBeforeUnmount(() => { active = false; });
    if (blocked) onBeforeUnmount(host.registerBlocker(blocked));
    return { ...host, invalidate: () => { if (active) host.invalidate(); } };
}
export function decodeOfficeContext(value: unknown): OfficeContext {
    if (!value || typeof value !== 'object' || !('page' in value) || typeof value.page !== 'string'
        || !Object.hasOwn(officePaths, value.page) || !('session' in value) || !('navigation' in value)) throw new Error('Contexto de oficina inválido.');
    const actor = decodeSession({ data: value.session });
    const nav = value.navigation;
    if (!nav || typeof nav !== 'object' || !('home' in nav) || typeof nav.home !== 'string'
        || !('modules' in nav) || !Array.isArray(nav.modules)) throw new Error('Navegación inválida.');
    const modules = nav.modules.map((link: unknown) => {
        if (!link || typeof link !== 'object' || !('key' in link) || typeof link.key !== 'string'
            || !('label' in link) || typeof link.label !== 'string' || !('href' in link)
            || typeof link.href !== 'string' || !link.href.startsWith('/') || link.href.startsWith('//')) throw new Error('Enlace inválido.');
        return { key: link.key, label: link.label, href: link.href };
    });
    return { page: value.page as OfficePage, session: actor, navigation: { home: nav.home, modules } };
}
