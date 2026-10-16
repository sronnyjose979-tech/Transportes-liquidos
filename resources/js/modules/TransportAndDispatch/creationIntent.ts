// Keep only an opaque intention key across reloads; form data stays in memory.
export interface CreationIntent {key: string; values: Record<string, string> | null}
const pending = new Map<string, CreationIntent>();
const slot = (actor: string, kind: string) => `transport-creation:${actor}:${kind}`;
export function creationIntent(actor: string, kind: string): CreationIntent | undefined {
    const name = slot(actor, kind);
    if (pending.has(name)) return pending.get(name);
    const key = sessionStorage.getItem(name);
    if (!key) return undefined;
    const intent = {key, values: null};
    pending.set(name, intent);
    return intent;
}
export function beginCreation(actor: string, kind: string, values: Record<string, string>): CreationIntent {
    const previous = creationIntent(actor, kind);
    if (previous?.values) return previous;
    const intent = {key: previous?.key ?? crypto.randomUUID(), values: {...values}};
    // Persist before sending. If storage fails, the operation must not be sent.
    sessionStorage.setItem(slot(actor, kind), intent.key);
    pending.set(slot(actor, kind), intent);
    return intent;
}
export function reviseCreation(actor: string, kind: string): CreationIntent | undefined {
    const intent = creationIntent(actor, kind);
    if (intent) intent.values = null;
    return intent;
}
export function finishCreation(actor: string, kind: string): void {
    sessionStorage.removeItem(slot(actor, kind));
    pending.delete(slot(actor, kind));
}
