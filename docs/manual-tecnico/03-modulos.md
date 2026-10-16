# 03. Módulos y responsabilidades


## Cómo leer cada módulo

Un módulo es un límite de responsabilidad, no necesariamente una pantalla o una tabla. A continuación se explica su comportamiento; cada enlace de implementación incluye **todas las clases identificadas**, métodos públicos, tablas propias y referencia completa de rutas. El [inventario frontend](frontend/archivos.md) complementa estos mapas. Los ejemplos completos están en [12](12-casos-practicos.md).

### Acceso y Personal — `AccessAndPersonnel`

Existe para administrar cuentas, empleados y permisos, y construir navegación por rol. El administrador usa `/administracion/personal`: registra una cuenta, asigna roles, la activa y opcionalmente vincula un empleado. `AccountAdministration::save()` revalida al administrador dentro de la transacción; no basta el rol que el navegador recordó. Conserva un administrador activo y no deduce oficio desde el nombre. `SessionController` inicia/cierra sesión; `SystemNavigation` elige inicio y enlaces.

Tablas: `users`, `roles`, `user_roles`, `employees`. Usa las APIs `/api/users`, `/api/roles`, `/api/employees`, `/api/session`, `/api/navigation` y autenticación. Usuarios/empleados pueden existir separados; nombre de empleado y nombre visible de cuenta no se sincronizan automáticamente. Conductores y mecánicos dependen de esta habilitación. Ejemplo: crear empleado y cuenta con rol `driver` permite que Transporte lo evalúe como elegible, sin crear otra entidad “chofer”. [Clases, tablas y contratos](modulos/acceso-y-personal.md).

### Flota, Documentación y Alertas — `FleetAndDocuments`

Flota identifica cabezales/cisternas y compartimentos. El administrador usa `/administracion/flota`; `FleetAdministration::save()` aplica restricciones estructurales e históricas. El activo común se especializa en `TractorUnit` o `Tanker`; no son dos vehículos independientes. La cisterna sin divisiones utiliza un compartimento lógico. La suma de capacidades se protege según la estructura existente, incluidas restricciones sobre compartimentos históricos.

Administrador y secretaria usan `/documentacion`. `DocumentAdministration::configure()` permite configuración al administrador; `register()` guarda archivo privado, versión y referencia actual. `DocumentConfigurationSnapshot` conserva requisitos al registro, y la tabla `current_fleet_documents` evita confundir actual con el de fecha más reciente. `DocumentValidity` calcula vigencia en Costa Rica. El anterior permanece descargable en historial, sin editarse para marcar renovación.

`DocumentAlerts::query()/summary()/review()` sirve `/alertas`: vencidos, 0–15 días, filtros y revisión informativa única por documento. Renovar crea otra identidad con atención independiente; revisar no oculta ni renueva. No hay documentos de personal implementados. Ejemplo: renovar seguro cambia el puntero dentro de la transacción, conserva el seguro anterior y mantiene separadas su vigencia y revisión. [Mapa completo](modulos/flota-y-documentacion.md).

### Transporte y Despacho — `TransportAndDispatch`

Secretaria y administrador coordinan `/transporte`; conductor consulta proyección limitada en `/mis-viajes`. Reutiliza clientes, productos, sitios y rutas administrados desde Catálogos. `TransportAdministration` crea solicitudes/viajes y estados; `AssignmentAdministration` asigna, sustituye y libera; `AssignmentAvailability` inspecciona y revalida disponibilidad; `TripOperations` registra operaciones y finalización; `DriverTripRecords` incorpora capturas idempotentes del conductor.

Solicitud: un cliente/producto/ruta, varios viajes. Viaje: producto histórico, planificación, ejecución y cierre administrativo independientes. Iniciar requiere asignaciones; finalizar exige D02 y aprobación de cualquier diferencia de litros. Cancelar/finalizar/cerrar **no libera recursos**. Las liberaciones son hechos manuales. La disponibilidad consultada no reserva un recurso: vuelve a comprobarse al confirmar. Excepciones requieren aprobación administrativa exacta y no anulan un impedimento mecánico.

La API tiene altas, historial, acciones, asignaciones, autorizaciones, carga/descarga, lecturas y consultas; no hay edición libre de historial. Las rutas utilizadas por solicitudes no se editan mientras no exista política histórica aprobada. Ejemplo: solicitud → dos viajes legítimos con distintas claves → asignación a uno → carga/descarga → finalización oficial → cierre → liberación manual. [Mapa completo](modulos/transporte.md).

### Taller Mecánico — `Workshop`

Administrador y secretaria coordinan `/taller`; mecánico ve órdenes de su participación y registra información técnica autorizada. Una orden equivale a una reparación o servicio, aunque un ingreso agrupe varias órdenes. Otra avería necesita otra orden; ampliación de la misma reparación exige alcance/decisión independiente. `WorkshopCore::execute()` adapta comandos del núcleo; `WorkshopCommands::run()` unifica transacción, idempotencia y autorización vigente.

`WorkshopAvailability` registra ingreso, impedimentos, vínculo con órdenes y salida. El ingreso bloquea nuevas asignaciones, conserva las existentes y genera advertencias. `WorkshopAuthorizations` separa solicitar, decidir y aplicar. `WorkshopExecution` registra actividades/tiempo/transiciones. `WorkshopMaterials` gestiona solicitudes, entregas y uso; `WorkshopCosts` separa estimado/real y moneda. `WorkshopAttachments` reserva/confirma evidencia privada.

El mecánico termina, el administrador cierra y autoriza salida mediante operaciones distintas. Cerrar/cancelar una orden no demuestra disponibilidad. Una orden puede tener varios mecánicos; liberar uno conserva participación histórica, pero restringe nuevas escrituras. Ejemplo: ingreso → orden vinculada → diagnóstico → alcance con repuestos → aprobación → entrega/uso → terminación → cierre → resolución y salida autorizada. [Mapa completo](modulos/taller.md).

### Inventario — `Inventory`

`/inventario` sirve a oficina y a mecánicos según sus permisos. Reutiliza `SparePart`, entrega/uso del Taller y recepciones de Compras. `InventoryLedger` tramita conciliación inicial, correcciones, decisiones, aplicaciones, devoluciones y entradas de recepción. No hay campo stock libremente editable ni existencias supuestas para catálogos recién creados.

Los saldos se derivan de `inventory_movements`: bodega, custodia y consumido. Entregar reduce bodega y aumenta custodia; usar reclasifica custodia a consumido; devolver necesita recepción física. Los triggers de Taller generan movimientos de sus fuentes una sola vez. Mecánico confirma uso real sin aprobación administrativa individual del consumo, dentro del alcance ya autorizado. Ajustes y reversiones requieren el proceso administrativo. Ejemplo: conciliar 10 unidades → entregar 4 → usar 3 → recibir 1 devuelta; quedan bodega 7, custodia 0, consumido 3. [Mapa completo](modulos/inventario.md).

### Compras — `Purchasing`

Oficina utiliza `/compras` y el acceso administrativo existente a solicitudes. `PurchaseRequestAdministration::record()` crea solicitud o decisión, reutilizando proveedor/repuesto y necesidad opcional de Taller. Administrador aprueba; la autorización de reparación no autoriza automáticamente comprar. `PurchaseFulfillment::record()` crea orden y recepción parcial/completa. Consultas muestran solicitado, autorizado, recibido y pendiente según los registros reales.

Una orden no aumenta existencias. Confirmar recepción física sí crea entrada mediante `InventoryLedger::receive()` en la misma transacción; restricciones evitan duplicación y exceso autorizado. Ejemplo: autorizar 5 filtros, recibir 2 y después 3 con claves diferentes, conservar dos recepciones y cinco unidades recibidas, no diez ante un reintento. No incluye valoración comercial inventada ni devoluciones al proveedor sin procedimiento. [Mapa completo](modulos/compras.md).

### Facturación y pagos — `BillingAndPayments`

Administrador/secretaria utilizan `/facturacion`. `BillingPayments::propose()` registra importe manual por viaje; `decide()` reserva aprobación al administrador; `invoice()` factura viajes finalizados con versión aprobada; `payment()` admite pagos parciales o totales. `Money` representa centavos exactos y formatea cadenas decimales, con ROUND_HALF_UP cuando procede.

Factura pertenece a un cliente/moneda; cada detalle referencia viaje y propuesta aprobada, cantidad 1. No se duplica el viaje aunque pertenezca a una solicitud con varios viajes. Total, pagado, saldo y estado se calculan, no son campos manuales de cabecera. El lock y las restricciones impiden sobrepagos concurrentes. Ejemplo: factura CRC 100.00, pago 30.00 y 70.00: saldo 0.00 y pagada. USD no se suma a CRC. No es emisión fiscal. [Mapa completo](modulos/facturacion.md).

### Gastos — `Expenses`

Oficina utiliza `/gastos`; conductor registra gastos de sus viajes desde el portal. `ExpenseAdministration` conserva identidad, revisiones y decisiones separadas. Crear un gasto con comprobante no lo aprueba. Solo administrador decide sobre una versión exacta; una corrección crea una nueva revisión que necesita su propia decisión. `PrivateReceiptStorage` conserva comprobantes fuera de URL pública y compensa archivos nuevos fallidos de forma conservadora.

Montos CRC/USD exactos reutilizan `Money`. Los gastos no modifican pagos de clientes. Ejemplo: conductor captura gasto de peaje offline; sincronización registra gasto pendiente y después comprobante; administrador revisa y aprueba, sin que se deduzca un reembolso realizado. [Mapa completo](modulos/gastos.md).

### Reportes — `AdministrativeReports`

Administrador consulta `/reportes`. `AdministrativeReports::consult()` produce consultas de solo lectura por dominio, con período Costa Rica, filtros y paginación. Suma relaciones por separado para no multiplicar facturas por sus pagos/detalles. Separa facturación de cobros, moneda y gastos aprobados de desembolsos no registrados. Inventario muestra existencia actual, no reconstruye un saldo histórico sin evidencia. No crea tablas, gráficos ficticios ni exportaciones. Ejemplo: filtrar pagos del mes y observar que el saldo actual de una factura no equivale a “cobrado en ese mes”. [Mapa completo](modulos/reportes.md).

### Auditoría — `AdministrativeAudit`

Administrador consulta `/auditoria`. `AdministrativeAudit::consult()/detail()` reúne fuentes fijas de recibos e historiales existentes; no duplica cada hijo ni inventa eventos de antes de que hubiera registro. La lista segura de cambios limita qué snapshots se muestran. Los catálogos no adquieren retrospectivamente auditoría durable solo porque exista esta pantalla. Ejemplo: buscar operación de pago por fecha/actor y abrir el registro afectado, sin exponer clave privada o payload de archivo. [Mapa completo](modulos/auditoria.md).

### Respaldos — `BackupRecovery`

No tiene pantalla. Un operador autorizado ejecuta `backups:run` y `BackupArchive` para crear/verificar/restaurar/purgar conjuntos cifrados de PostgreSQL y archivos privados. Requiere configuración, mantenimiento y escritores drenados. Restauración exige servidor aislado, destino nuevo y confirmación exacta. No añade tablas ni decide automáticamente el destino productivo. Ejemplo: verificar un conjunto antes de ensayar restauración en laboratorio; un checksum correcto no reemplaza el ensayo completo. [Mapa completo](modulos/respaldos.md).

## Guía transversal de estados y cantidades

[Estados, autorizaciones, disponibilidad y significado de los totales](modulos/estados-y-cantidades.md) desarrolla las transiciones reales y evita confundir cierre, salida, entrega, consumo, facturado y cobrado.

## Capacidades transversales que no duplican dominios

`Administration` reúne pantallas de recursos/catálogos; `System` monta login, dashboard y navegación; `DriverPortal` añade almacenamiento/sincronización al Transporte y Gastos existentes; `DocumentAlerts` presenta consultas de Documentación; `Supply` integra Inventario/Compras. No son nuevas cuentas, vehículos, gastos o bases paralelas. Laravel también conserva tablas técnicas de sesión, caché, cola y migraciones, descritas íntegramente en el diccionario.
