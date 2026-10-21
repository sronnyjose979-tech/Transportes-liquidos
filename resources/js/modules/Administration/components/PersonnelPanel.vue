<script setup lang="ts">
import {useOffice} from '../../Office/context';
import {onBeforeRouteUpdate,useRoute} from 'vue-router';
const officeHost=useOffice();
const officeRoute=officeHost?useRoute():null;
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useModuleRequests } from '../../Office/useModuleRequests';
const {all,request}=useModuleRequests();
import { useAdmin } from '../context';
import SearchSelect from '../../Workshop/components/SearchSelect.vue';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';
import PersonnelRegistration from './PersonnelRegistration.vue';
interface Role { id: string; name: string }
interface Account { id: string; name: string; login: string; email: string; is_active: boolean; roles: Role[]; employee_id: string | null }
interface Employee { id: string; name: string; is_active: boolean; user_id: number | null }
const ctx = useAdmin();
const accounts = ref<Account[]>([]), employees = ref<Employee[]>([]), roles = ref<Role[]>([]);
const locationState = new URLSearchParams(window.location.search);
const initialPage = Number(locationState.get('pagina') ?? 1);
const section = ref<'accounts' | 'employees'>(locationState.get('vista') === 'cuentas' ? 'accounts' : 'employees'), query = ref(''), page = ref(Number.isInteger(initialPage) && initialPage > 0 ? initialPage : 1), loading = ref(false);
const statusFilter = ref(['active', 'inactive', 'driver'].includes(locationState.get('estado') ?? '') ? locationState.get('estado')! : 'all');
const accountId = ref(''), employeeId = ref(''), showForm = ref(false), guided = ref(false);
const account = reactive({ name: '', login: '', email: '', password: '', password_confirmation: '', roles: [] as string[], is_active: true });
const employee = reactive({ name: '', user_id: '', is_active: true });
const roleLabels: Record<string, string> = { administrator: 'Administrador', secretary: 'Secretaria', driver: 'Chofer', mechanic: 'Mecánico' };
const disabled = computed(() => ctx.busy.value || ctx.uncertain.value || loading.value);
const filteredAccounts = computed(() => accounts.value.filter(item => matchesStatus(item.is_active) && `${item.name} ${item.login} ${item.email} ${item.roles.map(role => roleLabels[role.name] ?? role.name).join(' ')}`.toLocaleLowerCase('es').includes(query.value.toLocaleLowerCase('es'))));
const filteredEmployees = computed(() => employees.value.filter(item => matchesStatus(item.is_active) && (statusFilter.value !== 'driver' || eligible(item)) && `${item.name} ${linkedAccount(item)?.login ?? ''}`.toLocaleLowerCase('es').includes(query.value.toLocaleLowerCase('es'))));
const total = computed(() => section.value === 'accounts' ? filteredAccounts.value.length : filteredEmployees.value.length);
const pages = computed(() => Math.max(1, Math.ceil(total.value / 15)));
const accountOptions = computed(() => accounts.value.filter(item => !item.employee_id || item.employee_id === employeeId.value).map(item => ({ value: item.id, label: `${item.name} (${item.login})${item.is_active ? '' : ' — inactiva'}` })));
watch([query, section, statusFilter], () => { guided.value = false; page.value = 1; showForm.value = false; clearPasswords(); ctx.errors.value = {}; });
watch([section, page, statusFilter], () => { const url = new URL(window.location.href); url.searchParams.set('vista', section.value === 'accounts' ? 'cuentas' : 'empleados'); url.searchParams.set('pagina', String(page.value)); url.searchParams.set('estado', statusFilter.value); if(officeHost)officeHost.navigate(url.pathname+url.search,true);else window.history.replaceState(history.state, '', url); });
watch(section, () => { if (section.value === 'accounts' && statusFilter.value === 'driver') statusFilter.value = 'all'; });
if (officeHost) onBeforeRouteUpdate(to => {
    const params = new URL(to.fullPath, window.location.origin).searchParams;
    const nextSection = params.get('vista') === 'cuentas' ? 'accounts' : 'employees';
    const nextStatus = params.get('estado');
    // Match the route watcher, including missing and repeated query parameters.
    // Confirm before it closes the form or clears passwords.
    const changesStatus = nextStatus !== null
        && ['all', 'active', 'inactive', 'driver'].includes(nextStatus)
        && nextStatus !== statusFilter.value;
    if (nextSection !== section.value || changesStatus) return officeHost.confirmDiscard();
});
if(officeRoute)watch(()=>officeRoute.fullPath,()=>{const q=new URLSearchParams(location.search);const next=q.get('vista')==='cuentas'?'accounts':'employees';if(section.value!==next)section.value=next;const target=Number(q.get('pagina')??1);if(Number.isInteger(target)&&target>0)page.value=target;const status=q.get('estado');if(status&&['all','active','inactive','driver'].includes(status))statusFilter.value=status as typeof statusFilter.value;});
watch(pages, () => { page.value = Math.min(page.value, pages.value); });
function matchesStatus(active: boolean): boolean { return statusFilter.value === 'active' ? active : statusFilter.value === 'inactive' ? !active : true; }
function linkedAccount(item: Employee): Account | undefined { return accounts.value.find(value => value.employee_id === item.id); }
function eligible(item: Employee): boolean { const user = linkedAccount(item); return item.is_active && !!user?.is_active && user.roles.some(role => role.name === 'driver'); }
function clearPasswords(): void { account.password = ''; account.password_confirmation = ''; }
async function reload(): Promise<void> { const data = await Promise.all([all<Account>('/users'), all<Employee>('/employees'), all<Role>('/roles')]); [accounts.value, employees.value, roles.value] = data; }
async function registered(): Promise<void> { guided.value = false; query.value = ''; statusFilter.value = 'all'; page.value = 1; await load(); }
async function load(): Promise<void> { loading.value = true; try { await reload(); page.value = Math.min(page.value, pages.value); } catch (error) { await ctx.report(error); } finally { loading.value = false; } }
function create(): void {
    guided.value = false;
    accountId.value = ''; employeeId.value = ''; ctx.errors.value = {};
    Object.assign(account, { name: '', login: '', email: '', password: '', password_confirmation: '', roles: [], is_active: true });
    Object.assign(employee, { name: '', user_id: '', is_active: true }); showForm.value = true;
}
async function edit(id: string): Promise<void> {
    loading.value = true; ctx.errors.value = {}; clearPasswords();
    try {
        if (section.value === 'accounts') { const item = await request<Account>(`/users/${id}`); accountId.value = id; Object.assign(account, { ...item, roles: item.roles.map(role => role.id) }); }
        else { const item = await request<Employee>(`/employees/${id}`); employeeId.value = id; Object.assign(employee, { ...item, user_id: item.user_id === null ? '' : String(item.user_id) }); }
        showForm.value = true;
    } catch (error) { await ctx.report(error); } finally { loading.value = false; }
}
async function save(): Promise<void> {
    const isAccount = section.value === 'accounts';
    const id = isAccount ? accountId.value : employeeId.value;
    const body: Record<string, unknown> = isAccount ? { name: account.name, login: account.login, email: account.email, roles: [...account.roles] } : { name: employee.name, is_active: employee.is_active, user_id: employee.user_id || null };
    if (isAccount) { if (!id) body.is_active = account.is_active; if (!id || account.password) { body.password = account.password; body.password_confirmation = account.password_confirmation; } }
    const ok = await ctx.mutate(`${id ? 'Actualizar' : 'Crear'} ${isAccount ? 'cuenta' : 'empleado'}`, async () => {
        await request(`${isAccount ? '/users' : '/employees'}${id ? `/${id}` : ''}`, id ? 'PATCH' : 'POST', body);
        clearPasswords(); showForm.value = false; await reload();
    });
    if (ok) clearPasswords();
}
async function activation(item: Account): Promise<void> {
    await ctx.mutate(`${item.is_active ? 'Desactivar' : 'Activar'} cuenta ${item.login}`, async () => { await request(`/users/${item.id}/activation`, 'PATCH', { is_active: !item.is_active }); await reload(); });
}
onMounted(load);
</script>
<template>
    <section class="panel space-y-4">
        <h2 class="text-xl font-semibold text-blue-950">Personal y cuentas</h2>
        <p class="text-sm text-slate-600">Busca a una persona para consultar su acceso o actualizar sus datos. Para un nuevo chofer, utiliza el registro guiado y completa persona, perfil y acceso en un solo proceso.</p>
        <div class="flex flex-wrap items-center gap-3"><button class="btn-primary" :disabled="disabled" @click="guided = true; showForm = false; clearPasswords()">Registrar personal con acceso</button><p class="text-sm text-slate-600">¿Ya tiene cuenta? Usa Nuevo empleado y selecciona su cuenta existente.</p></div>
        <PersonnelRegistration v-if="guided" :roles="roles" @close="guided = false" @created="registered"/>
        <div class="flex flex-wrap gap-2"><button class="btn-secondary" :aria-pressed="section === 'employees'" :class="section==='employees'?'ring-2 ring-blue-600':''" :disabled="disabled" @click="section = 'employees'">Empleados</button><button class="btn-secondary" :aria-pressed="section === 'accounts'" :class="section==='accounts'?'ring-2 ring-blue-600':''" :disabled="disabled" @click="section = 'accounts'">Cuentas y roles</button></div>
        <div class="flex flex-wrap items-end gap-3"><label class="min-w-0 flex-1"><span class="field-label">Buscar personal</span><input v-model="query" class="field" type="search"></label><label><span class="field-label">Estado del personal</span><select aria-label="Estado del personal" v-model="statusFilter" class="field"><option value="all">Todos</option><option value="active">Activos</option><option value="inactive">Inactivos</option><option v-if="section==='employees'" value="driver">Choferes habilitados</option></select></label><button class="btn-primary" :disabled="disabled" @click="create">{{ section === 'employees' ? 'Nuevo empleado' : 'Nueva cuenta' }}</button><button class="btn-secondary" :disabled="disabled" @click="load">Actualizar lista</button></div>
        <p v-if="loading" role="status">Consultando personal…</p>
        <div class="overflow-x-auto focus-visible:outline-2 focus-visible:outline-blue-700" tabindex="0" role="region" aria-label="Listado de personal"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Nombre</th><th class="p-2">{{ section === 'accounts' ? 'Cuenta / roles' : 'Cuenta vinculada' }}</th><th class="p-2">Estado</th><th class="p-2">Acciones</th></tr></thead>
            <tbody v-if="section === 'accounts'"><tr v-for="item in filteredAccounts.slice((page - 1) * 15, page * 15)" :key="item.id" class="border-t border-slate-200"><td class="p-2">{{ item.name }}<br><span class="text-slate-500">{{ item.email }}</span></td><td class="p-2">{{ item.login }}<br>{{ item.roles.map(role => roleLabels[role.name] ?? role.name).join(', ') || 'Sin roles' }}</td><td class="p-2"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="item.is_active?'bg-emerald-50 text-emerald-900':'bg-slate-100 text-slate-700'">{{ item.is_active ? 'Activa' : 'Inactiva' }}</span></td><td class="p-2"><button class="btn-secondary" :disabled="disabled" @click="edit(item.id)">Consultar / editar</button><button class="btn-secondary" :disabled="disabled" @click="activation(item)">{{ item.is_active ? 'Desactivar' : 'Activar' }}</button></td></tr></tbody>
            <tbody v-else><tr v-for="item in filteredEmployees.slice((page - 1) * 15, page * 15)" :key="item.id" class="border-t border-slate-200"><td class="p-2">{{ item.name }}</td><td class="p-2">{{ linkedAccount(item)?.login ?? 'Sin cuenta vinculada' }}</td><td class="p-2"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="item.is_active?'bg-emerald-50 text-emerald-900':'bg-slate-100 text-slate-700'">{{ item.is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="text-slate-600">{{ eligible(item) ? 'Chofer habilitado por cuenta y empleado' : 'Sin habilitación de chofer' }}</span></td><td class="p-2"><button class="btn-secondary" :disabled="disabled" @click="edit(item.id)">Consultar / editar</button></td></tr></tbody>
        </table></div>
        <p v-if="!loading && !total">Sin resultados.</p>
        <nav aria-label="Paginación de personal" class="flex items-center gap-3"><button class="btn-secondary" :disabled="page <= 1" @click="page--">Anterior</button><span>{{ page }} / {{ pages }}</span><button class="btn-secondary" :disabled="page >= pages" @click="page++">Siguiente</button></nav>
        <form aria-label="Registro de personal" v-if="showForm" class="space-y-4 border-t border-blue-100 pt-4" @submit.prevent="save">
            <h3 class="font-semibold text-blue-900">{{ section === 'accounts' ? (accountId ? 'Consultar / editar cuenta' : 'Crear cuenta') : (employeeId ? 'Consultar / editar empleado' : 'Crear empleado') }}</h3>
            <template v-if="section === 'accounts'">
                <div class="grid gap-4 md:grid-cols-2"><label v-for="field in (['name', 'login', 'email'] as const)" :key="field"><span class="field-label">{{ { name: 'Nombre visible', login: 'Usuario de acceso', email: 'Correo electrónico' }[field] }}</span><input v-model="account[field]" class="field" :type="field === 'email' ? 'email' : 'text'" required maxlength="255" :disabled="disabled" :aria-invalid="!!ctx.errors.value[field]?.length" :aria-describedby="`personnel-${field}-error`"><FieldError :id="`personnel-${field}-error`" :errors="ctx.errors.value[field]" /></label></div>
                <fieldset :disabled="disabled" class="space-y-2"><legend class="field-label">Roles</legend><label v-for="role in roles" :key="role.id" class="mr-4 inline-flex items-center gap-2"><input v-model="account.roles" type="checkbox" :value="role.id">{{ roleLabels[role.name] ?? role.name }}</label><FieldError id="personnel-roles-error" :errors="ctx.errors.value.roles" /></fieldset>
                <div class="grid gap-4 md:grid-cols-2"><label><span class="field-label">{{ accountId ? 'Nueva contraseña (opcional)' : 'Contraseña' }}</span><input v-model="account.password" class="field" type="password" autocomplete="new-password" :required="!accountId" minlength="12" maxlength="72" :disabled="disabled" :aria-invalid="!!ctx.errors.value.password?.length" aria-describedby="personnel-password-error"><FieldError id="personnel-password-error" :errors="ctx.errors.value.password" /></label><label><span class="field-label">Confirmar contraseña</span><input v-model="account.password_confirmation" class="field" type="password" autocomplete="new-password" :required="!accountId || !!account.password" minlength="12" maxlength="72" :disabled="disabled"><FieldError id="personnel-password-confirmation-error" :errors="ctx.errors.value.password_confirmation" /></label></div>
                <label v-if="!accountId" class="flex items-center gap-2"><input v-model="account.is_active" type="checkbox" :disabled="disabled">Cuenta activa</label><p v-else class="text-sm text-slate-600">Estado: {{ account.is_active ? 'activa' : 'inactiva' }}. La activación se cambia mediante la acción independiente del listado.</p>
            </template>
            <template v-else><label class="block"><span class="field-label">Nombre del empleado</span><input v-model="employee.name" class="field" required maxlength="255" :disabled="disabled" :aria-invalid="!!ctx.errors.value.name?.length" aria-describedby="personnel-name-error"><FieldError id="personnel-name-error" :errors="ctx.errors.value.name" /></label><SearchSelect v-model="employee.user_id" label="Cuenta vinculada" :options="accountOptions" :disabled="disabled" :errors="ctx.errors.value.user_id" /><p class="text-sm text-slate-600">Solo aparecen cuentas sin otro empleado vinculado. Seleccionar la opción vacía permite conservar al empleado sin cuenta.</p><label class="flex items-center gap-2"><input v-model="employee.is_active" type="checkbox" :disabled="disabled">Empleado activo</label></template>
            <FieldError id="personnel-active-error" :errors="ctx.errors.value.is_active" />
            <div class="flex gap-3"><button class="btn-primary" :disabled="disabled">Guardar {{ section === 'accounts' ? 'cuenta' : 'empleado' }}</button><button class="btn-secondary" type="button" :disabled="ctx.busy.value" @click="showForm = false; clearPasswords()">Cerrar formulario</button></div>
        </form>
    </section>
</template>
