export type Json = null | boolean | number | string | Json[] | {
    [key: string]: Json;
};
export interface Entry {
    id: string;
    [key: string]: Json | undefined;
}
export interface Meta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}
export interface Page<T> {
    data: T[];
    meta: Meta;
}
export interface Compartment {
    id: string;
    code: string;
    capacity_liters: string;
    is_active: boolean;
}
export interface Lookup {
    id: string;
    name?: string;
    code?: string;
    type?: string;
    service_status?: string;
    compartments?: Compartment[];
    origin_site_id?: string;
    destination_site_id?: string;
}
export interface TransportRequest {
    id: string;
    customer_id: string;
    liquid_product_id: string;
    route_id: string;
    requested_liters: string;
    requested_at: string;
    registered_by_id: number;
    version: number;
    trips_count?: number;
}
export interface Item {
    id: string;
    compartment_id?: string;
    compartment_code_snapshot?: string;
    capacity_liters_snapshot?: string;
    loaded_liters?: string;
    load_item_id?: string;
    delivered_liters?: string;
}
export interface Operation {
    id: string;
    items: Item[];
    occurred_at: string;
    operation_site_id: string;
    recorded_by_id: number;
    reason: string;
}
export interface Assignment {
    id: string;
    employee_id?: string;
    fleet_asset_id?: string;
    asset_type?: string;
}
export type Execution = 'pending' | 'in_progress' | 'completed' | 'cancelled';
export interface Trip {
    id: string;
    transport_request_id: string;
    product_name_snapshot: string;
    planning_status: string;
    execution_status: Execution;
    administrative_status: 'open' | 'closed';
    version: number;
    scheduled_start_at: string | null;
    scheduled_end_at: string | null;
    officially_started_at: string | null;
    officially_completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    closed_at: string | null;
    request?: TransportRequest;
    load?: Operation | null;
    unload?: Operation | null;
    current_driver_assignments?: Assignment[];
    current_asset_assignments?: Assignment[];
}
export interface Authorization {
    id: string;
    trip_id: string;
    type: string;
    content: {
        [key: string]: Json;
    };
    target_version: number;
    reason: string;
    requested_by_id: number;
    registered_at: string;
    decision?: {
        id: string;
        decision: string;
        reason: string;
        decided_by_id: number;
        registered_at: string;
        application?: Json;
    } | null;
}
export interface Availability {
    resource_kind: string;
    resource_id: string;
    eligible: boolean;
    available: boolean;
    conflicts: Assignment[];
}
export type Lookups = Record<'customers' | 'products' | 'sites' | 'routes' | 'drivers' | 'assets', Lookup[]>;
export function object(value: unknown): Record<string, unknown> { if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('Respuesta inválida.'); return value as Record<string, unknown>; }
function text(value: unknown): string { if (typeof value !== 'string')
    throw new Error('Respuesta inválida.'); return value; }
function integer(value: unknown): number { if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0)
    throw new Error('Respuesta inválida.'); return value; }
function nullable(value: unknown): string | null { return value === null ? null : text(value); }
function array<T>(value: unknown, decode: (v: unknown) => T): T[] { if (!Array.isArray(value))
    throw new Error('Respuesta inválida.'); return value.map(decode); }
export function entry(value: unknown): Entry { const v = object(value); text(v.id); return v as Entry; }
export function lookup(value: unknown): Lookup { const v = object(value); text(v.id); if (v.name !== undefined)
    text(v.name); if (v.code !== undefined)
    text(v.code); return v as unknown as Lookup; }
export function requestRecord(value: unknown): TransportRequest { const v = object(value); return { id: text(v.id), customer_id: text(v.customer_id), liquid_product_id: text(v.liquid_product_id), route_id: text(v.route_id), requested_liters: text(v.requested_liters), requested_at: text(v.requested_at), registered_by_id: integer(v.registered_by_id), version: integer(v.version), ...(v.trips_count === undefined ? {} : { trips_count: integer(v.trips_count) }) }; }
function assignment(value: unknown): Assignment { const v = object(value); text(v.id); return v as unknown as Assignment; }
function operation(value: unknown): Operation | null { if (value === null)
    return null; const v = object(value); const items = array(v.items, (item) => { const x = object(item); text(x.id); return x as unknown as Item; }); return { id: text(v.id), items, occurred_at: text(v.occurred_at), operation_site_id: text(v.operation_site_id), recorded_by_id: integer(v.recorded_by_id), reason: text(v.reason) }; }
export function tripRecord(value: unknown): Trip {
    const v = object(value);
    const status = text(v.execution_status);
    const administrative = text(v.administrative_status);
    if (!['pending', 'in_progress', 'completed', 'cancelled'].includes(status) || !['open', 'closed'].includes(administrative))
        throw new Error('Estado inválido.');
    return { id: text(v.id), transport_request_id: text(v.transport_request_id), product_name_snapshot: text(v.product_name_snapshot), planning_status: text(v.planning_status), execution_status: status as Execution, administrative_status: administrative as 'open' | 'closed', version: integer(v.version), scheduled_start_at: nullable(v.scheduled_start_at), scheduled_end_at: nullable(v.scheduled_end_at), officially_started_at: nullable(v.officially_started_at), officially_completed_at: nullable(v.officially_completed_at), cancelled_at: nullable(v.cancelled_at), cancellation_reason: nullable(v.cancellation_reason), closed_at: nullable(v.closed_at), ...(v.request === undefined ? {} : { request: requestRecord(v.request) }), ...(v.load === undefined ? {} : { load: operation(v.load) }), ...(v.unload === undefined ? {} : { unload: operation(v.unload) }), ...(v.current_driver_assignments === undefined ? {} : { current_driver_assignments: array(v.current_driver_assignments, assignment) }), ...(v.current_asset_assignments === undefined ? {} : { current_asset_assignments: array(v.current_asset_assignments, assignment) }) };
}
export function authorization(value: unknown): Authorization { const v = object(value); text(v.id); text(v.trip_id); text(v.type); object(v.content); integer(v.target_version); return v as unknown as Authorization; }
export function page<T>(value: unknown, decode: (v: unknown) => T): Page<T> { const v = object(value), m = object(v.meta); return { data: array(v.data, decode), meta: { current_page: integer(m.current_page), last_page: integer(m.last_page), per_page: integer(m.per_page), total: integer(m.total) } }; }
export function single<T>(value: unknown, decode: (v: unknown) => T): T { return decode(object(value).data); }
