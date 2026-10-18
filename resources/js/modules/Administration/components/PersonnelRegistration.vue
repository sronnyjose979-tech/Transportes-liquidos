<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { ApiError, request } from '../api';
import { useAdmin } from '../context';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';
const props = defineProps<{ roles: { id: string; name: string }[] }>();
const emit = defineEmits<{ close: []; created: [] }>();
const ctx = useAdmin(), step = ref(1), form = ref<HTMLFormElement | null>(null);
const data = reactive({ name: '', role: props.roles.find(r => r.name === 'driver')?.id ?? '', login: '', email: '', password: '', password_confirmation: '' });
const labels: Record<string, string> = { administrator: 'Administrador', secretary: 'Secretaría', mechanic: 'Mecánico', driver: 'Chofer' };
const selectedRole = computed(() => props.roles.find(r => r.id === data.role));
const locked = computed(() => ctx.busy.value || ctx.uncertain.value);
const message = ref('');
function clearPasswords(): void { data.password = ''; data.password_confirmation = ''; }
async function move(target: number): Promise<void> {
    step.value = target;
    await nextTick();
    form.value?.querySelector<HTMLElement>('input,select,button')?.focus();
}
async function submit(): Promise<void> {
    if (locked.value) return;
    if (step.value < 3) { await move(step.value + 1); return; }
    const ok = await ctx.mutate(`registrar a ${data.name} con perfil ${labels[selectedRole.value?.name ?? ''] ?? ''}`, async () => {
        const created = await request<{id:string;employee_id:string|null}>('/users', 'POST', { name: data.name, login: data.login, email: data.email,
            password: data.password, password_confirmation: data.password_confirmation,
            roles: [data.role], is_active: true, employee: { name: data.name, is_active: true } });
        if (typeof created.id !== 'string' || !created.id || typeof created.employee_id !== 'string' || !created.employee_id) {
            throw new ApiError(0, 'La respuesta no confirmó el vínculo. Consulta el estado antes de registrar otra persona.');
        }
    });
    if (ok) { clearPasswords(); emit('created'); return; }
    if (ctx.uncertain.value) {
        clearPasswords();
        message.value = 'La respuesta no confirmó el resultado. Consulta el estado confirmado y busca a la persona antes de iniciar otro registro. No vuelvas a crear su cuenta.';
    } else if (Object.keys(ctx.errors.value).length) {
        const personError = Object.keys(ctx.errors.value).some(field => ['name', 'roles', 'employee'].some(key => field === key || field.startsWith(`${key}.`)));
        await move(personError ? 1 : 2);
    }
}
onBeforeUnmount(clearPasswords);
</script>
<template>
<section class="rounded-xl border border-blue-200 bg-white p-4 sm:p-6" aria-labelledby="personnel-registration-title">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 id="personnel-registration-title" class="text-xl font-semibold text-blue-950">Registrar personal con acceso</h3><p class="mt-1 text-sm text-slate-600">Completa los datos una sola vez. La persona y su acceso se guardarán juntos.</p></div><button type="button" class="btn-secondary" :disabled="locked" @click="emit('close')">Cancelar registro</button></div>
    <ol aria-label="Pasos del registro" class="my-5 grid grid-cols-3 gap-2 text-sm"><li v-for="(label,index) in ['Persona y perfil','Acceso al sistema','Revisar y guardar']" :key="label" class="rounded border p-2" :class="step===index+1?'border-blue-600 bg-blue-50 font-semibold':'border-slate-200 text-slate-600'" :aria-current="step===index+1?'step':undefined">{{index+1}}. {{label}}</li></ol>
    <form ref="form" aria-label="Alta guiada de personal" class="space-y-4" @submit.prevent="submit">
        <p v-if="message" role="alert" class="notice-info">{{message}}</p>
        <fieldset v-if="step===1" :disabled="locked" class="space-y-4"><legend class="sr-only">Persona y perfil</legend>
            <label class="block"><span class="field-label">Nombre completo</span><input aria-label="Nombre completo" v-model="data.name" class="field" required maxlength="255" autocomplete="name" :aria-invalid="!!ctx.errors.value.name || !!ctx.errors.value['employee.name']" aria-describedby="guided-name-error"><FieldError id="guided-name-error" :errors="ctx.errors.value.name ?? ctx.errors.value['employee.name']"/></label>
            <label class="block"><span class="field-label">Perfil de trabajo</span><select aria-label="Perfil de trabajo" v-model="data.role" class="field" required aria-describedby="guided-role-help guided-role-error"><option value="" disabled>Seleccionar perfil</option><option v-for="role in roles" :key="role.id" :value="role.id">{{labels[role.name] ?? role.name}}</option></select><FieldError id="guided-role-error" :errors="ctx.errors.value.roles ?? ctx.errors.value['roles.0']"/></label>
            <p id="guided-role-help" class="text-sm text-slate-600">El perfil determina las tareas permitidas. Este registro habilita una persona activa con acceso activo.</p>
            <p v-if="!roles.some(r=>r.name==='driver')" role="status" class="notice-info">El perfil Chofer no está disponible. Solicita su habilitación antes de registrar conductores.</p>
        </fieldset>
        <fieldset v-else-if="step===2" :disabled="locked" class="grid gap-4 sm:grid-cols-2"><legend class="sr-only">Acceso al sistema</legend>
            <label><span class="field-label">Usuario de acceso</span><input aria-label="Usuario de acceso" v-model="data.login" class="field" required maxlength="255" autocomplete="off" :aria-invalid="!!ctx.errors.value.login" aria-describedby="guided-login-error"><FieldError id="guided-login-error" :errors="ctx.errors.value.login"/></label>
            <label><span class="field-label">Correo electrónico</span><input aria-label="Correo electrónico" v-model="data.email" class="field" type="email" required maxlength="255" autocomplete="email" :aria-invalid="!!ctx.errors.value.email" aria-describedby="guided-email-error"><FieldError id="guided-email-error" :errors="ctx.errors.value.email"/></label>
            <label><span class="field-label">Contraseña</span><input aria-label="Contraseña" v-model="data.password" class="field" type="password" required minlength="12" maxlength="72" autocomplete="new-password" :aria-invalid="!!ctx.errors.value.password" aria-describedby="guided-password-error guided-password-help"><FieldError id="guided-password-error" :errors="ctx.errors.value.password"/></label>
            <label><span class="field-label">Confirmar contraseña</span><input aria-label="Confirmar contraseña" v-model="data.password_confirmation" class="field" type="password" required minlength="12" maxlength="72" autocomplete="new-password" :aria-invalid="!!ctx.errors.value.password_confirmation" aria-describedby="guided-confirm-error"><FieldError id="guided-confirm-error" :errors="ctx.errors.value.password_confirmation"/></label>
            <p id="guided-password-help" class="text-sm text-slate-600 sm:col-span-2">Usa al menos 12 caracteres. El servidor también comprueba que las contraseñas coincidan y no superen 72 bytes. No se guardan en el navegador.</p>
        </fieldset>
        <section v-else aria-label="Revisión del personal" class="space-y-3 rounded-lg bg-slate-50 p-4"><h4 class="font-semibold">Revisa antes de registrar</h4><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-sm text-slate-600">Persona</dt><dd class="break-words font-semibold">{{data.name}}</dd></div><div><dt class="text-sm text-slate-600">Perfil</dt><dd>{{labels[selectedRole?.name ?? '']}}</dd></div><div><dt class="text-sm text-slate-600">Usuario</dt><dd class="break-words">{{data.login}}</dd></div><div><dt class="text-sm text-slate-600">Correo</dt><dd class="break-words">{{data.email}}</dd></div></dl><p class="text-sm">La persona quedará vinculada a su cuenta. Para asignar viajes, el sistema comprobará además sus permisos y disponibilidad.</p></section>
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-4"><button v-if="step>1" type="button" class="btn-secondary" :disabled="locked" @click="move(step-1)">Volver al paso anterior</button><button class="btn-primary" :disabled="locked || !roles.length">{{ctx.busy.value?'Guardando…':step===3?'Registrar persona y acceso':'Continuar'}}</button></div>
    </form>
</section>
</template>
