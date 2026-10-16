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

## Ajuste responsivo de pantallas internas

Base: `38b1972`. Se revisaron Administración/Catálogos, Flota, Transporte, Taller,
Inventario, Compras, Facturación, Gastos, Reportes y Alertas mediante sus fixtures
existentes de interfaz, sin cambiar API, permisos o reglas de negocio.

Se reprodujeron desbordamientos de página a 320 px con referencias, conceptos y
texto descriptivo largos sin espacios, admitidos por los contratos actuales:

- Facturación: referencia y nombre del cliente en el detalle de factura.
- Gastos: concepto, descripción y versiones mostradas en el detalle.

La corrección aplica `overflow-wrap:anywhere` solo a los párrafos afectados de
`BillingAndPayments/InvoicesPanel.vue` y al detalle de `Expenses/ExpensesPanel.vue`.
No recorta ni elimina datos, no oculta el desbordamiento de la página y no modifica
la distribución de escritorio, los cálculos o el formato monetario.

Las demás pantallas revisadas ya se ajustaban a los viewports probados: se conservaron
sin cambios. Las tablas mantienen su desplazamiento interno, con comprobación real
de foco/ArrowRight y acceso a la última acción después de desplazarlas. No se rediseñó
la tabla ni se ocultaron acciones para hacer pasar la prueba. Se comprobó además que
los enlaces del menú permanecen dentro del viewport y que la navegación presentada
corresponde al administrador, secretaria y mecánico según los contratos sintéticos.

Verificación: 23 casos frontend distintos correctos (10 de diseño responsivo,
8 de Facturación y 5 de Gastos). Tras ampliar el nombre del cliente y las
comprobaciones de navegación se repitieron únicamente los 10 casos responsivos.
Estos cubren anchos de 320, 360, 390, 768 y 1280 px. Los primeros ensayos con datos
habituales ya pasaban; los textos largos reprodujeron los dos defectos corregidos.
TypeScript, build y diff check correctos; se mantiene el aviso anterior de
importación estática/dinámica de `DriverPortal/local.ts`.

Son pruebas Chromium con APIs sintéticas, no una nueva validación de permisos del
servidor ni pruebas en teléfonos físicos. No se repitieron backend/E2E ni Pint:
no se modificó PHP. No se alteraron la PWA, estilos globales, otras pantallas,
`.env`, `transporte_db` ni el servidor. Queda comprobar táctil/teclados virtuales,
Safari/iOS, zoom y otras combinaciones de datos con los dispositivos reales.
