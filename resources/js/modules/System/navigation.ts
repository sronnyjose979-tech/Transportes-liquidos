import type { InjectionKey } from 'vue';
import type { Session } from '../FleetAndDocuments/contracts';

export interface ModuleLink { key: string; label: string; href: string }
export interface Navigation { home: string; modules: ModuleLink[] }
export interface NavigationLoader {
    get: (actor: Session) => Promise<Navigation>;
    clear: () => void;
}
export const navigationKey: InjectionKey<NavigationLoader> = Symbol('navigation');

/** One loader per mounted application. A renewed session always gets fresh links. */
export function createNavigationLoader(load: () => Promise<Navigation>): NavigationLoader {
    let actor: Session | null = null;
    let identity = '';
    let pending: Promise<Navigation> | null = null;
    const clear = (): void => { actor = null; identity = ''; pending = null; };
    return {
        clear,
        get(value) {
            const key = JSON.stringify([value.id, value.roles]);
            if (actor === value && identity === key && pending) return pending;
            actor = value; identity = key;
            const result = load().catch(error => {
                if (pending === result) clear();
                throw error;
            });
            pending = result;
            return result;
        },
    };
}
