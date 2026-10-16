# Confiabilidad de formularios existentes

Base: `0140ec0`. Alcance: correcciones puntuales de presentación y estados;
sin cambios de API, permisos, reglas, migraciones, P0-04 ni idempotencia P1-05.

| Problema comprobado | Corrección | Regresión |
| --- | --- | --- |
| En Flota, buscar durante un guardado disparaba el watcher que cierra el formulario; un 422 podía llegar con el formulario oculto. | La búsqueda usa el bloqueo existente de carga/envío/resultado incierto. | Respuesta demorada, búsqueda y botón deshabilitados, segundo submit bloqueado; código y formulario permanecen tras 422. |
| Validaciones de gastos, importes/pagos, inventario y solicitudes de compra solo aparecían en el resumen. | Se reutiliza `FieldError` en campos concretos, con ID único, `aria-invalid` y `aria-describedby`; se conserva el resumen y los valores. Los selectores de proveedor/repuesto reutilizan sus errores existentes. | Importe de gasto/pago, cantidad de inventario y `items.0.quantity` de compra: error asociado y contenido conservado. |
| Faltaba una indicación visible durante el envío en Gastos, Facturación e Inventario/Compras. | Mensaje de estado mientras la petición está en curso, independiente del estado incierto y de la confirmación. | Petición demorada sin éxito prematuro; segundo submit no produce otra petición. |
| Propagar errores al campo podía llevarlos a otro formulario al cambiar de sección/operación. | Limpiar los errores de campo al cambiar de pestaña de Facturación o abrir otra operación de Inventario/Compras. | Un error de pago no marca el importe de un viaje; otra operación de inventario no hereda la cantidad inválida anterior. |

Se revisaron los bloqueos, conservación y manejo de errores existentes de Transporte,
Flota, Taller, Inventario, Compras, Facturación y Gastos. Los formularios revisados de
Transporte y Taller conservan su implementación; no se los refactorizó. Las respuestas
inciertas siguen requiriendo recuperación manual, sin reintentos automáticos ni cambio
de claves. Ninguna respuesta fallida se convierte en confirmación por estas mejoras.

## Verificación ejecutada

- 28 casos frontend distintos en Chromium con APIs sintéticas: Gastos 5, Facturación 8,
  Inventario/Compras 10 y Administración/Catálogos/Flota 5.
- La regresión de Gastos falló con el bundle anterior al no existir `aria-invalid`;
  pasó con la corrección. En la primera ejecución conjunta pasaron 27/28: el nuevo
  test de Flota tenía un selector ambiguo entre resumen y error de campo. Se ajustó
  al ID del error; la ejecución final de las suites afectadas por los últimos ajustes
  pasó 19/19. Se reutilizaron los otros nueve casos aprobados sin cambios funcionales.
- `npm run test:frontend-api`: 29 contratos correctos.
- `npm run typecheck`, `TRANSPORTE_BUILD_OFFLINE=1 npm run build` y
  `git diff --check`: correctos. Continúa el aviso previo de importación
  estática/dinámica de `DriverPortal/local.ts`.
- Sin PHP modificado: no se requirió Pint ni repetir pruebas backend o E2E de
  persistencia. Estas pruebas de navegador no se presentan como integración real.

## Límites

La asociación local cubre los campos modificados, no todos los errores posibles.
Los errores generales y otros detalles anidados de recepción/orden conservan el
resumen existente. No se añadieron persistencia de borradores ni recuperación de
operaciones nuevas: se mantienen los mecanismos y límites de cada módulo.
No se modificaron `.env`, `transporte_db`, servicios ni servidor.
