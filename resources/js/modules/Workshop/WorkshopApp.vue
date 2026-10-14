<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { getSession, login, logout, refreshCsrf } from '../FleetAndDocuments/api';
import type { Session } from '../FleetAndDocuments/contracts';
import { registrationDate } from '../FleetAndDocuments/presentation';
import { all, detail, failure, list, catalog, catalogAsset, write } from './api';
import type { RecordRow } from './api';
import { instant, labels, localInstant, options, states, terminal, text } from './presentation';
import CommandForm from './components/CommandForm.vue';
import ExitRequestForm from './components/ExitRequestForm.vue';
import type { Field } from './components/CommandForm.vue';
import RecordTable from './components/RecordTable.vue';
import ScopeForm from './components/ScopeForm.vue';
import WorkshopIcon from './components/WorkshopIcon.vue';
import WorkshopDialog from './components/WorkshopDialog.vue';
import SearchSelect from './components/SearchSelect.vue';
import ContentSummary from './components/ContentSummary.vue';
import OrderOperations from './components/OrderOperations.vue';
import BatchCorrectionForm from './components/BatchCorrectionForm.vue';
import EvidencePanel from './components/EvidencePanel.vue';
import { clearAttachmentRecovery, clearOtherAttachmentRecovery, listAttachments } from './attachments';
import type { OperationCommand } from './operations';
import { operationValues } from './operations';
import { object } from '../TransportAndDispatch/contracts';
import { request } from './api';
import './workshop.css';
type View = 'admissions' | 'admission' | 'impediment' | 'dashboard' | 'orders' | 'reports' | 'authorizations' | 'impediments' | 'order' | 'authorization' | 'report';
interface Command {
    title: string;
    path: string;
    method: string;
    fields: Field[];
    extra: Record<string, unknown>;
    key: string;
    actor: string;
    roles: string;
    scope?: boolean;
    batch?:boolean;
    exit?:boolean;
    transform?: (values:Record<string,string>)=>Record<string,unknown>;
}
const session = ref<Session | null>(null), denied = ref(false), booting = ref(true), username = ref(''), password = ref('');
const view = ref<View>('dashboard'), loading = ref(false), busy = ref(false), error = ref(''), success = ref(''), fieldErrors = ref<Record<string, string[]>>({}), csrfExpired = ref(false);
const rows = ref<RecordRow[]>([]), selected = ref<RecordRow | null>(null), related = ref<Record<string, RecordRow[]>>({}), decisions = ref<RecordRow[]>([]), applications = ref<RecordRow[]>([]);
const counts = ref<Record<string, number | null>>({}), metricsError = ref(''), assets = ref<RecordRow[]>([]), employees = ref<RecordRow[]>([]);
const parts = ref<RecordRow[]>([]), suppliers = ref<RecordRow[]>([]), lookupWarning = ref('');
const linkableReports = ref<RecordRow[]>([]), linkableAdmissions = ref<RecordRow[]>([]), vehicle = ref<RecordRow|null>(null);
const onlyOpen = ref(false);
const currentPage = ref(1), lastPage = ref(1), total = ref(0), search = ref(''), status = ref(''), priority = ref(''), assetFilter = ref(''), from = ref(''), to = ref(''), onlyPending = ref(true);
const timeTotals = ref<unknown>(null), costTotals = ref<unknown>(null), summaryWarning=ref(''), evidencePending=ref(false);
const originReport = ref<RecordRow | null>(null);
const command = ref<Command | null>(null), frozen = ref<Record<string, unknown> | null>(null), uncertain = ref(false), stale = ref(false);
let controller: AbortController | undefined, timer: ReturnType<typeof setInterval> | undefined, generation = 0;
const admin = computed(() => session.value?.roles.includes('administrator') ?? false);
const office = computed(() => session.value?.roles.some(r => ['administrator', 'secretary'].includes(r)) ?? false);
const diagnostician = computed(() => admin.value || session.value?.roles.includes('mechanic'));
const currentAssignments = computed(() => (related.value['mechanic-assignments'] ?? []).filter(a => a.is_current === true && (admin.value || a.is_current_actor === true)));
const canParticipate = computed(() => admin.value || currentAssignments.value.length > 0);
const activeNavigation = computed(() => ({order:'orders',report:'reports',authorization:'authorizations',impediment:'impediments',admission:'admissions'} as Partial<Record<View,View>>)[view.value] ?? view.value);
const titles: Record<View, string> = { admissions:'Ingresos y salidas', admission:'Detalle de ingreso', dashboard: 'Inicio de Taller', orders: 'Órdenes de trabajo', reports: 'Reportes de averías', authorizations: 'Autorizaciones', impediments: 'Vehículos con impedimentos', order: 'Detalle de orden', authorization: 'Detalle de autorización', report: 'Detalle de avería', impediment: 'Detalle de impedimento' };
const visibleRows = computed(() => rows.value.filter(row => !search.value || [row.id, row.description, assetName(String(row.fleet_asset_id ?? ''))].some(v => String(v ?? '').toLocaleLowerCase().includes(search.value.toLocaleLowerCase()))).map(row => ({ ...row, status: labels[String(row.status)] ?? row.status, priority: labels[String(row.priority)] ?? row.priority, type: labels[String(row.type)] ?? row.type, fleet_asset_id: assetName(String(row.fleet_asset_id ?? '')), authorization_state: authorizationState(row), admission_state: row.is_open===true?'En taller':'Salida registrada', admitted_at: row.admitted_at?registrationDate(String(row.admitted_at)):undefined, kind:labels[String(row.kind)] ?? row.kind })));
function assetName(id: string): string { return String(assets.value.find(a => a.id === id)?.code ?? id); }
const assetOptions = computed(() => assets.value.map(a => ({value:a.id,label:String(a.code ?? a.description ?? a.id)})));
const partOptions = computed(() => parts.value.map(p => ({value:p.id,label:`${p.code ?? ''} ${p.description ?? p.description_snapshot ?? p.id} · ${p.unit ?? p.unit_snapshot ?? ''}`.trim()})));
const supplierOptions = computed(() => suppliers.value.map(p => ({value:p.id,label:String(p.name ?? p.description ?? p.id)})));
function rejectedRequests(type?:string): {value:string;label:string}[] {return (related.value['authorization-requests'] ?? []).filter(r => decisionFor(r)?.decision==='rejected' && (!type || r.type===type)).map(r=>({value:r.id,label:`${labels[String(r.type)] ?? r.type} · ${r.reason} · ${r.registered_at}`}));}

function decisionFor(row: RecordRow): RecordRow | undefined { return decisions.value.find(d => d.request_id === row.id); }
function applied(d: RecordRow): boolean { return applications.value.some(a => a.decision_id === d.id); }
function authorizationState(row: RecordRow): string { const d = decisionFor(row); return d ? applied(d) ? 'Aplicada' : labels[String(d.decision)] ?? text(d.decision) : 'Pendiente'; }
function clearPrivate(preserveRecovery=false): void { if(!preserveRecovery)clearAttachmentRecovery(session.value?.id);timeTotals.value=null;costTotals.value=null;summaryWarning.value='';evidencePending.value=false; controller?.abort(); generation++; rows.value = []; related.value = {}; selected.value = null; decisions.value = []; applications.value = []; assets.value = []; employees.value = []; parts.value=[];suppliers.value=[];linkableReports.value=[];linkableAdmissions.value=[];vehicle.value=null;lookupWarning.value=''; counts.value = {}; command.value = null; frozen.value = null; uncertain.value = false; originReport.value = null; loading.value = false; }
async function handle(e: unknown): Promise<void> {
    const f = failure(e); error.value = f.message; fieldErrors.value = f.fields; csrfExpired.value = f.status === 419;
    if (f.status === 401) {const interrupted=evidencePending.value;clearPrivate(true);session.value=null;if(interrupted)error.value+=' La reserva permanece en el servidor. Inicia sesión con la misma cuenta para consultar su recibo y recuperar la carga.';}
    if (f.status === 403) {
        try {
            const current = await getSession();
            const changed = !session.value || current.id !== session.value.id || [...current.roles].sort().join(',') !== [...session.value.roles].sort().join(',');
            if (changed || !current.roles.some(role => ['administrator','secretary','mechanic'].includes(role))) {
                clearPrivate(); session.value = current; denied.value = !current.roles.some(role => ['administrator','secretary','mechanic'].includes(role)); view.value='dashboard';
            } else { command.value=null; frozen.value=null; uncertain.value=false; selected.value=null; related.value={}; rows.value=[]; }
        } catch (sessionError) {const status=failure(sessionError).status;if(status===401){clearPrivate();session.value=null;}else if(status===403){clearPrivate();denied.value=true;} }
    }
    if (f.status === 404) { selected.value = null; related.value = {}; }
    if (f.status === 409 || f.fields.version || f.fields.request_key) stale.value = true;
}
async function signIn(): Promise<void> { if (busy.value)
    return; busy.value = true; error.value = ''; try {
    session.value = await login(username.value, password.value);
    clearOtherAttachmentRecovery(session.value.id);
    password.value = '';
    denied.value = false;
    view.value = 'dashboard';
    await refresh();
}
catch (e) {
    await handle(e);
}
finally {
    busy.value = false;
} }
async function signOut(): Promise<void> { if (busy.value)
    return; busy.value = true; try {
    await logout();
    clearPrivate();
    session.value = null;
    view.value = 'dashboard';
    error.value = '';
    success.value = '';
}
catch (e) {
    await handle(e);
}
finally {
    busy.value = false;
} }
async function loadLookups(signal: AbortSignal): Promise<void> {
    lookupWarning.value='';
    if (!office.value) {
        const orders=await all('orders',{},signal);
        const ids=[...new Set(orders.map(row=>String(row.fleet_asset_id)))];
        const values=await Promise.all(ids.map(id=>catalogAsset(id,signal).catch(()=>null)));
        if(!signal.aborted)assets.value=values.filter((value):value is RecordRow=>value!==null);
        return;
    }
    const paths=admin.value?['/fleet-assets','/workshop/lookups/mechanics','/catalogs/spare-parts','/catalogs/suppliers']:['/fleet-assets','/workshop/lookups/mechanics'];
    const batches=await Promise.allSettled(paths.map(path=>catalog(path,signal)));
    if(signal.aborted)return;
    const failed=batches.find(b=>b.status==='rejected' && failure(b.reason).status===401);
    if(failed?.status==='rejected')throw failed.reason;
    const read=(index:number):RecordRow[]=>{const batch=batches[index];return batch?.status==='fulfilled'?batch.value:[];};
    assets.value=read(0);
    employees.value=read(1);
    if(admin.value){parts.value=read(2);suppliers.value=read(3);}
    if(batches.some(b=>b.status==='rejected'))lookupWarning.value='No se pudieron cargar algunos selectores. Puedes consultar Taller y actualizar para volver a intentarlo.';
}
async function loadScopeContext(order:string,signal:AbortSignal):Promise<void>{
    if(admin.value)return;
    const [p,t]=await Promise.all([all('scope-parts',{},signal),all('scope-tasks',{},signal)]);
    if(signal.aborted)return;
    parts.value=[...new Map(p.map(row=>[String(row.spare_part_id),{...row,id:String(row.spare_part_id)}])).values()];
    suppliers.value=[...new Map(t.filter(row=>typeof row.supplier_id==='string').map(row=>[String(row.supplier_id),{id:String(row.supplier_id),description:`Proveedor asociado a ${row.description}`}])).values()];
}
async function dashboard(signal: AbortSignal): Promise<void> {
    const keys = Object.keys(states);
    const results = await Promise.allSettled([...keys.map(s => list('orders', { status: s, per_page: 1 }, signal)), list('authorization-requests', { pending: 1, per_page: 1 }, signal), all('impediments', { open: 1 }, signal)]);
    if (signal.aborted)
        return;
    const values: Record<string, number | null> = {};
    let unavailable = false;
    results.forEach((result, i) => { const key = keys[i] ?? (i === keys.length ? 'authorizations' : 'vehicles'); if (result.status === 'fulfilled')
        values[key] = Array.isArray(result.value) ? new Set(result.value.map(v => v.fleet_asset_id)).size : result.value.meta.total;
    else {
        values[key] = null;
        unavailable = true;
    } });
    counts.value = values;
    metricsError.value = unavailable ? 'Algunos indicadores no están disponibles. Los valores corresponden a tu acceso autorizado.' : '';
    const failed = results.find(r => r.status === 'rejected' && [401, 403].includes(failure(r.reason).status));
    if (failed?.status === 'rejected')
        throw failed.reason;
}
async function refresh(): Promise<boolean> {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal, run = ++generation;
    loading.value = true;
    error.value = '';
    try {
        const nextSession = await getSession();
        if (signal.aborted || run !== generation)
            return false;
        if (session.value && (session.value.id !== nextSession.id || [...session.value.roles].sort().join(',') !== [...nextSession.roles].sort().join(','))) {
            clearAttachmentRecovery(session.value.id);timeTotals.value=null;costTotals.value=null;evidencePending.value=false;
            rows.value = [];
            related.value = {};
            selected.value = null;
            decisions.value = [];
            applications.value = [];
            assets.value = [];
            employees.value = [];parts.value=[];suppliers.value=[];linkableReports.value=[];linkableAdmissions.value=[];vehicle.value=null;lookupWarning.value='';
            counts.value = {};
            originReport.value = null;
            command.value = null;
            frozen.value = null;
            uncertain.value = false;
            success.value = '';
            view.value = 'dashboard';
        }
        session.value = nextSession;
        if (!session.value.roles.some(r => ['administrator', 'secretary', 'mechanic'].includes(r))) {
            clearPrivate();
            denied.value = true;
            return false;
        }
        denied.value = false;
        await loadLookups(signal);
        if(signal.aborted)return false;
        if (view.value === 'dashboard')
            await dashboard(signal);
        else if (['order', 'authorization', 'report', 'impediment', 'admission'].includes(view.value) && selected.value) {
            const kind = view.value === 'order' ? 'orders' : view.value === 'report' ? 'reports' : view.value === 'impediment' ? 'impediments' : view.value === 'admission' ? 'admissions' : 'authorization-requests';
            const row = await detail(kind, selected.value.id, signal);
            if (view.value === 'order') {
                const kinds = ['diagnoses', 'mechanic-assignments', 'mechanic-releases', 'admission-links', 'scopes', 'report-links', 'authorization-requests', 'scope-tasks', 'scope-parts','activities','time-events','deliveries','usages','material-needs',...(office.value?['external-repairs','costs']:[])];
                const values = await Promise.all(kinds.map(k => all(k, { work_order_id: row.id }, signal)));
                if (signal.aborted)
                    return false;
                related.value = Object.fromEntries(kinds.map((k, i) => [k, values[i] ?? []]));
                const reports=await all('reports',{fleet_asset_id:String(row.fleet_asset_id),effective:1},signal);
                if(signal.aborted)return false;linkableReports.value=reports;
                await loadScopeContext(row.id,signal);
                linkableAdmissions.value=await all('admissions',{fleet_asset_id:String(row.fleet_asset_id)},signal);
                if(signal.aborted)return false;
                const summaries=await Promise.allSettled([
                    request(`/workshop/orders/${encodeURIComponent(row.id)}/times`,'GET',undefined,signal),
                    ...(office.value?[request(`/workshop/orders/${encodeURIComponent(row.id)}/cost-totals`,'GET',undefined,signal)]:[]),
                ]);
                if(signal.aborted)return false;
                timeTotals.value=summaries[0]?.status==='fulfilled'?object(summaries[0].value).data:null;
                costTotals.value=office.value && summaries[1]?.status==='fulfilled'?object(summaries[1].value).data:null;
                summaryWarning.value=summaries.some(result=>result.status==='rejected')?'No se pudieron cargar algunos resúmenes. Actualiza para consultar tiempos y costos confirmados.':'';
                for(const result of summaries) if(result.status==='rejected' && [401,403].includes(failure(result.reason).status))throw result.reason;
                if(office.value){ const attachmentRows:RecordRow[]=[]; for(let p=1;;p++){const batch=await listAttachments(row.id,p);attachmentRows.push(...batch.data);if(p>=batch.meta.last_page)break;} if(signal.aborted)return false;related.value.attachments=attachmentRows; }

            }
            if (view.value === 'admission' || view.value === 'impediment') {
                const asset=String(row.fleet_asset_id);
                const kinds=['orders','admission-links','impediments','impediment-resolutions','warnings','exits','authorization-requests'];
                const [current,...values]=await Promise.all([catalogAsset(asset,signal),...kinds.map(k=>all(k,{fleet_asset_id:asset},signal))]);
                if(signal.aborted)return false;
                vehicle.value=current as RecordRow;
                related.value=Object.fromEntries(kinds.map((k,i)=>[k,values[i] as RecordRow[]]));
            }
            if (['order', 'authorization', 'admission'].includes(view.value)) {
                const filter = view.value === 'order' ? { work_order_id: row.id } : {};
                const [d, a] = await Promise.all([all('authorization-decisions', filter, signal), all('authorization-applications', filter, signal)]);
                if (signal.aborted)
                    return false;
                decisions.value = d;
                applications.value = a;
            }
            if (signal.aborted)
                return false;
            selected.value = row;
        }
        else {
            const kind = view.value === 'orders' ? 'orders' : view.value === 'reports' ? 'reports' : view.value === 'impediments' ? 'impediments' : view.value === 'admissions' ? 'admissions' : 'authorization-requests';
            const filters: Record<string, string | number> = { page: currentPage.value };
            if (assetFilter.value)
                filters.fleet_asset_id = assetFilter.value;
            if (view.value === 'orders') {
                if (status.value)
                    filters.status = status.value;
                if (priority.value)
                    filters.priority = priority.value;
                if (from.value)
                    filters.from = instant(from.value);
                if (to.value)
                    filters.to = instant(to.value);
            }
            if (['impediments','admissions'].includes(view.value) && onlyOpen.value)
                filters.open = 1;
            if (view.value === 'authorizations' && onlyPending.value)
                filters.pending = 1;
            const result = await list(kind, filters, signal);
            if (view.value === 'authorizations') {
                const [d, a] = await Promise.all([all('authorization-decisions', {}, signal), all('authorization-applications', {}, signal)]);
                if (signal.aborted)
                    return false;
                decisions.value = d;
                applications.value = a;
            }
            if (signal.aborted)
                return false;
            rows.value = result.data;
            lastPage.value = result.meta.last_page;
            total.value = result.meta.total;
        }
        return true;
    }
    catch (e) {
        if (!(e instanceof DOMException && e.name === 'AbortError') && run === generation)
            {if(['order','report','authorization','impediment','admission'].includes(view.value)){selected.value=null;related.value={};} await handle(e);}
        return false;
    }
    finally {
        if (run === generation)
            loading.value = false;
    }
}
async function navigate(target: View, row?: RecordRow): Promise<void> { if (busy.value || uncertain.value || evidencePending.value)
    return; timeTotals.value=null;costTotals.value=null;command.value = null; originReport.value=null;selected.value = row ?? null; vehicle.value=null; view.value = target; rows.value = []; related.value = {}; search.value = ''; currentPage.value = 1; success.value = ''; await refresh(); }
const reason: Field = { key: 'reason', label: 'Motivo / información recibida', type: 'textarea', required: true };
const description = (value = ''): Field => ({ key: 'description', label: 'Descripción de la reparación', type: 'textarea', required: true, value });
const time = (key: string, label: string): Field => ({ key, label, type: 'datetime-local', required: true, value: localInstant() });
const assetField = (value = ''): Field => ({ key: 'fleet_asset_id', label: 'Vehículo', required: true, value, options:assetOptions.value,searchable:true });
const priorityField = (value = 'normal'): Field => ({ key: 'priority', label: 'Prioridad', required: true, value, options: options({ low: 'Baja', normal: 'Normal', high: 'Alta', urgent: 'Urgente' }) });
function open(title: string, path: string, fields: Field[], extra: Record<string, unknown> = {}, method = 'POST', scope = false): void { if(loading.value || busy.value || evidencePending.value)return;error.value = ''; success.value = ''; fieldErrors.value = {}; frozen.value = null; uncertain.value = false; stale.value = false; command.value = { title, path, fields, extra, method, key: crypto.randomUUID(), actor: session.value?.id ?? '', roles: [...(session.value?.roles ?? [])].sort().join(','), scope }; }
function newReport(): void { open('Registrar avería', 'reports', [assetField(), { ...description(), label: 'Descripción de la avería' }, time('occurred_at', 'Fecha de la avería (Costa Rica)'), reason]); }
function newOrder(report?: RecordRow): void { originReport.value = report ?? null; open('Crear orden', 'orders', [assetField(report ? String(report.fleet_asset_id) : ''), { key: 'kind', label: 'Tipo de mantenimiento', required: true, value: 'corrective', options: options({ corrective: 'Correctivo', preventive: 'Preventivo' }) }, description(report ? String(report.description) : ''), priorityField(), time('created_at', 'Fecha de creación (Costa Rica)'), reason]); }
function newOrderForAdmission():void {const asset=selected.value?.fleet_asset_id;if(!asset)return;newOrder();const field=command.value?.fields.find(f=>f.key==='fleet_asset_id');if(field)field.value=String(asset);}
function newAdmission():void {open('Registrar ingreso','assets/pending/admissions',[assetField(),{key:'kind',label:'Tipo de mantenimiento',required:true,value:'corrective',options:options({corrective:'Correctivo',preventive:'Preventivo'})},time('admitted_at','Fecha de ingreso (Costa Rica)'),reason]);}
function admissionOrders():RecordRow[]{const row=selected.value;if(!row)return [];return (related.value.orders ?? []).filter(order=>(related.value['admission-links'] ?? []).some(link=>link.admission_id===row.id && link.work_order_id===order.id && link.is_invalidated!==true));}
function linkAdmissionOrder():void {const row=selected.value;if(!row)return;const linked=(related.value['admission-links'] ?? []).filter(l=>l.admission_id===row.id).map(l=>String(l.work_order_id));open('Vincular orden','orders/pending/admission-links',[{key:'work_order_id',label:'Orden de trabajo',required:true,searchable:true,options:(related.value.orders ?? []).filter(o=>!linked.includes(o.id)).map(o=>({value:o.id,label:String(o.description)}))},reason],{admission_id:row.id});}
function requestExit():void {if(!selected.value || !admin.value)return;open('Solicitar salida',`admissions/${selected.value.id}/exit-requests`,[]);if(command.value)command.value.exit=true;}
function resolveImpediment():void {if(!selected.value || !admin.value)return;open('Resolver impedimento',`impediments/${selected.value.id}/resolve`,[reason]);}
function releaseMechanic(row:RecordRow):void {open('Liberar mecánico',`mechanic-assignments/${row.id}/release`,[time('ends_at','Fecha de liberación (Costa Rica)'),reason]);}
const exitCauses=computed(()=>(related.value.impediments ?? []).filter(c=>c.is_open===true));
const exitPrevious=computed(()=>(related.value['authorization-requests'] ?? []).filter(q=>q.admission_id===selected.value?.id && q.type==='exit' && decisionFor(q)?.decision==='rejected').map(q=>({value:q.id,label:String(q.reason)})));
function orderAction(action: string): void {
    const row = selected.value;
    if (!row)
        return;
    const base = `orders/${row.id}`;
    if (action === 'edit')
        open('Editar orden', base, [description(String(row.description)), priorityField(String(row.priority)), reason], { version: row.version }, 'PATCH');
    if (action === 'assign')
        open('Asignar mecánico', `${base}/mechanic-assignments`, [{ key: 'employee_id', label: 'Empleado mecánico', required: true, options:employees.value.map(e=>({value:e.id,label:String(e.name ?? e.id)})),searchable:true }, time('starts_at', 'Inicio de asignación (Costa Rica)'), reason]);
    if (action === 'admission')
        open('Vincular ingreso', `${base}/admission-links`, [{key:'admission_id',label:'Ingreso al taller',required:true,searchable:true,options:linkableAdmissions.value.filter(a=>!(related.value['admission-links'] ?? []).some(l=>l.admission_id===a.id)).map(a=>({value:a.id,label:`${a.reason} · ${a.admitted_at} · ${a.is_open?'En taller':'Salida registrada'}`}))},reason]);
    if (action === 'link')
        open('Vincular avería', `${base}/report-links`, [{ key: 'report_id', label: 'Avería', required: true, value: originReport.value?.id ?? '',options:linkableReports.value.map(r=>({value:r.id,label:`${r.description} · ${r.occurred_at}`})),searchable:true }, reason]);
    if (action === 'diagnosis')
        open('Registrar diagnóstico', `${base}/diagnoses`, [{ key: 'mechanic_assignment_id', label: 'Asignación mecánica vigente',searchable:true, required: !admin.value, options: (related.value['mechanic-assignments'] ?? []).filter(a => a.is_current === true && (admin.value || a.is_current_actor === true)).map(a => ({ value: a.id, label: String(a.employee_name ?? a.employee_id) })) }, { key: 'problem', label: 'Problema encontrado', type: 'textarea', required: true }, { key: 'probable_cause', label: 'Causa probable', type: 'textarea', required: true }, { key: 'proposed_repair', label: 'Reparación propuesta', type: 'textarea', required: true }, time('occurred_at', 'Fecha de diagnóstico (Costa Rica)'), reason]);
    if (action === 'scope')
        open('Solicitar autorización de alcance', `${base}/scopes`, [], {}, 'POST', true);
    if (['cancellation', 'reopening', 'rectification'].includes(action))
        open(`Solicitar ${labels[action]?.toLowerCase()}`, `${base}/authorization-requests`, [...(action === 'rectification' ? [description(String(row.description)), priorityField(String(row.priority))] : []), { key: 'previous_request_id', label: 'Solicitud anterior rechazada (opcional)',options:rejectedRequests(action),searchable:true }, reason], { type: action, version: row.version });
}
async function submit(values: Record<string, unknown>): Promise<void> {
    if (busy.value || stale.value || !command.value)
        return;
    const c = command.value;
    const payload:Record<string,unknown>={...c.extra};
    if(c.transform) Object.assign(payload,c.transform(Object.fromEntries(Object.entries(values).filter(([,value])=>typeof value==='string')) as Record<string,string>));
    else for(const [key,value] of Object.entries(values))if(value!=='' && value!==undefined)payload[key]=typeof value==='string' && ['occurred_at','created_at','starts_at','performed_at','admitted_at','ends_at'].includes(key)?instant(value):value;
    if (!frozen.value)
        frozen.value = { ...payload, request_key: c.key };
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        const current = await getSession();
        if (current.id !== c.actor || [...current.roles].sort().join(',') !== c.roles) {
            clearPrivate();
            session.value = current;
            view.value = 'dashboard';
            await refresh();
            error.value = 'La identidad o los permisos cambiaron. Revisa los datos e inicia una nueva operación.';
            return;
        }
        let path=c.path;
        let body=frozen.value;
        if(path==='assets/pending/admissions'){path=`assets/${encodeURIComponent(String(body.fleet_asset_id))}/admissions`;body={...body};delete body.fleet_asset_id;}
        if(path==='orders/pending/admission-links'){path=`orders/${encodeURIComponent(String(body.work_order_id))}/admission-links`;body={...body};delete body.work_order_id;}
        const result = await write(path, c.method, body);
        uncertain.value = false;
        command.value = null;
        frozen.value = null;
        success.value = 'Operación confirmada. Actualizando información…';
        if (typeof result.order_id === 'string' && c.path === 'orders') {
            view.value = 'order';
            selected.value = { id: result.order_id };
        }
        if(typeof result.admission_id==='string' && c.path==='assets/pending/admissions'){view.value='admission';selected.value={id:result.admission_id};}
        if (c.path.endsWith('/report-links'))
            originReport.value = null;
        if (typeof result.report_id === 'string' && c.path === 'reports') {
            view.value = 'report';
            selected.value = { id: result.report_id };
        }
        const updated=await refresh();
        if(session.value && !denied.value)success.value=updated?'Operación confirmada y datos actualizados.':'Operación confirmada. No se pudo actualizar la información; consulta de nuevo sin repetir el envío.';
    }
    catch (e) {
        const f = failure(e);
        uncertain.value = f.status === 0 || f.status >= 500;
        if (!uncertain.value)
            frozen.value = null;
        await handle(e);
    }
    finally {
        busy.value = false;
    }
}
function operationCommand(spec:OperationCommand):void {
    open(spec.title,spec.path,spec.fields,spec.extra ?? {},spec.method ?? 'POST');
    if(command.value && spec.transform) command.value.transform=spec.transform;
}
async function evidenceError(issue:unknown):Promise<void>{if([401,403].includes(failure(issue).status))await handle(issue);}
async function evidenceChanged():Promise<void>{const updated=await refresh();if(updated)success.value='Evidencias y datos de la orden actualizados.';}
const approvedAttachmentCorrections=computed(()=> (related.value['authorization-requests'] ?? []).flatMap(row=>{
    const content=row.content && typeof row.content==='object'?row.content as Record<string,unknown>:{};
    const d=decisionFor(row);
    if(content.fact_table!=='workshop_attachments' || !d || d.decision!=='approved' || applied(d) || String(content.executor_id)!==session.value?.id || typeof content.fact_id!=='string' || !content.after || typeof content.after!=='object')return [];
    return [{decisionId:d.id,factId:content.fact_id,reason:String(row.reason),after:content.after as Record<string,unknown>}];
}));
async function retry(): Promise<void> { if (!frozen.value || !command.value)
    return; await submit({}); }
function cancel(): void { if (busy.value || uncertain.value || evidencePending.value)
    return; command.value = null; fieldErrors.value = {}; error.value = ''; }
function decide(): void { if (!selected.value)
    return; open('Registrar decisión', `authorization-requests/${selected.value.id}/decisions`, [{ key: 'decision', label: 'Decisión', required: true, options: options({ approved: 'Aprobar', rejected: 'Rechazar' }) }, reason]); }
const materialFactKinds:Record<string,string>={workshop_part_deliveries:'delivery',workshop_part_usages:'usage',workshop_external_repairs:'external',workshop_cost_records:'cost',workshop_material_needs:'need'};
function correctionContent(row:RecordRow):Record<string,unknown>{return row.content && typeof row.content==='object'?row.content as Record<string,unknown>:{};}
function applicationPath(row: RecordRow): string {
    const content=correctionContent(row),table=String(content.fact_table ?? '');
    if(materialFactKinds[table])return `facts/${materialFactKinds[table]}/apply`;
    if(['workshop_activities','workshop_time_events','workshop_attachments'].includes(table))return 'approved-fact';
    if(table)return '';
    if(row.scope_id)return 'apply-scope';
    if(row.type==='exit')return 'exit';
    return row.work_order_id && ['cancellation','reopening','rectification'].includes(String(row.type))?'apply-order':'';
}
function canApply(row:RecordRow):boolean{
    const content=correctionContent(row);
    return !!applicationPath(row) && (content.fact_table?String(content.executor_id)===session.value?.id:admin.value);
}
const batchOptions=computed(()=> (related.value['authorization-requests'] ?? []).flatMap(row=>{const content=correctionContent(row),d=decisionFor(row);return d && d.decision==='approved' && !applied(d) && materialFactKinds[String(content.fact_table)] && String(content.executor_id)===session.value?.id?[{value:d.id,label:`${row.reason} · ${labels[String(row.type)]}`}]:[];}));
function applyBatch():void{open('Aplicar rectificaciones en lote','rectifications/apply-batch',[]);if(command.value)command.value.batch=true;}
async function applyDecision():Promise<void>{
    const row=selected.value;if(!row)return;const d=decisionFor(row),path=applicationPath(row);if(!d || !canApply(row))return;
    const content=correctionContent(row);
    if(content.fact_table==='workshop_attachments'){await navigate('order',{id:String(row.work_order_id)});return;}
    if(path==='approved-fact'){
        const kind=content.fact_table==='workshop_activities'?'activities':'time-events';
        const keys=kind==='activities'?['task_id','mechanic_assignment_id','description','observations','occurred_at','reason']:['mechanic_assignment_id','event_type','occurred_at','reason'];
        const after=content.after && typeof content.after==='object'?content.after as Record<string,unknown>:{};
        const extra=Object.fromEntries(keys.filter(key=>after[key]!==undefined && after[key]!==null).map(key=>[key,after[key]]));
        open('Aplicar rectificación aprobada',`orders/${row.work_order_id}/${kind}`,[],{...extra,version:row.target_version,decision_id:d.id,[kind==='activities'?'previous_activity_id':'previous_event_id']:content.fact_id});
    }else open('Aplicar autorización',`decisions/${d.id}/${path}`,[]);
}
async function fixCsrf(): Promise<void> { try {
    await refreshCsrf();
    csrfExpired.value = false;
    error.value = 'Sesión de seguridad actualizada. Revisa y envía el formulario.';
}
catch (e) {
    await handle(e);
} }
onMounted(async () => { try {
    session.value = await getSession();
    await refresh();
}
catch (e) {
    if (failure(e).status !== 401)
        await handle(e);
}
finally {
    booting.value = false;
} timer = setInterval(() => { if (session.value && !denied.value && !command.value && !loading.value && !busy.value && !evidencePending.value && !document.hidden)
    void refresh(); }, 30000); });
onUnmounted(() => { controller?.abort(); if (timer)
    clearInterval(timer); });
const orderColumns = [{ key: 'id', label: 'Orden' }, { key: 'fleet_asset_id', label: 'Vehículo' }, { key: 'description', label: 'Reparación' }, { key: 'status', label: 'Estado' }, { key: 'priority', label: 'Prioridad' }];
function openRow(row: RecordRow): void { void navigate(view.value === 'orders' ? 'order' : view.value === 'reports' ? 'report' : view.value === 'authorizations' ? 'authorization' : view.value === 'admissions' ? 'admission' : 'impediment', { id: row.id }); }
</script>

<template>
<div class="workshop">
    <div v-if="booting" class="workshop-login" role="status">Cargando Taller Mecánico…</div>
    <div v-else-if="!session" class="workshop-login">
        <form class="workshop-card" aria-label="Iniciar sesión" @submit.prevent="signIn">
<span class="workshop-eyebrow">TRANSPORTES LÍQUIDOS</span>
<h1>Acceso a Taller Mecánico</h1>
<p>Gestión de averías, órdenes y autorizaciones.</p>
<div v-if="error" role="alert">{{ error }}</div>
<label>Usuario<input v-model="username" required autocomplete="username">
</label>
<label>Contraseña<input v-model="password" type="password" required autocomplete="current-password">
</label>
<button :disabled="busy">{{ busy?'Ingresando…':'Iniciar sesión' }}</button>
</form>
    </div>
    <template v-else>
        <aside class="workshop-sidebar">
<a class="workshop-brand" href="/">TL <span>Transportes Líquidos<small>Gestión operativa</small>
</span>
</a>
<p class="workshop-eyebrow">TALLER MECÁNICO</p>
<nav aria-label="Navegación Taller">
<button v-for="item in (office?['dashboard','orders','reports','admissions','authorizations','impediments'] as const:['dashboard','orders','reports','authorizations','impediments'] as const)" :key="item" :aria-current="activeNavigation===item?'page':undefined" :disabled="busy || uncertain || evidencePending || denied" @click="navigate(item)">
<WorkshopIcon :name="item" /> {{ {dashboard:'Inicio',orders:'Órdenes',reports:'Averías',authorizations:'Autorizaciones',impediments:'Impedimentos',admissions:'Ingresos y salidas'}[item] }}</button>
</nav>
<nav v-if="office" aria-label="Otros módulos">
<a v-if="admin" href="/administracion/flota">Administración ↗</a>
<a href="/transporte">Transporte y despacho ↗</a>
<a href="/inventario">Inventario ↗</a>
<a href="/compras">Compras ↗</a>
<a href="/documentacion">Flota y documentos ↗</a>
</nav>
<nav v-if="!office && session.roles.includes('mechanic')" aria-label="Repuestos asignados"><a href="/inventario">Mis repuestos ↗</a></nav>
<small>Una reparación por orden.<br>Autorización antes de ejecución.</small>
</aside>
        <main class="workshop-main">
<header class="workshop-topbar">
<span>Taller Mecánico</span>
<div>
<span>{{ session.login ?? `Usuario ${session.id}` }}</span> <button :disabled="busy || uncertain || evidencePending" @click="signOut">Cerrar sesión</button>
</div>
</header>
        <div class="workshop-content">
<div class="workshop-page-heading">
<div>
<p class="workshop-eyebrow">OPERACIÓN Y CONTROL</p>
<h1>{{ denied?'Acceso no autorizado':titles[view] }}</h1>
<p>{{ office?'Coordinación de Taller':'Consulta de órdenes asignadas' }} · Información del servidor</p>
</div>
<button v-if="!denied" :disabled="loading || busy || evidencePending || !!command" @click="refresh">Actualizar datos</button>
</div>
        <div v-if="error && !command" role="alert" class="workshop-alert">{{ error }}<button v-if="csrfExpired" @click="fixCsrf">Actualizar sesión de seguridad</button>
</div>
<div v-if="success" role="status" class="workshop-success">{{ success }}</div>
<p v-if="lookupWarning" role="status">{{ lookupWarning }}</p><p v-if="loading" role="status">Cargando información…</p>
        <p v-if="denied">Tu cuenta no tiene acceso vigente a esta operación. Contacta al administrador o cierra sesión.</p>
        <template v-else-if="view==='dashboard'">
            <p v-if="metricsError" role="status">{{ metricsError }}</p>
<div class="workshop-grid workshop-metrics">
<article v-for="(label,key) in states" :key="key" class="workshop-card">
<span>{{ label }}</span>
<strong>{{ counts[key] ?? '—' }}</strong>
<button @click="status=key; navigate('orders')">Consultar órdenes</button>
</article>
<article class="workshop-card">
<span>Autorizaciones pendientes</span>
<strong>{{ counts.authorizations ?? '—' }}</strong>
<button @click="onlyPending=true;navigate('authorizations')">Gestionar autorizaciones</button>
</article>
<article class="workshop-card">
<span>Vehículos con impedimentos abiertos</span>
<strong>{{ counts.vehicles ?? '—' }}</strong>
<button @click="navigate('impediments')">Consultar impedimentos</button>
</article>
</div>
            <section class="workshop-card">
<h2>Accesos rápidos</h2>
<div class="workshop-actions">
<button :disabled="loading || busy" @click="newReport">Registrar avería</button>
<button @click="navigate('orders')">Consultar órdenes</button><button @click="status='authorized';navigate('orders')">Trabajos autorizados</button><button @click="status='in_repair';navigate('orders')">Continuar ejecución</button>
<button @click="navigate('authorizations')">Gestionar autorizaciones</button>
<button v-if="office" :disabled="loading || busy" @click="newOrder()">Crear orden</button>
<button v-if="office" :disabled="loading || busy" @click="newAdmission">Registrar ingreso</button>
</div>
</section>
<section class="workshop-card">
<h2>Actividad reciente</h2>
<p>La API no expone un historial consolidado de actividad. Consulta los registros y las fechas en el detalle de cada orden.</p>
<button @click="navigate('reports')">Consultar averías registradas</button>
</section>
        </template>
        <template v-else-if="['orders','reports','authorizations','impediments','admissions'].includes(view)">
            <div class="workshop-actions">
<button v-if="view==='reports'" :disabled="loading || busy" @click="newReport">Registrar avería</button>
<button v-if="view==='admissions' && office" :disabled="loading || busy" @click="newAdmission">Registrar ingreso</button>
<button v-if="view==='orders' && office" :disabled="loading || busy" @click="newOrder()">Crear orden</button>
</div>
            <form class="workshop-card workshop-filters" aria-label="Filtros" @submit.prevent="currentPage=1;refresh()">
<label v-if="view==='orders'">Estado<select aria-label="Estado" v-model="status">
<option value="">Todos</option>
<option v-for="(label,key) in states" :key="key" :value="key">{{ label }}</option>
</select>
</label>
<label v-if="view==='orders'">Prioridad<select aria-label="Prioridad" v-model="priority">
<option value="">Todas</option>
<option v-for="item in options({low:'Baja',normal:'Normal',high:'Alta',urgent:'Urgente'})" :key="item.value" :value="item.value">{{ item.label }}</option>
</select>
</label>
<SearchSelect v-model="assetFilter" label="Vehículo" :options="assetOptions" :disabled="loading" />
<label v-if="view==='orders'">Desde (Costa Rica)<input v-model="from" type="datetime-local">
</label>
<label v-if="view==='orders'">Hasta (Costa Rica)<input v-model="to" type="datetime-local">
</label>
<label v-if="['admissions','impediments'].includes(view)"><input v-model="onlyOpen" type="checkbox"> Solo abiertos</label>
<label v-if="view==='authorizations'">
<input v-model="onlyPending" type="checkbox"> Solo pendientes</label>
<button :disabled="loading">Aplicar filtros</button>
</form>
            <label>Buscar en esta página<input v-model="search" type="search" placeholder="Identificador, vehículo o descripción">
</label>
<p>{{ total }} registros accesibles · Página {{ currentPage }} de {{ lastPage }}</p>
<RecordTable selectable :rows="visibleRows" :columns="view==='orders'?orderColumns:view==='reports'?[{key:'id',label:'Avería'},{key:'fleet_asset_id',label:'Vehículo'},{key:'description',label:'Descripción'},{key:'occurred_at',label:'Fecha'}]:view==='admissions'?[{key:'fleet_asset_id',label:'Vehículo'},{key:'kind',label:'Tipo'},{key:'reason',label:'Motivo'},{key:'admitted_at',label:'Ingreso'},{key:'admission_state',label:'Estado'}]:view==='authorizations'?[{key:'type',label:'Tipo'},{key:'reason',label:'Motivo'},{key:'authorization_state',label:'Estado'},{key:'registered_at',label:'Solicitada'}]:[{key:'fleet_asset_id',label:'Vehículo'},{key:'description',label:'Impedimento'},{key:'effect',label:'Restricción'}]" @open="openRow"/>
<div class="workshop-actions">
<button :disabled="loading || currentPage<=1" @click="currentPage--;refresh()">Anterior</button>
<button :disabled="loading || currentPage>=lastPage" @click="currentPage++;refresh()">Siguiente</button>
</div>
        </template>
        <template v-else-if="selected && typeof selected.description==='string' && view==='order'">
            <section v-if="originReport && office" class="workshop-card">
<h2>Avería de origen</h2>
<p>{{ originReport.description }}</p>
<button @click="orderAction('link')">Vincular esta avería a la orden</button>
</section>
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>{{ assetName(String(selected.fleet_asset_id)) }} · <span class="workshop-badge" :class="String(selected.status).startsWith('pending')?'pending':['finished','closed'].includes(String(selected.status))?'complete':selected.status==='cancelled'?'blocked':''">{{ labels[String(selected.status)] }}</span> · Prioridad {{ labels[String(selected.priority)] }} · Versión {{ selected.version }}</p>
<p>Orden {{ selected.id }} · {{ selected.created_at }}</p>
<div v-if="!loading && !evidencePending" class="workshop-actions">
<button v-if="office && !terminal(selected)" @click="orderAction('edit')">Editar orden</button>
<button v-if="office && !terminal(selected)" @click="orderAction('assign')">Asignar mecánico</button>
<button v-if="office" @click="orderAction('link')">Vincular avería</button>
<button v-if="office" @click="orderAction('admission')">Vincular ingreso</button>
<button v-if="diagnostician && canParticipate && !terminal(selected)" @click="orderAction('diagnosis')">Registrar diagnóstico</button>
<button v-if="(office || canParticipate) && !terminal(selected) && (related.diagnoses ?? []).some(d=>d.is_effective!==false)" @click="orderAction('scope')">Solicitar autorización de alcance</button>
<button v-if="office && !terminal(selected)" @click="orderAction('cancellation')">Solicitar cancelación</button>
<button v-if="office && terminal(selected)" @click="orderAction('reopening')">Solicitar reapertura</button>
<button v-if="office" @click="orderAction('rectification')">Solicitar rectificación</button>
</div>
</section>
            <section class="workshop-card">
<h2>Mecánicos asignados</h2>
<p v-if="!admin && !office">El servidor comprueba tu participación vigente al registrar diagnósticos y propuestas.</p>
<RecordTable :rows="related['mechanic-assignments'] ?? []" :columns="[{key:'employee_name',label:'Empleado'},{key:'starts_at',label:'Desde'},{key:'is_released',label:'Liberado'}]"/>
<article v-for="assignment in related['mechanic-assignments'] ?? []" :key="assignment.id">
<p>{{ assignment.employee_name }} · {{ assignment.is_current?'Participación vigente':assignment.is_released?'Liberado · consulta histórica':'Participación no vigente' }}</p>
<button v-if="office && assignment.is_released===false" :disabled="loading || busy" @click="releaseMechanic(assignment)">Liberar mecánico</button>
<p v-for="release in (related['mechanic-releases'] ?? []).filter(r=>r.assignment_id===assignment.id)" :key="release.id">Liberación: {{ registrationDate(String(release.ends_at)) }} · {{ release.reason }}</p>
</article>
</section>
            <section class="workshop-card">
<h2>Ingresos vinculados</h2>
<p v-if="!(related['admission-links'] ?? []).length">Sin ingresos vinculados.</p>
<p v-for="link in related['admission-links']" :key="link.id">{{ link.reason }} · {{ link.is_invalidated?'Histórico':'Vigente' }} <button @click="navigate('admission',{id:String(link.admission_id)})">Consultar ingreso</button></p>
</section><section class="workshop-card">
<h2>Diagnósticos</h2>
<p v-if="!(related.diagnoses ?? []).length">No hay diagnósticos. Se requiere un diagnóstico antes de proponer el alcance.</p>
<article v-for="d in related.diagnoses" :key="d.id">
<h3>{{ d.problem }}</h3>
<p>Causa probable: {{ d.probable_cause }}</p>
<p>Reparación propuesta: {{ d.proposed_repair }}</p>
<small>{{ d.occurred_at }} · Revisión {{ d.revision }} · {{ d.is_effective?'Vigente':'Histórica' }}</small>
</article>
</section>
            <section class="workshop-card">
<h2>Alcances propuestos y autorizados</h2>
<p v-if="!(related.scopes ?? []).length">Sin alcances registrados.</p>
<article v-for="s in related.scopes" :key="s.id">
<h3>{{ s.description }}</h3>
<p>{{ labels[String(s.scope_kind)] }} · {{ s.is_applied?'Aplicado':'Sin aplicar' }} · {{ s.is_effective?'Vigente':'Histórico' }}</p>
<ul>
<li v-for="t in (related['scope-tasks'] ?? []).filter(t=>t.scope_id===s.id)" :key="t.id">{{ t.line_number }}. {{ t.description }}</li>
</ul>
<p v-for="p in (related['scope-parts'] ?? []).filter(p=>p.scope_id===s.id)" :key="p.id">{{ p.description_snapshot }}: {{ p.quantity }} {{ p.unit_snapshot }}</p>
</article>
</section>
            <section class="workshop-card">
<h2>Autorizaciones de la orden</h2>
<p v-if="!(related['authorization-requests'] ?? []).length">Sin solicitudes.</p>
<article v-for="a in related['authorization-requests']" :key="a.id">
<h3>{{ labels[String(a.type)] }} · {{ authorizationState(a) }}</h3>
<p>{{ a.reason }}</p>
<button :disabled="loading || busy" @click="navigate('authorization',a)">Revisar autorización</button>
</article>
</section>
<section class="workshop-card">
<h2>Averías vinculadas</h2>
<p v-if="!(related['report-links'] ?? []).length">Sin averías vinculadas.</p>
<p v-for="l in related['report-links']" :key="l.id">{{ l.report_id }} <button @click="navigate('report',{id:String(l.report_id)})">Consultar avería</button>
</p>
</section>
            <button v-if="admin && batchOptions.length" :disabled="loading || busy || evidencePending" @click="applyBatch">Aplicar rectificaciones en lote</button>
<p v-if="summaryWarning" role="status">{{ summaryWarning }}</p>
<OrderOperations v-if="session" :key="`${session.id}:${selected.id}`" :order="selected" :actor="session" :related="related" :parts="partOptions" :suppliers="supplierOptions" :times="timeTotals" :cost-totals="costTotals" :disabled="loading || busy || evidencePending" @command="operationCommand" @error="handle" />
<EvidencePanel v-if="session" :key="`evidence:${session.id}:${selected.id}`" :order="selected" :actor="session" :can-write="(office || canParticipate) && !loading && !busy" :approved-corrections="approvedAttachmentCorrections" @changed="evidenceChanged" @error="evidenceError" @pending="evidencePending=$event" @navigate-authorization="navigate('authorization',{id:$event})" />
<p>Finalizar o cerrar una orden no libera automáticamente el vehículo ni modifica existencias de inventario.</p>
        </template>
        <template v-else-if="selected && !loading && view==='admission'">
<section class="workshop-card">
<h2>{{ assetName(String(selected.fleet_asset_id)) }} · {{ selected.is_open?'En taller':'Salida registrada' }}</h2>
<p>Ingreso {{ selected.id }} · {{ labels[String(selected.kind)] }} · Versión {{ selected.version }}</p>
<p>{{ selected.reason }} · {{ registrationDate(String(selected.admitted_at)) }} · Responsable {{ selected.admitted_by_id }}</p>
<p>Estado actual del vehículo: <strong>{{ vehicle?.service_status==='available'?'Disponible':labels[String(vehicle?.service_status)] ?? vehicle?.service_status ?? 'No disponible para consulta' }}</strong></p>
<p>El ingreso y la salida no cancelan viajes ni liberan recursos de Transporte.</p>
<div class="workshop-actions">
<button v-if="office && selected.is_open===true" :disabled="busy" @click="linkAdmissionOrder">Vincular orden</button>
<button v-if="office && selected.is_open===true" :disabled="busy" @click="newOrderForAdmission">Crear orden para este ingreso</button>
<button v-if="admin && selected.is_open===true" :disabled="busy" @click="requestExit">Solicitar salida</button>
</div>
</section>
<section class="workshop-card"><h2>Órdenes relacionadas</h2>
<p v-if="!admissionOrders().length">Sin órdenes vinculadas. Crear una orden y vincularla son operaciones independientes.</p>
<article v-for="order in admissionOrders()" :key="order.id"><h3>{{ order.description }}</h3><p>{{ labels[String(order.status)] }} · {{ labels[String(order.priority)] }}</p><button @click="navigate('order',order)">Consultar orden</button></article>
<h3>Historial de vínculos</h3>
<RecordTable :rows="(related['admission-links'] ?? []).filter(l=>l.admission_id===selected?.id)" :columns="[{key:'work_order_id',label:'Orden'},{key:'registered_at',label:'Fecha'},{key:'reason',label:'Motivo'},{key:'is_invalidated',label:'Invalidado'}]" />
</section>
<section class="workshop-card"><h2>Impedimentos del vehículo</h2>
<p v-if="!(related.impediments ?? []).length">Sin impedimentos accesibles.</p>
<article v-for="cause in related.impediments ?? []" :key="cause.id"><h3>{{ cause.description }}</h3><p>{{ cause.is_open?'Abierto':'Resuelto' }} · {{ labels[String(cause.effect)] }}</p><button @click="navigate('impediment',cause)">Consultar impedimento</button></article>
</section>
<section class="workshop-card"><h2>Advertencias del ingreso</h2>
<p v-if="!(related.warnings ?? []).some(w=>w.admission_id===selected?.id)">Sin advertencias registradas.</p>
<article v-for="warning in (related.warnings ?? []).filter(w=>w.admission_id===selected?.id)" :key="warning.id"><p>Vehículo asignado a un viaje al ingresar. La asignación se conserva.</p><ContentSummary :value="warning.context" /></article>
</section>
<section class="workshop-card"><h2>Autorizaciones de salida</h2>
<p v-if="!(related['authorization-requests'] ?? []).some(q=>q.admission_id===selected?.id)">Sin solicitudes de salida.</p>
<article v-for="authorization in (related['authorization-requests'] ?? []).filter(q=>q.admission_id===selected?.id)" :key="authorization.id"><h3>{{ authorizationState(authorization) }}</h3><p>{{ authorization.reason }} · {{ authorization.registered_at }}</p><button :disabled="loading || busy" @click="navigate('authorization',authorization)">Revisar autorización</button></article>
</section>
<section class="workshop-card"><h2>Historial de salidas</h2>
<p v-if="!(related.exits ?? []).some(e=>e.admission_id===selected?.id)">Sin salida registrada.</p>
<article v-for="exit in (related.exits ?? []).filter(e=>e.admission_id===selected?.id)" :key="exit.id"><p>{{ registrationDate(String(exit.exited_at)) }} · {{ exit.reason }} · Responsable {{ exit.recorded_by_id }}</p><ContentSummary :value="exit.reviewed_content" /></article>
<p>Una salida registrada no equivale a disponibilidad si permanecen impedimentos. Transporte verifica nuevamente las condiciones al asignar.</p>
</section>
</template>
        <template v-else-if="selected && !loading && view==='impediment'">
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>Vehículo: {{ assetName(String(selected.fleet_asset_id)) }}</p>
<p>Restricción: {{ labels[String(selected.effect)] }} · {{ selected.is_open?'Abierto':'Resuelto' }}</p>
<p>{{ selected.reason }} · {{ selected.occurred_at }}</p>
<button v-if="selected.work_order_id" @click="navigate('order',{id:String(selected.work_order_id)})">Consultar orden vinculada</button>
<p>Estado actual del vehículo: {{ labels[String(vehicle?.service_status)] ?? vehicle?.service_status }}</p>
<button v-if="admin && selected.is_open===true && !(selected.admission_id && !selected.work_order_id)" :disabled="loading || busy" @click="resolveImpediment">Resolver impedimento</button>
<p v-if="selected.admission_id && !selected.work_order_id">Este impedimento de ingreso solo puede resolverse mediante la salida autorizada.</p>
<button v-if="selected.admission_id" @click="navigate('admission',{id:String(selected.admission_id)})">Consultar ingreso</button>
<p v-for="resolution in (related['impediment-resolutions'] ?? []).filter(r=>r.impediment_id===selected?.id)" :key="resolution.id">Resolución: {{ registrationDate(String(resolution.resolved_at)) }} · {{ resolution.reason }} · Responsable {{ resolution.resolved_by_id }}</p>
<p>Resolver una causa no elimina otros impedimentos ni libera asignaciones.</p>
</section>
</template>
        <template v-else-if="selected && !loading && view==='report'">
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>Identificador: {{ selected.id }}</p>
<p>Vehículo: {{ assetName(String(selected.fleet_asset_id)) }} · {{ selected.occurred_at }}</p>
<p>{{ selected.reason }}</p>
<p>Revisión {{ selected.revision }} · {{ selected.is_effective?'Vigente':'Histórica' }}</p>
<button v-if="office" @click="newOrder(selected)">Crear orden para esta avería</button>
<p v-if="office">Después de crear la orden, vincula el identificador de esta avería en su detalle. Son operaciones independientes.</p>
<p v-else>La oficina debe crear la orden oficial y asignar sus mecánicos.</p>
</section>
</template>
        <template v-else-if="selected && !loading && view==='authorization'">
<section class="workshop-card">
<h2>{{ labels[String(selected.type)] }} · {{ authorizationState(selected) }}</h2>
<p>Solicitud {{ selected.id }}</p>
<p>{{ selected.reason }}</p>
<p>Fecha {{ selected.registered_at }} · Solicitante {{ selected.requested_by_id }} · Versión objetivo {{ selected.target_version ?? 'No aplica' }}</p>
<button v-if="selected.work_order_id" @click="navigate('order',{id:String(selected.work_order_id)})">Consultar orden</button>
<button v-if="selected.admission_id" @click="navigate('admission',{id:String(selected.admission_id)})">Consultar ingreso</button>
<h3>Contenido solicitado</h3>
<ContentSummary :value="selected.content" />
<button v-if="admin && !decisionFor(selected)" @click="decide">Registrar decisión</button>
<p v-else-if="!decisionFor(selected)">Pendiente de decisión exclusiva del administrador.</p>
<template v-if="decisionFor(selected)">
<h3>Historial de decisión</h3>
<p>{{ labels[String(decisionFor(selected)?.decision)] }} · {{ decisionFor(selected)?.registered_at }}</p>
<p>{{ decisionFor(selected)?.reason }}</p>
<p v-if="applied(decisionFor(selected)!)">Autorización aplicada.</p>
<button v-else-if="canApply(selected) && decisionFor(selected)?.decision==='approved'" @click="applyDecision">Aplicar autorización</button>
<p v-if="decisionFor(selected)?.decision==='approved' && !applicationPath(selected)">Esta solicitud requiere un proceso específico no expuesto por esta pantalla. Su aprobación no modifica registros hasta confirmar la aplicación autorizada.</p>
</template>
</section>
</template>
        </div>
</main>
    </template>
    <WorkshopDialog v-if="command && session && !denied" :title="command.title" @cancel="cancel">
<div v-if="error" role="alert" class="workshop-alert">{{ error }}<p v-for="(items,key) in fieldErrors" :key="key">{{ items.join(' ') }}</p>
</div>
<p v-if="!admin && command.scope">Los selectores muestran repuestos y proveedores ya referenciados en tus órdenes accesibles. El catálogo completo requiere acceso administrativo.</p><p v-if="!office && command.path==='reports'">Puedes seleccionar vehículos de tus órdenes asignadas. Si falta un vehículo, solicita a la oficina registrar la avería.</p><p v-if="uncertain">La respuesta no confirmó el resultado. Se conserva la operación exacta y su clave para evitar duplicados.</p>
<button v-if="uncertain" :disabled="busy" @click="retry">Reintentar la misma operación</button>
<button v-if="csrfExpired" @click="fixCsrf">Actualizar sesión de seguridad</button>
<p v-if="stale">El estado cambió. Cierra este formulario, actualiza los datos y revisa una nueva operación.</p>
<BatchCorrectionForm v-if="command.batch" :options="batchOptions" :busy="busy || uncertain || stale" :cancel-disabled="busy || uncertain" :errors="fieldErrors" @submit="submit" @cancel="cancel" />
<ExitRequestForm v-else-if="command.exit" :causes="exitCauses" :previous-requests="exitPrevious" :busy="busy || uncertain || stale" :cancel-disabled="busy || uncertain" :errors="fieldErrors" @submit="submit" @cancel="cancel" />
<ScopeForm v-else-if="command.scope" :diagnoses="related.diagnoses ?? []" :scopes="related.scopes ?? []" :parts="partOptions" :suppliers="supplierOptions" :previous-requests="rejectedRequests()" :busy="busy || uncertain || stale" :errors="fieldErrors" :cancel-disabled="busy || uncertain" @submit="submit" @cancel="cancel"/>
<CommandForm v-else :title="command.title" :fields="command.fields" :busy="busy || uncertain || stale" :errors="fieldErrors" :cancel-disabled="busy || uncertain" @submit="submit" @cancel="cancel"/>
<button v-if="stale" @click="cancel">Cerrar y revisar estado</button>
</WorkshopDialog>
</div>
</template>
