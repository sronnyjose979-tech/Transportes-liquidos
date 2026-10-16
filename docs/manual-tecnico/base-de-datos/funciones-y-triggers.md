# Funciones y triggers PostgreSQL

[Volver al modelo de datos](../05-base-de-datos.md).

PL/pgSQL es el lenguaje utilizado por estas funciones en PostgreSQL. `NEW` representa la fila propuesta y `OLD` la anterior. `RAISE EXCEPTION` aborta la escritura; `PERFORM` llama una función por su efecto. Una función de trigger puede proteger varias tablas; no equivale a un endpoint HTTP. Los siguientes mensajes son condiciones expresadas por el código, no ejemplos de errores que deban mostrarse sin sanear al navegador.

## billing_complete

Firma: `billing_complete()`.

Tablas con trigger directo: [`billing_amount_decisions`](facturacion.md#billing_amount_decisions), [`billing_invoices`](facturacion.md#billing_invoices), [`billing_operations`](facturacion.md#billing_operations), [`billing_payments`](facturacion.md#billing_payments), [`billing_trip_amounts`](facturacion.md#billing_trip_amounts).

Rechazos identificados en su definición: `Recibo idempotente incompatible`; `Factura sin detalles`; `Operación sin auditoría idempotente`.

Definición/evolución: [2026_10_14_000360_create_administrative_billing](../../../database/migrations/2026_10_14_000360_create_administrative_billing.php).

## billing_immutable

Firma: `billing_immutable()`.

Tablas con trigger directo: [`billing_amount_decisions`](facturacion.md#billing_amount_decisions), [`billing_invoice_items`](facturacion.md#billing_invoice_items), [`billing_invoices`](facturacion.md#billing_invoices), [`billing_operations`](facturacion.md#billing_operations), [`billing_payments`](facturacion.md#billing_payments), [`billing_trip_amounts`](facturacion.md#billing_trip_amounts).

Rechazos identificados en su definición: `Historial financiero inmutable`.

Definición/evolución: [2026_10_14_000360_create_administrative_billing](../../../database/migrations/2026_10_14_000360_create_administrative_billing.php).

## billing_validate

Firma: `billing_validate()`.

Tablas con trigger directo: [`billing_amount_decisions`](facturacion.md#billing_amount_decisions), [`billing_invoice_items`](facturacion.md#billing_invoice_items), [`billing_invoices`](facturacion.md#billing_invoices), [`billing_operations`](facturacion.md#billing_operations), [`billing_payments`](facturacion.md#billing_payments), [`billing_trip_amounts`](facturacion.md#billing_trip_amounts).

Rechazos identificados en su definición: `Viaje ya facturado; requiere procedimiento compensatorio`; `Corrección requiere antecedente exacto`; `Antecedente ajeno o sustituido`; `Alcance sustituido o facturado`; `Cliente histórico incorrecto`; `Viaje, moneda, importe o aprobación incompatible`; `Pago supera saldo, factura no confirmada o moneda incompatible`; `Responsable no autorizado`.

Definición/evolución: [2026_10_14_000360_create_administrative_billing](../../../database/migrations/2026_10_14_000360_create_administrative_billing.php).

## document_alert_validate_review

Firma: `document_alert_validate_review()`.

Tablas con trigger directo: [`document_alert_reviews`](flota-y-documentacion.md#document_alert_reviews).

Rechazos identificados en su definición: `Revisión documental no autorizada`; `Documento actual con vencimiento requerido`.

Definición/evolución: [2026_10_16_000390_create_document_alert_reviews](../../../database/migrations/2026_10_16_000390_create_document_alert_reviews.php).

## driver_receipt_validate

Firma: `driver_receipt_validate()`.

Tablas con trigger directo: [`driver_operation_receipts`](transporte.md#driver_operation_receipts).

Llama a: [`trip_operation_actor`](#trip_operation_actor).

Rechazos identificados en su definición: `Driver receipt report mismatch`; `Driver receipt odometer mismatch`.

Definición/evolución: [2026_10_14_000380_create_driver_operation_receipts](../../../database/migrations/2026_10_14_000380_create_driver_operation_receipts.php).

## expense_complete

Firma: `expense_complete()`.

Tablas con trigger directo: [`expense_decisions`](gastos.md#expense_decisions), [`expense_operations`](gastos.md#expense_operations), [`expense_receipts`](gastos.md#expense_receipts), [`expense_revisions`](gastos.md#expense_revisions), [`expenses`](gastos.md#expenses).

Rechazos identificados en su definición: `Recibo idempotente incompatible`; `Gasto sin registro`; `Registro sin auditoría`.

Definición/evolución: [2026_10_14_000370_create_administrative_expenses](../../../database/migrations/2026_10_14_000370_create_administrative_expenses.php).

## expense_validate

Firma: `expense_validate()`.

Tablas con trigger directo: [`expense_decisions`](gastos.md#expense_decisions), [`expense_operations`](gastos.md#expense_operations), [`expense_receipts`](gastos.md#expense_receipts), [`expense_revisions`](gastos.md#expense_revisions), [`expenses`](gastos.md#expenses).

Rechazos identificados en su definición: `Autor inicial incompatible`; `Corrección incompatible`; `Versión sustituida`; `Decisión reservada`; `Corrección reservada`; `Asignación o responsable incompatible`; `Responsable no autorizado`.

Definición/evolución: [2026_10_14_000370_create_administrative_expenses](../../../database/migrations/2026_10_14_000370_create_administrative_expenses.php).

## fleet_check_root

Firma: `fleet_check_root(root_id uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Fleet asset requires exactly one specialization matching its type`; `Tanker requires compartments; without physical partitions it requires exactly one`.

Definición/evolución: [2026_10_10_000030_create_fleet](../../../database/migrations/2026_10_10_000030_create_fleet.php).

## fleet_document_internal_reference

Firma: `fleet_document_internal_reference()`.

Tablas con trigger directo: [`current_fleet_documents`](flota-y-documentacion.md#current_fleet_documents), [`document_configuration_snapshots`](flota-y-documentacion.md#document_configuration_snapshots).

Rechazos identificados en su definición: `Document references may only be written during document registration`.

Definición/evolución: [2026_10_11_000050_track_current_fleet_documents](../../../database/migrations/2026_10_11_000050_track_current_fleet_documents.php).

## fleet_document_preserve_history

Firma: `fleet_document_preserve_history()`.

Tablas con trigger directo: [`current_fleet_documents`](flota-y-documentacion.md#current_fleet_documents), [`document_alert_reviews`](flota-y-documentacion.md#document_alert_reviews), [`document_configuration_snapshots`](flota-y-documentacion.md#document_configuration_snapshots), [`documents`](flota-y-documentacion.md#documents).

Rechazos identificados en su definición: `Fleet documents are append-only`.

Definición/evolución: [2026_10_11_000040_create_fleet_documents](../../../database/migrations/2026_10_11_000040_create_fleet_documents.php), [2026_10_11_000050_track_current_fleet_documents](../../../database/migrations/2026_10_11_000050_track_current_fleet_documents.php), [2026_10_16_000390_create_document_alert_reviews](../../../database/migrations/2026_10_16_000390_create_document_alert_reviews.php).

## fleet_document_register_current

Firma: `fleet_document_register_current()`.

Tablas con trigger directo: [`documents`](flota-y-documentacion.md#documents).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_11_000050_track_current_fleet_documents](../../../database/migrations/2026_10_11_000050_track_current_fleet_documents.php).

## fleet_document_type_validate_expiration

Firma: `fleet_document_type_validate_expiration()`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Existing documents require expiration dates`.

Definición/evolución: [2026_10_11_000040_create_fleet_documents](../../../database/migrations/2026_10_11_000040_create_fleet_documents.php), [2026_10_11_000050_track_current_fleet_documents](../../../database/migrations/2026_10_11_000050_track_current_fleet_documents.php).

## fleet_document_validate_expiration

Firma: `fleet_document_validate_expiration()`.

Tablas con trigger directo: [`documents`](flota-y-documentacion.md#documents).

Rechazos identificados en su definición: `Expiration date required`.

Definición/evolución: [2026_10_11_000040_create_fleet_documents](../../../database/migrations/2026_10_11_000040_create_fleet_documents.php).

## fleet_lock_root

Firma: `fleet_lock_root()`.

Tablas con trigger directo: [`compartments`](flota-y-documentacion.md#compartments), [`tankers`](flota-y-documentacion.md#tankers), [`tractor_units`](flota-y-documentacion.md#tractor_units).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_10_000030_create_fleet](../../../database/migrations/2026_10_10_000030_create_fleet.php).

## fleet_reject_truncate

Firma: `fleet_reject_truncate()`.

Tablas con trigger directo: [`compartments`](flota-y-documentacion.md#compartments), [`tankers`](flota-y-documentacion.md#tankers), [`tractor_units`](flota-y-documentacion.md#tractor_units).

Rechazos identificados en su definición: `TRUNCATE cannot preserve fleet aggregate integrity; use transactional DELETE`.

Definición/evolución: [2026_10_10_000030_create_fleet](../../../database/migrations/2026_10_10_000030_create_fleet.php).

## fleet_validate_roots

Firma: `fleet_validate_roots()`.

Tablas con trigger directo: [`compartments`](flota-y-documentacion.md#compartments), [`fleet_assets`](flota-y-documentacion.md#fleet_assets), [`tankers`](flota-y-documentacion.md#tankers), [`tractor_units`](flota-y-documentacion.md#tractor_units).

Llama a: [`fleet_check_root`](#fleet_check_root).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_10_000030_create_fleet](../../../database/migrations/2026_10_10_000030_create_fleet.php).

## inventory_sources

Firma: `inventory_sources()`.

Tablas con trigger directo: [`inventory_applications`](inventario.md#inventory_applications), [`inventory_returns`](inventario.md#inventory_returns), [`workshop_part_deliveries`](taller.md#workshop_part_deliveries), [`workshop_part_usages`](taller.md#workshop_part_usages).

Rechazos identificados en su definición: `Entrega contabilizada: rectificación física requiere autorización específica`; `Entrega histórica no conciliada`; `Solo el mecánico responsable confirma su uso`.

Definición/evolución: [2026_10_14_000330_create_inventory_ledger](../../../database/migrations/2026_10_14_000330_create_inventory_ledger.php).

## inventory_unit_history

Firma: `inventory_unit_history()`.

Tablas con trigger directo: [`spare_parts`](inventario.md#spare_parts).

Rechazos identificados en su definición: `Unidad de inventario histórico inmutable`.

Definición/evolución: [2026_10_14_000330_create_inventory_ledger](../../../database/migrations/2026_10_14_000330_create_inventory_ledger.php).

## inventory_validate

Firma: `inventory_validate()`.

Tablas con trigger directo: [`inventory_applications`](inventario.md#inventory_applications), [`inventory_corrections`](inventario.md#inventory_corrections), [`inventory_decisions`](inventario.md#inventory_decisions), [`inventory_movements`](inventario.md#inventory_movements), [`inventory_returns`](inventario.md#inventory_returns).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Unidad incompatible`; `Reversión incompatible`; `Fecha anterior a solicitud`; `Decisión no aprobada o fecha incompatible`; `Entrega no conciliada o fecha incompatible`; `Conciliación inicial/unidad requerida`; `Movimiento no autorizado`; `Actor/fecha incompatible`; `Repuesto incompatible`; `Entrega incompatible`; `Uso incompatible`; `Devolución incompatible`; `Recepción incompatible`; `Existencias insuficientes o fuera de rango`; `Consumo negativo`; `Cantidad superior a custodia disponible`.

Definición/evolución: [2026_10_14_000330_create_inventory_ledger](../../../database/migrations/2026_10_14_000330_create_inventory_ledger.php).

## purchasing_complete

Firma: `purchasing_complete()`.

Tablas con trigger directo: [`purchase_request_decisions`](compras.md#purchase_request_decisions), [`purchase_requests`](compras.md#purchase_requests), [`purchasing_operations`](infraestructura.md#purchasing_operations).

Rechazos identificados en su definición: `Solicitud sin líneas`; `Solicitud sin operación auditada`; `Decisión sin operación auditada`; `Resultado confirmado incompatible`; `Actor de solicitud incompatible`; `Destino de decisión incompatible`.

Definición/evolución: [2026_10_13_000320_create_purchase_requests](../../../database/migrations/2026_10_13_000320_create_purchase_requests.php), [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php).

## purchasing_fulfillment_complete

Firma: `purchasing_fulfillment_complete()`.

Tablas con trigger directo: [`purchase_fulfillment_operations`](compras.md#purchase_fulfillment_operations), [`purchase_orders`](compras.md#purchase_orders), [`purchase_receipts`](compras.md#purchase_receipts).

Rechazos identificados en su definición: `Orden incompleta o no auditada`; `Recepción incompleta o no auditada`; `Operación incompatible`.

Definición/evolución: [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php).

## purchasing_fulfillment_validate

Firma: `purchasing_fulfillment_validate()`.

Tablas con trigger directo: [`purchase_fulfillment_operations`](compras.md#purchase_fulfillment_operations), [`purchase_order_items`](compras.md#purchase_order_items), [`purchase_orders`](compras.md#purchase_orders), [`purchase_receipt_items`](compras.md#purchase_receipt_items), [`purchase_receipts`](compras.md#purchase_receipts).

Rechazos identificados en su definición: `Solicitud no autorizada para proveedor`; `Cantidad no autorizada o alcance confirmado`; `Recepción ajena, confirmada o superior a autorización`; `Responsable no habilitado`.

Definición/evolución: [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php).

## purchasing_immutable

Firma: `purchasing_immutable()`.

Tablas con trigger directo: [`purchase_fulfillment_operations`](compras.md#purchase_fulfillment_operations), [`purchase_order_items`](compras.md#purchase_order_items), [`purchase_orders`](compras.md#purchase_orders), [`purchase_receipt_items`](compras.md#purchase_receipt_items), [`purchase_receipts`](compras.md#purchase_receipts), [`purchase_request_decisions`](compras.md#purchase_request_decisions), [`purchase_request_items`](compras.md#purchase_request_items), [`purchase_requests`](compras.md#purchase_requests), [`purchasing_operations`](infraestructura.md#purchasing_operations).

Rechazos identificados en su definición: `Registro de compras inmutable`.

Definición/evolución: [2026_10_13_000320_create_purchase_requests](../../../database/migrations/2026_10_13_000320_create_purchase_requests.php), [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php).

## purchasing_receipt_stock_complete

Firma: `purchasing_receipt_stock_complete()`.

Tablas con trigger directo: [`inventory_movements`](inventario.md#inventory_movements), [`purchase_receipt_items`](compras.md#purchase_receipt_items).

Rechazos identificados en su definición: `Recepción sin origen de compra`; `Recepción sin entrada exacta de inventario`.

Definición/evolución: [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php).

## purchasing_unit_compatibility

Firma: `purchasing_unit_compatibility()`.

Tablas con trigger directo: [`purchase_request_decisions`](compras.md#purchase_request_decisions), [`spare_parts`](inventario.md#spare_parts).

Rechazos identificados en su definición: `La unidad está vinculada a una compra autorizada; no hay conversión aprobada`; `La unidad cambió desde la solicitud; revisar cantidades antes de autorizar`.

Definición/evolución: [2026_10_14_000350_protect_approved_purchase_units](../../../database/migrations/2026_10_14_000350_protect_approved_purchase_units.php).

## purchasing_validate

Firma: `purchasing_validate()`.

Tablas con trigger directo: [`purchase_request_decisions`](compras.md#purchase_request_decisions), [`purchase_request_items`](compras.md#purchase_request_items), [`purchase_requests`](compras.md#purchase_requests), [`purchasing_operations`](infraestructura.md#purchasing_operations).

Rechazos identificados en su definición: `Alcance ya confirmado`; `Repuesto histórico incompatible`; `Necesidad de otro repuesto`; `Administrador activo requerido`.

Definición/evolución: [2026_10_13_000320_create_purchase_requests](../../../database/migrations/2026_10_13_000320_create_purchase_requests.php), [2026_10_14_000340_create_purchase_orders_and_receipts](../../../database/migrations/2026_10_14_000340_create_purchase_orders_and_receipts.php), [2026_10_14_000345_enable_office_purchase_requests](../../../database/migrations/2026_10_14_000345_enable_office_purchase_requests.php).

## transport_assignment_actor

Firma: `transport_assignment_actor(actor bigint)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`transport_require_role`](#transport_require_role).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php), [2026_10_12_000150_protect_recorded_volume_differences](../../../database/migrations/2026_10_12_000150_protect_recorded_volume_differences.php).

## transport_assignment_application

Firma: `transport_assignment_application()`.

Tablas con trigger directo: [`transport_authorization_applications`](transporte.md#transport_authorization_applications).

Llama a: [`transport_assignment_actor`](#transport_assignment_actor).

Rechazos identificados en su definición: `Approval does not cover assignment operation`; `Substitution authorization type mismatch`.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_assignment_conflict_record

Firma: `transport_assignment_conflict_record()`.

Tablas con trigger directo: [`transport_assignment_conflicts`](transporte.md#transport_assignment_conflicts).

Rechazos identificados en su definición: `Conflict identity mismatch`.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_assignment_decision

Firma: `transport_assignment_decision()`.

Tablas con trigger directo: [`transport_authorization_decisions`](transporte.md#transport_authorization_decisions).

Llama a: [`transport_assignment_actor`](#transport_assignment_actor), [`transport_require_role`](#transport_require_role).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_assignment_integrity

Firma: `transport_assignment_integrity()`.

Tablas con trigger directo: [`transport_assignment_operations`](transporte.md#transport_assignment_operations), [`trips`](transporte.md#trips).

Llama a: [`transport_check_trip_assignments`](#transport_check_trip_assignments).

Rechazos identificados en su definición: `Incomplete assignment operation`; `Conflict history incomplete`; `Conflicts require administrator approval`; `Empty tanker verification procedure pending`; `Substitution requires administrator approval`.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_assignment_lock

Firma: `transport_assignment_lock()`.

Tablas con trigger directo: [`driver_assignments`](transporte.md#driver_assignments), [`driver_operation_receipts`](transporte.md#driver_operation_receipts), [`driver_releases`](transporte.md#driver_releases), [`fleet_assets`](flota-y-documentacion.md#fleet_assets), [`fleet_impediment_resolutions`](taller.md#fleet_impediment_resolutions), [`fleet_operating_impediments`](taller.md#fleet_operating_impediments), [`load_items`](transporte.md#load_items), [`transport_assignment_conflicts`](transporte.md#transport_assignment_conflicts), [`transport_assignment_operations`](transporte.md#transport_assignment_operations), [`trip_asset_assignments`](transporte.md#trip_asset_assignments), [`trip_asset_releases`](transporte.md#trip_asset_releases), [`trip_events`](transporte.md#trip_events), [`trip_loads`](transporte.md#trip_loads), [`trip_odometer_readings`](transporte.md#trip_odometer_readings), [`trip_unloads`](transporte.md#trip_unloads), [`trips`](transporte.md#trips), [`unload_items`](transporte.md#unload_items), [`workshop_activities`](taller.md#workshop_activities), [`workshop_admission_exits`](taller.md#workshop_admission_exits), [`workshop_admissions`](taller.md#workshop_admissions), [`workshop_attachments`](taller.md#workshop_attachments), [`workshop_authorization_applications`](taller.md#workshop_authorization_applications), [`workshop_authorization_decisions`](taller.md#workshop_authorization_decisions), [`workshop_authorization_requests`](taller.md#workshop_authorization_requests), [`workshop_diagnoses`](taller.md#workshop_diagnoses), [`workshop_mechanic_assignments`](taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](taller.md#workshop_mechanic_releases), [`workshop_operations`](taller.md#workshop_operations), [`workshop_order_admission_links`](taller.md#workshop_order_admission_links), [`workshop_report_links`](taller.md#workshop_report_links), [`workshop_reports`](taller.md#workshop_reports), [`workshop_scope_parts`](taller.md#workshop_scope_parts), [`workshop_scope_tasks`](taller.md#workshop_scope_tasks), [`workshop_scopes`](taller.md#workshop_scopes), [`workshop_time_events`](taller.md#workshop_time_events), [`workshop_warnings`](taller.md#workshop_warnings), [`workshop_work_orders`](taller.md#workshop_work_orders).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php), [2026_10_12_000140_create_trip_operational_records](../../../database/migrations/2026_10_12_000140_create_trip_operational_records.php), [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php), [2026_10_12_000290_protect_workshop_attachments](../../../database/migrations/2026_10_12_000290_protect_workshop_attachments.php), [2026_10_14_000380_create_driver_operation_receipts](../../../database/migrations/2026_10_14_000380_create_driver_operation_receipts.php).

## transport_assignment_record

Firma: `transport_assignment_record()`.

Tablas con trigger directo: [`driver_assignments`](transporte.md#driver_assignments), [`driver_releases`](transporte.md#driver_releases), [`transport_assignment_operations`](transporte.md#transport_assignment_operations), [`trip_asset_assignments`](transporte.md#trip_asset_assignments), [`trip_asset_releases`](transporte.md#trip_asset_releases).

Llama a: [`transport_assignment_actor`](#transport_assignment_actor).

Rechazos identificados en su definición: `Operation payload identity mismatch`; `Malformed assignment intent`; `Stale assignment operation`; `Invalid assignment operation`; `Driver identity mismatch`; `Asset identity mismatch`; `Assignment time must be actual server time`; `Resource is not eligible`; `Conflict set changed`; `Active substitution requires explicit operation`; `Invalid release identity`; `Release kind mismatch`; `Invalid release interval`.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_check_trip_assignments

Firma: `transport_check_trip_assignments(target_id uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Active trip must have exactly one resource of each kind`.

Definición/evolución: [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_lock_write

Firma: `transport_lock_write()`.

Tablas con trigger directo: [`routes`](transporte.md#routes), [`transport_requests`](transporte.md#transport_requests), [`trips`](transporte.md#trips).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php).

## transport_preserve_history

Firma: `transport_preserve_history()`.

Tablas con trigger directo: [`driver_assignments`](transporte.md#driver_assignments), [`driver_operation_receipts`](transporte.md#driver_operation_receipts), [`driver_releases`](transporte.md#driver_releases), [`expense_decisions`](gastos.md#expense_decisions), [`expense_operations`](gastos.md#expense_operations), [`expense_receipts`](gastos.md#expense_receipts), [`expense_revisions`](gastos.md#expense_revisions), [`expenses`](gastos.md#expenses), [`load_items`](transporte.md#load_items), [`routes`](transporte.md#routes), [`transport_assignment_conflicts`](transporte.md#transport_assignment_conflicts), [`transport_assignment_guard`](transporte.md#transport_assignment_guard), [`transport_assignment_operations`](transporte.md#transport_assignment_operations), [`transport_authorization_applications`](transporte.md#transport_authorization_applications), [`transport_authorization_decisions`](transporte.md#transport_authorization_decisions), [`transport_authorization_requests`](transporte.md#transport_authorization_requests), [`transport_changes`](transporte.md#transport_changes), [`transport_creation_receipts`](transporte.md#transport_creation_receipts), [`transport_requests`](transporte.md#transport_requests), [`trip_asset_assignments`](transporte.md#trip_asset_assignments), [`trip_asset_releases`](transporte.md#trip_asset_releases), [`trip_events`](transporte.md#trip_events), [`trip_loads`](transporte.md#trip_loads), [`trip_odometer_readings`](transporte.md#trip_odometer_readings), [`trip_product_reviews`](transporte.md#trip_product_reviews), [`trip_unloads`](transporte.md#trip_unloads), [`trip_warning_resolutions`](transporte.md#trip_warning_resolutions), [`trip_warnings`](transporte.md#trip_warnings), [`trips`](transporte.md#trips), [`unload_items`](transporte.md#unload_items).

Rechazos identificados en su definición: `Transport history is immutable`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php), [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php), [2026_10_12_000140_create_trip_operational_records](../../../database/migrations/2026_10_12_000140_create_trip_operational_records.php), [2026_10_14_000370_create_administrative_expenses](../../../database/migrations/2026_10_14_000370_create_administrative_expenses.php), [2026_10_14_000380_create_driver_operation_receipts](../../../database/migrations/2026_10_14_000380_create_driver_operation_receipts.php), [2026_10_16_000400_create_transport_creation_receipts](../../../database/migrations/2026_10_16_000400_create_transport_creation_receipts.php).

## transport_preserve_used_route

Firma: `transport_preserve_used_route()`.

Tablas con trigger directo: [`routes`](transporte.md#routes).

Rechazos identificados en su definición: `Referenced route is immutable`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php).

## transport_require_audit

Firma: `transport_require_audit()`.

Tablas con trigger directo: [`transport_requests`](transporte.md#transport_requests), [`trips`](transporte.md#trips).

Llama a: [`transport_require_role`](#transport_require_role).

Rechazos identificados en su definición: `Exact audit required in same transaction`; `Product change needs matching approval`; `Affected trips require review`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php).

## transport_require_role

Firma: `transport_require_role(actor bigint, administrator_only boolean)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Transport actor not authorized`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php), [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php).

## transport_validate_application

Firma: `transport_validate_application()`.

Tablas con trigger directo: [`transport_authorization_applications`](transporte.md#transport_authorization_applications).

Llama a: [`transport_require_role`](#transport_require_role).

Rechazos identificados en su definición: `Authorization cannot cover this application`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php), [2026_10_11_000130_protect_trip_assignment_integrity](../../../database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php), [2026_10_12_000150_protect_recorded_volume_differences](../../../database/migrations/2026_10_12_000150_protect_recorded_volume_differences.php).

## transport_validate_record

Firma: `transport_validate_record()`.

Tablas con trigger directo: [`transport_authorization_decisions`](transporte.md#transport_authorization_decisions), [`transport_authorization_requests`](transporte.md#transport_authorization_requests), [`transport_changes`](transporte.md#transport_changes), [`transport_requests`](transporte.md#transport_requests), [`trip_product_reviews`](transporte.md#trip_product_reviews), [`trip_warning_resolutions`](transporte.md#trip_warning_resolutions), [`trip_warnings`](transporte.md#trip_warnings), [`trips`](transporte.md#trips).

Llama a: [`transport_require_role`](#transport_require_role).

Rechazos identificados en su definición: `Initial version must be one`; `Initial product must match request`; `Stale authorization intent`; `Audit must describe current target`; `Product review identity mismatch`; `Warning must identify actual closure version`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php).

## transport_validate_update

Firma: `transport_validate_update()`.

Tablas con trigger directo: [`transport_requests`](transporte.md#transport_requests), [`trips`](transporte.md#trips).

Rechazos identificados en su definición: `Invalid identity or version update`; `Historical trip product is immutable in 5.2A`.

Definición/evolución: [2026_10_11_000100_protect_transport_core_integrity](../../../database/migrations/2026_10_11_000100_protect_transport_core_integrity.php).

## trip_completion_integrity

Firma: `trip_completion_integrity()`.

Tablas con trigger directo: [`load_items`](transporte.md#load_items), [`trips`](transporte.md#trips), [`unload_items`](transporte.md#unload_items).

Llama a: [`trip_operation_actor`](#trip_operation_actor), [`trip_require_completion`](#trip_require_completion).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000160_require_official_trip_completion](../../../database/migrations/2026_10_12_000160_require_official_trip_completion.php).

## trip_operation_actor

Firma: `trip_operation_actor(actor bigint, target uuid, driver_allowed boolean)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Trip operation actor not authorized`.

Definición/evolución: [2026_10_12_000140_create_trip_operational_records](../../../database/migrations/2026_10_12_000140_create_trip_operational_records.php), [2026_10_12_000160_require_official_trip_completion](../../../database/migrations/2026_10_12_000160_require_official_trip_completion.php), [2026_10_14_000380_create_driver_operation_receipts](../../../database/migrations/2026_10_14_000380_create_driver_operation_receipts.php).

## trip_operation_record

Firma: `trip_operation_record()`.

Tablas con trigger directo: [`load_items`](transporte.md#load_items), [`trip_events`](transporte.md#trip_events), [`trip_loads`](transporte.md#trip_loads), [`trip_odometer_readings`](transporte.md#trip_odometer_readings), [`trip_unloads`](transporte.md#trip_unloads), [`unload_items`](transporte.md#unload_items).

Llama a: [`trip_operation_actor`](#trip_operation_actor).

Rechazos identificados en su definición: `Driver reading requires current tractor assignment`; `Operation requires reason and object evidence`; `Load requires current assigned tanker`; `Invalid loading compartment`; `Unload must refer to same trip load`; `Unload item belongs to another trip`; `Delivered liters exceed recorded compartment capacity`; `Odometer requires assigned tractor history`; `Initial reading revision must be one`; `Odometer correction identity mismatch`.

Definición/evolución: [2026_10_12_000140_create_trip_operational_records](../../../database/migrations/2026_10_12_000140_create_trip_operational_records.php), [2026_10_14_000380_create_driver_operation_receipts](../../../database/migrations/2026_10_14_000380_create_driver_operation_receipts.php).

## trip_require_completion

Firma: `trip_require_completion(target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`trip_require_completion_base`](#trip_require_completion_base).

Rechazos identificados en su definición: `Completion requires audited official start and matching operational tanker history`.

Definición/evolución: [2026_10_12_000160_require_official_trip_completion](../../../database/migrations/2026_10_12_000160_require_official_trip_completion.php), [2026_10_12_000170_require_operational_assignment_history](../../../database/migrations/2026_10_12_000170_require_operational_assignment_history.php).

## trip_require_completion_base

Firma: `trip_require_completion_base(target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Official completion requires start, assignment history and full load/unload coverage`.

Definición/evolución: [2026_10_12_000170_require_operational_assignment_history](../../../database/migrations/2026_10_12_000170_require_operational_assignment_history.php).

## trip_validate_official_start

Firma: `trip_validate_official_start()`.

Tablas con trigger directo: [`trips`](transporte.md#trips).

Llama a: [`trip_operation_actor`](#trip_operation_actor).

Rechazos identificados en su definición: `Official start requires eligible current assignments`.

Definición/evolución: [2026_10_12_000140_create_trip_operational_records](../../../database/migrations/2026_10_12_000140_create_trip_operational_records.php).

## trip_volume_application

Firma: `trip_volume_application()`.

Tablas con trigger directo: [`transport_authorization_applications`](transporte.md#transport_authorization_applications).

Llama a: [`transport_assignment_actor`](#transport_assignment_actor), [`trip_volume_content`](#trip_volume_content).

Rechazos identificados en su definición: `Volume approval does not cover recorded values and audited change`.

Definición/evolución: [2026_10_12_000150_protect_recorded_volume_differences](../../../database/migrations/2026_10_12_000150_protect_recorded_volume_differences.php).

## trip_volume_content

Firma: `trip_volume_content(target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000150_protect_recorded_volume_differences](../../../database/migrations/2026_10_12_000150_protect_recorded_volume_differences.php).

## trip_volume_integrity

Firma: `trip_volume_integrity()`.

Tablas con trigger directo: [`load_items`](transporte.md#load_items), [`trips`](transporte.md#trips), [`unload_items`](transporte.md#unload_items).

Llama a: [`trip_volume_content`](#trip_volume_content).

Rechazos identificados en su definición: `Recorded volume difference requires consumed administrator approval`.

Definición/evolución: [2026_10_12_000150_protect_recorded_volume_differences](../../../database/migrations/2026_10_12_000150_protect_recorded_volume_differences.php).

## workshop_application_is_exit

Firma: `workshop_application_is_exit(decision uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_applied_scope_quota

Firma: `workshop_applied_scope_quota()`.

Tablas con trigger directo: [`workshop_authorization_applications`](taller.md#workshop_authorization_applications).

Llama a: [`workshop_effective_scope`](#workshop_effective_scope).

Rechazos identificados en su definición: `Alcance aplicado incompatible con entregas históricas efectivas`.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_asset_projection

Firma: `workshop_asset_projection(asset uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_attachment_actor

Firma: `workshop_attachment_actor()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Raíz de archivo incompatible`; `Participación no vigente`; `Motivo requerido`; `Reserva inválida`; `Formato de reserva incompatible`; `Terminal no autorizado`.

Definición/evolución: [2026_10_12_000290_protect_workshop_attachments](../../../database/migrations/2026_10_12_000290_protect_workshop_attachments.php).

## workshop_attachment_integrity

Firma: `workshop_attachment_integrity()`.

Tablas con trigger directo: [`workshop_attachments`](taller.md#workshop_attachments), [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Reserva incompatible`; `Confirmación sin adjunto coherente`; `Adjunto sin confirmación`; `Metadata distinta de reserva`; `Revisión sin aplicación sensible`; `Revisión incompatible`; `Máximo veinte adjuntos y reservas`.

Definición/evolución: [2026_10_12_000290_protect_workshop_attachments](../../../database/migrations/2026_10_12_000290_protect_workshop_attachments.php).

## workshop_authorization_audit_phase2

Firma: `workshop_authorization_audit_phase2()`.

Tablas con trigger directo: [`workshop_authorization_applications`](taller.md#workshop_authorization_applications), [`workshop_authorization_decisions`](taller.md#workshop_authorization_decisions), [`workshop_authorization_requests`](taller.md#workshop_authorization_requests), [`workshop_scopes`](taller.md#workshop_scopes).

Llama a: [`workshop_scope_content`](#workshop_scope_content), [`workshop_validate_fact_application`](#workshop_validate_fact_application).

Rechazos identificados en su definición: `Aplicación no aprobada o ámbito incompatible`; `Orden terminal requiere reapertura antes de aplicar alcance`; `Aplicación inicial sin transición autorizada`; `Versión de rectificación obsoleta`; `Alcance anterior o ya aplicado`; `Aplicación de alcance distinta del aprobado`; `Cancelación inválida`; `Destino reapertura incompatible`; `Aplicación de versión/contenido no autorizada`; `Hecho autorización sin correlación única`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_authorization_record

Firma: `workshop_authorization_record()`.

Tablas con trigger directo: [`workshop_authorization_applications`](taller.md#workshop_authorization_applications), [`workshop_authorization_decisions`](taller.md#workshop_authorization_decisions), [`workshop_authorization_requests`](taller.md#workshop_authorization_requests), [`workshop_scope_parts`](taller.md#workshop_scope_parts), [`workshop_scope_tasks`](taller.md#workshop_scope_tasks), [`workshop_scopes`](taller.md#workshop_scopes).

Llama a: [`workshop_require_actor`](#workshop_require_actor), [`workshop_scope_content`](#workshop_scope_content), [`workshop_validate_fact_request`](#workshop_validate_fact_request).

Rechazos identificados en su definición: `Mecánico sin participación vigente`; `Cadena de alcance incompatible`; `Líneas de alcance solicitado inmutables`; `Snapshot de catálogo incompatible`; `Identidad de línea histórica incompatible`; `Solicitante sin participación vigente`; `Orden terminal requiere reapertura antes de nuevo alcance`; `Tipo/ámbito de alcance incompatible`; `Contenido de alcance no corresponde o sin tareas`; `Versión solicitada obsoleta`; `Versión de alcance obsoleta`; `Contenido de cambio de orden incompatible`; `Predecesor de solicitud incompatible o no rechazado`; `Solicitud duplicada pendiente o aprobada`.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_availability_actor

Firma: `workshop_availability_actor()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_availability_audit

Firma: `workshop_availability_audit()`.

Tablas con trigger directo: [`fleet_assets`](flota-y-documentacion.md#fleet_assets), [`fleet_impediment_resolutions`](taller.md#fleet_impediment_resolutions), [`fleet_operating_impediments`](taller.md#fleet_operating_impediments), [`workshop_admission_exits`](taller.md#workshop_admission_exits), [`workshop_admissions`](taller.md#workshop_admissions), [`workshop_order_admission_links`](taller.md#workshop_order_admission_links), [`workshop_warnings`](taller.md#workshop_warnings).

Llama a: [`workshop_asset_projection`](#workshop_asset_projection).

Rechazos identificados en su definición: `Ingreso sin causa/auditoría única`; `Ingreso sin capturar asignación existente`; `Versión de ingreso sin salida exacta`; `Causa sin auditoría única`; `Causa sin operación coherente`; `Resolución sin operación autorizada exacta`; `Salida de otra autorización`; `Reparación vinculada no resuelta`; `Salida sin resolver causa canónica`; `Vínculo sin operación exacta`; `Advertencia sin ingreso del mismo actor`; `Más de un ingreso abierto`; `Proyección operativa incoherente`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_availability_destinations

Firma: `workshop_availability_destinations()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Destino de disponibilidad inesperado`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_availability_operation

Firma: `workshop_availability_operation()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Raíz/versión ingreso inválida`; `Creación sin causa propia`; `Salida sin aplicación`; `Operación de otra causa/activo`; `Actor ajeno a causa`; `Operación sin resolución`; `Vínculo de otra orden`; `Nota de otro ingreso`; `Destinos adicionales incompatibles`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_availability_record

Firma: `workshop_availability_record()`.

Tablas con trigger directo: [`fleet_impediment_resolutions`](taller.md#fleet_impediment_resolutions), [`fleet_operating_impediments`](taller.md#fleet_operating_impediments), [`workshop_admission_exits`](taller.md#workshop_admission_exits), [`workshop_admissions`](taller.md#workshop_admissions), [`workshop_order_admission_links`](taller.md#workshop_order_admission_links), [`workshop_warnings`](taller.md#workshop_warnings).

Llama a: [`workshop_asset_projection`](#workshop_asset_projection), [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Conciliar estado histórico antes del ingreso`; `Versión inicial inválida`; `Cambio de ingreso no habilitado o versión inválida`; `Conciliar estado histórico antes de registrar causa`; `Alta genérica de causa no habilitada`; `Observación no corresponde al estado desconocido actual`; `Resolución anterior a causa`; `Salida anterior al ingreso`; `Advertencia ajena o asignación no vigente`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_core_record

Firma: `workshop_core_record()`.

Tablas con trigger directo: [`workshop_diagnoses`](taller.md#workshop_diagnoses), [`workshop_mechanic_assignments`](taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](taller.md#workshop_mechanic_releases), [`workshop_report_links`](taller.md#workshop_report_links), [`workshop_reports`](taller.md#workshop_reports).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Ingreso no habilitado: requiere integración Flota y Transporte`; `Estado inicial de orden inválido`; `Cambio de orden no habilitado o versión inválida`; `Evento no corresponde al historial del activo`; `Revisión de reporte incompatible`; `Reporte y orden de activos distintos`; `Empleado mecánico no activo`; `Fin de participación anterior a inicio`; `Mecánico sin participación vigente`; `Revisión de diagnóstico incompatible`; `Vinculación a ingreso pendiente de integración`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_cost_coverage_effective

Firma: `workshop_cost_coverage_effective()`.

Tablas con trigger directo: [`workshop_cost_records`](taller.md#workshop_cost_records).

Rechazos identificados en su definición: `Cobertura dependiente requiere revisión atómica efectiva`.

Definición/evolución: [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php).

## workshop_cost_record

Firma: `workshop_cost_record()`.

Tablas con trigger directo: [`workshop_cost_records`](taller.md#workshop_cost_records).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Cadena costo incompatible`; `Costo externo sin proveedor autorizado`; `Origen de costo de otra orden`; `Cobertura incompatible/no efectiva`; `Cobertura cíclica`; `Costo comunicado duplicado requiere revisión`.

Definición/evolución: [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php).

## workshop_effective_scope

Firma: `workshop_effective_scope(scope_target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_exact_cost

Firma: `workshop_exact_cost(value text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Costo decimal exacto inválido`; `Costo fuera de rango`.

Definición/evolución: [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_exact_quantity

Firma: `workshop_exact_quantity(value text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Cantidad decimal exacta inválida`; `Cantidad fuera de rango`.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_execution_actor

Firma: `workshop_execution_actor(actor bigint, member uuid, target uuid, moment timestamp with time zone)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Participación requerida`; `Participación no vigente`; `Participación ajena/inactiva`.

Definición/evolución: [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_execution_audit

Firma: `workshop_execution_audit()`.

Tablas con trigger directo: [`workshop_activities`](taller.md#workshop_activities), [`workshop_time_events`](taller.md#workshop_time_events).

Rechazos identificados en su definición: `Ejecución sin recibo correlacionado`; `Secuencia temporal ambigua/incompatible`; `Secuencia de tiempos inválida`; `Rectificación histórica sin autorización explícita`.

Definición/evolución: [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_execution_operation

Firma: `workshop_execution_operation()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Destino ejecución inesperado`; `Destino ejecución incompatible`; `Tipo/destino actividad incompatible`; `Tipo/destino tiempo incompatible`; `Transición sin versión`; `Participación requerida`; `Cierre sin revisión administrativa`; `Hecho ejecución incompatible`.

Definición/evolución: [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_execution_record

Firma: `workshop_execution_record()`.

Tablas con trigger directo: [`workshop_activities`](taller.md#workshop_activities), [`workshop_time_events`](taller.md#workshop_time_events).

Llama a: [`workshop_execution_actor`](#workshop_execution_actor), [`workshop_scope_is_effective`](#workshop_scope_is_effective).

Rechazos identificados en su definición: `Tarea fuera de alcance aplicado vigente`; `Orden sin alcance aplicado`; `Orden no activa para ejecución`; `Rectificación de ámbito/cadena incompatible`.

Definición/evolución: [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_exit_application

Firma: `workshop_exit_application()`.

Tablas con trigger directo: [`workshop_authorization_applications`](taller.md#workshop_authorization_applications).

Llama a: [`workshop_exit_content`](#workshop_exit_content).

Rechazos identificados en su definición: `Salida no autorizada/versionada`; `Causas/órdenes/resoluciones distintas de aprobación`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_exit_content

Firma: `workshop_exit_content(admission uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_exit_request

Firma: `workshop_exit_request()`.

Tablas con trigger directo: [`workshop_authorization_requests`](taller.md#workshop_authorization_requests).

Llama a: [`workshop_exit_content`](#workshop_exit_content).

Rechazos identificados en su definición: `Revisión de salida obsoleta/incompleta`; `Resoluciones propuestas incompatibles`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_fact_audit

Firma: `workshop_fact_audit()`.

Tablas con trigger directo: [`workshop_diagnoses`](taller.md#workshop_diagnoses), [`workshop_mechanic_assignments`](taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](taller.md#workshop_mechanic_releases), [`workshop_report_links`](taller.md#workshop_report_links), [`workshop_reports`](taller.md#workshop_reports).

Rechazos identificados en su definición: `Hecho sin operación única correspondiente`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php).

## workshop_fact_context

Firma: `workshop_fact_context(target_table text, target_id uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Destino de rectificación no permitido`; `Hecho de rectificación inexistente`.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_fact_values

Firma: `workshop_fact_values(target_table text, value jsonb)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_cost`](#workshop_exact_cost), [`workshop_exact_quantity`](#workshop_exact_quantity).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_fleet_status_record

Firma: `workshop_fleet_status_record()`.

Tablas con trigger directo: [`fleet_assets`](flota-y-documentacion.md#fleet_assets).

Llama a: [`workshop_asset_projection`](#workshop_asset_projection).

Rechazos identificados en su definición: `Activo nuevo requiere estado available`; `No liberar estado histórico sin observación administrativa`.

Definición/evolución: [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php).

## workshop_material_audit

Firma: `workshop_material_audit()`.

Tablas con trigger directo: [`workshop_cost_records`](taller.md#workshop_cost_records), [`workshop_external_repairs`](taller.md#workshop_external_repairs), [`workshop_material_needs`](taller.md#workshop_material_needs), [`workshop_operations`](taller.md#workshop_operations), [`workshop_part_deliveries`](taller.md#workshop_part_deliveries), [`workshop_part_usages`](taller.md#workshop_part_usages).

Rechazos identificados en su definición: `Recibo incompatible con hecho material/económico`; `Raíz material/económica incompatible`; `Hecho sin recibo único`.

Definición/evolución: [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php).

## workshop_material_record

Firma: `workshop_material_record()`.

Tablas con trigger directo: [`workshop_external_repairs`](taller.md#workshop_external_repairs), [`workshop_material_needs`](taller.md#workshop_material_needs), [`workshop_part_deliveries`](taller.md#workshop_part_deliveries), [`workshop_part_usages`](taller.md#workshop_part_usages).

Llama a: [`workshop_effective_scope`](#workshop_effective_scope), [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Uso incompatible con entrega`; `Proveedor o tarea externa no autorizados`; `Necesidad de otra orden/repuesto`; `Orden terminal`; `Participación no vigente`; `Asignación no vigente`; `Revisión incompatible`; `Origen histórico incompatible`; `Entrega histórica incompatible`; `Orden histórica incompatible`; `Línea sin autorización vigente`; `Entrega excede cantidad autorizada`; `Entrega corregida inferior a uso comunicado`; `Uso excede entrega efectiva`.

Definición/evolución: [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php).

## workshop_operation_actor

Firma: `workshop_operation_actor()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_operation_destinations

Firma: `workshop_operation_destinations()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Tipo de operación no aprobado`; `Operación sin destino requerido`; `Destino ajeno al tipo de operación`; `Raíz incompatible con operación`; `Hecho inmutable no incrementa versión de raíz`; `Cambio de raíz requiere versiones auditables`.

Definición/evolución: [2026_10_12_000300_require_workshop_operation_destinations](../../../database/migrations/2026_10_12_000300_require_workshop_operation_destinations.php).

## workshop_operation_integrity

Firma: `workshop_operation_integrity()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Destino autorización inesperado`; `Destino adicional incompatible`; `Operación de otra solicitud`; `Decisión de otra solicitud/actor`; `Actor/destino solicitud incompatible`; `Operación sin aplicación`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_operation_integrity_core

Firma: `workshop_operation_integrity_core()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Rechazos identificados en su definición: `Raíz de reporte incorrecta`; `Raíz de orden incorrecta`; `Operación pendiente de integración`; `Destinos de operación incompatibles`; `Operación sin hecho confirmado`; `Fin de otro participante`; `Hecho de otra orden`; `Actor no corresponde al hecho`; `Tipo de revisión de reporte incorrecto`; `Tipo de revisión de diagnóstico incorrecto`; `Cambio sin versiones/snapshot`; `Evento no incrementa raíz mutable`.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_participation_integrity

Firma: `workshop_participation_integrity()`.

Tablas con trigger directo: [`workshop_mechanic_assignments`](taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](taller.md#workshop_mechanic_releases).

Rechazos identificados en su definición: `Participación vigente duplicada`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php).

## workshop_phase2_actor

Firma: `workshop_phase2_actor()`.

Tablas con trigger directo: [`workshop_operations`](taller.md#workshop_operations).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php).

## workshop_phase2_order

Firma: `workshop_phase2_order()`.

Tablas con trigger directo: [`workshop_work_orders`](taller.md#workshop_work_orders).

Llama a: [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Estado inicial inválido`; `Cambio/versión inválido`; `Transición no habilitada en esta fase`.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_phase2_order_audit

Firma: `workshop_phase2_order_audit()`.

Tablas con trigger directo: [`workshop_work_orders`](taller.md#workshop_work_orders).

Rechazos identificados en su definición: `Orden sin creación auditada`; `Cambio sin snapshots exactos`; `Transición ejecución sin recibo`; `Transición sin solicitud inicial`; `Transición sin aplicación`; `Cancelación sin aprobación`; `Reapertura sin aprobación`; `Cambio sin operación compatible`.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_preserve_history

Firma: `workshop_preserve_history()`.

Tablas con trigger directo: [`fleet_impediment_resolutions`](taller.md#fleet_impediment_resolutions), [`fleet_operating_impediments`](taller.md#fleet_operating_impediments), [`inventory_applications`](inventario.md#inventory_applications), [`inventory_corrections`](inventario.md#inventory_corrections), [`inventory_decisions`](inventario.md#inventory_decisions), [`inventory_movements`](inventario.md#inventory_movements), [`inventory_operations`](inventario.md#inventory_operations), [`inventory_returns`](inventario.md#inventory_returns), [`workshop_activities`](taller.md#workshop_activities), [`workshop_admission_exits`](taller.md#workshop_admission_exits), [`workshop_admissions`](taller.md#workshop_admissions), [`workshop_attachments`](taller.md#workshop_attachments), [`workshop_authorization_applications`](taller.md#workshop_authorization_applications), [`workshop_authorization_decisions`](taller.md#workshop_authorization_decisions), [`workshop_authorization_requests`](taller.md#workshop_authorization_requests), [`workshop_cost_records`](taller.md#workshop_cost_records), [`workshop_diagnoses`](taller.md#workshop_diagnoses), [`workshop_external_repairs`](taller.md#workshop_external_repairs), [`workshop_material_needs`](taller.md#workshop_material_needs), [`workshop_mechanic_assignments`](taller.md#workshop_mechanic_assignments), [`workshop_mechanic_releases`](taller.md#workshop_mechanic_releases), [`workshop_operations`](taller.md#workshop_operations), [`workshop_order_admission_links`](taller.md#workshop_order_admission_links), [`workshop_part_deliveries`](taller.md#workshop_part_deliveries), [`workshop_part_usages`](taller.md#workshop_part_usages), [`workshop_report_links`](taller.md#workshop_report_links), [`workshop_reports`](taller.md#workshop_reports), [`workshop_scope_parts`](taller.md#workshop_scope_parts), [`workshop_scope_tasks`](taller.md#workshop_scope_tasks), [`workshop_scopes`](taller.md#workshop_scopes), [`workshop_time_events`](taller.md#workshop_time_events), [`workshop_warnings`](taller.md#workshop_warnings), [`workshop_work_orders`](taller.md#workshop_work_orders).

Rechazos identificados en su definición: `Historial de Taller inmutable`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php), [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php), [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php), [2026_10_12_000290_protect_workshop_attachments](../../../database/migrations/2026_10_12_000290_protect_workshop_attachments.php), [2026_10_14_000330_create_inventory_ledger](../../../database/migrations/2026_10_14_000330_create_inventory_ledger.php).

## workshop_require_actor

Firma: `workshop_require_actor(actor bigint, permitted text[])`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Rechazos identificados en su definición: `Actor de Taller no autorizado`.

Definición/evolución: [2026_10_12_000190_protect_workshop_core](../../../database/migrations/2026_10_12_000190_protect_workshop_core.php), [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php), [2026_10_12_000230_protect_workshop_availability](../../../database/migrations/2026_10_12_000230_protect_workshop_availability.php), [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php), [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php), [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php), [2026_10_12_000290_protect_workshop_attachments](../../../database/migrations/2026_10_12_000290_protect_workshop_attachments.php), [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php), [2026_10_14_000330_create_inventory_ledger](../../../database/migrations/2026_10_14_000330_create_inventory_ledger.php).

## workshop_scope_content

Firma: `workshop_scope_content(target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php).

## workshop_scope_is_effective

Firma: `workshop_scope_is_effective(target uuid)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000250_protect_workshop_execution](../../../database/migrations/2026_10_12_000250_protect_workshop_execution.php).

## workshop_sensitive_fact_audit

Firma: `workshop_sensitive_fact_audit()`.

Tablas con trigger directo: [`workshop_activities`](taller.md#workshop_activities), [`workshop_attachments`](taller.md#workshop_attachments), [`workshop_cost_records`](taller.md#workshop_cost_records), [`workshop_external_repairs`](taller.md#workshop_external_repairs), [`workshop_material_needs`](taller.md#workshop_material_needs), [`workshop_part_deliveries`](taller.md#workshop_part_deliveries), [`workshop_part_usages`](taller.md#workshop_part_usages), [`workshop_time_events`](taller.md#workshop_time_events).

Llama a: [`workshop_fact_context`](#workshop_fact_context).

Rechazos identificados en su definición: `Revisión sensible sin aplicación única de autorización`.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_validate_fact_application

Firma: `workshop_validate_fact_application(value jsonb)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_fact_context`](#workshop_fact_context), [`workshop_fact_values`](#workshop_fact_values), [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Destino sensible inválido`; `Rectificación distinta de la autorización exacta`.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_validate_fact_request

Firma: `workshop_validate_fact_request(value jsonb)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_fact_context`](#workshop_fact_context), [`workshop_fact_values`](#workshop_fact_values), [`workshop_require_actor`](#workshop_require_actor).

Rechazos identificados en su definición: `Rectificación no corresponde a hecho/versión exactos`; `Predecesor de solicitud inválido`; `Rectificación ya solicitada`; `Contenido inválido`.

Definición/evolución: [2026_10_12_000310_enable_workshop_fact_rectifications](../../../database/migrations/2026_10_12_000310_enable_workshop_fact_rectifications.php).

## workshop_write_cost

Firma: `workshop_write_cost(data jsonb, amount_text text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_cost`](#workshop_exact_cost).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000270_create_workshop_costs](../../../database/migrations/2026_10_12_000270_create_workshop_costs.php).

## workshop_write_delivery

Firma: `workshop_write_delivery(data jsonb, quantity_text text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_quantity`](#workshop_exact_quantity).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php).

## workshop_write_material_need

Firma: `workshop_write_material_need(data jsonb, quantity_text text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_quantity`](#workshop_exact_quantity).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php).

## workshop_write_scope_part

Firma: `workshop_write_scope_part(part_id uuid, target_scope uuid, target_task uuid, catalog_part uuid, line integer, value text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_quantity`](#workshop_exact_quantity).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000210_protect_workshop_authorizations](../../../database/migrations/2026_10_12_000210_protect_workshop_authorizations.php).

## workshop_write_usage

Firma: `workshop_write_usage(data jsonb, quantity_text text)`.

Tablas con trigger directo: no se invoca directamente desde un trigger de tabla; revisar llamadas de servicios/otras funciones.

Llama a: [`workshop_exact_quantity`](#workshop_exact_quantity).

Su definición coordina, calcula o registra información; no se debe inferir ausencia de validaciones en las funciones que llama.

Definición/evolución: [2026_10_12_000260_create_workshop_materials](../../../database/migrations/2026_10_12_000260_create_workshop_materials.php).
