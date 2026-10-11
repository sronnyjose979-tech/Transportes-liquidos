export interface Session { id: string; login: string | null; roles: string[] }
export interface FleetAsset { id: string; code: string; type: 'tractor_unit' | 'tanker' }
export interface DocumentType {
    id: string;
    name: string;
    tracks_expiration: boolean;
    required_for_tractor_units: boolean;
    required_for_tankers: boolean;
}
export type TypeConfiguration = Omit<DocumentType, 'id'>;
export type Validity = 'valid' | 'expiring_soon' | 'expired' | 'no_expiration';
export interface FleetDocument {
    id: string;
    fleet_asset_id: string;
    document_type_id: string;
    configuration: TypeConfiguration;
    issued_on: string | null;
    expires_on: string | null;
    registered_at: string;
    uploaded_by: { id: string; name: string };
    is_current: boolean;
    validity: Validity;
}
export interface PageMeta { current_page: number; last_page: number; total: number; per_page: number }
export interface Page<T> { data: T[]; meta: PageMeta }
export type FieldErrors = Record<string, string[]>;

function object(value: unknown): Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('Respuesta inválida.');
    return value as Record<string, unknown>;
}
function text(value: unknown): string {
    if (typeof value !== 'string') throw new Error('Respuesta inválida.');
    return value;
}
function bool(value: unknown): boolean {
    if (typeof value !== 'boolean') throw new Error('Respuesta inválida.');
    return value;
}
function integer(value: unknown): number {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) throw new Error('Respuesta inválida.');
    return value;
}
function nullableText(value: unknown): string | null { return value === null ? null : text(value); }
export function configuration(value: unknown): TypeConfiguration {
    const v = object(value);
    return { name: text(v.name), tracks_expiration: bool(v.tracks_expiration),
        required_for_tractor_units: bool(v.required_for_tractor_units), required_for_tankers: bool(v.required_for_tankers) };
}
export function documentType(value: unknown): DocumentType {
    const v = object(value);
    return { id: text(v.id), ...configuration(v) };
}
export function fleetAsset(value: unknown): FleetAsset {
    const v = object(value);
    if (v.type !== 'tractor_unit' && v.type !== 'tanker') throw new Error('Respuesta inválida.');
    return { id: text(v.id), code: text(v.code), type: v.type };
}
export function fleetDocument(value: unknown): FleetDocument {
    const v = object(value);
    const author = object(v.uploaded_by);
    if (!['valid', 'expiring_soon', 'expired', 'no_expiration'].includes(text(v.validity))) throw new Error('Respuesta inválida.');
    return { id: text(v.id), fleet_asset_id: text(v.fleet_asset_id), document_type_id: text(v.document_type_id),
        configuration: configuration(v.configuration), issued_on: nullableText(v.issued_on), expires_on: nullableText(v.expires_on),
        registered_at: text(v.registered_at), uploaded_by: { id: text(author.id), name: text(author.name) },
        is_current: bool(v.is_current), validity: v.validity as Validity };
}
export function session(value: unknown): Session {
    const v = object(object(value).data);
    if (!Array.isArray(v.roles)) throw new Error('Respuesta inválida.');
    return { id: text(v.id), login: nullableText(v.login), roles: v.roles.map(text) };
}
export function page<T>(value: unknown, decode: (item: unknown) => T): Page<T> {
    const v = object(value);
    const m = object(v.meta);
    if (!Array.isArray(v.data)) throw new Error('Respuesta inválida.');
    return { data: v.data.map(decode), meta: { current_page: integer(m.current_page), last_page: integer(m.last_page),
        total: integer(m.total), per_page: integer(m.per_page) } };
}
export function single<T>(value: unknown, decode: (item: unknown) => T): T { return decode(object(value).data); }
