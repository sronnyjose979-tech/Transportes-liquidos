---
name: transport-laravel-module
description: "Implementar o revisar módulos Laravel 13 y casos de uso autorizados con SOLID, DRY, KISS, Eloquent, validación, autorización, API JSON, transacciones y pruebas."
---

# transport-laravel-module

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Inspeccionar versiones y convenciones; confirmar límites del módulo y contratos,
   incluido API JSON, antes de introducir arquitectura o dependencias.
2. Separar HTTP, aplicación, dominio e infraestructura; mantener controladores pequeños.
   Usar Form Requests, Policies/Gates y Resources según necesidad, sin repositorios,
   interfaces o capas que solo dupliquen Laravel. Aplicar SOLID, DRY y KISS.
3. Configurar relaciones y asignación masiva explícita; prevenir N+1 y paginar.
   Revisar asociaciones de PK compuesta y especializaciones PK/FK sin asumir que
   Eloquent las soporta automáticamente como modelos ordinarios.
4. Coordinar servicios de aplicación y transacciones; autorizar operaciones por registro,
   revisar concurrencia/idempotencia y despachar efectos externos después del commit.
5. Mantener escrituras en su módulo: Taller pide consumos a Inventario y Compras
   entradas por recepción, sin duplicar efectos ni romper contratos aprobados.

## Verificaciones esenciales

Cubrir éxito, validación, autorización por registro, contrato JSON, rollback y
consultas. Usar herramientas instaladas; pruebas con DB solo en entorno aislado
verificado. No implementar transiciones propuestas como aprobadas.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
