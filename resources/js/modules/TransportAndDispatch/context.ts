import type { InjectionKey, Ref } from 'vue';
import { inject } from 'vue';
import type { Lookups } from './contracts';
export interface TransportContext {
    lookups: Ref<Lookups>;
    admin: Ref<boolean>;
    busy: Ref<boolean>;
    uncertain: Ref<boolean>;
    errors: Ref<Record<string, string[]>>;
    mutate: (description: string, work: () => Promise<void>) => Promise<boolean>;
    report: (error: unknown) => Promise<void>;
}
export const contextKey: InjectionKey<TransportContext> = Symbol('transport-context');
export function useTransport(): TransportContext { const value = inject(contextKey); if (!value)
    throw new Error('Falta contexto de transporte.'); return value; }
