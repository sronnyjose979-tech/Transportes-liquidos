# Propuesta técnica actualizada para revisión

> Referencia histórica del borrador inicial. Para Transporte y Despacho y sus
> contratos futuros prevalece [etapa-5-1.md](etapa-5-1.md); el esquema de flota y
> documentos vigente se describe en ARCHITECTURE.md y etapas 4.x. Este borrador
> conserva hipótesis sustituidas: liberación automática, pagos parciales, bloqueo
> documental y cierre sin pendientes. No utilizarlas como requisitos de 5.2.
> Fuente vigente: [REGLAS_TRANSPORTE_DESPACHO.md](REGLAS_TRANSPORTE_DESPACHO.md).
> RN-TR-17 permanece pendiente. Producto histórico y excepciones de recursos
> requieren el diseño de 5.1; prohibiciones absolutas del borrador no prevalecen.

Estado: borrador conceptual/lógico. Conserva el diseño existente, incorpora las correcciones de la empresa y distingue requisitos de decisiones propuestas. La implementación queda posterior a la revisión y aprobación del ER. No se generan migraciones, modelos Eloquent ni SQL.

## Requisitos confirmados

- Facturación semanal por litros netos recibidos y aceptados; una factura agrupa viajes completados del mismo cliente, incluso de distintas solicitudes, mediante `InvoiceItem`. Nombres técnicos en inglés e interfaz en español.

- Transporte de líquidos para clientes externos; taller propio exclusivamente para flota de la empresa. Operación actual principalmente con papel y Excel.
- Aproximadamente 26–50 usuarios, acceso remoto y offline para conductores.
- Administrador con acceso completo. Secretario: solicitudes, despacho, viajes, documentos, facturas y gastos. Conductor: viajes asignados y capturas móviles. Mecánico: diagnósticos, reparaciones y repuestos.
- Solo cabezales y cisternas independientes e intercambiables entre viajes; sin camiones rígidos. Algunas cisternas tienen divisiones físicas.
- Cada solicitud tiene un cliente, un producto, litros totales, origen y destino mediante una ruta, una tarifa fija cliente/ruta y cero o muchos viajes. Cada viaje pertenece a una solicitud y transporta su único producto; tiene un conductor responsable vigente, una carga y una descarga y puede durar varios días. Cantidades en litros. Esta regla sustituye el supuesto previo de varios productos en un viaje.
- Mantenimiento correctivo, diagnósticos, reparaciones, repuestos y costos; autorización del administrador para reparaciones/compras cuando corresponda. Preventivo para fase posterior.
- Inventario, compras, facturación, contado/crédito, pagos parciales, saldos y gastos desde primera versión.
- Documentos de activos, trabajadores, viajes, reparaciones y compras; archivos privados y referencias en PostgreSQL. Control de vencimiento cuando aplique; unidades fuera de servicio o con documentos obligatorios vencidos no se asignan.
- Offline inicial: viajes descargados, salida, llegada, kilometraje, combustible, gastos e incidencias. Asignaciones, autorizaciones y cierre administrativo en línea.

## Decisiones de diseño propuestas

### Continuidad y alcance del ER

Se conservan las entidades iniciales de acceso, personal, clientes, flota, viaje, asignaciones de activos, productos, puntos, órdenes, tareas y lecturas. LegacyTripCrew se reemplaza por DriverAssignment. LegacyLiquidMovement se reemplaza por TripLoad, LoadItem, TripUnload y UnloadItem: no permanece otra fuente de cantidades.

UUID para identificadores; fechas con zona y almacenamiento UTC. NUMERIC/decimal para litros y dinero, con precisión, moneda y redondeo por acordar. Los atributos son iniciales; claves, nulabilidad y restricciones definitivas se diseñarán después de aprobación. La auditoría y el registro de operaciones sincronizadas son infraestructura transversal fuera del ER comercial.

### Flota, compartimentos y asignaciones

FleetAsset es supertipo. TractorUnit y Tanker comparten su PK como FK al activo: cada especialización corresponde exactamente a un activo (1:1). Desde el activo cada rama es 0..1, pero entre ambas hay exclusividad y totalidad: exactamente una especialización, coherente con tipo. Dos FK por sí solas no garantizan esta regla; requiere integridad adicional al implementar.

Propuesta: toda cisterna posee al menos un Compartment lógico; sin divisiones físicas, exactamente uno que representa toda su capacidad. Capacidad en litros; capacidad total derivada de compartimentos. Conservar configuración/capacidad vigente al cargar mediante historial o instantánea por definir; no modificar retrospectivamente compartimentos usados.

TripAssetAssignment conserva intervalos [starts_at, ends_at), con ends_at nulo para vigencia abierta y tipo obtenido del activo. Despachado/en curso exige exactamente un cabezal y una cisterna vigentes. Prohibir solapamientos del mismo tipo y del mismo activo entre viajes incompatibles; comprobar también reservas previstas, cambios de horarios y viajes de varios días. Sustitución cierra la anterior y crea la nueva en una transacción con motivo y auditoría. Cancelación libera reservas; final de ejecución libera recursos sin esperar cierre administrativo.

DriverAssignment mantiene uno vigente e historial excepcional, sin tripulación múltiple. Validar función conductor y no doble asignación incompatible. Borradores pueden no tener recursos. Sustituciones se validan en línea. Cambio de cabezal no altera carga; cambio de cisterna con líquido a bordo requiere transbordo aún pendiente: propuesta inicial, bloquearlo hasta definir el procedimiento.

Disponibilidad se deriva de servicio, reservas/asignaciones, órdenes que inmovilizan y documentos; no debe ser un indicador manual que eluda bloqueos.

### Solicitudes, rutas y tarifas

**Confirmado:** `TransportRequest` pertenece a un `Customer`, corresponde a un `LiquidProduct`, tiene `requested_liters` y utiliza una `Route`. Puede existir con cero viajes y luego generar muchos; cada `Trip` pertenece obligatoriamente a una solicitud. Diferentes viajes pueden tener conductores, cabezales y cisternas diferentes mediante las asignaciones ya existentes. No se crea `TransportRequestItem`: no hay múltiples productos por solicitud.

`Route` representa origen y destino comerciales mediante `origin_site_id` y `destination_site_id`, ambos FK obligatorias a `OperationSite`. Una ruta admite muchas solicitudes; ambas relaciones con los lugares son distintas y dirigidas. No se crean puntos duplicados para el mismo lugar. Identidad de ruta por par origen/destino es propuesta a validar si hay variantes comerciales. No se diseña trazado vial ni GPS. Propuesta de conservación histórica: al referenciar una ruta desde una solicitud/tarifa, congelar sus origen y destino. Cambiar esos lugares crea otra ruta; no editar extremos de una ruta referenciada. Cambios de dirección/identificación de OperationSite que alteren el lugar real también requieren historial, sin trasladar retroactivamente operaciones.

`Trip` mantiene `transport_request_id` obligatorio; se eliminan su `customer_id` redundante y cualquier necesidad de `route_id`, `liquid_product_id` o tarifa propia. Cliente, producto, ruta y tarifa se obtienen desde la solicitud. Los lugares reales siguen en `TripLoad.operation_site_id` y `TripUnload.operation_site_id`, con fechas e incidencias en `TripEvent`. Validar los lugares reales frente a origen/destino comerciales; una desviación se registra como incidencia y requiere revisión, sin reescribir la ruta ni borrar el hecho ocurrido. Procedimiento de autorización de excepciones pendiente; no asumir que se puede completar/facturar una desviación sin resolverla.

**Tarifa confirmada:** depende del cliente y de la ruta, se cobra por litro y permanece fija durante toda la solicitud. Dos clientes pueden usar la misma ruta con precios distintos. Nuevos acuerdos futuros no alteran solicitudes pasadas.

**Diseño propuesto:** `TransportRate` es una versión inmutable de tarifa negociada, con `customer_id`, `route_id`, `rate_per_liter`, `currency` y `agreed_at`. Cada negociación genera otra fila, incluso para la misma pareja cliente/ruta. No imponer UNIQUE sobre esa pareja, pues impediría historial. Una versión puede respaldar muchas solicitudes; cada solicitud referencia exactamente una mediante `transport_rate_id`.

El precio acordado de la solicitud se conserva mediante esa referencia a una versión congelada: no se copia otro precio editable en `TransportRequest`, ni se busca la tarifa «actual» al facturar. Una tarifa asociada no cambia de cliente, ruta, precio ni moneda. Desactivarla para nuevas solicitudes no invalida referencias históricas. Propuesta: también las versiones sin uso se reemplazan por otras para mantener una regla uniforme. La selección de versión es explícita; no elegir automáticamente por precio más reciente sin confirmar política.

Garantizar `transport_requests.customer_id = transport_rates.customer_id` y `transport_requests.route_id = transport_rates.route_id` con validación transaccional y restricciones de integridad entre tablas a definir en el diseño físico. Son referencias consistentes, no tres elecciones independientes. Congelar cliente/producto/ruta/tarifa al confirmar la solicitud y antes de despachar sus viajes (propuesta); cambios posteriores de condiciones o volumen requieren procedimiento aún pendiente. El ER exige los cuatro vínculos al persistir una solicitud; si se desea guardar solicitudes preliminares sin tarifa, confirmar esa excepción antes de cambiar la participación obligatoria.

No se agrega `TransportAgreement`: un precio por solicitud queda cubierto por la versión de `TransportRate` referenciada. Solo reevaluar un acuerdo separado si aparecen contratos con obligaciones adicionales, múltiples condiciones o vigencias jurídicas que no estén representadas aquí.

**Avance de la solicitud:**

- Litros solicitados: `requested_liters`, positivo.
- Litros cargados: suma de `LoadItem.loaded_liters` de sus viajes, para seguimiento operativo.
- Litros descargados: suma de `UnloadItem.delivered_liters`, que representa la medición de descarga, no aceptación automática.
- Litros recibidos y aceptados: suma de `UnloadItem.accepted_liters` con aceptación confirmada y evidencia válida de todos los viajes de la solicitud. No dependen de factura, pago ni cierre administrativo.
- Balance = solicitados − aceptados. Pendientes = máximo(0, balance); exceso = máximo(0, −balance). Mostrar exceso por separado para no ocultarlo. Política de sobreentrega aún pendiente.

Los totales son derivados, sin mantener contadores editables duplicados. Una aceptación pendiente no se trata como cero definitivo; mostrar también viajes/mediciones por confirmar. Ejemplo ilustrativo: una solicitud de 6.000.000 litros con entregas aceptadas de 30.000 y 28.000 litros acumula 58.000 aceptados y 5.942.000 pendientes, independientemente de los viajes todavía en organización o de si esas entregas ya se facturaron.

Propuesta de estados de solicitud: `draft` → `confirmed` → `in_progress` → `fulfilled` → `closed`. Confirmar fija condiciones; iniciar ocurre con ejecución; cumplir depende de aceptación y revisión de diferencias, no del número de viajes. Reglas exactas de cierre, sobreentrega, cancelación y cambios de volumen siguen pendientes. No anular litros ya aceptados automáticamente por cambiar estado del viaje.

### Carga y descarga

Unicidad de trip_id en cada cabecera: máximo una carga y una descarga. Pueden no existir antes de captura; al completar ejecución ambas se exigen confirmadas y con detalles. Punto y fecha pertenecen a cabecera.

**Se conservan `TripLoad`, `LoadItem`, `TripUnload` y `UnloadItem`.** Las cabeceras mantienen una operación de carga y una de descarga por viaje, lugares reales, fechas y estados. Los detalles siguen siendo necesarios para cantidades por compartimento y trazabilidad carga–descarga; no se añaden entidades de producto o recibo innecesarias.

`LoadItem` guarda compartimento y `loaded_liters` positivos. Se elimina su `liquid_product_id`: producto derivado de `TripLoad` → `Trip` → `TransportRequest`. Todos los compartimentos usados en un viaje llevan el mismo producto. Validar cisterna asignada, capacidad vigente y una fila por compartimento/carga (unicidad propuesta). La condición de compartimento lógico para cisternas sin divisiones permanece. Compatibilidad con residuos/cargas anteriores y limpieza sigue pendiente; esta regla de un producto no autoriza mezcla.

`UnloadItem` referencia `LoadItem` y registra por separado:

- `delivered_liters`: litros realmente descargados medidos, no negativos.
- `accepted_liters`: litros netos efectivamente recibidos y aceptados en destino, no negativos cuando conocidos; nulo mientras se determina aceptación. Nunca copiar automáticamente los litros cargados o descargados como aceptación.
- `acceptance_status`, `accepted_by_id`, `accepted_at`: estado, usuario que valida la evidencia y momento de aceptación. Propuesta: `pending` / `confirmed`; procedimiento de rechazo, corrección y permisos pendiente. El usuario no se presume receptor externo: registra la evidencia del destino.
- `difference_reason`: explicación/revisión de diferencias. Documentos de recepción se adjuntan al viaje con la estructura privada existente, sin una tabla nueva de recibos.

Se mantiene la propuesta de una fila de descarga por detalle de carga, FK `load_item_id` única, perteneciente al mismo viaje. Antes de confirmar descarga cubrir todos los detalles cargados, incluso descarga cero con motivo. La aceptación puede quedar pendiente después de registrar descarga; viaje completado no equivale a aceptación confirmada ni a facturable. Antes de facturar, todas las cantidades de recepción del viaje deben tener aceptación confirmada. Si se confirma aceptación solo a nivel global de destino, validar cómo distribuirla por compartimento antes de dar esta granularidad por definitiva.

Totales del viaje, derivados:

- Cargado = suma de `loaded_liters`.
- Descargado = suma de `delivered_liters`.
- Neto recibido/aceptado = suma de `accepted_liters` confirmados.
- Diferencia operativa = cargado − descargado.
- Diferencia de aceptación = descargado − aceptado.
- Diferencia total = cargado − aceptado.

No asumir tolerancia ni equivalencia entre estas cantidades. Diferencias de cualquier signo se conservan para revisión. Propuesta: verificar aceptación frente a medición descargada y exigir revisión si la supera; no definir rechazo automático ni ajustes de temperatura sin confirmar método de medición. Guardar motivos/evidencia y auditoría. Una aceptación facturada no se modifica silenciosamente; correcciones requieren política aún pendiente.


### Taller, inventario y compras

WorkOrder atiende un activo propio. Diagnosis guarda hallazgos y autor; WorkTask describe reparación, participantes y costo de mano de obra. PartConsumption vincula repuesto con tarea; orden derivada, sin FK redundante. Costo aplicado queda histórico. Total de orden = mano de obra + repuestos aplicados, por moneda, sin sumar nuevamente la compra. Otros costos pendientes.

Existencia = suma de signed_quantity de InventoryMovement por repuesto/almacén. Warehouse es propuesta: uno lógico inicial, ubicaciones por validar. Entrada por compra positiva con purchase_item_id; consumo negativo con part_consumption_id único; ajuste con motivo, autor y permiso. reversal_of_id opcional única identifica compensación del original, sin editarlo. Referencias permitidas/obligatorias según tipo; no combinar orígenes incompatibles. Reversa referencia al original y deriva su trazabilidad; ajuste no necesita compra ni consumo.

Aplicar consumo y crear una única salida en la misma transacción. Borrador no afecta stock; aplicado exige salida original. Anulación crea reversa una vez. No descontar nuevamente al cerrar tarea/orden. Comprobar repuesto/unidad/cantidad. Propuesta: bloquear stock negativo y serializar operaciones por repuesto/almacén. Método de valorización pendiente.

Confirmar Purchase no aumenta stock: recepción crea entradas. Varias entradas por PurchaseItem permiten recepción parcial; acumulado y reintentos controlados. PurchaseRequest con detalles representa autorización previa. Purchase puede referenciar solicitud autorizada; si la aprobación es directa, registrar administrador y fecha en la compra; omitirla solo si política confirmada lo permite. Validar cantidades/repuestos cubiertos y acumulado entre compras. Varias compras por solicitud es propuesta; si se aprueba cumplimiento parcial, agregar vínculo entre detalles antes de implementar. Sobreentregas y devoluciones pendientes. Preventivo no se implementa como obligación inicial.

### Facturación, pagos y gastos

**Confirmado:** facturación semanal por litros netos efectivamente recibidos y aceptados. Una `Invoice` agrupa viajes completados del mismo cliente, incluso pertenecientes a distintas solicitudes. Cada `InvoiceItem` identifica obligatoriamente un viaje mediante `trip_id`; cliente obtenido de `Trip.transportRequest.customer` debe coincidir con `Invoice.customer`. Viajes en curso o con aceptación pendiente no son elegibles.

Se sustituyen `quantity` y `unit_price` del detalle por `billed_liters` y `applied_rate_per_liter`; se incorpora `subtotal`. La cantidad ahora son litros, nunca `quantity = 1` por viaje. `subtotal = billed_liters × applied_rate_per_liter`, calculado y validado por el servidor con el redondeo por confirmar. `tax_amount` se conserva aparte; reglas fiscales pendientes.

La tarifa aplicada debe ser la versión acordada de `TransportRequest.transportRate`, no una tarifa futura. El detalle conserva como instantánea histórica litros facturados, tarifa por litro y subtotal. La solicitud obtiene su tarifa por referencia inmutable; la factura conserva los valores efectivamente emitidos para que su historia no se reconstruya desde precios futuros. Una factura puede contener viajes con tarifas distintas porque proceden de solicitudes distintas del mismo cliente. Validar moneda de cada tarifa frente a factura; conversión de moneda pendiente, no convertir automáticamente.

`Invoice.billing_period_start` y `billing_period_end` identifican el período semanal (propuesta de atributos). Día de corte, zona horaria y fecha de elegibilidad (completado o aceptación) pendientes; no inventar lunes/domingo ni excluir entregas tardíamente aceptadas. Registrar viajes pendientes de facturar en semanas posteriores. El número de viajes no determina precio ni saldo de solicitud.

**Control de doble facturación sin decidir facturación parcial:**

1. Litros facturables del viaje = suma de aceptación confirmada y válida, con todas sus recepciones revisadas. No usar litros cargados ni descargados como sustituto.
2. Litros ya facturados = suma de `billed_liters` de detalles en facturas emitidas que siguen teniendo efecto. Mientras no se defina anulación/crédito, conservarlos como consumidos, sin liberación automática.
3. Reservas de borradores vigentes = suma de litros asignados a detalles todavía no emitidos. Descartar o quitar un detalle de borrador libera solo su reserva con auditoría.
4. No permitir que facturados + reservas superen litros facturables. Validar al guardar y al emitir, bajo una transacción que serializa operaciones por viaje. Al emitir, la reserva propia pasa a facturada, no se cuenta dos veces. UUID/idempotencia impide reemisión por reintento, pero no sustituye el control del acumulado entre facturas diferentes.
5. No habilitar reparto parcial por el solo hecho de disponer de cantidades. La política debe confirmarse antes de implementar ese flujo. Cantidad autorizada para cada emisión, reversión, notas de crédito y ajustes sigue pendiente.

`Trip` → `InvoiceItem` se representa como 1 : 0..N **con límite superior provisional**: cada detalle tiene un viaje obligatorio, que puede no estar facturado todavía. No es una aprobación de facturación parcial. Si se confirma facturación íntegra en un solo detalle, ajustar a 1 : 0..1 y proponer `UNIQUE(invoice_items.trip_id)`; la cantidad del detalle seguirá siendo litros netos aceptados, no una unidad/viaje. Si se confirma parcial, conservar 0..N y controlar litros acumulados; UNIQUE por viaje impediría ese caso. No fijar ahora UK en el ER. Tampoco basta una unicidad factura/viaje para evitar cobro duplicado en otra factura.

Las reglas de igualdad de cliente, tarifa de solicitud, aceptación, reserva y acumulado se validan en el servidor y con integridad apropiada en el diseño físico posterior; las FK solas no garantizan estas reglas entre tablas. Congelar factura emitida y bloquear cambios de condiciones/aceptación que la invaliden. No inventar facturación parcial, notas de crédito, liberación tras anulación ni ajustes fiscales.

Ejemplo conceptual: dos viajes aceptados de 30.000 y 28.000 litros de una solicitud con tarifa acordada r generan subtotales 30.000 × r y 28.000 × r. Si se agregan viajes de otra solicitud del mismo cliente, cada línea utiliza su tarifa acordada; si pertenecen a otro cliente, se rechazan. No hay cobro «por dos viajes».

`Invoice` conserva contado/crédito, moneda, emisión y vencimiento. Pagos parciales siguen permitidos: fraccionar pago no implica permitir facturación parcial del volumen de un viaje. Conceptos independientes sin viaje no están definidos en el alcance de factura de transporte; confirmar necesidad antes de introducirlos. No utilizar líneas genéricas para volver a cobrar litros ya facturados.

Payment y PaymentAllocation permiten varios pagos parciales de una factura. Distribuir un pago entre varias facturas es propuesta pendiente. Comprobar cliente/moneda; aplicaciones no exceden importe del pago ni saldo de factura. Reversiones conservan evidencia. Total derivado de detalles e impuestos/descuentos validados; saldo = total − aplicaciones vigentes. Contado no significa pagada sin pago confirmado. No definir reglas fiscales ni integración de Hacienda aún.

Expense mantiene importe canónico, moneda, autor, fecha, estado y viaje opcional. TripEvent de tipo gasto o combustible origina como máximo un Expense con FK única: reenvío no duplica gasto. Combustible: litros en registro y dinero en gasto vinculado si existe. Kilometraje: AssetReading ligada a registro y activo correspondiente, sin repetir valor en evento. Capturas pendientes no se contabilizan como gastos aprobados; revisión/reembolso/comprobantes pendientes.

### Documentación

Document guarda clave privada de archivo, tipo, fechas opcionales, estado y autor. DocumentLink usa siete destinos con FK explícitas: activo, personal, viaje, orden, compra, gasto y factura. Exactamente una FK destino por fila; otras nulas. Cada documento persistido tiene uno o más vínculos y pareja documento/destino única. Permite archivo compartido conservando integridad sin referencias polimórficas libres. Cabezal/cisterna se documentan mediante FleetAsset.

Acceso a archivo solo tras autorización, rutas no públicas y enlaces temporales si procede. Reemplazo conserva versión anterior. Vencimiento no implica obligatoriedad: catálogo de requisitos por activo/trabajador/jurisdicción pendiente. Asignar/despachar comprueba existencia, vigencia y aceptación de todos los documentos obligatorios, incluida ausencia. Vencimiento durante viaje requiere alerta y política por definir. Respaldar archivos y base coherentemente.

## Convenciones técnicas confirmadas

Todos los identificadores técnicos se escriben en inglés. Modelos: singular y PascalCase (`InvoiceItem`). Tablas: plural y snake_case (`invoice_items`). Atributos: snake_case (`issued_at`). FK: nombre de entidad o función terminada en `_id` (`customer_id`, `approved_by_id`). Relaciones/métodos: camelCase en inglés; singular para una entidad (`customer`, `invoice`, `trip`) y plural para colecciones (`invoiceItems`). Métodos de negocio candidatos: `issueInvoice`, `assignTrip`, `approvePurchase`. Son nombres de diseño, sin código implementado.

Los módulos y namespaces usan PascalCase; `Audit` y `Synchronization` son capacidades transversales. Valores técnicos de estado en inglés y etiquetas visibles traducidas: `draft` → Borrador, `issued` → Emitida, `partially_paid` → Pago parcial. La interfaz, mensajes de validación y explicaciones funcionales permanecen en español. Los nombres de archivos solicitados se conservan.

`UserRole` y `TaskAssignment` se tratan como entidades de asociación explícitas con tablas plurales `user_roles` y `task_assignments`. No se presentan como tablas pivote inferidas automáticamente por Laravel (que tienen otra convención). La PK compuesta indicada sigue siendo conceptual: resolver persistencia de asociaciones antes de implementar Eloquent. `TractorUnit` y `Tanker` conservan `fleet_asset_id` como PK/FK compartida; configurar esa especialización explícitamente cuando corresponda. FK de actor (`approved_by_id`, `created_by_id`, `mechanic_id`) apuntan a la entidad indicada por el ER.

La correspondencia automática entre modelo y tabla plural snake_case sigue la [documentación oficial de Laravel](https://laravel.com/framework/docs/11.x/eloquent). Esta referencia describe la convención, no fija la versión del proyecto.

## Glosario breve del negocio

| Término en español | Modelo | Tabla |
|---|---|---|
| Cliente | Customer | customers |
| Solicitud de transporte | TransportRequest | transport_requests |
| Ruta comercial | Route | routes |
| Tarifa negociada por litro | TransportRate | transport_rates |
| Trabajador | Employee | employees |
| Activo de flota | FleetAsset | fleet_assets |
| Cabezal | TractorUnit | tractor_units |
| Cisterna | Tanker | tankers |
| Compartimento | Compartment | compartments |
| Viaje | Trip | trips |
| Asignación de conductor | DriverAssignment | driver_assignments |
| Carga / detalle de carga | TripLoad / LoadItem | trip_loads / load_items |
| Descarga / detalle de descarga | TripUnload / UnloadItem | trip_unloads / unload_items |
| Orden de trabajo | WorkOrder | work_orders |
| Repuesto / consumo | SparePart / PartConsumption | spare_parts / part_consumptions |
| Compra | Purchase | purchases |
| Factura | Invoice | invoices |
| Detalle de factura | InvoiceItem | invoice_items |
| Pago / aplicación | Payment / PaymentAllocation | payments / payment_allocations |
| Gasto | Expense | expenses |
| Documento | Document | documents |

## Tabla de entidades

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

## Tabla de relaciones y cardinalidades

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

## Arquitectura y tecnologías

Se conserva PostgreSQL, monolito modular Laravel, Vue 3 con TypeScript, API JSON sobre HTTPS, PWA con service worker e IndexedDB. Módulos internos:

1. `AccessAndPersonnel` — Acceso y personal.
2. `FleetAndDocuments` — Flota y documentación.
3. `TransportAndDispatch` — Transporte y despacho.
4. `Workshop` — Taller.
5. `Inventory` — Inventario.
6. `Purchasing` — Compras.
7. `BillingAndPayments` — Facturación y pagos.
8. `Expenses` — Gastos.
9. `Reports` — Reportes.

Route y TransportRate pertenecen a `TransportAndDispatch`; `BillingAndPayments` toma la tarifa acordada de la solicitud y los litros aceptados, conservando sus valores en factura. No cambian módulos, procesos ni despliegue: `arquitectura.mmd` permanece idéntico.

Auditoría y sincronización transversales. Reportes consulta datos existentes sin duplicar entidades. Cada módulo controla escrituras; Taller pide consumo a Inventario y Compras entradas al recibir. Efectos relacionados se confirman transaccionalmente. Despliegue sencillo con proceso web y trabajador de cola del mismo proyecto; cola inicialmente PostgreSQL. Dimensionar por concurrencia/volumen/adjuntos; 26–50 usuarios no obliga a microservicios.

Sesiones seguras, permisos por rol/registro, archivos privados, auditoría de actor/fecha/motivo/cambios, errores/trabajos supervisados, respaldos y prueba de restauración. Versiones exactas por compatibilidad/soporte al implementar. Vite, Workbox, Dexie y Playwright continúan como candidatos auxiliares de la propuesta base; Redis opcional según carga. Sin integración fiscal definida.

| Actor | Alcance y límite inicial |
|---|---|
| Administrador | Todos los módulos y autorizaciones, con auditoría e integridad. |
| Secretario | Solicitudes, despacho, viajes, documentos, facturas y gastos; no reemplaza autorización del administrador. |
| Conductor | Viajes asignados y capturas; no asigna ni cierra administrativamente. |
| Mecánico | Diagnósticos, reparaciones y consumos; no autoriza compras propias por defecto (propuesta). |

Matriz detallada para recepción, ajustes, pagos, costos, anulaciones/reportes pendiente. Employee independiente de User permite trabajadores sin cuenta.

### Offline del conductor

Descargar solo viajes autorizados y datos mínimos. Service worker conserva recursos; IndexedDB guarda consultas y cola de capturas. Salida/llegada, lecturas, combustible, gastos e incidencias pendientes hasta aceptación. Carga/descarga offline no está confirmada para la primera versión.

Operación con UUID, autor/dispositivo, fecha ocurrida y versión base. Servidor revalida sesión, permisos actuales, asignación en momento relevante y reglas. Una transacción registra operación/resultado y efectos. UUID aceptado devuelve resultado previo sin duplicar evento, lectura o gasto; huella de contenido rechaza reutilizar UUID con datos distintos. Conflicto de versión no sobrescribe silenciosamente. UUID controla reintentos; restricciones de negocio evitan dos salidas nuevas con UUID distintos.

Estados visibles: pendiente, sincronizando, confirmado, conflicto y rechazado. Sincronizar al abrir, recuperar conexión y con botón manual; fondo depende del navegador. IndexedDB no es respaldo. Retención, recuperación de sesión, dispositivos compartidos y avisos de pendientes por diseñar. No exponer datos de otro conductor ni borrar pendientes sin resolverlos. Reasignación durante offline conserva capturas para revisión sin cambiar automáticamente responsable vigente. Asignación, autorización y cierre administrativo exigen conexión.

## Estados y transiciones propuestos

Estas transiciones se presentan para validación, no como procesos aprobados.

| Objeto | Transiciones | Condiciones |
|---|---|---|
| Viaje: ejecución | draft → scheduled → dispatched → in_progress → completed; draft/scheduled/dispatched → cancelled antes de salida | Reservar valida intervalos. Despachar exige cabezal/cisterna/conductor y documentos/servicio válidos. Salida aceptada inicia; completar exige carga, descarga y llegada confirmadas; la aceptación en destino se registra aparte y debe estar confirmada antes de facturar. |
| Viaje: administración | open → under_review → closed; under_review → open por observaciones | Revisar tras completar/cancelar; cerrar en línea con capturas pendientes resueltas y documentos/gastos/diferencias revisados. No exigir pago previo hasta validar política. Reapertura excepcional por administrador con motivo. |
| Orden de trabajo | open → diagnosed → pending_approval → approved → in_repair → finished → closed | Administrador autoriza cuando aplica; exención documentada permite diagnosticada → autorizada. Cierre comprueba tareas, consumos, costos y aceptación. Cancelación antes de ejecución; después de consumos exige compensación auditada. |
| Solicitud de compra | draft → submitted → approved/rejected; draft/submitted → cancelada | Administrador decide; cambios relevantes requieren nueva revisión. |
| Compra | draft → pending_approval → approved → confirmed → partially_received → received → closed | Solicitud aprobada puede habilitar autorización validada. Recepción completa permite confirmada → recibida. Recibir crea entradas una vez. Cancelar antes de recepción; luego devolución/compensación aún por definir. |
| Factura: emisión | draft → issued; draft → discarded | Viajes completados del cliente, aceptación confirmada, litros disponibles sin duplicación, tarifa de solicitud, moneda/período y permisos; reglas fiscales por definir. Corrección/anulación de emitida pendiente de procedimiento fiscal; no eliminación directa. |
| Factura: cobranza derivada | unpaid / partially_paid / paid; overdue como indicador adicional | Aplicaciones y vencimiento; no estado manual. Reversa recalcula saldo. |
| Activo: servicio | operational ↔ out_of_service; ambos → retired | Actor autorizado y motivo; volver a operativo requiere aceptación de reparación cuando aplica. Retirado no asignable. |
| Activo: disponibilidad derivada | available / reserved / assigned / blocked | Según intervalo, documentos y órdenes que inmovilizan; no eludir restricciones manualmente. |

Auditar transiciones. Avería en ruta, cancelación tras salida, transbordo y devolución requieren flujo excepcional pendiente. Viajes no se limitan a un día. Confirmar qué órdenes inmovilizan: no toda orden bloquea automáticamente.

## Concurrencia y validación

Servidor comprueba intervalos al programar, despachar, sustituir y modificar horarios; serialización por recurso y restricciones apropiadas al implementar. Pantalla no garantiza disponibilidad. Autorización/transición atómicas. Stock/consumo, recepción y pago/saldo transaccionales con protección concurrente; no leer y escribir sin bloqueo. No eliminar historial operativo físicamente.

Pruebas previstas para implementación: factura con varios viajes del mismo cliente, rechazo de viaje de otro cliente, doble facturación concurrente del mismo viaje, control concurrente de litros facturados/reservados, aceptación pendiente no facturable, tarifa histórica sin cambios, producto/ruta coherentes, balance de solicitud; dos despachos por mismo recurso, último repuesto consumido concurrentemente, recepción duplicada, pagos sobre mismo saldo, respuesta perdida tras confirmar, reenvío offline, permisos revocados y conflicto de versión. Esta etapa comprueba consistencia de entidades/referencias/cardinalidades; no prueba software inexistente.

## Resumen de cambios respecto al modelo anterior

1. `TransportRequest` pasa de cabecera genérica a solicitud obligatoria de cliente/producto/ruta/litros/tarifa; sus viajes pueden ser cero o muchos. No hay `TransportRequestItem`.
2. Se agregan únicamente `Route` y `TransportRate`: ruta reutilizable y versiones negociadas cliente/ruta. No se agrega `TransportAgreement`.
3. `Trip.transport_request_id` es obligatorio. Cliente, producto, ruta y tarifa se derivan; se elimina `Trip.customer_id` redundante.
4. Un producto por solicitud y viaje sustituye la hipótesis de múltiples productos por viaje. Se retira `LoadItem.liquid_product_id`; compartimentos y cabeceras/detalles se conservan.
5. Descarga medida y aceptación neta se separan en `UnloadItem`; avance/pendientes de solicitud se calculan con aceptación confirmada, no con carga ni facturación.
6. Facturación semanal: cada línea guarda viaje, litros, tarifa histórica y subtotal. Se retira el supuesto cantidad = un viaje y UK incondicional de `trip_id`; parcial queda pendiente, con control acumulado de litros y reservas.
7. Taller, inventario, compras, personal, flota, documentación, pagos, gastos y PWA se conservan. La arquitectura no requiere cambio de módulos ni de diagrama.

## Revisión de coherencia del modelo

| Verificación de diseño | Resultado y límite |
|---|---|
| Cliente/producto/ruta de solicitud | Un vínculo obligatorio de cada tipo; sin detalles de productos ni múltiples rutas. |
| Solicitud/viajes | Cero viajes permitido en solicitud; cada viaje tiene exactamente una solicitud. Recursos siguen independientes por viaje. |
| Cliente de viaje/factura | Se deriva de solicitud; igualdad obligatoria con cliente de factura. |
| Tarifa | Versión por cliente/ruta, referencia obligatoria de solicitud y coincidencia de ambos; congelada para evitar cambios retroactivos. |
| Lugares comerciales/reales | Ruta en solicitud, lugares reales en carga/descarga; desviaciones se registran y revisan. |
| Producto/compartimentos | Producto derivado de solicitud, litros por compartimento; no se autoriza mezcla ni múltiple producto por viaje. |
| Cantidades | Cargado, descargado y aceptado distintos; solo aceptación confirmada aporta avance y facturabilidad. |
| Factura histórica | Litros, tarifa aplicada y subtotal persistidos al emitir; tarifa futura no la modifica. |
| Doble cobro | Control de facturado + reservado por viaje; multiplicidad máxima y UNIQUE condicionados a decisión de parcial. |
| Módulos ajenos | Entidades/atributos y relaciones de taller, inventario, compras, personal, flota y documentos conservados. |

Se revisó coherencia conceptual y referencias del ER. Las restricciones entre tablas y reglas por estado no quedan garantizadas solamente por líneas Mermaid; requieren diseño físico después de aprobación. El modelo sigue provisional por las decisiones siguientes.

## Decisiones pendientes

1. ¿Se permitirá facturación parcial de litros de un viaje? Según respuesta, definir 0..1 + UNIQUE por viaje o 0..N con control acumulado. No confundirla con pagos parciales ya confirmados.
2. Corte semanal, zona horaria, fecha de elegibilidad y entregas aceptadas tardíamente; política de borradores/reservas y moneda/impuestos/redondeos.
3. Notas de crédito, correcciones, anulación/refacturación, ajustes de litros aceptados y efectos sobre reservas/saldos; integración con Hacienda y jurisdicción fiscal.
4. Quién registra/valida aceptación y qué evidencia del destino exige; aceptación por compartimento o cantidad global, precisión/temperatura/medición, rechazos y tolerancias/diferencias.
5. Sobreentrega, aceptación mayor que medición de descarga, viajes cancelados después de recepción y cómo rectificar avances sin perder historial.
6. Cambios de litros/condiciones de una solicitud ya confirmada, cierre/estado de cumplimiento y necesidad de solicitudes preliminares sin tarifa acordada.
7. Variantes comerciales de rutas con mismo par de lugares, selección/vigencia de tarifas y aprobación de desvíos/transbordos; conservar historia de rutas referenciadas.
8. Se mantienen pendientes anteriores: documentos obligatorios y vigencia durante viaje; permisos y reservas de recursos; autorización/aceptación de reparaciones; almacenes/valorización/devoluciones; gastos/reembolsos y pagos a proveedores; pago distribuido/anticipos; offline y migración desde Excel/papel.

Revisar estas decisiones y aprobar el ER antes de iniciar implementación.

## Entregables y aprobación

modelo-er.mmd y arquitectura.mmd son Mermaid editable. Tablas incluidas aquí y separadas en tabla-entidades.md y tabla-relaciones.md. Primero revisar y aprobar entidades, relaciones, cardinalidades y reglas por estado; resolver decisiones que alteran modelo antes de migraciones.

## Referencias técnicas de la propuesta base

- PostgreSQL, restricciones: https://www.postgresql.org/docs/current/ddl-constraints.html
- PostgreSQL, numéricos: https://www.postgresql.org/docs/current/datatype-numeric.html
- Laravel, transacciones: https://laravel.com/docs/database
- MDN, offline: https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Offline_and_background_operation
- MDN, IndexedDB: https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API/Using_IndexedDB
- MDN, sincronización: https://developer.mozilla.org/en-US/docs/Web/API/Background_Synchronization_API
