# Etapa 5.2A — Núcleo de persistencia de Transporte y Despacho

Base: e1aaae8 y documentación 5.1 pendiente de commit. Implementación autorizada
exclusivamente de persistencia, relaciones, Gates y pruebas. Sin endpoints, servicios
de operación ni frontend. No se implementa 5.2B, precios, facturación, viáticos,
asignaciones, liberaciones, sustituciones, conflictos, cargas ni descargas.

## Decisiones aplicadas

Fuente: REGLAS_TRANSPORTE_DESPACHO.md, RN-TR-01..39 y RN-SEG-01. No se modifica
su texto. Se conservan tres dimensiones independientes con identificadores técnicos
en inglés, según convenciones del proyecto:

| Dimensión | Identificador | Etiqueta aprobada |
| --- | --- | --- |
| Planificación | scheduled / rescheduled | programado / reprogramado |
| Ejecución | pending / in_progress / completed / cancelled | pendiente / en_curso / finalizado / cancelado |
| Administración | open / closed | abierto / cerrado |

Defaults scheduled/pending/open, versión inicial 1. Programación puede no tener
fechas todavía; si ambas existen, fin no es anterior a inicio. Fechas previstas no
cambian estados. Inicio, finalización, cancelación y cierre tienen actor/fecha propios.
Un completion_report solo es información; no inicia ni finaliza oficialmente.

Los volúmenes solicitados son NUMERIC(15,2) positivos; Eloquent rechaza entrada con
más de dos decimales antes de PostgreSQL. SQL directo hacia NUMERIC(15,2) tiene el
redondeo propio de PostgreSQL: la aplicación futura deberá validar igualmente sus
entradas. No se introducen volúmenes reales ni reglas de cálculo de pendientes.

Producto de cada viaje: FK histórica y copia de nombre al crear, comprobadas contra
la solicitud y el catálogo. Cambiar nombre del catálogo o producto de solicitud no
reinterpreta el viaje. En 5.2A el producto/nombre histórico del viaje no se modifica;
la aplicación de cambios a viajes pendientes permanece fuera hasta definir su revisión.

## Archivos y orden de migración

Cinco migraciones nuevas, sin cambios a las anteriores:

1. `2026_10_11_000060_create_transport_planning_core.php`: routes, transport_requests, trips.
2. `2026_10_11_000070_create_transport_authorization_records.php`: solicitudes y decisiones.
3. `2026_10_11_000080_create_transport_change_history.php`: cambios y revisiones de producto.
4. `2026_10_11_000090_create_trip_information_records.php`: eventos, advertencias y resoluciones.
5. `2026_10_11_000100_protect_transport_core_integrity.php`: aplicaciones de autorización y protección SQL.

Todas en database/migrations/. Dependencias: catálogos, users/roles/user_roles existentes;
PostgreSQL 18 sin extensiones o paquetes nuevos. No asignar activos ni empleados.

Once modelos en app/Modules/TransportAndDispatch/Models/:
Route, TransportRequest, Trip, TransportAuthorizationRequest,
TransportAuthorizationDecision, TransportAuthorizationApplication, TransportChange,
TripProductReview, TripEvent, TripWarning y TripWarningResolution.

Apoyo: Models/Concerns/PreservesTransportHistory.php, Policies/TransportCorePolicy.php,
registro de seis Gates en AppServiceProvider, tests/Support/TransportFixtures.php y
cinco grupos de pruebas en tests/Feature/Transport*.php. No modificar rutas existentes.

## Relaciones, restricciones e índices

- UUID en nuevas PK; bigint para FK a users; FK restrictOnDelete, sin cascadas.
- Cliente/producto/ruta → solicitudes 1:N; solicitud → viajes 1:N; lugares → rutas
  como origen/destino separados. Sin unicidad comercial de rutas por pareja.
- Route referenciada conserva identidad/configuración; modificarla retrospectivamente
  se rechaza. No se versionan direcciones del catálogo OperationSite en esta entrega.
- Estados con CHECK; fecha y actor van juntos. completed necesita inicio y finalización;
  closed necesita finalización y cronología coherente. cancelled necesita motivo y actor.
  Son restricciones de representación, no un servicio que autoriza ejecutar transiciones.
- Solicitud de autorización y cambio tienen exactamente un destino solicitud/viaje.
- Intención, decisión, aplicación, cambio, revisión, evento, advertencia y resolución
  son append-only: SQL rechaza UPDATE/DELETE/TRUNCATE; modelos históricos también
  rechazan actualizar/eliminar. Raíces solicitud/viaje no se eliminan ni truncan.
- Cada intención tiene una decisión; cada decisión tiene como máximo una aplicación;
  cada cambio puede tener como máximo una aplicación. UNIQUE garantiza esas reglas.
- PK, índices de FK/destino, cliente/fecha de solicitud, solicitud/viajes, estado/programación,
  trip/fecha de eventos/avisos y warning/fecha de resoluciones respaldan consultas.
- Un único cambio por versión de cada raíz, mediante índices únicos parciales.
- Resoluciones son actuaciones históricas separadas; no cambian ni borran la advertencia.
  No se calcula un estado final automático del pendiente sin procedimiento aprobado.

## Auditoría exacta y correcciones

Toda actualización de solicitud/viaje incrementa version exactamente en uno y conserva
id, registrador y fecha de registro. Trigger diferido exige en la misma transacción un
TransportChange con fila anterior/nueva completas, versiones, motivo y actor autorizado.
Auditoría falsa, ausente o incompleta aborta toda la transacción. Validar antes de responder
con SET CONSTRAINTS ALL IMMEDIATE, además de la comprobación al commit real.

Se permiten correcciones de datos representados en un viaje closed sin reapertura ni
borrado de historia. Esto no autoriza reprogramar un viaje en ejecución ni corregir
volúmenes/precios mediante un flujo inexistente: futuros casos de uso deberán validar
RN-TR-24/29 y autorizaciones por campo. No hay propagación automática a otros módulos.

Los avisos identifican una versión real de cierre. Una corrección posterior no modifica
su evidencia. El núcleo permite cerrar con avisos pendientes y conservar resoluciones.
La captura completa de todos los pendientes al cerrar corresponde al servicio futuro.

## Solicitud, decisión y aplicación de autorización

Tipos de intención acotados a RN: product_change, volume_difference, price_change,
expense_without_receipt, allowance_disbursement y cancellation_charge. Se pueden guardar
solicitudes/decisiones como registros, sin implementar sus operaciones de negocio.
Tipos específicos de asignación/conflicto/sustitución no se incorporan: son 5.2B.

Actor que solicita: administrador/secretaria activos. Actor que decide aprobación o rechazo:
solo administrador activo, conservando evidencia histórica. Intención guarda versión destino
exacta al registrar. Modificar contenido requiere otra intención; no se sobrescriben decisiones.

Única aplicación disponible en 5.2A: product_change de solicitud con cambio auditado. Debe
referenciar decisión approved, el mismo destino y versión anterior, y contenido before/after
que coincida con los productos/valores reales del cambio. Cada viaje afectado tiene revisión
vinculada a ese cambio (retained o requires_review), sin modificar producto del viaje.
Registrar intención/aprobación no ejecuta por sí solo ninguna modificación.

Aplicaciones de precio, gastos, diferencias, viáticos y cancelación se rechazan porque no
existen sus operaciones todavía. Reutilización de decisión o cambio se rechaza por UNIQUE;
no se implementa un motor genérico ni una excepción basada en un booleano del cliente.
Vigencia/revocación de aprobaciones no aplicadas sigue pendiente; no hay revocación automática.

## Permisos y concurrencia

Gates nuevos: manage-transport-core, request-transport-authorization,
correct-transport-record, record-transport-information y resolve-transport-warning para
administrator/secretary; decide-transport-authorization solo administrator. Consultan
cuenta y roles actuales, incluso si el objeto User conserva datos previos a revocación.
No existe un before global que eluda controles ni se amplían permisos de otros módulos.

SQL comprueba elegibilidad del actor registrado y comparte advisory lock 873210 con
AccessAndPersonnel. Actualizaciones de raíces adquieren ese lock a nivel de sentencia,
antes de locks de filas; intención/aplicación bloquea su destino/intención cuando corresponde.
Una segunda aplicación concurrente espera y luego no puede consumir dos veces la decisión.
No se implementan los conflictos de recursos de 5.2B.

SQL valida el actor representado, no autentica la identidad de quien abre una conexión:
la futura capa HTTP/aplicación debe tomar el actor de sesión, autorizar, ordenar locks y
no aceptar IDs de actor libres. SQL privilegiado puede alterar DDL/deshabilitar triggers.
El bloqueo global conserva la coordinación existente, con coste de serializar escrituras;
optimización futura requiere mantener garantías de revocación.

El acceso del conductor a sus reportes requiere asignaciones de 5.2B. Por ahora driver y
mechanic no pueden escribir registros privados mediante los Gates ni el campo de actor SQL.
La oficina sí puede registrar información comunicada por el conductor. No se concede acceso
indiscriminado como sustituto de una relación todavía inexistente.

## Pruebas y resultados

Cinco grupos: TransportCorePersistenceTest, TransportHistoryIntegrityTest,
TransportAuthorizationIntegrityTest, TransportInformationRecordsTest y TransportPermissionTest.
Cubren relaciones/catálogos, UUID/bigint, NUMERIC, estados y fechas, historia de producto,
auditoría exacta y rollback, corrección cerrada, inmutabilidad SQL/Eloquent, intención/decisión/
aplicación, payload/destino/versiones incompatibles, revisión de afectados, permisos/revocación,
reportes informativos, pendientes conservados y una sola aplicación bajo concurrencia real.

La prueba concurrente utiliza conexiones PostgreSQL independientes y un esquema sintético
con copias de las restricciones y triggers instalados, aislado dentro del clúster del runner.
Se verifica espera 55P03, resultado único 23505 y una aplicación confirmada. Solo se elimina
ese esquema de pruebas, nunca datos del entorno de desarrollo.

Comandos reproducibles con herramientas existentes:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
vendor/bin/pint --test
git diff --check
```

Resultado de backend: 123 pruebas correctas, 1.261 aserciones; incluye 27 pruebas nuevas
con 148 aserciones y las 96 pruebas anteriores. PostgreSQL 18 temporal, artefactos en
/tmp/transporte-tests-Fbhx5T; detenido por runner. Pint --test y git diff --check correctos; se revisaron también archivos nuevos. No se ejecuta migración sobre transporte_db.

## Límites y despliegue

No endpoints ni servicios transaccionales de operación, frontend o PWA; estas pruebas
no equivalen a autorización HTTP de un flujo de despacho. Tampoco confirman cargas,
descargas, permiso de salida, diferencias reales ni asignaciones que aún no existen.
RN-TR-17 permanece pendiente: no se implementa bloqueo, aviso ni excepción por ausencia.
Persistir estados no convierte este núcleo en módulo de operación desplegable por sí solo.

No se migran datos previos ni se inventa información histórica. Las nuevas migraciones
solo se ejecutaron en la base temporal de pruebas. down destruye las tablas nuevas y
no es un procedimiento de recuperación; no se ejecutó. Despliegue exige destino autorizado,
revisión y respaldo/recuperación. No commit ni push. No avanzar a 5.2B sin autorización.
