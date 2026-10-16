# 05. Base de datos: cómo leer el modelo

Una **tabla** guarda filas de una entidad o de un hecho. Una **columna** define un dato y su tipo; una **clave primaria (PK)** identifica la fila. Una **clave foránea (FK)** exige que una referencia apunte a otra fila existente. Un **índice** acelera búsquedas; cuando es UNIQUE también impide repetir una combinación. Un **CHECK** rechaza valores incompatibles. Un **trigger** es una función ejecutada por PostgreSQL al insertar, actualizar o eliminar: protege reglas incluso si una escritura no pasó por Vue.

El inventario se obtuvo aplicando las **44 migraciones actuales a PostgreSQL 18 temporal nuevo**, sin datos empresariales, y leyendo `pg_catalog`. Son **105 tablas, 801 columnas, 102 funciones y 384 triggers**. No se consultó ni migró `transporte_db`. Los diagramas muestran FK físicas; no se copiaron ciegamente los ER de diseño anteriores. Las tablas técnicas también están incluidas.

## Diccionarios por dominio

- [Infraestructura Laravel: 9 tablas](base-de-datos/infraestructura.md).
- [Acceso y Personal: 4 tablas](base-de-datos/acceso-y-personal.md).
- [Flota y Documentación: 9 tablas](base-de-datos/flota-y-documentacion.md).
- [Transporte y Despacho: 28 tablas](base-de-datos/transporte.md).
- [Taller Mecánico: 27 tablas](base-de-datos/taller.md).
- [Inventario: 7 tablas](base-de-datos/inventario.md).
- [Compras: 9 tablas](base-de-datos/compras.md).
- [Facturación administrativa y pagos: 7 tablas](base-de-datos/facturacion.md).
- [Gastos: 5 tablas](base-de-datos/gastos.md).
- [Funciones y triggers: propósito, mensajes de rechazo y referencias](base-de-datos/funciones-y-triggers.md).

## Índice de las 105 tablas

| Tabla | Responsable | Propósito |
| --- | --- | --- |
| [`billing_amount_decisions`](base-de-datos/facturacion.md#billing_amount_decisions) | Facturación administrativa y pagos | Aprobación/rechazo de esa versión de importe. |
| [`billing_guard`](base-de-datos/facturacion.md#billing_guard) | Facturación administrativa y pagos | Guardia técnica financiera, no estado de factura. |
| [`billing_invoice_items`](base-de-datos/facturacion.md#billing_invoice_items) | Facturación administrativa y pagos | Conceptos de viajes completos con importe aprobado exacto; un viaje no se factura dos veces. |
| [`billing_invoices`](base-de-datos/facturacion.md#billing_invoices) | Facturación administrativa y pagos | Cabecera de factura administrativa interna de un cliente y una moneda. |
| [`billing_operations`](base-de-datos/facturacion.md#billing_operations) | Facturación administrativa y pagos | Recibos idempotentes de propuestas, decisiones, facturas y pagos. |
| [`billing_payments`](base-de-datos/facturacion.md#billing_payments) | Facturación administrativa y pagos | Pagos parciales/totales registrados contra una factura; saldo derivado. |
| [`billing_trip_amounts`](base-de-datos/facturacion.md#billing_trip_amounts) | Facturación administrativa y pagos | Propuestas/versiones del importe manual y moneda de un viaje. |
| [`cache`](base-de-datos/infraestructura.md#cache) | Laravel | Valores de caché de Laravel cuando se selecciona el driver database. |
| [`cache_locks`](base-de-datos/infraestructura.md#cache_locks) | Laravel | Exclusión mutua temporal de la caché database; no es inventario ni autorización. |
| [`compartments`](base-de-datos/flota-y-documentacion.md#compartments) | Flota y Documentación | Compartimentos de una cisterna, incluso el lógico de una cisterna sin divisiones; capacidad exacta en litros. |
| [`current_fleet_documents`](base-de-datos/flota-y-documentacion.md#current_fleet_documents) | Flota y Documentación | Puntero único al documento actual por activo y tipo. |
| [`customers`](base-de-datos/transporte.md#customers) | Transporte y Despacho | Clientes externos de las solicitudes y de la facturación administrativa. |
| [`document_alert_reviews`](base-de-datos/flota-y-documentacion.md#document_alert_reviews) | Flota y Documentación | Constancia de revisión de una alerta, sin renovar ni alterar su documento. |
| [`document_configuration_snapshots`](base-de-datos/flota-y-documentacion.md#document_configuration_snapshots) | Flota y Documentación | Copia inmutable de los requisitos documentales aplicables al registrar cada documento. |
| [`document_types`](base-de-datos/flota-y-documentacion.md#document_types) | Flota y Documentación | Configuración documental vigente que el administrador puede gestionar. |
| [`documents`](base-de-datos/flota-y-documentacion.md#documents) | Flota y Documentación | Versión de un documento de activo con fechas y referencia privada del archivo. |
| [`driver_assignments`](base-de-datos/transporte.md#driver_assignments) | Transporte y Despacho | Asignación efectiva de un empleado conductor a un viaje. |
| [`driver_operation_receipts`](base-de-datos/transporte.md#driver_operation_receipts) | Transporte y Despacho | Idempotencia de reportes y lecturas del conductor, sin duplicar las entidades operativas. |
| [`driver_releases`](base-de-datos/transporte.md#driver_releases) | Transporte y Despacho | Liberación manual de una asignación de conductor. |
| [`employees`](base-de-datos/acceso-y-personal.md#employees) | Acceso y Personal | Persona trabajadora, con cuenta opcional única; reutilizada para conductores y mecánicos. |
| [`expense_decisions`](base-de-datos/gastos.md#expense_decisions) | Gastos | Decisión del administrador sobre una versión concreta, no comprobante de pago. |
| [`expense_operations`](base-de-datos/gastos.md#expense_operations) | Gastos | Recibos idempotentes de registrar, corregir, decidir y adjuntar comprobantes de gastos. |
| [`expense_receipts`](base-de-datos/gastos.md#expense_receipts) | Gastos | Metadatos de comprobantes privados de un gasto. |
| [`expense_revisions`](base-de-datos/gastos.md#expense_revisions) | Gastos | Concepto, categoría, importe, moneda, fecha y descripción de una versión del gasto. |
| [`expenses`](base-de-datos/gastos.md#expenses) | Gastos | Identidad de un gasto general o de un viaje, separada de sus revisiones. |
| [`failed_jobs`](base-de-datos/infraestructura.md#failed_jobs) | Laravel | Registro técnico de trabajos fallidos. |
| [`fleet_assets`](base-de-datos/flota-y-documentacion.md#fleet_assets) | Flota y Documentación | Identidad común de un activo de flota: código, tipo y estado de servicio. |
| [`fleet_impediment_resolutions`](base-de-datos/taller.md#fleet_impediment_resolutions) | Taller Mecánico | Resolución trazable de un impedimento, conservando su causa y autorización aplicable. |
| [`fleet_operating_impediments`](base-de-datos/taller.md#fleet_operating_impediments) | Taller Mecánico | Impedimentos de operación de un activo compartidos con disponibilidad de Transporte. |
| [`inventory_applications`](base-de-datos/inventario.md#inventory_applications) | Inventario | Aplicación de corrección aprobada que origina el movimiento. |
| [`inventory_corrections`](base-de-datos/inventario.md#inventory_corrections) | Inventario | Solicitud de conciliación inicial, ajuste o reversión compensatoria. |
| [`inventory_decisions`](base-de-datos/inventario.md#inventory_decisions) | Inventario | Decisión del administrador sobre la corrección. |
| [`inventory_movements`](base-de-datos/inventario.md#inventory_movements) | Inventario | Libro inmutable de deltas de bodega, custodia y consumido, con origen único. |
| [`inventory_operations`](base-de-datos/inventario.md#inventory_operations) | Inventario | Recibo idempotente de los comandos de Inventario. |
| [`inventory_returns`](base-de-datos/inventario.md#inventory_returns) | Inventario | Recepción física en bodega del sobrante de una entrega. |
| [`job_batches`](base-de-datos/infraestructura.md#job_batches) | Laravel | Metadatos de lotes de trabajos Laravel. |
| [`jobs`](base-de-datos/infraestructura.md#jobs) | Laravel | Trabajos pendientes de una cola database de Laravel. |
| [`liquid_products`](base-de-datos/transporte.md#liquid_products) | Transporte y Despacho | Catálogo de productos líquidos, reutilizado por las solicitudes y el producto histórico del viaje. |
| [`load_items`](base-de-datos/transporte.md#load_items) | Transporte y Despacho | Litros reales de carga por compartimento, con capacidad y código históricos. |
| [`migrations`](base-de-datos/infraestructura.md#migrations) | Laravel | Migraciones que Laravel considera aplicadas y su lote de ejecución. |
| [`operation_sites`](base-de-datos/transporte.md#operation_sites) | Transporte y Despacho | Sitios de carga/descarga utilizados por rutas y operaciones. |
| [`password_reset_tokens`](base-de-datos/infraestructura.md#password_reset_tokens) | Laravel | Soporte del esqueleto para tokens de recuperación; no acredita una interfaz pública de recuperación implementada. |
| [`purchase_fulfillment_operations`](base-de-datos/compras.md#purchase_fulfillment_operations) | Compras | Idempotencia y trazabilidad de crear órdenes y registrar recepciones. |
| [`purchase_order_items`](base-de-datos/compras.md#purchase_order_items) | Compras | Cantidad ordenada por renglón solicitado, sin exceder autorización. |
| [`purchase_orders`](base-de-datos/compras.md#purchase_orders) | Compras | Orden de compra creada desde solicitud aprobada. |
| [`purchase_receipt_items`](base-de-datos/compras.md#purchase_receipt_items) | Compras | Cantidades efectivamente recibidas por detalle de orden; cada una origina una entrada única. |
| [`purchase_receipts`](base-de-datos/compras.md#purchase_receipts) | Compras | Recepción física parcial/completa de una orden, responsable y fecha. |
| [`purchase_request_decisions`](base-de-datos/compras.md#purchase_request_decisions) | Compras | Decisión administrativa sobre la solicitud de compra. |
| [`purchase_request_items`](base-de-datos/compras.md#purchase_request_items) | Compras | Repuestos/cantidades solicitados y posible necesidad de Taller de origen. |
| [`purchase_requests`](base-de-datos/compras.md#purchase_requests) | Compras | Solicitud de compra con proveedor, motivo y responsable. |
| [`purchasing_operations`](base-de-datos/infraestructura.md#purchasing_operations) | Laravel | Recibos idempotentes de solicitudes y decisiones de compra. |
| [`roles`](base-de-datos/acceso-y-personal.md#roles) | Acceso y Personal | Catálogo de los cuatro roles técnicos aprobados. |
| [`routes`](base-de-datos/transporte.md#routes) | Transporte y Despacho | Ruta nombrada entre dos sitios operativos; no equivale a una asignación de recursos. |
| [`sessions`](base-de-datos/infraestructura.md#sessions) | Laravel | Sesiones persistidas cuando el driver es database; contiene información privada que no se expone en recursos JSON. |
| [`spare_parts`](base-de-datos/inventario.md#spare_parts) | Inventario | Catálogo de repuestos y unidad; no contiene una existencia ficticia. |
| [`suppliers`](base-de-datos/compras.md#suppliers) | Compras | Catálogo de proveedores reutilizado por Compras y reparaciones externas. |
| [`tankers`](base-de-datos/flota-y-documentacion.md#tankers) | Flota y Documentación | Especialización uno-a-uno del activo que es cisterna; indica divisiones físicas. |
| [`tractor_units`](base-de-datos/flota-y-documentacion.md#tractor_units) | Flota y Documentación | Especialización uno-a-uno del activo que es cabezal. |
| [`transport_assignment_conflicts`](base-de-datos/transporte.md#transport_assignment_conflicts) | Transporte y Despacho | Conflictos efectivos detectados durante una operación de asignación, vinculados a su evidencia. |
| [`transport_assignment_guard`](base-de-datos/transporte.md#transport_assignment_guard) | Transporte y Despacho | Guardia técnica de coordinación de asignaciones y operaciones relacionadas. |
| [`transport_assignment_operations`](base-de-datos/transporte.md#transport_assignment_operations) | Transporte y Despacho | Hecho auditado de asignar, sustituir o liberar recursos. |
| [`transport_authorization_applications`](base-de-datos/transporte.md#transport_authorization_applications) | Transporte y Despacho | Consumo de una decisión en un cambio/operación concreto; evita autorizar datos diferentes. |
| [`transport_authorization_decisions`](base-de-datos/transporte.md#transport_authorization_decisions) | Transporte y Despacho | Decisión administrativa sobre una solicitud de autorización. |
| [`transport_authorization_requests`](base-de-datos/transporte.md#transport_authorization_requests) | Transporte y Despacho | Intención de autorización con contenido exacto y objetivo/versiones. |
| [`transport_changes`](base-de-datos/transporte.md#transport_changes) | Transporte y Despacho | Auditoría de modificaciones de solicitud/viaje con snapshots y versiones. |
| [`transport_creation_receipts`](base-de-datos/transporte.md#transport_creation_receipts) | Transporte y Despacho | Idempotencia persistente P1-05 de altas de solicitudes y viajes, con snapshot original. |
| [`transport_requests`](base-de-datos/transporte.md#transport_requests) | Transporte y Despacho | Solicitud de un cliente para un solo producto, ruta, litros y fecha; puede producir varios viajes. |
| [`trip_asset_assignments`](base-de-datos/transporte.md#trip_asset_assignments) | Transporte y Despacho | Asignación histórica de cabezal o cisterna a un viaje. |
| [`trip_asset_releases`](base-de-datos/transporte.md#trip_asset_releases) | Transporte y Despacho | Liberación manual de una asignación de activo. |
| [`trip_events`](base-de-datos/transporte.md#trip_events) | Transporte y Despacho | Eventos e información recibida; un reporte de terminación no constituye finalización oficial. |
| [`trip_loads`](base-de-datos/transporte.md#trip_loads) | Transporte y Despacho | Cabecera de la única operación de carga de un viaje. |
| [`trip_odometer_readings`](base-de-datos/transporte.md#trip_odometer_readings) | Transporte y Despacho | Lecturas y revisiones de kilometraje vinculadas al viaje y a su asignación de cabezal. |
| [`trip_product_reviews`](base-de-datos/transporte.md#trip_product_reviews) | Transporte y Despacho | Evidencia de revisión del producto histórico; su existencia no habilita cambios de producto pendientes de regla. |
| [`trip_unloads`](base-de-datos/transporte.md#trip_unloads) | Transporte y Despacho | Cabecera de la única descarga del viaje. |
| [`trip_warning_resolutions`](base-de-datos/transporte.md#trip_warning_resolutions) | Transporte y Despacho | Registro de atención/resolución de una advertencia de viaje. |
| [`trip_warnings`](base-de-datos/transporte.md#trip_warnings) | Transporte y Despacho | Advertencia persistida asociada al viaje. |
| [`trips`](base-de-datos/transporte.md#trips) | Transporte y Despacho | Viaje de una solicitud con producto histórico y tres dimensiones separadas de estado. |
| [`unload_items`](base-de-datos/transporte.md#unload_items) | Transporte y Despacho | Litros descargados del detalle de carga correspondiente, incluso cero. |
| [`user_roles`](base-de-datos/acceso-y-personal.md#user_roles) | Acceso y Personal | Unión muchos-a-muchos entre cuentas y roles. |
| [`users`](base-de-datos/acceso-y-personal.md#users) | Acceso y Personal | Cuenta autenticable: identidad de acceso, hash de contraseña y activación; no es el empleado. |
| [`workshop_activities`](base-de-datos/taller.md#workshop_activities) | Taller Mecánico | Actividades realizadas y sus revisiones técnicas. |
| [`workshop_admission_exits`](base-de-datos/taller.md#workshop_admission_exits) | Taller Mecánico | Salida de un ingreso mediante el proceso autorizado; no se deriva de cerrar una orden. |
| [`workshop_admissions`](base-de-datos/taller.md#workshop_admissions) | Taller Mecánico | Ingreso físico oficial de un activo al taller, independiente del estado de sus órdenes. |
| [`workshop_attachments`](base-de-datos/taller.md#workshop_attachments) | Taller Mecánico | Metadatos de fotografías/documentos/facturas privados confirmados; el archivo está fuera de PostgreSQL. |
| [`workshop_authorization_applications`](base-de-datos/taller.md#workshop_authorization_applications) | Taller Mecánico | Aplicación auditable de una decisión, separada de pedirla y aprobarla. |
| [`workshop_authorization_decisions`](base-de-datos/taller.md#workshop_authorization_decisions) | Taller Mecánico | Aprobación o rechazo exclusivo del administrador. |
| [`workshop_authorization_requests`](base-de-datos/taller.md#workshop_authorization_requests) | Taller Mecánico | Petición administrativa de alcance, salida, rectificación u otra acción soportada, ligada a contenido exacto. |
| [`workshop_cost_records`](base-de-datos/taller.md#workshop_cost_records) | Taller Mecánico | Costos estimados/reales y sus revisiones, sin inventar conversiones. |
| [`workshop_diagnoses`](base-de-datos/taller.md#workshop_diagnoses) | Taller Mecánico | Diagnóstico técnico versionado previo al alcance/autorización. |
| [`workshop_external_repairs`](base-de-datos/taller.md#workshop_external_repairs) | Taller Mecánico | Reparación externa con proveedor, datos económicos y autorización correspondiente. |
| [`workshop_material_needs`](base-de-datos/taller.md#workshop_material_needs) | Taller Mecánico | Necesidades de repuestos comunicadas por Taller, reutilizadas en Compras. |
| [`workshop_mechanic_assignments`](base-de-datos/taller.md#workshop_mechanic_assignments) | Taller Mecánico | Participación asignada de un empleado mecánico en una orden. |
| [`workshop_mechanic_releases`](base-de-datos/taller.md#workshop_mechanic_releases) | Taller Mecánico | Liberación manual de esa participación, sin borrar su historia. |
| [`workshop_operations`](base-de-datos/taller.md#workshop_operations) | Taller Mecánico | Recibos, resultados y auditoría de comandos de Taller; también coordina reservas técnicas de adjuntos. |
| [`workshop_order_admission_links`](base-de-datos/taller.md#workshop_order_admission_links) | Taller Mecánico | Vinculación de órdenes independientes con un ingreso. |
| [`workshop_part_deliveries`](base-de-datos/taller.md#workshop_part_deliveries) | Taller Mecánico | Entrega física autorizada a una asignación de mecánico/orden; pasa de bodega a custodia, no a consumo. |
| [`workshop_part_usages`](base-de-datos/taller.md#workshop_part_usages) | Taller Mecánico | Uso real confirmado del material entregado; reduce custodia e incrementa consumido. |
| [`workshop_report_links`](base-de-datos/taller.md#workshop_report_links) | Taller Mecánico | Vínculo trazable entre reporte y orden. |
| [`workshop_reports`](base-de-datos/taller.md#workshop_reports) | Taller Mecánico | Reportes de avería versionados; varios pueden vincularse a una sola reparación. |
| [`workshop_scope_parts`](base-de-datos/taller.md#workshop_scope_parts) | Taller Mecánico | Repuestos y cantidades concretas propuestos para el alcance. |
| [`workshop_scope_tasks`](base-de-datos/taller.md#workshop_scope_tasks) | Taller Mecánico | Trabajos concretos propuestos dentro de un alcance. |
| [`workshop_scopes`](base-de-datos/taller.md#workshop_scopes) | Taller Mecánico | Versión del alcance inicial, ampliación o revisión de una misma reparación. |
| [`workshop_time_events`](base-de-datos/taller.md#workshop_time_events) | Taller Mecánico | Inicio, pausa, reanudación y finalización para calcular tiempo efectivo. |
| [`workshop_warnings`](base-de-datos/taller.md#workshop_warnings) | Taller Mecánico | Advertencias del taller, por ejemplo sobre asignaciones/viajes preexistentes. |
| [`workshop_work_orders`](base-de-datos/taller.md#workshop_work_orders) | Taller Mecánico | Orden oficial de una reparación o servicio, con estado y prioridad propios. |

## Tipos y decisiones de modelado

- Los catálogos y entidades nuevas usan UUID: identificadores como `a1...` que no dependen de un contador global. `users.id` conserva bigint por compatibilidad; las referencias de actores respetan ese tipo. No convertirlo a UUID sin un plan distinto.
- `numeric` almacena decimales exactos. No asumir precisión por el nombre: cada ficha muestra el tipo final y los CHECK de escala/rango. Los litros operativos usan dos decimales; Inventario admite cantidades más precisas según sus constraints y unidad. Dinero usa dos decimales, CRC/USD separados.
- `timestamptz` representa un instante; la interfaz lo presenta en Costa Rica. `date` representa un día, importante para conservar completa la fecha de vencimiento. No aplicar medianoche UTC a una regla de día local.
- `jsonb` guarda un objeto estructurado: snapshots, contenido aprobado o resultado de operación. No implica ausencia de validación ni autorización para exponer todo su contenido.
- Las columnas `*_snapshot` preservan cómo era un dato al registrar el hecho. Leer el catálogo actual en su lugar reinterpreta la historia.
- `previous_*`, `root_record_id`, `revision` y `supersedes_id` enlazan correcciones/versiones. No son una invitación a actualizar el registro original.

## Transacciones, concurrencia y bloqueos

Una **transacción** agrupa escrituras: todas confirman (**commit**) o se revierten (**rollback**). Ejemplo: una recepción y su entrada de inventario no deben separarse en dos confirmaciones. Las transacciones anidadas de Laravel usan savepoints, no dos commits independientes de negocio.

La **concurrencia** aparece cuando dos peticiones intentan decidir sobre el mismo estado. Una validación HTTP previa no basta: ambas podrían ver saldo disponible. `lockForUpdate()` bloquea las filas leídas; `pg_advisory_xact_lock(873210)` es un candado transaccional compartido por varias escrituras administrativas. Las guardias `transport_assignment_guard` y `billing_guard` fuerzan coordinación/versionado técnico. Este diseño prioriza integridad, pero serializa trabajo: medir antes de cambiarlo, no quitar el lock para “hacerlo rápido”.

`READ COMMITTED` permite releer lo confirmado después de esperar; `REPEATABLE READ, READ ONLY` da a reportes/auditoría una vista consistente para filas, totales y paginación. Una excepción `40001`, deadlock `40P01` o espera de lock `55P03` debe tratarse según el contrato del módulo, sin presentar éxito ni reenviar ciegamente.

Una restricción **diferible** se comprueba al final de la transacción, permitiendo insertar hechos relacionados antes de que el conjunto esté completo. Transporte ejecuta además `SET CONSTRAINTS ALL IMMEDIATE` para detectar incompatibilidades antes de responder. Las fichas conservan `DEFERRABLE` cuando existe; no todas las restricciones lo son.

## Recibos idempotentes y hechos inmutables

La **idempotencia** hace que reenviar la misma intención no cree otro resultado. La clave viene del cliente; el recibo conserva usuario, ámbito, huella del contenido y resultado. Escribir entidad y recibo en una misma transacción evita confirmar una mitad. Misma clave con otro contenido produce conflicto; una clave nueva permite una operación legítimamente distinta.

No todos los recibos tienen el mismo contrato. `transport_creation_receipts` devuelve el snapshot de alta aun cuando el viaje cambió; `purchase_fulfillment_operations` recupera su orden con relaciones; Inventario/Taller conservan resultados de comandos. Las tablas canónicas de Auditoría evitan contar estos hijos como eventos nuevos duplicados.

Un **movimiento inmutable** no se modifica ni borra: se agrega una operación compensatoria autorizada. En inventario, entregar cambia bodega/custodia; usar cambia custodia/consumido; devolver exige recepción física. La suma se interpreta por origen, unidad y dimensiones, no como un campo `stock` editable en `spare_parts`.

## Cómo estudiar una migración

1. Abrir la migración que crea la tabla y luego las correctivas enlazadas.
2. Separar estructura (columnas/FK/índices) de funciones y triggers.
3. Buscar el servicio que coordina la operación y su prueba de regresión.
4. Revisar qué cambió después: cierre de cancelados, protección de cargas/descargas, stock, dinero, recibos y snapshots se agregaron incrementalmente.
5. Nunca editar una migración publicada para “arreglar” una instalación. Crear otra, probarla en PostgreSQL aislado y planificar actualización/recuperación. Algunos `down()` rechazan rollback automático para preservar historia: no usar `migrate:fresh` en datos reales.

Las fichas enseñan consultas de lectura, no SQL alternativo para saltarse permisos. Los ejemplos de creación autorizada están en [casos prácticos](12-casos-practicos.md).
