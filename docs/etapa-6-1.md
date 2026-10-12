# Etapa 6.1 — Análisis funcional del Taller Mecánico

> Referencia del análisis inicial publicado en 96338c7. Las respuestas funcionales
> aprobadas posteriormente para D-TAL-01..13 prevalecen sobre las preguntas y
> celdas pendientes de este documento: ver [etapa-6-2.md](etapa-6-2.md).
> Las 28 RN permanecen iguales; el diseño físico todavía requiere aprobación.

## Alcance y estado del documento

Base inspeccionada: cf7bc8100dc1109c2f5d15d8b775a5dd976d0c47, etapa 5.6 publicada.
Empresa con una sede, más de 40 tráileres y reparación propia de cabezales y cisternas.
No se introducen camiones rígidos, vehículos ajenos, multisede ni gestión de clientes
externos de taller. Se conserva Laravel 13, PostgreSQL 18 y Vue 3 + TypeScript.

La fuente funcional es [REGLAS_TALLER_MECANICO.md](REGLAS_TALLER_MECANICO.md):
RN-TAL-01 a RN-TAL-28 confirmadas, junto con RN-SEG-01 y las decisiones explícitas
del usuario. Esta etapa **solo documenta**. No aprueba esquema físico, nombres de tablas,
Gates nuevos, procedimientos abiertos ni implementación de 6.2.

Se contemplan reportes, ingreso y bloqueo, órdenes independientes, diagnóstico,
autorización, ejecución, tiempos, evidencias, faltantes, reparación externa, revisión,
salida e historial. Inventario y Compras se integrarán cuando sus procesos reales existan.
Los preventivos están dentro del dominio; su programación automática permanece pendiente.

## Fuentes y contraste con el repositorio

Se inspeccionan AGENTS.md, ARCHITECTURE.md, docs/propuesta-tecnica.md,
tabla-entidades.md, tabla-relaciones.md, modelo-er.mmd, arquitectura.mmd y etapas 4.x,
5.1–5.6, junto con los componentes citados en la sección de integración.
Los documentos globales identifican sus tablas como borrador histórico; no equivalen
al esquema implementado. `Workshop` está previsto en arquitectura.mmd y la propuesta
modular, pero **no existe** `app/Modules/Workshop` ni implementación de órdenes.

| Hallazgo | Tratamiento vigente |
| --- | --- |
| ER histórico: WorkOrder → WorkTask → TaskAssignment, potencialmente varias reparaciones/tareas independientes | Una orden representa una reparación o servicio. Actividades internas describen pasos de esa reparación; no se usan para reunir averías distintas. Mecánicos se asignan a la orden. Otra avería genera reporte y otra orden oficial. |
| Borrador incluye una sola aprobación opcional en WorkOrder | No basta para solicitudes, decisiones, contenido autorizado, consumo de aprobación y salida independiente. No importar una excepción genérica del borrador. |
| `fleet_assets.service_status` tiene available, maintenance y out_of_service | Reutilizar la elegibilidad de flota; ese campo aislado no explica motivos múltiples ni acredita una salida administrativa. Necesita integración revisada en 6.2, no un booleano paralelo. |
| Gates actuales de flota solo permiten administrador; mechanic existe como rol | No ampliar manage-fleet a secretaria/mecánico. Autorizar futuros casos concretos de taller mediante políticas propias. No hay permiso Taller existente que pueda reutilizarse sin diseño. |
| Inventario/Compras tienen catálogos | SparePart y Supplier sí existen; movimientos, saldos, compras, recepción y autorizaciones de compras todavía no están implementados. |
| Viejo AssetReading propone duplicar lecturas de viaje | TripOdometerReading ya registra lecturas y revisiones por asignación. Consultarlas; no duplicarlas ni deducir un odómetro vigente de todo activo sin definirlo. |
| Document pertenece a FleetAsset y DocumentType, con vigencia e historial | No insertar evidencia de orden como documento reglamentario de flota ni cambiar su modelo para fingir esa relación. |

Las RN de Transporte siguen vigentes: ingreso al taller no dispara cancelación/liberación.
Una excepción de conflicto de ocupación autorizada en Transporte no convierte un activo
bloqueado en elegible. RN-TR-17, D05, cambios de producto y cancelación de viajes completados
siguen pendientes; Taller no los resuelve.

## Actores y matriz funcional de permisos

Roles técnicos reales: `administrator`, `secretary`, `mechanic`; identidades en User,
empleados en Employee, membresías en Role/user_roles. El conductor no recibe permisos de
Taller por esta etapa. Usuarios inactivos o con rol revocado no conservan capacidad de operar.

Leyenda: **Sí** regla confirmada; **No** prohibido/no concedido; **Pendiente** precisión
que requiere aprobación. La matriz describe funciones futuras, no permisos ya instalados.

| Operación | Administrador | Secretaria | Mecánico | Fuente / alcance |
| --- | --- | --- | --- | --- |
| Reportar avería | Sí | Sí | Sí | 02; reporte no es orden oficial |
| Crear orden oficial | Sí | Sí | No | 02,06,08; una reparación por orden |
| Registrar ingreso oficial al taller | Sí | Pendiente | Pendiente | 01 no especifica quién formaliza el ingreso; un reporte no prueba ingreso físico |
| Asignar/reasignar mecánicos | Sí | Pendiente | No concedido | 09 define cardinalidad, no delegación de gestión |
| Consultar órdenes/historial | Sí, completo | Sí, información de coordinación | Sí, órdenes asignadas | 23,25,28; acceso a otras órdenes/costos/evidencias del mecánico requiere precisión |
| Diagnosticar/proponer trabajo y repuestos | Sí, acceso completo | No concedido como función técnica | Sí | 03,11; delimitar propiedad de orden en políticas futuras |
| Registrar estimación disponible | Sí | Pendiente | Sí como propuesta de trabajos/repuestos | 03,12; responsable de costos/mano de obra por precisar |
| Solicitar autorización de reparación | Sí | Sí, coordinar solicitud | Sí, propuesta técnica | 02–04,11; solicitud no es decisión |
| Autorizar reparación, repuestos o reparación externa | Sí, exclusivo | No | No | 02–04,19 |
| Iniciar/reanudar y registrar trabajo terminado | Sí, sin omitir controles | No concedido como ejecutora técnica | Sí, con autorización previa para ejecución | 03,05,15,16 |
| Pausar/registrar tiempos y observaciones | Sí | No concedido como función técnica | Sí | 15,16; no ampliar alcance al reanudar |
| Registrar consumo real autorizado | Sí | Pendiente como registradora | Sí, después de autorización | 03,13; nunca fabricar salida de inventario |
| Registrar faltante/necesidad de compra | Sí | Sí como coordinación; detalle de delegación pendiente | Sí como propuesta | 03,14; no equivale a compra efectuada |
| Autorizar compra | Sí, exclusivo | No | No | 14 |
| Registrar/consultar evidencia privada | Sí | Sí en su ámbito; detalle pendiente | Sí en su ámbito; detalle pendiente | 17,28; matriz por documento por definir |
| Solicitar cancelación | Sí | Sí | No concedido | 20 |
| Decidir cierre si existen trabajos/consumos | Sí, exclusivo | No | No | 20; disposición concreta pendiente |
| Cerrar orden terminada | Sí, sujeto a criterio pendiente | Pendiente | Pendiente | 05,10; no confundir con salida |
| Autorizar salida del vehículo | Sí, exclusivo | No | No | 01,05,21 |
| Rectificar preservando historia | Sí | Según función autorizada, pendiente | Según función autorizada, pendiente | 27 no concede modificar todos los registros |
| Consultar reportes consolidados/costos | Sí | Alcance pendiente | Alcance pendiente | 26; no derivar acceso económico del rol técnico |

RN-SEG-01 no permite a la secretaria aprobar reparaciones, compras, salida o decisiones
reservadas. El administrador puede ejecutar funciones de cualquier ámbito sin impersonar
al mecánico: siempre conservar su identidad real y los requisitos de la operación.

## Ciclo de vida de la orden

Los siete estados de RN-TAL-10 están aprobados. Nombres físicos/API en inglés se revisarán
en 6.2; todavía no se fijan enums ni columnas. Estado de orden, espera de materiales,
estado de solicitud de autorización y disponibilidad del vehículo no son equivalentes.

| Transición funcional | Responsable | Validación / condición | Estado de definición |
| --- | --- | --- | --- |
| Crear → pendiente de diagnóstico | Secretaria/administrador | Activo real, única reparación, descripción, fecha, prioridad, creador | Flujo base coherente con 02,08,11; adopción técnica inicial en 6.2 |
| Pendiente de diagnóstico → pendiente de autorización | Mecánico registra diagnóstico y solicita; administrador puede hacerlo | Problema, causa probable, propuesta; cantidades/costos conocidos, sin completar desconocidos | Derivada de 03,11 |
| Pendiente de autorización → autorizada | Administrador | Decisión explícita referida a la orden y versión/contenido exactos | 02,03 |
| Autorizada → en reparación | Mecánico/administrador | Autorización válida y aplicada a ese alcance; registrar inicio | 03,16 |
| En reparación → pausa → reanudación | Mecánico/administrador | Eventos de tiempo, motivo y alcance autorizado preservados | 16; pausa es evento, no octavo estado aprobado |
| En reparación → terminada | Mecánico/administrador | Registrar trabajo realmente terminado, actividades y fecha; no libera activo | 05,15,16; mínimos de evidencia/consumo final por precisar |
| Terminada → cerrada | Actor/criterios por precisar | Revisión y cierre auditables; salida independiente | **Bloqueada hasta D-TAL-04** |
| Estado no terminal → solicitud de cancelación | Secretaria/administrador | Motivo, actor, fecha; preservar estado mientras no haya decisión aplicable | 20; solicitud no es cancelación automática |
| Solicitud → cancelada o cierre correspondiente | Administrador si hay trabajo/consumo; caso sin ejecución pendiente | Mantener hechos, consumos y autorizaciones; no desbloquear | **D-TAL-07** define resultado y delegación sin trabajo |
| Autorización rechazada → siguiente estado | Por definir | Preservar rechazo; no comenzar ejecución ni cancelar automáticamente | **D-TAL-06** |
| Rectificación/reapertura de terminada/cerrada/cancelada | Por definir | Conservar original, motivo, autorización especial si afecta ejecución aprobada | **D-TAL-08**; no reapertura automática |

No se introduce estado “esperando materiales” además de los siete: se propone representar
la espera como evento/condición consultable, pendiente de validación técnica en 6.2.
No retroceder automáticamente a pendiente de autorización por una **avería distinta**:
se abre otra orden y los trabajos previamente autorizados pueden continuar (RN-TAL-04).
La ampliación de la misma reparación requiere decisión D-TAL-01.

## Casos de uso y flujos principales

### CU-TAL-01 — Reportar avería y crear orden

Mecánico, secretaria o administrador identifica el activo y comunica avería, fecha y
observaciones; adjunta evidencia cuando exista. Se conserva el reporte original.
Secretaria/administrador crea una orden de una reparación, con identificación única,
prioridad baja/normal/alta/urgente, descripción, fecha y creador. Puede vincular el reporte.
El reporte por sí solo no crea orden ni prueba ingreso al taller. Criterio de duplicados,
agrupación de reportes sobre la misma avería y cardinalidad definitiva requieren D-TAL-09.

### CU-TAL-02 — Ingreso, bloqueo y advertencias

Actor habilitado (D-TAL-05) formaliza ingreso por avería: activo, fecha, motivo y evidencia
si existe. La confirmación y el impedimento para nuevas asignaciones deben ser atómicos.
Se consulta ocupación efectiva del activo, incluidos viajes finalizados/cancelados con
recursos aún no liberados. Se conserva advertencia con referencias y actor/fecha; no se
cancela viaje ni se escriben liberaciones. Las nuevas asignaciones y sustituciones entrantes
no pueden eludir el bloqueo mediante una autorización de conflicto de Transporte.

No inferir ingreso de crear una orden, ni disponibilidad física de autorizar un trabajo.
Qué hacer con inicio/reprogramación de un viaje ya asignado se contrastará con las
validaciones actuales y D-TAL-10; ingreso no decide automáticamente continuidad del viaje.

### CU-TAL-03 — Diagnóstico, estimación y autorización

Mecánico registra problema encontrado, causa probable y reparación propuesta; puede
proponer repuestos, cantidades, costos y mano de obra disponibles. Desconocido no equivale
a cero; diferenciar estimado de efectivo. Se solicita autorización de contenido concreto.
El administrador revisa y decide con actor, fecha y motivo. Antes de iniciar o consumir,
el backend verifica que la aprobación cubra esa orden y esos trabajos/repuestos; otra
orden o modificación de contenido no puede reaprovecharla. Rechazo no inicia reparación.
Caducidad/revocación y rangos autorizados precisan D-TAL-06.

### CU-TAL-04 — Ejecutar y registrar tiempos/repuestos

Tras autorización se registra inicio, actividades, observaciones, pausas, reanudaciones
y finalización real. Cada corrección conserva anterior/nuevo, motivo y autor. Los eventos
permiten reconstruir intervalos; no borrar pausas ni confundir tiempo de trabajo con tiempo
fuera de servicio del activo. Evitar registros duplicados/intervalos imposibles con controles
transaccionales; la atribución por varios mecánicos se define antes de calcular indicadores.

Registrar repuestos realmente usados dentro de lo autorizado. Un faltante genera necesidad,
no consumo ni compra. Cuando Inventario opere, consumo y movimiento deben coordinarse sin
confirmar éxito si falla el movimiento. Hasta entonces no presentar saldo o movimiento ficticio.

### CU-TAL-05 — Avería adicional y materiales faltantes

Mecánico registra avería distinta y solicitud independiente. Secretaria/administrador
crea otra orden; administrador decide su autorización propia. La primera orden conserva
su alcance y puede continuar lo autorizado. Un faltante puede pausar la ejecución; compra
requiere autorización exclusiva. Ni urgencia ni faltante eximen de aprobación previa.
Ampliación de alcance dentro de la misma reparación permanece fuera hasta D-TAL-01.

### CU-TAL-06 — Terminación, revisión, cierre y salida

El mecánico comunica trabajo terminado. La orden pasa a terminada; el activo sigue
bloqueado. Criterio/actor de cierre se define mediante D-TAL-04. La revisión administrativa
para salida considera **todas** las órdenes que impiden operar y otros impedimentos.
Solo el administrador autoriza salida, registrando contenido, motivo, actor y fecha.
Se revalida bajo bloqueo transaccional para evitar una orden/ingreso simultáneo no revisado.

Salida autorizada no elimina el historial ni garantiza disponibilidad para una nueva
asignación: un recurso ocupado no liberado o fuera de servicio conserva su impedimento.
Cerrar una orden no ejecuta salida, cambio a available ni liberación de Transporte.
Cancelación tampoco demuestra avería resuelta. Clasificación de órdenes impeditivas y
resolución verificable requieren D-TAL-05; no elegir automáticamente “cerrada = resuelta”.

### CU-TAL-07 — Reparación externa

Orden de una reparación en activo propio; proveedor existente, autorización administrativa,
trabajo, fechas, costo efectivo y factura cuando consten. Estimación no es costo pagado;
proveedor no constituye mecánico interno ni permiso administrativo. No registrar compras,
pagos o facturas fiscales como realizadas desde Taller. Urgencia fuera de sede se mantiene
pendiente, sin aprobación retroactiva implícita (D-TAL-03).

### CU-TAL-08 — Cancelación y rectificaciones

Secretaria/administrador solicita cancelar. Si existen trabajos o consumos, administrador
decide cierre correspondiente sin eliminar esos hechos. No implementar devolución automática
de repuestos ni reversión de costos; dependen de procesos reales posteriores.
Una rectificación enlaza original y reemplazo lógico, valores, motivo, actor y fecha;
si cambia contenido autorizado requiere las decisiones especiales aplicables. No recrear
una orden para esconder su historial. Flujo de cancelación sin trabajos/reapertura: pendientes.

### CU-TAL-09 — Consulta, avisos y acceso móvil

Administrador consulta pendientes de decisión; secretaria ve activos bloqueados y coordinación;
mecánico ve órdenes asignadas y registra información permitida desde celular/tablet.
Filtrar órdenes por estado, activo y fechas; historial por activo incorpora todos los hechos.
Reportes de reparaciones frecuentes, costos y tiempo fuera de servicio utilizan datos reales,
explican cobertura y valores faltantes. No inventar clasificación ni sumar monedas distintas.
Avisos en interfaz satisfacen el alcance funcional; correo/push/tareas automáticas no están
aprobados. Acceso móvil responsive no significa PWA ni autorización para guardar datos offline.

## Integración con componentes reales

Referencias relativas a la raíz del repositorio; los métodos y clases citados existen.

| Componente existente | Reutilización prevista y límite |
| --- | --- |
| `app/Modules/FleetAndDocuments/Models/FleetAsset.php`, `TractorUnit.php`, `Tanker.php` | Identidad UUID del activo, tipo exclusivo, código, service_status. No crear tabla paralela de vehículos. |
| `app/Modules/FleetAndDocuments/Application/FleetAdministration.php` | Coordinación transaccional de flota y bloqueo advisory 873210. save no ofrece cambio de service_status; no fingir un API de ingreso/salida existente. |
| `app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php` | inspect/revalidate calculan elegibilidad y conflictos por asignaciones sin liberación. Integrar el impedimento de taller en la fuente de flota, sin duplicar disponibilidad en Vue/Taller. |
| `app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php` | Operaciones bajo bloqueo 873210/guard; asignar/sustituir/release manual. Taller consulta historia; no llama release al ingresar o salir. |
| `app/Modules/TransportAndDispatch/Models/TripAssetAssignment.php`, `TripAssetRelease.php`, `Trip.php` | Vincular advertencias a asignaciones efectivas/trip y consultar historial. current representa ausencia de liberación real, no fechas programadas ni estado terminal. |
| `database/migrations/2026_10_11_000130_protect_trip_assignment_integrity.php` | Integridad de elegibilidad: service_status=available; excepción por conflicto no omite elegibilidad. Solo lectura en 6.1; eventual corrección mediante migración nueva aprobada. |
| `database/migrations/2026_10_12_000140_create_trip_operational_records.php` y `TripOperations::start` | Inicio oficial exige recursos elegibles. No retirar esta validación para acomodar Taller sin aclarar D-TAL-10. |
| `app/Models/User.php`, `app/Modules/AccessAndPersonnel/Models/Employee.php`, `Role.php` | Identidad y personal existentes; users PK bigint, Employee/Role UUID. No crear catálogo duplicado de mecánicos ni adoptar UUID para User. |
| `app/Providers/AppServiceProvider.php`, `TransportCorePolicy.php` | Roles y Gates reales; patrón de consultar cuenta/roles actuales, administrador para decisión. Gates manage-fleet, manage-trip-assignments, manage-trip-operations y decide-transport-authorization **no son permisos de Taller**. |
| `routes/backend.php`, `EnsureActiveAccount.php`, autenticación web | Sesión, CSRF, auth.session, cuenta activa; conservar la misma integración futura. No escribir rutas ahora. |
| `TransportChange`, `TransportAuthorizationRequest/Decision/Application`, `PreservesTransportHistory` | Referencia del patrón append-only, separación solicitud/decisión/aplicación y contenido exacto. Están acoplados a request/trip/cambios/operaciones de Transporte; no guardar decisiones Taller usando trip_id ficticio ni copiar directamente el trait con mensajes de Transporte. |
| `PrivateDocumentStorage`, `DocumentUploadRequest`, `DocumentResource`, `ReconcileDocumentStorage` en FleetAndDocuments | Patrón privado: validación real, nombres internos, no storage_key, compensación segura y recuperación. register devuelve Document y reconciliación consulta Document; no es adaptador genérico de adjuntos de orden. Revisar extensión mínima/servicio concreto en 6.2 sin alterar documentación reglamentaria. |
| `app/Modules/TransportAndDispatch/Models/TripOdometerReading.php` | Lecturas históricas y correcciones reales; referencia para preventivos futura. No deducir contador para cisterna, reset o suma de tramos sin decisión. |
| `app/Modules/Inventory/Models/SparePart.php` | Reutilizar catálogo spare_parts: code, description, unit. No contiene existencias ni movimientos. |
| `app/Modules/Purchasing/Models/Supplier.php` | Reutilizar catálogo suppliers; no implica compra/recepción/pago existente. |
| `resources/js/modules/FleetAndDocuments/` y `TransportAndDispatch/`, `resources/js/app.ts` | Futuro montaje Blade/Vite, fetch con sesión/CSRF, Tailwind 4, formularios/errores responsive y componentes mecánicos de paginación. No copiar reglas de negocio al frontend. |

### Disponibilidad y concurrencia: propuesta técnica para revisar en 6.2

Mantener una única decisión de elegibilidad operativa de flota, coordinada con inspect,
revalidate y restricciones SQL. Registrar causas/ingresos y decisiones de salida, evitando
que available borre un impedimento out_of_service o una segunda avería. No basta restituir
el estado anterior: otro impedimento puede haber surgido durante la reparación.

Evaluar una proyección de service_status desde impedimentos activos y su protección SQL,
con identidad/historial propios de Taller. El diseño físico y la representación de razones
múltiples necesitan revisión antes de migraciones; no crear un segundo cálculo de disponibilidad.
Ocupación por Transporte sigue independiente: aunque cesen impedimentos mecánicos, current
puede impedir asignar por conflicto. Documentos vencidos/faltantes mantienen advertencias,
no nuevos bloqueos técnicos (4.x y 5.x).

Ingreso/salida/asignación deben compartir orden de bloqueo compatible con advisory 873210,
guard y bloqueo de activo, y revalidar después de adquirirlos. Si asignación confirma primero,
el ingreso registra la asignación existente y conserva recursos; si ingreso confirma primero,
la nueva asignación se rechaza. Salida contra ingreso/segunda orden debe revalidar todas las
causas. No hacer almacenamiento externo o llamadas prolongadas dentro de estas transacciones.
No permitir que escrituras SQL directas o futuros cambios genéricos de flota omitan el bloqueo.
Esta etapa no añade triggers ni modifica los actuales.

## Modelo conceptual propuesto — no esquema aprobado

Los nombres describen responsabilidades, no clases/tablas implementadas ni DDL.
Relaciones nuevas se revisarán en 6.2, incluida asociación ingreso/órdenes y propiedad mecánica.

| Concepto | Responsabilidad y relación |
| --- | --- |
| Reporte de avería | Activo existente 1:N reportes; autor User y hechos originales. Vínculo a orden/cardinalidad pendiente. |
| Ingreso/impedimento de taller | Activo 1:N ingresos históricos; fecha/actor/motivo, advertencias de viajes y evidencia. Agrupación de órdenes por ingreso pendiente. |
| Orden de trabajo | Activo 1:N órdenes independientes, una reparación/servicio por orden. Reportes/ingreso sin crear duplicación de identidad de activo. |
| Asignación de mecánico | Orden 1:N participaciones en Employee existente; un empleado puede participar en varias órdenes. Historial de cambios; límites de concurrencia de personal pendientes. |
| Diagnóstico y estimación | Orden 1:N revisiones de diagnóstico/propuesta, con cantidades y costos solo conocidos. Versiones vinculadas al contenido autorizado. |
| Solicitud, decisión y aplicación | Separación conceptual como 5.2A, ámbito Taller propio. Reparación, compra y salida son autorizaciones distintas, no intercambiables. Granularidad/consumo pendiente de diseño. |
| Actividad y evento de tiempo | Orden 1:N pasos de una misma reparación, inicio/pausa/reanudación/fin, actores, observaciones y revisiones. No agrupan reparaciones distintas. |
| Uso de repuesto / necesidad | Orden 1:N usos efectivos autorizados y faltantes diferenciados; referencia SparePart existente. Movimiento real futuro en Inventario. |
| Reparación externa | Datos de la reparación de esa orden, proveedor Supplier, autorización, factura/costo. Cardinalidad de contratación y factura pendiente. |
| Evidencia privada | Orden 1:N adjuntos autorizados; originales preservados y rectificaciones vinculadas. No sustituye Document de flota. |
| Cambio/advertencia/resolución | Hechos inmutables con referencias de orden/activo, valores y responsable; una resolución no borra la advertencia. |
| Revisión/autorización de salida | Ámbito activo/ingreso y conjunto revisado de impedimentos; no equivale a decisión de una orden ni a liberación de asignación. |

No añadir Warehouse, Purchase, InventoryMovement o lectura duplicada de activo como si
existiesen. No definir fórmula de costo total, moneda, precisión monetaria o unidades de
mano de obra desde un ejemplo del borrador.

## Inventario y Compras: contratos futuros

Taller comunica orden, repuesto, cantidad/unidad, contenido autorizado, actor y operación
única; Inventario decide y registra movimiento real con referencia rastreable. No consumir
stock por una propuesta/estimación. El éxito requiere confirmar la operación real, y un
reintento no puede duplicarla. Devoluciones y rectificaciones deben producir historia y
movimientos legítimos, nunca borrar consumo. Políticas de reserva, stock negativo y entrega
por encargado no se definen aquí.

Necesidad de compra identifica faltante y orden, pero no acredita adquisición. Compras
conserva autorización administrativa, proveedor, compra y recepción reales cuando se
implemente. Autorización de reparar no autoriza comprar ni pagar automáticamente. Factura
adjunta a reparación externa es evidencia; no implementa facturación fiscal ni módulo Gastos.
Se deben acordar contratos transaccionales e idempotencia antes de activar consumos integrados.

## Auditoría, documentos y seguridad

Todo hecho conserva identidad, ámbito, actor autenticado, fecha real/fecha de registro,
motivo y referencias. Rectificaciones enlazan original/revisión con antes/después; no
DELETE silencioso ni actualización de hechos históricos. Conservar historial de estados,
participantes, diagnósticos, estimados, trabajos, tiempos, usos, costos, decisiones y salida.
Usar America/Costa_Rica para presentación y semántica de fechas; fechas con instante/offset
siguen convenciones TIMESTAMPTZ existentes. No reconstruir datos desconocidos.

Propuestas/solicitudes no son decisiones; decisiones no son aplicaciones. Cada ejecución
verifica contenido, orden/activo, versión y alcance, con exclusividad administrativa donde
corresponda. Una aprobación no se reutiliza para otra reparación ni un contenido modificado.
La autorización de una reparación legítimamente cubre sus actividades previstas: cómo
representar aplicaciones/consumos parciales y ampliaciones requiere D-TAL-01/06, no exigir
una aprobación empresarial nueva por cada clic ni permitir reutilización indiscriminada.

Revalidar roles vigentes en servidor y persistencia cuando corresponda, relaciones del activo,
propiedad de orden y contenido. No aceptar actor/decididor cliente ni considerar botones
ocultos como seguridad. Sesión, CSRF, errores HTTP y bloqueo optimista siguen convenciones
existentes. Las respuestas perdidas requieren consultar estado antes de reenviar; clave
idempotente, alcance, retención y protección durable se diseñan antes de mutaciones reintentables.

Evidencias: descarga privada autorizada, nombre interno único, MIME/contenido real, extensión
y límite acordado, no rutas libres/storage_key/URLs públicas; historial y compensaciones
que no borren archivos confirmados. Flota ya admite PDF/JPG/PNG y 10.000.000 bytes, pero
**esos límites no se transfieren como regla aprobada de Taller**: propuesta de reutilización
mínima sujeta a D-TAL-12. No almacenar documentos privados offline ni añadir push/correo.

## Criterios de aceptación para implementación posterior

| ID | Escenario verificable | RN |
| --- | --- | --- |
| CA-01 | Ingreso por avería bloquea nuevas asignaciones; datos y bloqueo se confirman juntos o ninguno. | 01 |
| CA-02 | Ingreso con recurso asignado conserva viaje/recursos y registra advertencia consultable; estados terminales sin release siguen mostrando ocupación. | 01,22 |
| CA-03 | Reporte mecánico no crea orden oficial; creación oficina registra activo, descripción, fecha, prioridad e identidad única. | 02,08,18 |
| CA-04 | Una avería distinta crea otra orden; varias órdenes del activo coexisten sin mezclar autorizaciones. | 04,06,07 |
| CA-05 | Diagnóstico registra problema, causa y propuesta; cantidades/costos desconocidos permanecen desconocidos. | 11,12 |
| CA-06 | Mecánico/secretaria no aprueban reparación, compra o salida; cuenta inactiva/rol revocado pierde acceso. | 02,03,14,01; SEG-01 |
| CA-07 | Inicio/consumo sin aprobación válida se rechaza sin efectos; aprobación ajena o contenido cambiado no se aplica. | 03,04 |
| CA-08 | Órdenes con uno o varios mecánicos conservan sus asignaciones y cambios; consulta de asignadas respeta ámbito definido. | 09,25,28 |
| CA-09 | Inicio/pausas/reanudaciones/fin y actividades preservan historia; solicitudes repetidas/concurrentes no duplican una operación lógica. | 15,16,27 |
| CA-10 | Repuesto usado es distinto de estimado/faltante; integración futura confirma un único movimiento real o informa fallo, nunca éxito ficticio. | 12–14 |
| CA-11 | Terminar/cerrar/cancelar una orden no cambia automáticamente disponibilidad ni libera recursos. | 05,21,22 |
| CA-12 | Administrador revisa todas las causas impeditivas y autoriza salida; segunda orden/impedimento concurrente no se pierde. | 01,05,07,21 |
| CA-13 | Faltante queda registrado y visible; necesidad no simula compra ni exime de autorización. | 14,25 |
| CA-14 | Fotografías/facturas/documentos solo se descargan con autorización; archivos inválidos, traversal y acceso público se rechazan; errores no destruyen historia. | 17,27 |
| CA-15 | Reparación externa conserva proveedor real, autorización, fechas, costo/factura disponible; no simula pago. | 19 |
| CA-16 | Cancelación solicitada preserva trabajos/consumos; con ejecución existente únicamente administrador decide resultado definido. | 20 |
| CA-17 | Historial por cabezal/cisterna incluye averías, diagnósticos, decisiones, actividades, repuestos y costos, con rectificaciones enlazadas. | 23,27 |
| CA-18 | Preventivo representa un servicio por orden sin programar automáticamente por un umbral no aprobado. | 06,24 |
| CA-19 | Paneles muestran pendientes del administrador, asignadas del mecánico y bloqueados de secretaria sin filtrar datos fuera del ámbito autorizado. | 25 |
| CA-20 | Filtros reales por estado/activo/fecha, frecuencia/costos/tiempo con metodología y faltantes explícitos; no sumar tiempo de órdenes solapadas como indisponibilidad del activo. | 26 |
| CA-21 | Flujo móvil/tablet permite consulta y captura autorizada, muestra carga/validación/error y recupera respuesta perdida sin éxito simulado. | 28 |
| CA-22 | Transiciones inválidas, vínculos de otro activo/orden, SQL directo histórico y carreras asignación/ingreso o salida/ingreso no evaden integridad. | 01,03,07,10,21,27 |

CA que dependa de una precisión pendiente deberá probarse después de aprobarla;
no inventar resultados esperados para cerrar la cobertura. Validación futura con PostgreSQL
18 aislado y navegador real Laravel/Vue; las pruebas simuladas se reportan por separado.

## Decisiones pendientes, impacto y recomendaciones no aprobadas

Todas las RN permanecen confirmadas. Las siguientes preguntas **no tienen respuesta
aprobada**; recomendaciones son propuestas, no permisos ni comportamiento implementable.

| ID / impacto | Pregunta abierta | Recomendación separada / parte bloqueada |
| --- | --- | --- |
| D-TAL-01 / alta | ¿Cómo aprobar ampliación de alcance de la misma reparación, nuevos repuestos/costos y ejecución parcialmente consumida? | Proponer revisión de alcance y decisión independiente vinculada a contenido/versiones; no aplicar a otra avería. Bloquea ampliaciones y diseño de aplicaciones parciales. |
| D-TAL-02 / media | ¿Preventivos por kilometraje, tiempo o ambos; umbrales, fuente, reinicios y responsables? ¿Ingreso preventivo bloquea igual? | Reutilizar lecturas reales existentes; no cron/umbrales ni bloqueo preventivo presumido. Puede documentarse/registrarse servicio único sin automatizar programación. |
| D-TAL-03 / alta | ¿Cómo manejar reparación urgente fuera de sede, ingreso remoto, comunicación y aprobación previa? | Urgencia no otorga excepción. Proponer canal administrativo trazable; bloquear excepciones/retroautorización hasta decidir. |
| D-TAL-04 / alta | ¿Qué diferencia verificable separa terminada de cerrada, quién cierra y qué revisa? ¿Puede salir un activo con orden terminada aún no cerrada? | Separar declaración técnica, cierre y salida; definir lista mínima de revisión. Bloquea cierre oficial y relación de revisión con salida. |
| D-TAL-05 / alta | ¿Quién registra ingreso? ¿Qué órdenes impiden operar, quién clasifica y acredita resolución? ¿Varias órdenes por ingreso y averías sin orden? | Proponer ingreso/impedimentos auditables independientes; no usar estado terminal como prueba de resolución. Bloquea habilitación completa de ingreso/salida y proyección de disponibilidad. |
| D-TAL-06 / alta | ¿Rechazo, revocación/caducidad de aprobación y modificaciones de diagnóstico? ¿Autorización por repuesto/cantidad/costo, usos parciales y reanudación? | Diseñar alcance exacto y aplicaciones no reutilizables fuera de él; conservar decisiones. No inventar estados posteriores a rechazo ni límites autorizados. |
| D-TAL-07 / alta | ¿Quién confirma cancelación sin trabajo/consumo? Con trabajo, ¿cancelada o cerrada y cómo resolver materiales/costos/avería pendiente? | Mantener solicitud separada, administrador cuando hay ejecución; no desbloquear ni devolver stock por cancelar. Bloquea confirmación y reversión no definida. |
| D-TAL-08 / alta | ¿Quién rectifica qué datos? ¿Reapertura terminal y rectificación de consumo/costo/autorización requieren nueva decisión? | Revisiones append-only y permisos por función; no edición amplia ni reapertura implícita. |
| D-TAL-09 / media | ¿Cómo vincular múltiples reportes de una avería a una orden y evitar duplicados? ¿Quién asigna mecánicos y pueden trabajar simultáneamente en varias órdenes? ¿Acceso fuera de asignadas? | Reutilizar Employee y política por orden; no exclusividad horaria ni permisos globales mecánicos inventados. |
| D-TAL-10 / alta | ¿Cómo coordinar viaje ya asignado al ingresar, especialmente en curso? El inicio/reprogramación actuales revalidan elegibilidad; ¿se conserva esa restricción? | Mantener asignaciones y avisos sin cambiar transiciones de Transporte; no introducir sustitución de cisterna ni resolver D05. Aprobar cualquier cambio necesario antes de tocar Transporte. |
| D-TAL-11 / media | ¿Monedas, precisión/unidades de costos/mano de obra, responsables de registro y alcance de consultas? ¿Frecuencia por qué clasificación y tiempo por activo/orden/mecánico? | Mostrar datos reales diferenciando estimado/efectivo; unión de intervalos por activo para indisponibilidad como propuesta técnica, sin facturación ni total comercial inventado. |
| D-TAL-12 / media | ¿Formatos, tamaño, confidencialidad/ámbito de evidencia y retención? ¿Factura externa es requisito para terminar/cerrar/salir? | Proponer patrón privado de Flota sin heredar obligatoriedad/limites; no bloquear salida por falta de factura hasta aprobar criterio. |
| D-TAL-13 / alta para integración | ¿Quién entrega repuestos, política de reserva/stock y devolución? ¿Compras/recepción primero, qué registrar de uso real mientras no hay movimientos? | Separar necesidad, uso comunicado y movimiento real; habilitar consumo integrado solo con proceso real y acuerdo de transacción. No simular existencias. |

Riesgos: estado available impuesto sin considerar otras causas; orden cancelada usada para
ocultar avería; múltiples órdenes contadas como tiempo duplicado; aprobación genérica
utilizada para comprar/consumir alcance distinto; contratos históricos interpretados como
implementados; uso de almacenamiento Document para adjuntos incompatibles; corrección de
Taller que altere silenciosamente viaje, inventario o costo de otro módulo.

## División de trabajo propuesta

| Etapa | Trabajo posterior a autorización | Dependencias / salida verificable |
| --- | --- | --- |
| 6.2 persistencia/backend | Revisar modelo físico mínimo, identidad del activo/personal, reportes/órdenes, versiones/auditoría, diagnóstico/solicitud/decisión, actividades/tiempos y evidencia. Diseñar bloqueo/salida con Flota y PostgreSQL; nuevas migraciones revisadas, servicios concretos y políticas por función. | Resolver primero D-TAL-04/05/06/07/09/10 y permisos necesarios. Dividir núcleo independiente de integraciones bloqueadas; no implementar ampliación/urgencia/preventivo automático sin decisión. Preparar restricciones, concurrencia, rollback y permisos. |
| 6.3 API REST | Form Requests, Resources, sesión/CSRF y operaciones explícitas por caso de uso; errores y versiones/conflictos; descarga privada, consulta de historial y paneles. | Núcleo backend probado y contratos de evidencia/rectificación aprobados. No endpoints para edición de hechos inmutables ni roles cliente. |
| 6.4 Vue | Navegación interna; coordinación oficina, pendientes administrador, asignadas mecánico, diagnóstico/trabajo responsive, historial/evidencia y errores. | API real, permisos definidos. Reutilizar Vue/TypeScript/Tailwind/Blade/Vite y componentes simples; sin datos ficticios, offline ni autorización en frontend como autoridad. |
| 6.5 integración/pruebas | Flujos reales multiorden, ingreso con viaje asignado, autorización y consumo, pausa/terminación/revisión/salida, rectificación, externo, móvil y carreras. | PostgreSQL 18 temporal y navegador real; pruebas por rol/revocación, CSRF, SQL directo, archivos/fallos, respuesta perdida; distinguir mocks y E2E, documentar bloqueos. |

**Recomendación para iniciar 6.2:** resolver las decisiones de alta prioridad que afectan
bloqueo/salida, cierre/cancelación, alcance de aprobación y actores; revisar y autorizar el
modelo físico por separado. Puede prepararse un núcleo de reportes/órdenes/diagnóstico e
historial independiente, pero no declarar resuelto el flujo completo de disponibilidad.
No avanzar a 6.2 sin autorización expresa del usuario.

## Verificación documental de 6.1

Verificar numeración completa y única de las 28 RN, trazabilidad a criterios de aceptación,
referencias a archivos/métodos existentes, distinción implementado/propuesto y cambios
limitados a Markdown. No ejecutar suites de aplicación, migraciones, seeders ni acceder a
bases de datos para una entrega documental. La publicación documental no certifica que
Taller exista ni que sus flujos hayan sido probados. Resultado de controles y publicación
se informa al entregar.

Controles ejecutados: validación de RN-TAL-01..28 únicas y consecutivas; comprobación de
archivos y clases referenciados y enlaces Markdown locales; comprobación de que solo
ARCHITECTURE.md y los dos documentos nuevos de 6.1 cambian; `git diff --check` correcto.
No se ejecutaron pruebas de aplicación ni operaciones de base de datos. El estado inicial
estaba limpio y main remoto coincidía con el commit base de 5.6 antes de publicar.
