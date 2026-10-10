---
name: transport-vue-typescript
description: "Desarrollar o revisar interfaz Vue 3 y TypeScript: componentes, Composition API, formularios, API, estados, accesibilidad y permisos visibles; usar para frontend autorizado."
---

# transport-vue-typescript

## Fuentes y alcance

Leer AGENTS.md y las secciones relevantes de docs/propuesta-tecnica.md,
docs/modelo-er.mmd, docs/tabla-entidades.md, docs/tabla-relaciones.md y
docs/arquitectura.mmd. Aplicar reglas generales por referencia, sin copiar documentos.
Distinguir requisitos confirmados, decisiones aprobadas, propuestas y pendientes.
No inventar reglas ni resolver pendientes; pedir aclaración cuando afecten la tarea.
Ante cambios en el modelo de negocio, usar transport-model-review leyendo
../transport-model-review/SKILL.md antes de implementar.

## Procedimiento

1. Inspeccionar herramientas y contratos existentes; confirmar integración aprobada.
2. Usar Composition API, TypeScript estricto y tipos de props, eventos, formularios
   y API. Evitar any; validar entradas externas cuando corresponda, pues tipos
   estáticos no validan respuestas en ejecución. Extraer componentes/composables
   reutilizables ante necesidades concretas, sin estado global innecesario.
3. Crear formularios accesibles con etiquetas, teclado, errores por campo y mensajes
   en español. La validación local no sustituye validación y reglas del servidor.
4. Manejar carga, vacío, éxito, errores, cancelación y respuestas fuera de orden;
   preservar entradas ante fallos y evitar envíos accidentales duplicados.
5. Mostrar acciones según permisos y estados del servidor; ocultar botones no
   autoriza ni protege registros. No inventar permisos, contratos ni redondeos.

## Verificaciones esenciales

Verificar tipos, build e interacciones con herramientas disponibles: formulario
inválido, API fallida, sesión expirada, acceso denegado y navegación por teclado.
Pruebas integradas requieren datos aislados. Para sincronización usar
transport-driver-pwa cuando corresponda.

## Resultado

Informar concisamente hallazgos, cambios, bloqueos y verificaciones realmente
ejecutadas. Citar documento y sección para discrepancias y pendientes; indicar
limitaciones sin afirmar garantías no comprobadas.
