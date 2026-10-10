# Entidades y nombres técnicos

| Modelo propuesto | Tabla | Responsabilidad | Atributos iniciales |
|---|---|---|---|
| User | users | Cuenta de acceso. | id, login, is_active |
| Role | roles | Administrador, secretario, conductor o mecánico. | id, name |
| UserRole | user_roles | Asignación de perfiles, pareja única. | user_id, role_id |
| Employee | employees | Trabajador con cuenta opcional y única. | id, user_id, name, is_active |
| Customer | customers | Cliente externo. | id, name |
| FleetAsset | fleet_assets | Activo propio mantenible; tipo exclusivo. | id, code, type, service_status |
| TractorUnit | tractor_units | Especialización para tracción. | fleet_asset_id |
| Tanker | tankers | Especialización para líquidos. | fleet_asset_id, has_physical_partitions |
| Compartment | compartments | Espacio lógico, capacidad en litros. | id, tanker_id, code, capacity_liters, is_active |
| TransportRequest | transport_requests | Solicitud de un cliente, un producto, una ruta, litros totales y una versión fija de tarifa; admite cero o muchos viajes. | id, customer_id, liquid_product_id, route_id, transport_rate_id, requested_liters, requested_at, status, description |
| Trip | trips | Viaje de una solicitud obligatoria; cliente, producto, ruta y tarifa derivados de ella. Ejecución y cierre administrativo separados. | id, transport_request_id, execution_status, administrative_status, scheduled_start_at, scheduled_end_at, departed_at, arrived_at |
| TripAssetAssignment | trip_asset_assignments | Historial temporal de cabezal/cisterna. | id, trip_id, fleet_asset_id, starts_at, ends_at, reason |
| DriverAssignment | driver_assignments | Responsable vigente y sustituciones excepcionales. | id, trip_id, employee_id, starts_at, ends_at, reason |
| LiquidProduct | liquid_products | Catálogo de líquidos. | id, name, is_active |
| OperationSite | operation_sites | Lugar de carga o descarga. | id, name, address |
| TripLoad | trip_loads | Única operación de carga del viaje. | id, trip_id, operation_site_id, status, occurred_at |
| LoadItem | load_items | Litros cargados por compartimento; producto único obtenido mediante la solicitud del viaje. | id, trip_load_id, compartment_id, loaded_liters |
| TripUnload | trip_unloads | Única operación de descarga del viaje. | id, trip_id, operation_site_id, status, occurred_at |
| UnloadItem | unload_items | Litros descargados y litros netos recibidos/aceptados por detalle cargado; evidencia, estado de aceptación y diferencias. | id, trip_unload_id, load_item_id, delivered_liters, difference_reason, accepted_liters, acceptance_status, accepted_by_id, accepted_at |
| TripEvent | trip_events | Capturas de conductor: salida, llegada, combustible, gastos e incidencias. | id, trip_id, created_by_id, type, occurred_at, validation_status, fuel_liters, description |
| WorkOrder | work_orders | Trabajo correctivo sobre activo propio y autorización. | id, fleet_asset_id, status, reason, opened_at, closed_at, approved_by_id, approved_at |
| Diagnosis | diagnoses | Hallazgos de la orden e historial. | id, work_order_id, mechanic_id, description, occurred_at |
| WorkTask | work_tasks | Reparación y mano de obra; repuestos derivados de consumos. | id, work_order_id, description, status, labor_cost, currency |
| TaskAssignment | task_assignments | Mecánicos de cada tarea. | work_task_id, employee_id |
| AssetReading | asset_readings | Medición, incluido kilometraje capturado en viaje. | id, fleet_asset_id, trip_event_id, type, value, unit, measured_at |
| SparePart | spare_parts | Catálogo y unidad de inventario. | id, code, description, unit |
| Warehouse | warehouses | Ubicación de existencias; uno lógico inicialmente, propuesta. | id, name |
| Supplier | suppliers | Proveedor de repuestos. | id, name |
| PurchaseRequest | purchase_requests | Autorización previa cuando corresponde. | id, requested_by_id, approved_by_id, status, approved_at, reason |
| PurchaseRequestItem | purchase_request_items | Repuesto y cantidad solicitados. | id, purchase_request_id, spare_part_id, quantity |
| Purchase | purchases | Compra a proveedor y recepción. | id, supplier_id, purchase_request_id, approved_by_id, approved_at, status, purchased_at, currency |
| PurchaseItem | purchase_items | Cantidad y costo acordados. | id, purchase_id, spare_part_id, quantity, unit_cost |
| PartConsumption | part_consumptions | Repuesto aplicado a tarea y costo aplicado. | id, work_task_id, spare_part_id, quantity, unit_cost, currency, status |
| InventoryMovement | inventory_movements | Libro único de entradas, salidas, ajustes y reversas. | id, spare_part_id, warehouse_id, purchase_item_id, part_consumption_id, reversal_of_id, type, signed_quantity, occurred_at, created_by_id, reason |
| Invoice | invoices | Factura semanal de un cliente; agrupa viajes completados de una o varias solicitudes, con moneda y período. | id, customer_id, issuance_status, payment_terms, issued_at, due_at, currency, billing_period_start, billing_period_end |
| InvoiceItem | invoice_items | Viaje facturado, litros netos aceptados facturados, tarifa histórica aplicada y subtotal; multiplicidad por viaje pendiente de facturación parcial. | id, invoice_id, trip_id, description, billed_liters, applied_rate_per_liter, subtotal, tax_amount |
| Payment | payments | Recepción de dinero del cliente. | id, customer_id, amount, currency, occurred_at, reference, status |
| PaymentAllocation | payment_allocations | Importe de un pago aplicado a factura; pagos parciales. | id, payment_id, invoice_id, amount, status |
| Expense | expenses | Importe canónico y vínculo opcional a viaje/captura. | id, trip_id, recorded_by_id, trip_event_id, description, amount, currency, occurred_at, status |
| DocumentType | document_types | Clasificación documental, requisitos por validar. | id, name, tracks_expiration |
| Document | documents | Referencia privada de archivo y vigencia. | id, document_type_id, storage_key, issued_on, expires_on, status, uploaded_by_id |
| DocumentLink | document_links | Un destino por fila con FK explícitas; demás destinos nulos. | id, document_id, fleet_asset_id, employee_id, trip_id, work_order_id, purchase_id, expense_id, invoice_id |
| Route | routes | Ruta comercial dirigida con un origen y un destino; reutilizable en solicitudes. | id, origin_site_id, destination_site_id, name, is_active |
| TransportRate | transport_rates | Versión inmutable de tarifa por litro negociada para un cliente y una ruta; nuevas negociaciones crean versiones nuevas. | id, customer_id, route_id, rate_per_liter, currency, agreed_at, is_active |
