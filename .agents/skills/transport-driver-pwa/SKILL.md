---
name: transport-driver-pwa
description: "Diseñar, implementar o revisar PWA offline de conductores: service worker, IndexedDB, cola, UUID, sincronización, reintentos, conflictos y seguridad según operaciones autorizadas."
---

# transport-driver-pwa

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Leer «Offline del conductor», «Requisitos confirmados» y «Decisiones pendientes».
   Verificar alcance vigente: viajes descargados, salida/llegada, kilometraje,
   combustible, gastos e incidencias. No habilitar carga/descarga offline sin
   aprobación; asignaciones, autorizaciones y cierre administrativo son en línea.
2. Separar recursos del service worker, consultas mínimas en IndexedDB y cola por
   usuario. Definir versiones, actualización, cuotas y recuperación sin perder
   pendientes; no cachear API privada indiscriminadamente.
3. Mantener UUID por operación lógica y versión base según contrato. Coordinar
   pestañas; quitar pendientes solo tras confirmación inequívoca. Reintentar fallos
   transitorios con espera progresiva y límites; separar rechazo, sesión y conflicto.
   Sincronizar manualmente, al abrir y reconectar; no depender de Background Sync.
4. Revalidar permisos y asignación en servidor; exigir huella, resultado previo y
   efectos atómicos. UUID no evita operaciones incompatibles con IDs distintos.
   No usar hora del dispositivo como autoridad ni sobrescribir conflictos.
5. Mostrar pendiente/sincronizando/confirmado/conflicto/rechazado. Resolver solo con
   reglas aprobadas; conservar capturas reasignadas para revisión. Aclarar retención,
   sesión y dispositivos compartidos antes de implementar lo dependiente.
6. Minimizar datos y separar usuarios; no borrar pendientes silenciosamente al salir.
   IndexedDB no es respaldo ni protección XSS; no guardar contraseñas o tokens
   de sesión de larga duración allí.

## Verificaciones esenciales

Cubrir desconexión/reconexión, respuesta perdida, duplicados, UUID incompatible,
conflictos, permisos revocados, dos pestañas, actualización, cuotas y cambio de
usuario. Usar datos sintéticos y backend de pruebas aislado cuando sea necesario.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
