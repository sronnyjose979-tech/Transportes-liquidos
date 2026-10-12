# Etapa 5.4 — API REST de Transporte y Despacho

Base: main 0d1ae9a. Laravel 13, PostgreSQL 18 y módulos existentes. Sin frontend,
dependencias nuevas ni migraciones sobre transporte_db. RN-TR-01..39 y RN-SEG-01
conservan su numeración y reglas; esta etapa no resuelve RN-TR-17, D05 ni otros pendientes.

## Seguridad y contrato HTTP

Todas las rutas siguientes usan `/api/transport`, autenticación web por sesión,
EnsureActiveAccount, auth.session y CSRF existentes. Obtener `/api/csrf`, conservar
cookies y enviar X-XSRF-TOKEN en escrituras. No se añaden tokens, registro público,
permisos de modificar personal/flota ni acceso público a archivos.

Administrador y secretaria activos gestionan el núcleo mediante los Gates existentes.
Solo administrador decide autorizaciones. Conductor activo, con empleado activo y
asignación vigente propia, únicamente puede POST reports; no consulta vistas administrativas,
inicia o finaliza oficialmente ni registra cantidades o kilometrajes definitivos.
Liberación, revocación del rol o desactivación revocan la facultad de reportar.

Form Requests rechazan campos no admitidos, incluidos actor_id, registered_by_id,
created_by_id, estados y versiones históricas enviados fuera de sus contratos. Actores,
fechas oficiales, producto histórico y versiones nuevas proceden del servidor. Resources
usan listas explícitas de campos; no devuelven cuentas completas, email, contraseñas,
storage_key ni rutas físicas. Las referencias de evidencia se conservan como metadatos;
esta API no carga ni publica archivos. Referencias internas de almacenamiento se omiten
recursivamente de metadatos en respuestas; se conserva el JSON histórico en PostgreSQL. Los archivos documentales siguen en la API privada 4.4.

Respuestas de recurso: `data`; listas paginadas: `data`, `links`, `meta`. page >= 1,
per_page 1..100 (25 por defecto). Listas ordenadas también por identidad cuando corresponde.
Cache-Control no-store. Fechas de entrada ISO 8601 con segundos y offset explícito o Z,
hasta seis decimales de segundo. Se conserva el instante, normalizando a UTC para campos
anteriores que no serializan offset. America/Costa_Rica se representa con -06:00.

Litros como cadenas decimales, hasta 13 dígitos enteros y dos decimales: solicitado > 0,
real >= 0. Capacidades e integridad PostgreSQL siguen siendo autoritativas. Odómetro como
cadena decimal no negativa, sin fijar escala comercial nueva; no se compensa un reinicio.

| HTTP | Significado |
| --- | --- |
| 200 / 201 | Consulta/cambio confirmado o registro creado. |
| 401 / 403 | Falta de sesión / rol, elegibilidad de acceso o propiedad insuficiente. |
| 404 | Recurso o registro relacionado del viaje no encontrado. |
| 419 | Falta o invalidez de CSRF. |
| 422 | Validación, estado incompatible, capacidad, relación o autorización no aplicable. |
| 409 | Versión obsoleta, decisión duplicada, ocupación detectada o conflicto concurrente PostgreSQL. |
| 500 | Error inesperado, sin SQL ni datos internos en la respuesta de base de datos. |

No repetir automáticamente cargas, reportes o asignaciones tras perder una respuesta:
consultar primero detalle, historia y registros. No se introduce idempotencia offline o
retención de claves sin resolver D10. Cambios de estado y de solicitud requieren version;
las asignaciones y operaciones releen y bloquean el estado dentro de los servicios existentes.

## Endpoints

37 rutas HTTP, además de login/logout/csrf existentes. `{id}` y `{readingId}` son UUID.

| Método | Ruta relativa | Uso |
| --- | --- | --- |
| GET / POST | /requests | Consultar/crear solicitudes. |
| GET / PATCH | /requests/{id} | Consultar/cambiar volumen solicitado o fecha solicitada. |
| GET | /requests/{id}/history | Auditoría inmutable, valores anteriores/nuevos. |
| GET / POST | /trips | Consultar/crear viajes con producto histórico de la solicitud. |
| GET | /trips/{id} | Detalle, solicitud, carga/descarga con detalles y asignaciones actuales. |
| GET | /trips/{id}/history | Cambios de estados y programación auditados. |
| POST | /trips/{id}/schedule | Programar viaje no iniciado. |
| POST | /trips/{id}/reschedule | Reprogramar viaje no iniciado; revalidar ocupación efectiva. |
| POST | /trips/{id}/start | Inicio oficial con recursos vigentes y elegibles. |
| POST | /trips/{id}/finish | Finalización D02 y consumo de aprobación RN-TR-10 si corresponde. |
| POST | /trips/{id}/cancel | Cancelar pending/in_progress; motivo, actor y fecha. |
| POST | /trips/{id}/close | Cerrar completed/cancelled, conservando pendientes y recursos. |
| GET | /trips/{id}/warnings | Advertencias capturadas y resoluciones históricas. |
| GET | /availability | Elegibilidad, disponibilidad informativa y ocupantes efectivos. |
| GET | /trips/{id}/assignments | Historia de operaciones, asignaciones, liberaciones y conflictos. |
| POST | /trips/{id}/assignments/assign | Asignar recurso elegible, con decisiones cuando corresponda. |
| POST | /trips/{id}/assignments/substitute | Sustituir conductor/cabezal, con aprobación administrativa durante viaje. |
| POST | /trips/{id}/assignments/release | Liberación individual manual; respeta cardinalidad de viaje activo. |
| GET / POST | /trips/{id}/authorizations | Consultar/solicitar autorización específica. |
| GET | /authorizations/{id} | Intención, decisión y aplicación consumida cuando existe. |
| POST | /authorizations/{id}/decisions | Aprobar/rechazar exclusivamente por administrador. |
| GET / POST | /trips/{id}/load | Consultar/registrar carga con detalles. |
| GET / POST | /trips/{id}/unload | Consultar/registrar descarga con detalles trazables. |
| GET / POST | /trips/{id}/reports | Oficina consulta; oficina o conductor propio reporta información. |
| GET / POST | /trips/{id}/odometer | Historia/registro de lecturas por asignación histórica de cabezal. |
| POST | /trips/{id}/odometer/{readingId}/corrections | Nueva revisión, sin editar ni eliminar el original. |
| GET | /trips/{id}/distance | Distancia exacta por tramo; null sin datos suficientes o ante reinicio. |
| GET | /trips/{id}/volume-differences | Contenido exacto de diferencias por compartimento. |
| GET | /lookups/{kind} | Catálogos mínimos de lectura para selección en formularios. |

Filtros de GET trips: transport_request_id, execution_status, administrative_status.
Lookups: customers, products activos, sites, routes existentes, drivers elegibles,
assets (incluye compartimentos y capacidades). Solo id/nombre u otros campos necesarios
para selección; no se otorgan modificaciones de catálogos/flota/personal a secretaria.
Disponibilidad requiere resource_kind (driver/tractor_unit/tanker) y resource_id.
Distance requiere tractor_assignment_id perteneciente al viaje. Historias operativas
incluyen las liberaciones, actor/motivo de su operación y aplicaciones de autorización.

## Entradas de escritura

Crear solicitud: customer_id, liquid_product_id, route_id, requested_liters, requested_at.
PATCH solicitud: version, reason y al menos requested_liters o requested_at. Nunca cambia
litros reales; trips_count y GET trips por transport_request_id permiten revisar viajes
asociados. No se calcula volumen pendiente con una fórmula comercial no aprobada.

Crear viaje: transport_request_id. No admite estados ni producto libre. Producto/nombre
histórico se obtienen bajo bloqueo de la solicitud y se validan en PostgreSQL.

Acciones de viaje: version, reason. schedule/reschedule agregan scheduled_start_at y
scheduled_end_at opcional. finish admite decision_id específico. close admite warnings
como objetos `{code, evidence}`: quedan ligados a closure_version, sin resolverlos ni
borrarlos. Cierre no exige finalización ficticia de cancelados. Ninguna acción libera recursos.

Asignar/sustituir: resource_kind, resource_id, reason, decisions opcionales (IDs de
aprobaciones existentes). Liberar: resource_kind, reason. Conflicto no aprobado persiste
como conflict_detected antes del HTTP 409; su ID queda en el mensaje y en la historia.
La elegibilidad se verifica antes de registrar ocupación, evitando tratar como conflicto
un recurso del tipo equivocado o inactivo. PostgreSQL conserva su validación adicional.

Autorización de asignación: type (assignment_conflict/driver_substitution/tractor_substitution),
reason y content `{action: assign|substitute, resource_kind, resource_id}`. El servidor
reconstruye previous_assignment_id, ocupantes ordenados y versión bajo bloqueo; no confía
en una lista de conflictos enviada libremente. Para volume_difference enviar solo type
más reason: contenido exacto proviene de trip_volume_content, una vez existe cobertura D02.
Decidir: decision (approved/rejected), reason. Aprobar no aplica ni garantiza disponibilidad;
servicios revalidan contenido/versión/ocupantes y consumo único al ejecutar la operación.

Carga/descarga: operation_site_id, occurred_at, reason, evidence opcional, items no vacíos.
Carga items: `{compartment_id, liters}`; descarga items: `{load_item_id, liters}`. Identidades
no se repiten dentro de la misma entrada. Cabeceras son únicas por viaje. PostgreSQL
valida pertenencia de compartimento/carga, capacidad histórica y cobertura para finalizar.
Cero explícito es válido; un detalle ausente no se interpreta como cero.

Reporte: type (information/completion_report/incident), information (objeto), occurred_at.
Lectura: tractor_assignment_id, reading_type (initial/final), reading_value, occurred_at,
reason, evidence opcional. Corrección: reading_value, occurred_at, reason, evidence opcional.
La identidad del tramo y revisión siguiente se derivan del original, vinculado al viaje de URL.

## Revisión de 5.3 y correcciones

- PostgreSQL ya rechazaba detalles de descarga de cargas de otros viajes mediante las
  relaciones de cabecera y trigger de detalle. Nueva regresión usa INSERT SQL directo
  y HTTP, verificando ausencia de cabecera parcial y conservación del registro original.
- Existencia de asignaciones antiguas por sí sola era insuficiente: un UPDATE SQL podía
  inventar el inicio y completar usando asignaciones liberadas. Nueva migración
  2026_10_12_000170_require_operational_assignment_history exige inicio pending→in_progress
  auditado, con el mismo actor/instante, y cisterna de carga del mismo viaje/operación,
  vigente al registrar la carga. El inicio auditado conserva las validaciones de recursos
  de 5.3/5.2B, incluyendo sustituciones autorizadas posteriores. No inventa asignaciones
  para historia anterior ni invalida correcciones ajenas de finalizaciones ya existentes.
- Aprobaciones de diferencias ya tenían contenido, versión y consumo únicos. Pruebas HTTP
  agregan rechazo de reutilización entre viajes y decisiones repetidas; las regresiones
  SQL y de cantidades exactas de 5.3 siguen pasando sin cambiar migraciones publicadas.
- Fechas nuevas con offset se normalizan al escribir en modelos temporales antiguos.
  No se reescriben datos históricos ni se modifica el comportamiento documental de 4.4.

La migración sustituye el punto de validación manteniendo el chequeo original como función
base; no altera tablas ni borra historia. Es transaccional, no realiza reconstrucción de
datos y requiere recuperación revisada para down. Despliegue no ejecutado en esta tarea.

Reprogramar no modifica intervalos efectivos de asignación ni libera recursos. Bajo
lock/guard se revalidan elegibilidad, ocupantes reales y aplicaciones históricas que cubren
esa misma ocupación. Una aprobación existente no se consume otra vez ni cubre recursos o
conflictos diferentes. Programación no constituye reserva por fecha prevista.

## Archivos y validación

Application: TransportAdministration y AssignmentAvailability nuevos; AssignmentAdministration
revalida elegibilidad antes de conflictos y comunica ocupación como 409. Servicios de
TripOperations se reutilizan. HTTP: seis controladores, nueve Requests concretos y base
TransportInput, cuatro Resources y middleware de respuestas privadas/errores. routes/transport.php
se integra dentro del grupo autenticado de routes/backend.php. bootstrap/app.php renderiza
errores PostgreSQL solo para estas rutas; conserva los contratos anteriores.

TransportApiTest cubre roles, sesión/logout/CSRF, validación, CRUD permitido, auditoría,
versiones, transiciones, cierre con pendientes, asignación, sustitución, liberación,
autorizaciones, diferencias, captura, kilometraje y reportes propios/revocación.
TransportOperationalRegressionTest cubre los escenarios SQL anteriores. TransportApiConcurrencyTest
usa dos conexiones PostgreSQL reales con las mismas restricciones, sin API simulada,
para comprobar bloqueo, HTTP 409 seguro y reintento condicionado al estado confirmado.
OperationalConcurrencySchema comparte el aislamiento sintético de las suites concurrentes.
Fixtures de viaje cerrado incorporan inicio oficial real auditado; no se debilitan sus
aserciones de inmutabilidad, pendientes o permisos.

Comandos ejecutados con herramientas existentes:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
vendor/bin/pint --test
git diff --check
```

Resultado final: **189 pruebas y 1.797 aserciones correctas**, incluidas 170 pruebas
anteriores y 19 nuevas. PostgreSQL 18 temporal /tmp/transporte-tests-8Ln440, detenido por
el runner. Pint --test y git diff --check correctos. Persistencia y concurrencia reales,
sin SQLite, simulación de API ni modificaciones de transporte_db, .env o dependencias.
La inspección de route:list confirma las 37 rutas. Publicación autorizada solo después
de estas verificaciones y revisión de alcance/secretos.

## Límites y pendientes

- RN-TR-17: sin conducta para permiso obligatorio ausente ni autorizaciones inventadas.
- D05: sustitución de cisterna y autorizaciones para aplicarla quedan excluidas.
- RN-TR-26/D02 residual: cambio de producto de solicitud/reinterpretación de viajes
  pendientes no expuesto. PATCH solo cambia volumen/fecha; producto histórico intacto.
- Cancelación posterior de completed permanece excluida; cancelación pending/in_progress sí.
- D07/D10: sin compensación de reinicios, agregado comercial de litros pendientes,
  tolerancias nuevas, offline o claves de idempotencia. Distancia por tramo disponible.
- No hay edición/borrado de cargas, descargas, auditoría, decisiones, asignaciones o
  lecturas anteriores. Kilometraje cerrado sí se corrige mediante nuevas revisiones.
- Sin administración adicional de catálogos, carga de evidencias, facturación, viáticos,
  taller, inventario, compras, frontend ni avance de etapa.
