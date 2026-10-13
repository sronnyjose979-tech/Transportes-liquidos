import { inject } from 'vue';
import type { InjectionKey, Ref } from 'vue';
export interface AdminContext {
    busy: Ref<boolean>;
    uncertain: Ref<boolean>;
    errors: Ref<Record<string, string[]>>;
    mutate: (title: string, work: () => Promise<void>) => Promise<boolean>;
    report: (error: unknown) => Promise<void>;
}
export const adminKey: InjectionKey<AdminContext> = Symbol('administration');
export function useAdmin(): AdminContext {
    const context = inject(adminKey);
    if (!context) throw new Error('Contexto administrativo no disponible.');
    return context;
}
