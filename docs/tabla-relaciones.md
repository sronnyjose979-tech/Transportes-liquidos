# Relaciones y cardinalidades

La cardinalidad junto a A indica cuántas A corresponden a una B; junto a B, cuántas B corresponden a una A. En cabeceras en borrador se admite ausencia de detalles; al confirmar/emitir se exigen. Trip–InvoiceItem tiene límite superior provisional hasta decidir facturación parcial.

| Tablas A → B | Cardinalidad A : B | Relación propuesta desde A | Restricciones |
|---|---|---|---|
| users → user_roles | 1 : 0..N | userRoles |  |
| roles → user_roles | 1 : 0..N | userRoles |  |
| users → employees | 0..1 : 0..1 | employee | Vínculo opcional único en ambos sentidos. |
| customers → transport_requests | 1 : 0..N | transportRequests | Un cliente obligatorio por solicitud; puede no tener solicitudes todavía. |
| transport_requests → trips | 1 : 0..N | trips | Solicitud obligatoria por viaje; solicitud admite cero viajes iniciales y varios con recursos distintos. |
| fleet_assets → tractor_units | 1 : 0..1 | tractorUnit | Cada cabezal tiene un activo; XOR total con Tanker. |
| fleet_assets → tankers | 1 : 0..1 | tanker | Cada cisterna tiene un activo; todo activo es exactamente uno de los dos tipos. |
| tankers → compartments | 1 : 1..N | compartments | Sin divisiones físicas, exactamente uno lógico. |
| trips → trip_asset_assignments | 1 : 0..N | tripAssetAssignments | Borrador admite cero; activo exige un cabezal y una cisterna vigentes. |
| fleet_assets → trip_asset_assignments | 1 : 0..N | tripAssetAssignments | Sin intervalos incompatibles simultáneos. |
| trips → driver_assignments | 1 : 0..N | driverAssignments | Activo exige uno vigente, sin solapamientos. |
| employees → driver_assignments | 1 : 0..N | driverAssignments | Función conductor y no doble asignación incompatible. |
| trips → trip_loads | 1 : 0..1 | tripLoad | Cero antes de captura; exactamente una al completar ejecución. |
| trips → trip_unloads | 1 : 0..1 | tripUnload | Cero antes de captura; exactamente una al completar ejecución. |
| operation_sites → trip_loads | 1 : 0..N | tripLoads |  |
| operation_sites → trip_unloads | 1 : 0..N | tripUnloads |  |
| trip_loads → load_items | 1 : 0..N | loadItems | Confirmada exige uno o más. |
| compartments → load_items | 1 : 0..N | loadItems | Compartimento de cisterna vigente al cargar, capacidad controlada; todo detalle lleva el único producto de la solicitud. |
| trip_unloads → unload_items | 1 : 0..N | unloadItems | Confirmada exige uno o más y cubrir detalles cargados. |
| load_items → unload_items | 1 : 0..1 | unloadItem | Máximo una fila de descarga por detalle cargado; FK única, mismo viaje. Aceptación separada de litros descargados. |
| trips → trip_events | 1 : 0..N | tripEvents |  |
| users → trip_events | 1 : 0..N | tripEvents |  |
| fleet_assets → work_orders | 1 : 0..N | workOrders |  |
| users → work_orders | 0..1 : 0..N | approvedWorkOrders | Administrador; opcional hasta aprobación o excepción documentada. |
| work_orders → diagnoses | 1 : 0..N | diagnoses |  |
| employees → diagnoses | 1 : 0..N | diagnoses |  |
| work_orders → work_tasks | 1 : 0..N | workTasks |  |
| work_tasks → task_assignments | 1 : 0..N | taskAssignments |  |
| employees → task_assignments | 1 : 0..N | taskAssignments |  |
| fleet_assets → asset_readings | 1 : 0..N | assetReadings |  |
| trip_events → asset_readings | 0..1 : 0..N | assetReadings | Opcional; único por registro, activo y tipo. |
| users → purchase_requests | 1 : 0..N | purchaseRequests |  |
| users → purchase_requests | 0..1 : 0..N | approvedPurchaseRequests | Vínculo approved_by_id; administrador, opcional hasta aprobación. |
| purchase_requests → purchase_request_items | 1 : 0..N | purchaseRequestItems | Enviada exige al menos un detalle. |
| spare_parts → purchase_request_items | 1 : 0..N | purchaseRequestItems |  |
| suppliers → purchases | 1 : 0..N | purchases |  |
| users → purchases | 0..1 : 0..N | approvedPurchases | Autorización directa cuando no hay solicitud previa; administrador. |
| purchase_requests → purchases | 0..1 : 0..N | purchases | Omitir solo según política; compras parciales es propuesta. |
| purchases → purchase_items | 1 : 0..N | purchaseItems | Confirmada exige uno o más. |
| spare_parts → purchase_items | 1 : 0..N | purchaseItems |  |
| work_tasks → part_consumptions | 1 : 0..N | partConsumptions | Orden derivada de tarea. |
| spare_parts → part_consumptions | 1 : 0..N | partConsumptions |  |
| spare_parts → inventory_movements | 1 : 0..N | inventoryMovements |  |
| warehouses → inventory_movements | 1 : 0..N | inventoryMovements |  |
| purchase_items → inventory_movements | 0..1 : 0..N | inventoryMovements | Obligatorio para entrada por compra; recepción acumulada controlada. |
| part_consumptions → inventory_movements | 0..1 : 0..1 | inventoryMovement | FK opcional única; aplicado exige una salida original. |
| inventory_movements → inventory_movements | 0..1 : 0..1 | reversal | Reversa única compensatoria, sin editar original. |
| users → inventory_movements | 1 : 0..N | inventoryMovements |  |
| customers → invoices | 1 : 0..N | invoices |  |
| invoices → invoice_items | 1 : 0..N | invoiceItems | Borrador admite cero detalles; emitida exige uno o más. Varios viajes completados del mismo cliente, incluso de distintas solicitudes. |
| trips → invoice_items | 1 : 0..N | invoiceItems | Viaje obligatorio por detalle. Límite superior provisional: 0..1 si no hay parcial; 0..N si se aprueba parcial. No autoriza fraccionamiento todavía; controlar litros acumulados. |
| customers → payments | 1 : 0..N | payments |  |
| payments → payment_allocations | 1 : 0..N | paymentAllocations | Confirmado exige aplicaciones salvo anticipos pendientes de definir. |
| invoices → payment_allocations | 1 : 0..N | paymentAllocations | Cliente/moneda coincidentes; no exceder saldo. |
| trips → expenses | 0..1 : 0..N | expenses |  |
| users → expenses | 1 : 0..N | expenses |  |
| trip_events → expenses | 0..1 : 0..1 | expense | Tipo gasto o combustible; FK única evita duplicación por reenvío. |
| document_types → documents | 1 : 0..N | documents |  |
| users → documents | 1 : 0..N | documents |  |
| documents → document_links | 1 : 1..N | documentLinks | Documento persistido exige al menos un vínculo. |
| fleet_assets → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| employees → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| trips → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| work_orders → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| purchases → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| expenses → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| invoices → document_links | 0..1 : 0..N | documentLinks | Exactamente un destino entre siete FK; las demás nulas. |
| operation_sites → routes | 1 : 0..N | originRoutes | Cada ruta tiene un origen obligatorio; un lugar puede ser origen de muchas rutas. FK origin_site_id. |
| operation_sites → routes | 1 : 0..N | destinationRoutes | Cada ruta tiene un destino obligatorio; FK destination_site_id. Ruta comercial, no geometría/GPS. |
| routes → transport_requests | 1 : 0..N | transportRequests | Una ruta obligatoria por solicitud, sin route_id duplicado en Trip. |
| liquid_products → transport_requests | 1 : 0..N | transportRequests | Un producto obligatorio por solicitud; no TransportRequestItem. |
| customers → transport_rates | 1 : 0..N | transportRates | Cada versión corresponde a un cliente obligatorio; diferentes clientes pueden tener tarifas distintas en la misma ruta. |
| routes → transport_rates | 1 : 0..N | transportRates | Cada versión corresponde a una ruta obligatoria; puede haber varias negociaciones históricas por cliente/ruta. |
| transport_rates → transport_requests | 1 : 0..N | transportRequests | Una versión acordada obligatoria por solicitud; cliente/ruta coinciden con solicitud, precio/moneda y vínculo quedan fijos. |
| users → unload_items | 0..1 : 0..N | acceptedUnloadItems | accepted_by_id opcional mientras falta aceptación; identifica usuario que valida evidencia del destino, no necesariamente al receptor externo. |
