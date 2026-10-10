---
name: transport-security-review
description: "Revisar seguridad de autenticación, autorización por rol y registro, API, entradas, archivos, auditoría, secretos y datos offline; usar en auditoría o endurecimiento solicitado."
---

# transport-security-review

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Delimitar superficie y actores; consultar «Arquitectura y tecnologías»,
   «Documentación», «Offline del conductor» y permisos pendientes. No inferir
   matriz completa de autorización a partir del nombre del rol.
2. Revisar sesión, revocación, CSRF según autenticación, cookies y HTTPS; comprobar
   permisos por acción y registro en API, servicios, archivos y sincronización.
   IDs aportados por cliente no demuestran derecho de acceso.
3. Revisar validación, asignación masiva, campos expuestos, consultas parametrizadas,
   XSS y límites. Comprobar tamaño/tipo de archivos, rutas privadas y autorización
   previa a enlaces temporales.
4. Revisar auditoría de actor/motivo/cambios, logs y errores sin revelar secretos.
   Aplicar diferenciación local/producción de AGENTS.md sección 8: credenciales,
   privilegios mínimos, respaldos y evidencia de restauración.
5. Revisar aislamiento offline, cachés privadas, tokens y permisos al sincronizar;
   no usar borrado silencioso de pendientes como remedio.

## Verificaciones esenciales

Trabajar en lectura; correcciones solo con tarea explícitamente autorizada.
Documentar evidencia, escenario, impacto y corrección propuesta; distinguir
vulnerabilidad demostrada, riesgo no verificado y política pendiente. Verificar
accesos cruzados con datos sintéticos en entorno aislado. No realizar escaneos
intrusivos ni acceder a producción sin autorización; nunca imprimir secretos.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
