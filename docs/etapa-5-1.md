# Etapa 5.1 — Diseño de Transporte y Despacho

Base revisada: `e1aaae8`. Entrega exclusivamente documental; 5.2 no está autorizada.
Se preservan código, esquema, dependencias y comportamiento de la etapa 4.4.

## Fuentes y estado de aprobación

Fuente de negocio: [REGLAS_TRANSPORTE_DESPACHO.md](REGLAS_TRANSPORTE_DESPACHO.md),
incorporada íntegramente desde el adjunto del usuario, con numeración y texto exactos.
Contiene 39 RN-TR y RN-SEG-01: 39 reglas confirmadas contando seguridad y una
pendiente, RN-TR-17. No se renumeran ni se reemplazan por identificadores locales.

Se contrastaron AGENTS.md, ARCHITECTURE.md, docs/propuesta-tecnica.md, ER global,
tablas de entidades/relaciones, diagrama arquitectónico, entregas 4.2–4.4 y modelos,
Gates y rutas actuales de AccessAndPersonnel, FleetAndDocuments y catálogos de transporte.
Las decisiones recientes y las RN adjuntas prevalecen sobre hipótesis del borrador.
Los nombres de tablas/campos, estados técnicos y estructuras siguientes son propuestas
para aprobación, no funcionalidades ni esquema existentes. Una regla confirmada puede
necesitar parámetros técnicos; eso no convierte la regla en pendiente de negocio.

## Trazabilidad de reglas

El texto normativo completo está en la fuente enlazada. Esta tabla registra su
estado y cobertura del diseño, sin alterar el significado de cada número.

| Regla | Estado de negocio | Aplicación en el diseño |
| --- | --- | --- |
| RN-SEG-01 | Confirmada | Jerarquía y autorización por operación; backend autoritativo. |
| RN-TR-01 | Confirmada | Solicitud → viajes; producto único. |
| RN-TR-02 | Confirmada | Producto histórico por viaje y revisión de pendientes. |
| RN-TR-03 | Confirmada | Contrato comercial sin fórmula fija; D03. |
| RN-TR-04 | Confirmada | Conflictos y excepciones administrativas; autorización específica y locks. |
| RN-TR-05 | Confirmada | Liberación manual auditada, independiente de estados. |
| RN-TR-06 | Confirmada | Empleado activo + identificación como conductor + cuenta activa; D04. |
| RN-TR-07 | Confirmada | Inicio oficial manual con actor/fecha. |
| RN-TR-08 | Confirmada | Reporte del conductor separado de finalización oficial. |
| RN-TR-09 | Confirmada | Carga/descarga únicas y detalles; completitud D02. |
| RN-TR-10 | Confirmada | Diferencias reales autorizadas antes de finalización oficial. |
| RN-TR-11 | Confirmada | Cancelación incluso en curso con motivo, sin borrado/liberación. |
| RN-TR-12 | Confirmada | Cabezal/conductor: solicitud y autorización administrativa, mismo viaje. |
| RN-TR-13 | Confirmada | Cisterna vacía y autorización administrativa; evidencia D05. |
| RN-TR-14 | Confirmada | Volúmenes definitivos registrados solo por oficina. |
| RN-TR-15 | Confirmada | Permiso físico y adjunto privado de viaje, sin integración WhatsApp. |
| RN-TR-16 | Confirmada | Requisito configurable por sitio e historia de configuración. |
| RN-TR-17 | Pendiente, no aprobada | Comportamiento ausente sin aprobación; D01. |
| RN-TR-18 | Confirmada | Lecturas inicial/final de cabezal, distancia y correcciones auditadas. |
| RN-TR-19 | Confirmada | Gastos de viaje con una fuente canónica futura. |
| RN-TR-20 | Confirmada | Gasto sin comprobante pendiente de autorización administrativa. |
| RN-TR-21 | Confirmada | Condiciones individuales de saldo de viáticos; D08. |
| RN-TR-22 | Confirmada | Autorización administrativa y desembolso registrado por oficina. |
| RN-TR-23 | Confirmada | Múltiples entregas, autorización individual y total entregado. |
| RN-TR-24 | Confirmada | Reprogramación previa a inicio con revisión de conflictos. |
| RN-TR-25 | Confirmada | Cambios de volumen solicitado con historia; cálculo pendiente D07. |
| RN-TR-26 | Confirmada | Cambio autorizado de producto; preservar cargados/completados y revisar pendientes. |
| RN-TR-27 | Confirmada | Cierre tras finalización oficial y revisión. |
| RN-TR-28 | Confirmada | Conservar avisos al cierre sin omitir autorizaciones especiales. |
| RN-TR-29 | Confirmada | Corrección cerrada auditada; sin propagación automática a otros módulos. |
| RN-TR-30 | Confirmada | Contrato futuro: factura individual/agrupada mismo cliente sin duplicación. |
| RN-TR-31 | Confirmada | Contrato futuro: facturar viajes completados antes de terminar solicitud según acuerdo. |
| RN-TR-32 | Confirmada | Contrato futuro: contado/crédito, plazo y vencimiento. |
| RN-TR-33 | Confirmada | Contrato futuro: un pago completo por factura, sin abonos. |
| RN-TR-34 | Confirmada | Contrato futuro: métodos configurables administrados por administrador. |
| RN-TR-35 | Confirmada | Contrato futuro: anulación autorizada por administrador y solicitada por secretaria. |
| RN-TR-36 | Confirmada | Contrato futuro: factura emitida inmutable; documento fiscal pendiente D11. |
| RN-TR-37 | Confirmada | Contrato futuro: concepto de cobro de cancelación conserva estado cancelado. |
| RN-TR-38 | Confirmada | Contrato futuro: cobro e importe cancelados autorizados por administrador. |
| RN-TR-39 | Confirmada | Cambio de precio de viaje: propuesta/solicitud y autorización administrativa. |

## Contradicciones y resolución

| Borrador / propuesta anterior | Regla o decisión prevalente | Ajuste de diseño |
| --- | --- | --- |
| Producto de viaje siempre derivado de solicitud editable | RN-TR-02, RN-TR-26 | Snapshot/versionado del producto por viaje; cargados/completados no cambian. |
| Prohibición absoluta de dos viajes por recurso | RN-TR-04 | Detectar conflicto y admitir excepción solo autorizada; no UNIQUE global de recurso abierto. |
| Liberación automática por finalización/cancelación/hora | RN-TR-05 | Acción manual independiente, siempre auditada. |
| Conductor sin cuenta o empleado inactivo aún pendiente | RN-TR-06 | Solo empleado activo, identificado como conductor, con cuenta activa. |
| Captura de salida inicia oficialmente | RN-TR-07, RN-TR-08 | Captura y confirmaciones de oficina separadas. |
| Cancelación solo antes de salida | RN-TR-11 | También después de iniciar; conservar hechos, motivo y recursos. |
| Sustitución ordinaria sin autorización | RN-TR-12, RN-TR-13 | Administrador autoriza; cisterna reemplazada debe estar vacía. |
| Producto/volumen de solicitud congelados sin cambios | RN-TR-25, RN-TR-26 | Cambios auditados; producto con autorización y revisión de pendientes. |
| Cierre exige resolver todos los pendientes | RN-TR-27, RN-TR-28 | Después de finalización oficial, puede conservar pendientes; no omite RN-TR-10. |
| Solo administrador reabre para corregir | RN-TR-29 | Oficina puede corregir cerrado, con auditoría y controles por campo. |
| Fórmula fija por litro y precio únicamente en solicitud | RN-TR-03, RN-TR-39 | Modelo comercial pendiente; cambio de precio acordado de viaje solo autorizado por administrador. |
| Facturación solo semanal y pagos parciales | RN-TR-30..34 | Individual/agrupada y un pago íntegro por factura; no imponer corte semanal. |
| Solo viajes completados generan conceptos | RN-TR-37..38 | Cancelados pueden generar cargo autorizado; no cambia su estado operativo. |
| Documentos faltantes/vencidos bloquean | Decisiones 4.1–4.4 | Advertencias documentales; RN-TR-17 es un permiso de salida distinto y pendiente. |
| DocumentLink y UUID para User en ER global | Arquitectura implementada | Document ligado a FleetAsset, User bigint; no remodelar etapa 4.4. |

El borrador global se conserva como referencia histórica señalizada. El diagrama
[modelo-er-transporte-5-1.mmd](modelo-er-transporte-5-1.mmd) describe la propuesta
actual acotada. No implica que los módulos restantes del ER hayan sido aprobados.

## Entidades existentes reutilizadas

| Existente | Responsabilidad / límite |
| --- | --- |
| Customer, LiquidProduct, OperationSite | Catálogos propios de TransportAndDispatch; no duplicar clientes/productos/lugares. |
| FleetAsset, TractorUnit, Tanker, Compartment | Identidad de flota propia y compartimentos físicos/lógicos; sin camiones rígidos ni terceros. |
| Employee, User, Role | Empleado UUID, cuenta bigint y roles vigentes. Employee.user_id opcional único en general; obligatorio para conductor asignado por RN-TR-06. |
| Document, DocumentType, DocumentConfigurationSnapshot, current_fleet_documents | Historia privada inmutable y selección actual explícita; consultas de avisos sin modificar el módulo. |

FleetAsset.service_status = available/maintenance/out_of_service representa servicio,
no disponibilidad de despacho. Compartment.capacity_liters es NUMERIC(12,2) editable;
se propone conservar capacidad/configuración al cargar. Cada cisterna tiene al menos
un compartimento lógico. Un conductor por viaje y un producto histórico por viaje.

Roles técnicos reales: administrator, secretary, driver, mechanic. El modelo Employee
no contiene función laboral; se propone comprobar identificación como conductor mediante
rol driver de su cuenta. Requiere aprobar esa correspondencia (D04); no basta cualquier
cuenta activa y no se añade una profesión ni un rol nuevo automáticamente.

## Propuesta de persistencia y cardinalidades para 5.2

No es DDL aprobado. Nuevas PK UUID; FK de actor bigint compatibles con User.
No crear entidades nuevas para catálogos ya existentes. No agregar migraciones aquí.

| Entidad / tabla propuesta | Contenido principal | Cardinalidad / responsabilidad |
| --- | --- | --- |
| Route / routes | Origen/destino OperationSite e identidad comercial histórica. | Dos FK distintas; lugar 1:N rutas; variantes/historia D09. |
| TransportRequest / transport_requests | Cliente, producto vigente, ruta, litros solicitados, fecha, estado y versión. | Cliente/producto/ruta 1:N solicitudes; solicitud 1:N viajes, admite cero inicialmente. Precio no se impone obligatorio hasta D03. |
| Trip / trips | Solicitud, producto histórico propio, programación, estados, reporte de término, inicio/finalización/cierre oficiales con actor/fecha y versión. | Una solicitud y un producto aplicable; snapshot no es otra selección comercial libre. |
| TripAssetAssignment / trip_asset_assignments | Viaje, activo, intervalo, registrador, motivo, liberador y evidencia de autorización aplicable. | Historia 1:N por viaje y activo; un cabezal/cisterna por viaje a la vez. Solapamiento entre viajes solo con excepción autorizada. |
| DriverAssignment / driver_assignments | Viaje, empleado elegible, intervalo, motivos, registrador/liberador y autorización aplicable. | Uno por viaje a la vez; coincidencia entre viajes conforme RN-TR-04. |
| TripLoad / trip_loads | Sitio real, fechas, registrador de oficina, estado de captura; viaje FK única. | Viaje 0..1 antes de captura; única operación. Completitud D02. |
| LoadItem / load_items | Carga, compartimento, litros reales, capacidad/configuración histórica. | Carga 0..N detalles; UNIQUE carga/compartimento propuesto. Producto del Trip histórico. |
| TripUnload / trip_unloads | Sitio real, fechas, registrador de oficina y viaje FK única. | Una operación de descarga; puede faltar antes de registrar. |
| UnloadItem / unload_items | Descarga, detalle cargado, litros reales y motivo/evidencia de diferencia. | LoadItem 0..1 UnloadItem propuesto; mismo viaje. Aceptación por compartimento/global sigue D07. |
| TripEvent / trip_events | Reportes del conductor, incidencias y fechas del hecho/registro. | Viaje 1:N; fuente informativa, no volúmenes definitivos ni confirmación oficial automática. |
| TransportAuthorization / transport_authorizations | Tipo concreto, solicitud/viaje, actor solicitante/autorizador, motivo, fechas y contenido exacto autorizado. | Solicitud 0..N y viaje 0..N; propuesta XOR de destino obligatorio. Clases acotadas, sin motor genérico. |
| AssignmentConflict / assignment_conflicts | Pares de asignaciones incompatibles, recurso/intervalo y autorización administrativa vinculada. | Asignación 1:N conflictos; cada conflicto excepcional cubierto. Diseño de vínculo activos/conductores D06. |
| TransportChange / transport_changes | Anteriores/nuevos, motivo, actor, fecha y autorización; destino solicitud o viaje. | Historia append-only 1:N con XOR de destino; abarca RN-TR-25/26 además de viajes cerrados. |
| TripWarning / trip_warnings | Pendientes y evidencia capturados al cerrar, fecha y resolución posterior sin borrar original. | Viaje 1:N; no transforma pendiente en validado. |
| TripDeparturePermit / trip_departure_permits | Datos registrados de permiso, sitio, viaje, registrador y requisito aplicable conservado. | Viaje 0..N como propuesta hasta definir permisos/correcciones; RN-TR-17 sin comportamiento. |
| TripAttachment / trip_attachments | Evidencia privada de viaje/permiso con autor, tipo y clave interna. | Viaje 1:N; posible vínculo a permiso. Nuevo diseño, no reutilizar Document con activo ficticio. |
| TripOdometerReading / trip_odometer_readings | Cabezal/asignación, viaje, inicial/final, valor real y registrador; distancia derivada. | Viaje 1:N lecturas; tramos por sustitución y reinicios D07. Sin repetir km en TripEvent. |
| TripExpense / trip_expenses | Viaje, concepto, monto/moneda, actor, comprobante y autorización si falta. | Viaje 1:N; única fuente operativa hasta integración futura con Expenses, sin doble gasto. |
| TripAllowanceDisbursement / trip_allowance_disbursements | Viaje/conductor, monto autorizado, registrador, fecha y monto efectivamente entregado. | Viaje 1:N; cada entrega requiere autorización individual. Total = entregas confirmadas. |
| TripAllowanceSettlement / trip_allowance_settlements | Condiciones por acuerdo, entrega/uso y liquidación cuando corresponda. | Granularidad y saldo D08; no imponer devolución o retención. |
| TripPriceRevision / trip_price_revisions | Propuesta anterior/nueva de precio de viaje, motivo, autorización y aplicación. | Viaje 1:N; unidad/moneda/fórmula D03, no usar precio más reciente sin autorización. |

TransportRate del borrador no se implementa tal cual (rate_per_liter obligatorio):
RN-TR-03 no aprueba una fórmula única. RN-TR-37/38 requiere conservar autorización
operativa de cargo por cancelación, sin crear factura, pago o cálculo fiscal en etapa 5.

TransportAuthorization vincula contenido autorizado para conflictos (04), diferencias
(10), sustituciones (12/13), gastos sin comprobante (20), entregas (22/23), producto (26),
precio (39) y cobro cancelado (38). Estados técnicos de solicitud/decisión/aplicación,
revocación, vigencia y granularidad son propuestas D10. Autorizar no es ejecutar ni
entregar dinero. Cambiar contenido después de aprobar no reutiliza la aprobación.

### Producto histórico y cambios de solicitud

Propuesta: Trip.liquid_product_id representa el producto aplicable, acompañado por
instantánea de identificación o versión del catálogo. Al crear el viaje se fija
explícitamente desde la solicitud. RN-TR-26 no permite propagar UPDATE masivo al Trip:
los cargados/completados conservan producto; los pendientes requieren revisión antes
de aplicar autorización. Cambio de solicitud, revisión de afectados y cambios de
viajes aplicables se auditan. Carga/descarga siempre usan el producto histórico de Trip.
Momento exacto de congelación y revisión de viajes iniciados sin carga: D02.
RN-TR-25 conserva versiones de litros solicitados y nunca modifica litros reales.

### Concurrencia e integridad

- FK sin cascadas destructivas; valores reales y auditoría no se eliminan por cancelación.
- TIMESTAMPTZ para instantes, UTC y representación America/Costa_Rica propuestos;
  calendario documental conserva comportamiento 4.4. NUMERIC para litros/dinero;
  precisión operativa, moneda, redondeo y rangos técnicos requieren aprobación D07.
- Intervalos [inicio, fin), fin nulo hasta acción manual o sustitución autorizada.
  Sin liberación automática por programación, completed, cancelled o closed.
- Máximo una asignación vigente por función dentro del viaje. No imponer UNIQUE de
  recurso abierto entre viajes: impediría RN-TR-04. La excepción no permite dos
  conductores responsables a la vez dentro de un mismo viaje.
- Serializar escritores por viaje y recursos ordenados; detectar todos los conflictos
  con datos confirmados tras locks y comprobar autorización para cada excepción.
  Si aparece otro conflicto después de solicitar autorización, no presumir que está cubierto.
- Constraint triggers/validación de aplicación propuestos para comprobar coherencia
  entre asignaciones, tipo de activo, conflicto y autorización. Campo is_override enviado
  por usuario no basta. No se asumen extensiones PostgreSQL ni dependencias nuevas.
- Reprogramación RN-TR-24 también revalida RN-TR-04, conserva horarios y motivo.
- Elegibilidad RN-TR-06 se valida dentro de transacción, coordinando cambios de personal
  y cuentas. Reutilizar coordinación con advisory lock 873210 para revocaciones;
  orden global antes de locks de recursos como propuesta compatible con servicios actuales.
- Cambio/asignación/liberación, autorización/aplicación y auditoría se confirman juntos.
  Locks breves; archivos y otras operaciones externas fuera de transacciones prolongadas.
- Confirmación de finalización consulta volúmenes reales. Si cargados != descargados,
  exige autorización RN-TR-10 que cubra esos valores/versiones. Corrección posterior
  no conserva una autorización para valores diferentes automáticamente.
- Versiones optimistas e idempotencia propuestas para cambios concurrentes/reintentos,
  incluyendo respuesta perdida; alcance/retención D10. No última escritura gana.
- Pruebas futuras con PostgreSQL 18 aislado: excepción válida, rechazo sin autorización,
  conflictos concurrentes, revisión de producto, diferencias, cancelación, liberación manual,
  desembolsos autorizados, correcciones y rollback sin pérdida de historia.

## Estados, transiciones y operaciones

Identificadores técnicos son propuesta; actores y reglas son confirmados.

| Dimensión | Transición / acción propuesta | Autoridad y condición |
| --- | --- | --- |
| Solicitud | draft → confirmed → in_progress → fulfilled → closed | Estados propuestos; cumplimiento/cálculo D07. Cambios de volumen no se prohíben por estado sin decisión. |
| Ejecución | draft → scheduled → dispatched | Oficina coordina; validar recursos, conflictos y autorizaciones; requisito ausente de permiso pendiente. |
| Ejecución | dispatched → in_progress | Secretaria/administrador confirma manualmente, actor/fecha; no inicia por hora/carga/reporte. |
| Evento informativo | reporte de término | Conductor reporta, con actor/fechas; no equivale a completed. |
| Ejecución | in_progress → completed | Oficina confirma oficialmente; diferencias requieren aprobación administrativa; no libera recursos. |
| Ejecución | draft/scheduled/dispatched/in_progress → cancelled | Oficina cancela incluso tras inicio con motivo/actor/fecha y conservación de hechos/recursos. Cancelar completed sigue D02. |
| Administración | open → under_review → closed | Tras finalización oficial o cancelación y revisión (aclaración posterior); under_review opcional propuesto. Cierre conserva pendientes y controles especiales. |
| Administración | corrección de closed conservando closed | Oficina con auditoría; autorización por dato; sin modificación automática fiscal. |
| Recursos | asignación → liberación manual | Secretaria/administrador; actor/fecha/motivo auditados; individual/conjunta D06. |
| Recursos | sustitución durante viaje | Administrador autoriza cabezal/conductor; cisterna también requiere estar vacía. Misma identidad de viaje. |

No confundir fin informado, llegada, descarga, fin oficial y cierre. RN-TR-28 no
permite completar oficialmente sin RN-TR-10. Viaje cancelado conserva operación y
puede generar cargo autorizado, el cierre de cancelados fue autorizado explícitamente después de 5.2A: puede cerrarse
sin finalización oficial ficticia, conservando cancelación y cierre como hechos distintos.

## Matriz de permisos

A = autorizado por regla; S = registrar/solicitar sin aprobar; R = reportar; — = sin
facultad aprobada. La cuenta debe estar activa y Laravel valida operación/recurso;
frontend refleja esos permisos. Administrador no elude integridad ni historia fiscal.

| Acción | administrator | secretary | driver | mechanic | Regla |
| --- | --- | --- | --- | --- | --- |
| Inicio/finalización/cierre oficiales | A | A | — | — | 07,08,27,28 |
| Reportar término/información de viaje asignado | A | A para gestión | R | — | 08,14,18,19 |
| Volúmenes definitivos, kilometraje y gastos registrados | A | A | Solo reporta | — | 14,18,19 |
| Excepción de conflicto de recursos | A | S | — | — | 04 |
| Liberación manual | A | A | — | — | 05 |
| Autorizar diferencia de litros | A | Revisa/S | — | — | 10 |
| Cancelar incluso después de inicio | A | A | — | — | 11 |
| Autorizar sustitución cabezal/conductor | A | S | — | — | 12 |
| Autorizar cisterna reemplazada vacía | A | S | — | — | 13 |
| Registrar permiso y adjunto privado | A | A | Comunica evidencia | — | 15,16 |
| Actuar ante permiso obligatorio ausente | Pendiente | Pendiente | — | — | 17 |
| Registrar gasto sin comprobante pendiente | A | S | Reporta | — | 19,20 |
| Autorizar gasto sin comprobante | A | — | — | — | 20 |
| Autorizar cada desembolso de viáticos | A | — | — | — | 22,23 |
| Registrar desembolso previamente autorizado | A | A | — | — | 22,23 |
| Reprogramar antes de inicio/cambiar litros solicitados | A | A | — | — | 24,25 |
| Autorizar producto con viajes asociados | A | S | — | — | 26 |
| Corregir viaje cerrado | A con controles | A con controles | — | — | 29 |
| Autorizar cobro e importe cancelados | A | S evaluación | — | — | 37,38 |
| Autorizar precio acordado de viaje | A | S | — | — | 39 |
| Anulación/corrección fiscal futura | A | S | — | — | 35,36 |
| Administrar métodos de pago futuros | A | — | — | — | 34 |
| Editar factura emitida directamente | — | — | — | — | 36 |

Secretaria segundo rol no hereda autorizaciones exclusivas ni Gates globales de flota,
personal o catálogos. Se proponen lecturas mínimas para despacho, sin otorgar modificación.
Quién ejecuta una sustitución ya aprobada debe especificarse (D10); no inferir que
solicitar autoriza aplicarla. Conductor no recibe acceso documental nuevo automáticamente:
4.4 permite administrator/secretary; ampliar consultas mínimas requiere aprobación.

## Operación, historia y archivos privados

Guardar valores anteriores/nuevos, motivo, actor y fecha para solicitud/viaje; no solo
logs técnicos. Capturas, confirmaciones, autorizaciones, aplicaciones y entregas son
hechos distintos. Correcciones preservan original y no propagan cambios a otros módulos.
Snapshots de pendientes al cierre permanecen aun cuando luego se resuelvan.

Lecturas identifican cabezal/asignación; distancia derivada de lecturas, sin sumar
automatismos para cambio de cabezal o reinicio de odómetro. Viáticos: autorización
individual, desembolso real y liquidación por condiciones del viaje/conductor, sin
retención/devolución uniforme. Total autorizado no equivale a entregado.

Permiso de salida físico registrado por oficina, foto/PDF opcional, sin WhatsApp automático.
Configurar requisito en OperationSite requiere futura migración aprobada; propuesta de
snapshot del requisito por viaje/operación evita reinterpretar historia. Comportamiento
cuando falta permanece RN-TR-17; no adoptar advertencia documental de flota como sustituto.

TripAttachment es propuesta propia: reutilizar patrones seguros (MIME real, claves
internas, descarga autorizada, no-store, compensación que preserve referencias), no
forzar Document a apuntar a viaje ni cambiar su inmutabilidad. Formatos/límites/permisos
concretos D09; fotografía/PDF de permiso no habilita otros archivos ni URLs públicas.

## Integración y contratos futuros

FleetAndDocuments: consultas de referencia actual, validity y missingFor existentes,
configuración histórica y límites 0–15 días en America/Costa_Rica. Advertencias por
faltantes/vencidos/próximos; sin bloqueo documental y sin exponer storage_key. Avisos
conservados al cierre no se reescriben por renovación posterior. No alterar 4.4.

AccessAndPersonnel: identidad activa y roles vigentes; comprobación de empleado
activo y cuenta activa al asignar conductor. Desactivación posterior no libera recurso
ni sustituye responsable automáticamente; procedimiento de continuidad D04.

TransportAndDispatch mantiene Models, Http/Requests, Controllers pequeños, Resources,
Application transaccional, Domain para reglas y Infrastructure solo ante necesidad.
No llamadas HTTP internas ni repositorios genéricos; Vue/PWA no se implementa aquí.

Contratos RN-TR-30..38, sin tablas/API de BillingAndPayments:

- Individual o agrupada por mismo cliente, incluso solicitudes distintas, sin duplicación.
- RN-TR-31 permite facturar viajes completados mientras otros de la solicitud siguen
  pendientes según acuerdo; no aprueba fraccionar importe/litros de un mismo viaje.
- Contado/crédito y vencimiento; una factura se paga íntegra una sola vez en una
  transacción, sin abonos parciales. Pago conjunto de varias facturas D11.
- Métodos configurables administrados por administrador; monto/fecha/usuario y
  referencia/comprobante cuando corresponda. Efectivo/transferencia/SINPE Móvil y otros.
- Anulación solicitada por secretaria, autorizada por administrador; original conservado.
  Corrección con documento fiscal relacionado; tipo exacto sujeto a normativa, sin
  actualización directa de factura emitida.
- Cargo de cancelación con concepto/motivo y autorización administrativa de decisión
  e importe. No convierte cancelled en completed ni se factura sin autorización.
- Precio cambiado por RN-TR-39 no recalcula factura emitida ni solicitud/otros viajes.
  Elegibilidad con pendientes y efecto de correcciones se acordarán antes de facturación.
- Expenses futuro consumirá un gasto canónico; evitar duplicar gasto por TripEvent y
  migración hacia otro módulo. Lecturas también tienen una fuente, no km repetidos.
- Offline posterior: idempotencia, versiones, revalidación, conflictos y respuestas perdidas;
  sin IndexedDB ni service worker en 5.2.

## Decisiones pendientes y aprobación técnica

Solo RN-TR-17 está marcada pendiente en la fuente. D02..D11 son precisiones de diseño
u otros contratos aún no definidos; no reabren permisos ya confirmados.

| ID | Prioridad | Decisión necesaria | Impacto |
| --- | --- | --- | --- |
| D01 | Alta, negocio RN-TR-17 | Conducta ante permiso obligatorio ausente, actor y evidencia si corresponde. No elegir bloqueo/aviso/excepción. | Flujo dependiente de permiso. |
| D02 | Alta | Finalización y cobertura de carga/descarga resueltas por aclaración 5.3 al final de este documento. Siguen pendientes producto aplicable en pendientes/iniciados sin carga y cancelación de completados; cierre de cancelados ya resuelto. | Estados, snapshot, nulabilidad y confirmaciones. |
| D03 | Alta | Modelo comercial/unidad, moneda, precisión, origen y fecha de efecto de precio acordado; no fórmula fija. | Precio y revisiones, sin facturación. |
| D04 | Alta, técnica | Aprobar rol driver como identificación de conductor o definición laboral distinta; continuidad tras desactivación. | Elegibilidad sin cambiar regla cuenta/empleado activo. |
| D05 | Alta | Evidencia y validación de cisterna vacía para sustitución; intervalos efectivos. | RN-TR-13, sin permitir trasvase rutinario. |
| D06 | Alta, técnica | Reservas planificadas/ocupación, alcance de autorización de cada conflicto, liberación individual/conjunta e intervalos; vínculo FK de pares por clase de recurso. | Integridad que permita excepción sin abrir bypass. |
| D07 | Media/alta | Precisión/medición, rangos, distancia por tramos/reinicio, aceptación y cálculo transportado/pendiente/exceso ante volumen cambiado. | Campos NUMERIC y totales, no imponer tolerancias. |
| D08 | Media | Granularidad/evidencia de liquidación y condiciones de saldo de viáticos. | Sin devolución ni retención uniforme. |
| D09 | Media, técnica | Historia de rutas/lugares/requisito de permiso; formatos/límites y vínculos de adjuntos; datos mínimos del permiso. | Nuevos campos de OperationSite/archivos privados. |
| D10 | Alta, técnica | Aprobar tablas/campos de auditoría, avisos y autorizaciones acotadas; ejecutor tras aprobación, vigencia/revocación, retención/idempotencia. | Diseño físico 5.2; no permiso implícito. |
| D11 | Futura | Normativa/documento fiscal, pagos conjuntos, elegibilidad con pendientes y correcciones; parcial de un viaje si se necesita. | Facturación futura exclusivamente. |

Riesgos centrales: UNIQUE que impida RN-TR-04, excepción global sin vínculo específico,
producto histórico derivado de solicitud mutable, cierre utilizado para omitir RN-TR-10,
liberación por reloj, aplicación de precio solicitado sin aprobación, mezcla de reporte
con volumen definitivo, desembolsos contabilizados antes de entrega y archivo histórico
eliminado por compensación. Autorización de conflicto no garantiza disponibilidad física.

## Preparación y criterio para iniciar 5.2

Las 40 reglas están incorporadas y trazadas. La falta de la fuente original está
resuelta. El diseño funcional confirmado está consolidado; el esquema completo aún
necesita aprobación de las propuestas y precisiones de esta tabla.

Se puede autorizar una 5.2 acotada al núcleo aprobado con sus restricciones e historia,
pero no iniciar automáticamente. Separar subetapas: solicitudes/viajes/producto histórico;
asignaciones/excepciones/autorizaciones; operaciones y evidencias/viáticos; API/interfaz
posteriores. D01 excluye cualquier conducta ante permiso ausente; D03 excluye fórmula
comercial automática. Si un campo depende de decisión no resuelta, no inventar default,
rellenar datos históricos ni crear migración incompatible para corregir después.

Entrega solo documental; git diff --check y revisión de correspondencia RN. No pruebas
de software ni migraciones ejecutadas; no código, esquema, dependencia, .env, commit o push.

## Continuidad autorizada en 5.2A

Posteriormente se autorizó solo el núcleo de persistencia, once modelos, Gates y pruebas,
con cinco migraciones nuevas, tres dimensiones de estado y NUMERIC(15,2).
Ver [etapa-5-2a.md](etapa-5-2a.md) para lo efectivamente implementado y sus límites.
Las propuestas de asignaciones, conflictos, liberaciones y sustituciones son 5.2B,
todavía sin autorización; el resto de operaciones futuras no se consideran implementadas.


## Aclaración aprobada de D02 — Finalización oficial (etapa 5.3)

La aprobación posterior del usuario resuelve los requisitos de finalización de D02:

- Inicio oficial registrado y asignaciones históricas válidas de conductor, cabezal y cisterna.
- Una carga y una descarga con fecha, lugar, responsable y cantidades reales en litros.
- Cada detalle cargado tiene descarga para el mismo compartimento, incluso si entrega cero.
- Cualquier diferencia exige autorización administrativa específica RN-TR-10; no se exige igualdad.
- Secretaria o administrador confirma oficialmente con actor, fecha y auditoría.
- Reporte del conductor opcional; nunca constituye finalización oficial.
- Finalizar no libera conductor, cabezal ni cisterna.
- No se agregan documentos obligatorios; se conservan evidencias adjuntas cuando existen.
- RN-TR-17 permanece pendiente y la sustitución de cisterna continúa bloqueada por D05.

Esta aclaración no resuelve los otros temas que se agrupaban bajo D02 (por ejemplo,
revisión de producto o cancelación posterior de completados), ni cambia RN-TR-01 a
RN-TR-39 o RN-SEG-01. Detalles técnicos: [etapa-5-3.md](etapa-5-3.md).

### Actualización posterior: pagos parciales

La instrucción del usuario del 2026-10-14 sustituye el contrato anterior de
RN-TR-33: se permiten pagos parciales o totales. Las menciones anteriores de
este diseño 5.1 sobre pago único describen la decisión histórica y ya no
constituyen la regla vigente. La implementación sigue pendiente de moneda,
precisión, importes/conceptos autorizados y elegibilidad comercial; véase
[inicio y preparación de facturación](inicio-y-facturacion-administrativa.md).
