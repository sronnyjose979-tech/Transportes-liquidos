---
name: transport-test-verification
description: "Planificar y ejecutar verificaciones con PHPUnit, PostgreSQL aislado, concurrencia, integridad, autorización, API, frontend y offline; usar para validar cambios sin afectar datos reales."
---

# transport-test-verification

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Inspeccionar cambios, contratos, scripts y herramientas; consultar «Concurrencia
   y validación» y reglas del área. Elegir pruebas por comportamiento/invariantes,
   sin convertir propuestas pendientes en resultados exigidos.
2. Antes de pruebas con DB, verificar configuración efectiva, DB_URL, variables,
   caché y conexiones secundarias: PostgreSQL 18 exclusivo de pruebas, credenciales
   separadas y datos sintéticos. APP_ENV=testing no acredita aislamiento. No mostrar
   secretos ni ejecutar migraciones, RefreshDatabase o limpieza antes de verificar.
3. Sin entorno aislado, no ejecutar pruebas dependientes de DB; informar bloqueo y
   continuar solo con pruebas independientes. Revisar hooks, colas, archivos y
   servicios externos antes de comandos; no crear entornos sin autorización.
4. Seleccionar PHPUnit unitario/funcional e integración para restricciones, rollback,
   cliente/ruta/tarifa, aceptación, saldos, autorización por registro y contratos API.
   SQLite no sustituye las garantías de PostgreSQL.
5. Usar conexiones independientes y coordinación determinista para doble reserva,
   último repuesto, recepción duplicada, facturado/reservado y pagos concurrentes.
   Verificar resultado persistido; una transacción envolvente no prueba carreras.
6. Con herramientas disponibles, probar frontend, formularios, errores y accesibilidad;
   offline: reenvío, respuesta perdida, conflicto, revocación y aislamiento de usuarios.
7. Ejecutar formato en modo comprobación, análisis estático, tipos y build pertinentes
   tras revisar efectos; no instalar frameworks ni cambiar código durante verificación.

## Verificaciones esenciales

Registrar comandos, resultados y omisiones con causa; no declarar éxito global
con cobertura parcial ni ocultar herramientas ausentes. Mantener artefactos y
limpieza en entorno autorizado, nunca datos reales. Ampliar pruebas solo ante
cambios, fallos o incertidumbre concreta; evitar tests que reflejen implementación.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
