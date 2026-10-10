---
name: transport-postgres-integrity
description: "Diseñar y revisar PostgreSQL 18: restricciones, índices, transacciones ACID, concurrencia, idempotencia y migraciones seguras ante cambios de persistencia o escrituras críticas."
---

# transport-postgres-integrity

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Inspeccionar versiones y diseño existente. Contrastar PK, FK, UNIQUE, CHECK,
   nulabilidad, precisión decimal, fechas e índices con el modelo aprobado.
   Revisar semántica de NULL; justificar índices por restricciones y consultas.
2. Separar invariantes de fila, entre tablas y agregados: un CHECK no garantiza
   acumulados ni coherencia entre tablas. Revisar cliente/ruta/tarifa e historia.
3. Definir unidad ACID, aislamiento y operaciones competidoras. Elegir actualizaciones
   condicionales, control optimista o bloqueos cuando correspondan; ordenar bloqueos
   y limitar reintentos seguros. Bloquear filas ausentes no serializa por sí solo.
4. Revisar reservas/asignaciones de flota, consumos/recepciones/reversas de inventario,
   litros facturados más reservados y aplicaciones contra importe de pago/saldo.
   No fijar UNIQUE por viaje hasta resolver facturación parcial.
5. Revisar clave idempotente, alcance, huella, resultado previo y atomicidad de efectos.
6. En migraciones autorizadas, evaluar datos existentes, locks, compatibilidad de
   despliegue y recuperación; separar operaciones incompatibles con transacción.
   No ejecutar migraciones como parte de una revisión.

## Verificaciones esenciales

Revisar protección contra escrituras concurrentes, rollback y duplicados con
conexiones independientes. Ejecutar verificaciones de datos solo con PostgreSQL 18
de pruebas aislado y tarea autorizada; SQLite no acredita estas garantías.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
