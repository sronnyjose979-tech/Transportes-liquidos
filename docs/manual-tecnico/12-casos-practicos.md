# 12. Casos prácticos de principio a fin


## Antes de seguir los ejemplos

Los nombres y cifras son didácticos, no registros existentes ni operaciones ejecutadas durante esta documentación. `UUID_CLIENTE`, `UUID_VIAJE`, etc. significan **sustituir por un UUID realmente devuelto por la API**, no enviar esas palabras como UUID válido. Las fechas se muestran con `-06:00`, correspondiente a Costa Rica. Cada intención nueva que admite `request_key` recibe UUID distinto; el reintento de la misma conserva clave/contenido.

Los ejemplos muestran el recorrido funcional. Para campos condicionales y respuestas exactas consultar [Form Requests](api/validaciones.md) y [API completa](06-api-y-backend.md). No ejecutar SQL directo para saltar autorizaciones. Los [diccionarios](05-base-de-datos.md) permiten seguir cada tabla.

## 1. Iniciar sesión y llegar al inicio correcto

**Pantalla:** `/login`, `SystemApp.vue`. **Actor:** cuenta activa previamente creada.

1. Navegador obtiene `GET /api/csrf`: Laravel entrega token/cookie, con respuesta sin contenido.
2. El usuario escribe login y contraseña. `POST /api/login` llama `SessionController::store()`; credenciales no van en URL ni se imprimen en logs.
3. Laravel verifica hash/cuenta activa, regenera sesión y devuelve identidad limitada. Si falla, no se muestra un dashboard como si hubiera autenticación.
4. `GET /api/navigation` utiliza `SystemNavigation`; `/` redirige según rol. Administrador ve dashboard, secretaria Transporte, mecánico Taller, conductor sus viajes.

**Persistencia:** sesión Laravel en `sessions` cuando el driver es database; consulta `users`, `roles`, `user_roles`. No crea un empleado ni un viaje. **Usuario recibe:** acceso y menú autorizados, o mensaje de autenticación/limitación.

Cerrar utiliza `POST /api/logout` y `SessionController::destroy()`. Si hay capturas del conductor pendientes, se advierte sin borrarlas; sin conexión no puede afirmarse que se invalidó la sesión del servidor.

## 2. Registrar cliente, producto, sitios y ruta

**Pantalla:** `/administracion/catalogos`. **Actor:** administrador.

1. Abrir Clientes y registrar nombre, por ejemplo “Cliente de estudio”. `POST /api/catalogs/customers` pasa por `CustomerController`, que hereda `CatalogController` y exige nombre requerido/máximo 255.
2. Se crea una fila `customers` y se devuelve el recurso. La lista vuelve a consultar; no se agrega un cliente ficticio en el selector antes de confirmar.
3. Productos y sitios usan `/api/catalogs/liquid-products` y `/api/catalogs/operation-sites`, con sus controladores/reglas existentes.
4. Rutas utiliza `POST /api/catalogs/routes`, `RouteWriteRequest`, `RouteController` y `RouteAdministration::save()`. Se eligen origen/destino entre sitios existentes.
5. Transporte consulta `GET /api/transport/lookups/routes` y puede usarla como secretaria sin administrar el catálogo.

**Tablas:** `customers`, `liquid_products`, `operation_sites`, `routes`, según la acción. **Usuario recibe:** registro confirmado y disponible en consultas posteriores. Una ruta usada por solicitudes queda protegida contra edición histórica incompatible; no se inventa una clonación o cambio retroactivo.

## 3. Registrar recursos sin duplicar entidades

**Pantallas:** `/administracion/flota` y `/administracion/personal`. **Actor:** administrador.

- Cabezal: `POST /api/tractor-units` → `TractorUnitController` → `FleetAdministration::save()`. Crea activo común `fleet_assets` y especialización `tractor_units`.
- Cisterna: `POST /api/tankers` → `TankerController` → mismo coordinador. Crea `fleet_assets`, `tankers` y estructura de `compartments` validada; una sin divisiones tiene compartimento lógico.
- Cuenta: `POST /api/users` → `AccountController` → `AccountAdministration::save()`, contraseña confirmada/roles existentes/activación explícita.
- Empleado: `POST /api/employees` → `EmployeeController`; vincular `user_id` si corresponde y habilitarlo. La cuenta con rol driver y el empleado son los mismos recursos utilizados por Transporte.

**Usuario recibe:** recursos reales en selectores después de refrescar. Registrar una cisterna no crea disponibilidad contra cualquier conflicto; un empleado habilitado no reserva un viaje. Las ediciones usan PATCH de sus IDs con las precondiciones existentes.

## 4. Crear solicitud y varios viajes legítimos

**Pantalla:** `/transporte`, solicitudes y viajes. **Actor:** secretaria/administrador.

```json
{
  "request_key": "UUID_NUEVA_INTENCION",
  "customer_id": "UUID_CLIENTE",
  "liquid_product_id": "UUID_PRODUCTO",
  "route_id": "UUID_RUTA",
  "requested_liters": "12000.00",
  "requested_at": "2026-10-16T08:00:00-06:00"
}
```

1. `POST /api/transport/requests` → `TransportRequestWrite` → `TransportRequestController::store()` → `TransportAdministration::createRequest()`.
2. La transacción crea `transport_requests` y `transport_creation_receipts`; el usuario recibe Resource y 201.
3. Crear viaje: `POST /api/transport/trips`, con `request_key` nuevo y `transport_request_id`. `TripCreateRequest`, `TripController::store()` y `createTrip()` crean `trips` con producto histórico y recibo.
4. Un segundo viaje de la solicitud usa otra clave. No existe prohibición comercial de tener dos viajes para la misma solicitud.
5. Programar mediante `POST /api/transport/trips/{id}/schedule`, versión actual, motivo, `scheduled_start_at` y fin opcional. `TripActionRequest` y `tripAction()` conservan cambio auditado.

**Tablas:** solicitud/viaje, recibos y `transport_changes` según comando. **Usuario recibe:** estado actual/versiones. Si se perdió respuesta de alta, reintentar con clave original; no generar otra para “asegurar” la creación. Reutilizar clave con contenido distinto devuelve 409.

## 5. Asignar conductor, cabezal y cisterna

**Pantalla:** detalle del viaje → asignaciones. **Actor:** oficina.

1. Lookups reales muestran candidatos. `GET /api/transport/availability` consulta `TripAssignmentController::availability()`/`AssignmentAvailability::inspect()`.
2. `POST /api/transport/trips/{id}/assignments/assign` envía `resource_kind`, `resource_id` y motivo. Los tipos son `driver`, `tractor_unit`, `tanker`.
3. `AssignmentWriteRequest` valida formato; `AssignmentAdministration::assign()` vuelve a comprobar elegibilidad/conflictos bajo transacción. La consulta previa no garantiza disponibilidad al confirmar.
4. Se crean `driver_assignments` o `trip_asset_assignments`, y `transport_assignment_operations`. Conflictos detectados tienen evidencia en `transport_assignment_conflicts` cuando el proceso los registra.
5. Si corresponde excepción, pedir autorización por `/authorizations` del viaje; administrador decide por `/api/transport/authorizations/{id}/decisions`. La decisión debe coincidir con operación/datos exactos. No permite usar activo mecánicamente bloqueado.

**Usuario recibe:** asignación confirmada o rechazo/conflicto explicado. Para sustituir conductor/cabezal durante viaje se usa la operación autorizada; sustitución de cisterna continúa bloqueada. Para liberar, `/assignments/release` crea `driver_releases`/`trip_asset_releases`; no borra asignaciones.

## 6. Inicio, carga y descarga por compartimento

**Pantalla:** detalle → operaciones. **Actor:** oficina.

1. `POST /api/transport/trips/{id}/start` con versión/motivo → `TripController::action()` → `TripOperations::start()`. Requiere asignaciones válidas, no una lista vacía que Vue permita enviar.
2. `POST /api/transport/trips/{id}/load` envía lugar, instante, motivo y `items` con `compartment_id`/`liters`. `TripOperationController::store()` y `TripOperations::load()` crean `trip_loads`/`load_items`, preservando referencias/capacidad históricas.
3. Ejemplo: compartimento A carga `5000.00` y B `4000.00`. La capacidad real debe permitirlo. No sumar dos productos diferentes.
4. `POST /api/transport/trips/{id}/unload` utiliza `load_item_id`, no un compartimento arbitrario de otra carga. `TripOperations::unload()` crea `trip_unloads`/`unload_items`.
5. Descargar `5000.00` y `3990.00` deja diferencia `10.00`. `GET .../volume-differences` la consulta; no se decide desde un total formateado en Vue.
6. Si faltó un detalle, las rutas `POST .../load/items` y `.../unload/items`, mediante `addDetails()`, permiten completar lo autorizado mientras el estado lo admite. No editan/eliminan silenciosamente detalles existentes ni permiten reparar un histórico cerrado por un atajo.

**Usuario recibe:** operación/ítems confirmados y totales. Intentar vincular detalle de descarga a carga ajena, exceder capacidad o completar fuera del estado permitido se rechaza en servidor/PostgreSQL.

## 7. Diferencia de litros, finalización, cierre y liberación

D02 exige inicio oficial, asignaciones históricas correspondientes, una carga y descarga con fecha/lugar/responsable/cantidades y descarga de cada detalle cargado, incluso cero. No exige un reporte informativo del conductor como requisito adicional.

1. Oficina solicita autorización exacta por diferencia; administrador revisa contenido y decide. `transport_authorization_requests`, `transport_authorization_decisions` y su aplicación son hechos separados.
2. `POST /api/transport/trips/{id}/finish`, versión/motivo/`decision_id` cuando hay diferencia, llama `TripOperations::finish()`.
3. PostgreSQL comprueba correspondencia de operaciones/asignaciones y contenido de autorización. No se puede reutilizar para otros litros/viaje ni completar con una autorización genérica anterior.
4. `POST .../close` registra cierre administrativo; puede conservar advertencias pendientes. El viaje cancelado puede cerrarse sin inventar finalización oficial; pendiente/en curso no cumple esa condición terminal.
5. Recursos continúan asignados. La oficina ejecuta liberaciones manuales individuales. Sus actores/fechas permanecen separados de finalización/cancelación/cierre.

**Usuario recibe:** estados independientes e historial. No existe un botón que convierta el reporte “ya terminé” del conductor en finalización oficial.

## 8. Capturar offline y sincronizar

**Pantalla:** `/mis-viajes`, conductor asignado. **Backend:** `AssignedTripController`, `DriverTripRecordController`, `DriverTripRecords`, `ExpenseAdministration`.

1. Con conexión, consultar `/api/transport/my-trips` y detalle autorizado. `cacheTrip()` guarda proyección mínima con actor/contexto en IndexedDB.
2. Sin conexión, abrir aplicación instalada previamente y viaje descargado. Registrar lectura por `/transport/my-trips/{id}/odometer` o reporte por `/reports`; la ruta queda en captura, todavía sin POST exitoso.
3. `newCapture()` agrega UUID estable. `saveCapture()` confirma almacenamiento local; interfaz dice pendiente local. Un comprobante de gasto conserva bytes y dependencia del gasto padre.
4. Reiniciar el navegador no debe borrar IndexedDB mientras el navegador conserve el almacenamiento. Esto es persistencia local, no copia de seguridad garantizada.
5. Recuperar conexión con aplicación abierta y sincronizar. `offline.ts` obtiene Web Lock, comprueba sesión, refresca CSRF y envía la misma intención.
6. Backend confirma `trip_events`/`trip_odometer_readings` con `driver_operation_receipts`, o `expenses`/revisión/recibo. El archivo dependiente se envía después del ID del gasto.
7. Confirmación inequívoca cambia a sincronizado y libera bytes del archivo. Timeout conserva captura; 409 la deja en conflicto sin sobrescribir datos actuales.

**Usuario recibe:** estados pendientes/sincronizando/sincronizados/rechazados/conflicto. No puede cambiar de cuenta para enviar los pendientes como otro conductor. No se promete sincronizar cerrada.

## 9. Avería, ingreso, reparación y salida

**Pantalla:** `/taller`. **Actores:** oficina crea/coordinada, mecánico técnico, administrador autoriza/cierra/salida.

| Paso | Endpoint real bajo `/api/workshop` | Clase/método principal | Registros |
| --- | --- | --- | --- |
| Reportar avería | `POST reports` | `WorkshopCore::execute()` | `workshop_reports`, operación |
| Ingreso físico | `POST assets/{asset}/admissions` | `WorkshopAvailability::admit()` | ingreso, impedimento, advertencias/operación |
| Orden oficial | `POST orders` | `WorkshopCore::execute()` | orden, operación |
| Vincular ingreso | `POST orders/{order}/admission-links` | `WorkshopAvailability::linkOrder()` | vínculo trazable |
| Asignar mecánico | `POST orders/{order}/mechanic-assignments` | `WorkshopCore::execute()` | asignación/operación |
| Diagnóstico | `POST orders/{order}/diagnoses` | `WorkshopCore::execute()` | diagnóstico/operación |
| Proponer alcance | `POST orders/{order}/scopes` | `WorkshopAuthorizations::requestScope()` | alcance, tareas, partes, solicitud |
| Decidir | `POST authorization-requests/{authorization}/decisions` | `WorkshopAuthorizations::decide()` | decisión exclusiva del administrador |
| Aplicar alcance | `POST decisions/{decision}/apply-scope` | `WorkshopAuthorizations::applyScope()` | aplicación, estado/operación |
| Actividades y tiempo | `POST orders/{order}/activities`, `/time-events` | `WorkshopExecution::recordActivity()/recordTime()` | actividades/eventos/operación |
| Terminar | `POST orders/{order}/finish` | `WorkshopExecution::transition()` | estado/auditoría |
| Cerrar | `POST orders/{order}/close` | `WorkshopExecution::transition()` | revisión administrativa/estado |
| Solicitar salida | `POST admissions/{admission}/exit-requests` | `WorkshopAvailability::requestExit()` | solicitud exacta de salida |
| Decidir/aplicar salida | decisión y `POST decisions/{decision}/exit` | `decide()` / `WorkshopAvailability::exit()` | salida/resoluciones/aplicación |

Cada comando usa clave y contenido/versiones según su Form Request. El ingreso conserva viajes/asignaciones existentes; muestra advertencia y bloquea nuevas asignaciones. Cerrar orden no resuelve automáticamente todas las causas. Antes de salir se revisan impedimentos de todas las órdenes correspondientes.

Una nueva avería distinta exige nueva orden. Una ampliación de la misma reparación crea alcance independiente y nueva autorización; trabajos ya aprobados pueden continuar. Evidencia privada usa reserva, transferencia/confirmación y consulta de recibo, no URL pública.

## 10. Compra, recepción, entrega, consumo y devolución

**Pantallas:** Taller → Compras → Inventario → Taller.

1. Necesidad: `POST /api/workshop/orders/{order}/material-needs` → `WorkshopMaterialController`/`WorkshopMaterials::requestMaterial()`, fila `workshop_material_needs`.
2. Solicitud: `POST /api/purchasing/requests` → `PurchaseRequestController`/`PurchaseRequestAdministration::record()`, cabecera/líneas ligadas a necesidad opcional.
3. Administrador decide por `POST .../requests/{id}/decisions`: decisión no aumenta stock.
4. Crear orden por `POST /api/purchasing/orders` → `PurchaseOrderController`/`PurchaseFulfillment::record()`, `purchase_orders`/items desde aprobación.
5. Recibir físicamente 2 de 5 por `POST .../orders/{id}/receipts`: recepción/items y `InventoryLedger::receive()` confirman movimiento de entrada en la misma transacción. Otra recepción de 3 completa; reintento de la primera no suma 2 otra vez.
6. Si es inventario físico inicial, primero tramitar conciliación mediante `/api/inventory/corrections`, decisión administrativa y aplicación. Un saldo no conciliado no se inventa como cero conocido.
7. Entregar 4 de esas 5 a asignación del mecánico/orden: `POST /api/workshop/orders/{order}/deliveries` → `WorkshopMaterials::deliver()` → `workshop_part_deliveries`; `inventory_sources()` crea bodega −4, custodia +4.
8. Mecánico confirma uso de 3 por `/usages` → `usePart()` y `workshop_part_usages`; trigger custodia −3, consumido +3. No requiere que administrador apruebe cada uso ya permitido por alcance.
9. Oficina recibe físicamente 1 sobrante por `POST /api/inventory/returns` → `InventoryLedger::returnParts()`: devolución inmutable y delta custodia −1/bodega +1.

**Resultado:** del lote recibido de 5, quedan bodega 2, custodia 0, consumido 3. La aplicación conserva responsables/fechas/orígenes. No es costo contable calculado. No permite uso mayor a entrega ni devolución ficticia para compensar un error sin recibir material.

## 11. Importe aprobado, factura interna y pagos

**Pantalla:** `/facturacion`. **Actor:** oficina; aprobación exclusiva administrador.

1. Viaje finalizado aparece en consulta `GET /api/billing/trips`. Oficina propone `POST /api/billing/trips/{trip}/amounts`, importe cadena `"100.00"`, CRC, motivo y clave.
2. `BillingController::propose()` → `BillingPayments::propose()`, `billing_trip_amounts`/operación. Aún no es facturable por propuesta pendiente.
3. Administrador decide por `POST /api/billing/amounts/{id}/decisions` → `decide()`, `billing_amount_decisions`.
4. `POST /api/billing/invoices` envía cliente y detalles con `trip_id`, `price_proposal_id`, descripción y clave. `invoice()` crea `billing_invoices`, `billing_invoice_items`, operación; cada detalle conserva versión aprobada.
5. `GET /api/billing/invoices/{id}` devuelve total/pagado/saldo y detalle. No se actualiza un campo total a mano.
6. `POST .../payments` envía `amount: "30.00"`, CRC, `paid_at` con zona, motivo/clave y medio opcional. `payment()` bloquea y valida saldo, inserta `billing_payments` y recibo.
7. Otro pago de `"70.00"` deja saldo cero. Repetir el primero con misma clave recupera resultado, no reduce saldo otra vez. Sobrepago o moneda diferente se rechaza.

**Usuario recibe:** pendiente, parcialmente pagada o pagada según saldo. No recibe comprobante fiscal ni conversión USD/CRC. Una factura interna agrupa viajes del mismo cliente/moneda; no un viaje dos veces.

## 12. Gasto con comprobante y decisión

**Pantalla:** `/gastos` o conductor en `/mis-viajes`.

1. `POST /api/expenses`, concepto/categoría/monto/moneda/fecha/descripción/viaje opcional, clave estable. `ExpenseController::store()` → `ExpenseAdministration::create()` crea identidad `expenses`, versión `expense_revisions` y `expense_operations`.
2. `POST /api/expenses/{id}/receipts` multipart con archivo real/clave → `receipt()` y almacenamiento privado. Metadatos en `expense_receipts`; no se expone ruta física.
3. Administrador usa `POST .../{id}/decisions` con `expected_revision_id`, decisión y motivo → `decide()`. `expense_decisions` acredita revisión/aprobación o rechazo, no pago.
4. Corrección autorizada por oficina usa `/corrections`, `supersedes_id` y motivo; conserva anterior y genera versión pendiente. Una aprobación vieja no aprueba el importe corregido.
5. Descargar por `GET /api/expenses/receipts/{receipt}/download` vuelve a autorizar actor/recurso.

**Usuario recibe:** gasto y estado de su versión, historial/comprobante permitido. Ni factura ni saldo de cliente cambian.

## 13. Documento vencido, alerta y revisión

**Pantallas:** `/documentacion` y `/alertas`, administrador/secretaria.

1. Consultar activos/tipos y registrar por `POST /api/fleet-assets/{assetId}/documents` → `DocumentAdministration::register()`: archivo privado, `documents`, snapshot y referencia actual en una operación coordinada.
2. En día local, vencimiento de hoy sigue válido todo el día pero aparece próximo a vencer; anterior al día actual está vencido; 0–15 días próximos, más de 15 vigente. Tipo sin vencimiento no genera vencimiento ficticio.
3. `GET /api/document-alerts` → `DocumentAlerts::query()` filtra solo documentos actuales relevantes. `GET .../summary` alimenta indicadores.
4. `POST /api/document-alerts/{id}/review` → `review()` inserta `document_alert_reviews` única, conservando actor/fecha. No edita ni renueva documento.
5. Renovar usa `POST /api/fleet-assets/{assetId}/documents/{id}/renew`. El nuevo pasa a actual; la revisión histórica del anterior no revisa automáticamente el nuevo.

**Usuario recibe:** fecha/días/estado y enlaces al original, sin bloqueo automático de asignaciones por esta alerta informativa.

## 14. Consultar reportes y auditoría

**Pantallas:** `/reportes` y `/auditoria`, administrador.

1. `GET /api/reports/{kind}` → `ReportController::show()` → `AdministrativeReports::consult()`: período/filtros se validan y la lectura consistente produce tablas/totales paginados. No escribe operaciones.
2. Facturado, cobrado y saldo son medidas diferentes; gastos aprobados no son reembolsos. Las monedas se separan. Una relación uno-a-muchos no multiplica sumas.
3. `GET /api/audit` → `AuditController::index()` → `AdministrativeAudit::consult()` reúne fuentes fijas. `GET /api/audit/{source}/{id}` muestra evidencia permitida, no payloads completos indiscriminados.
4. El administrador puede abrir módulos/registros originales cuando existe enlace. Ausencia de un evento histórico no se rellena con una suposición.

**Tablas cambiadas:** ninguna por estas lecturas de negocio. La sesión/infraestructura puede mantener su propia actividad técnica; eso no es crear una operación auditada ficticia.
