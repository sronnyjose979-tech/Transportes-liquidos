# Etapa 5.2B — Asignaciones y control de disponibilidad

Base: main 524fad0. Alcance: persistencia, integridad y coordinación transaccional de
asignaciones, liberaciones manuales, conflictos y sustituciones definidas. Sin API,
frontend, facturación, precios, viáticos, cargas/descargas ni avance a 5.3.

## Reglas aplicadas y operación pendiente

RN-TR-04/05/06/12 y RN-SEG-01: administrador/secretaria gestionan recursos; solo
administrador decide excepciones y sustituciones durante ejecución. Se identifica al
conductor mediante el rol driver existente de su cuenta, además de Employee.is_active y
User.is_active; no se agrega catálogo de funciones. Los activos asignables deben tener
el tipo correcto y service_status=available. Una excepción de ocupación no convierte
un activo fuera de servicio/mantenimiento en elegible ni garantiza disponibilidad física.

RN-TR-13 requiere comprobar cisterna saliente vacía. D05 de 5.1 sigue sin procedimiento
ni evidencia aprobados y no existe carga/descarga que permita comprobarlo. Por la
instrucción explícita de continuar las partes independientes, esa operación se excluye:
substitute de cisterna se rechaza en aplicación y PostgreSQL, también antes del inicio.
No se acepta un booleano aportado por el cliente ni se presupone vacía por falta de carga.
Puede registrarse su intención/decisión, pero no aplicarse. Se pidió definición de la
constatación; no se asumió una respuesta. La operación deberá habilitarse en otra tarea
solo después de esa decisión. Esta exclusión no bloquea asignar o liberar cisternas.

RN-TR-17 no se implementa. Documentos ausentes/vencidos siguen como advertencias y no
intervienen en la elegibilidad del recurso. No se cambian los contratos de 4.4 o 5.2A.

## Persistencia nueva

Dos migraciones nuevas, sin editar las publicadas:

- 000120_create_trip_assignments: operaciones, asignaciones de activos y conductores,
  sus liberaciones, conflictos y guard de serialización.
- 000130_protect_trip_assignment_integrity: triggers, cardinalidad, permisos e integración
  con solicitudes/decisiones/aplicaciones de autorización existentes.

| Tabla | Responsabilidad |
| --- | --- |
| transport_assignment_operations | Acción, viaje, actor, fecha real, motivo, versión del viaje, ejecución capturada y contenido exacto. |
| trip_asset_assignments | Cabezal/cisterna, viaje, operación y comienzo efectivo. |
| driver_assignments | Empleado conductor, viaje, operación y comienzo efectivo. |
| trip_asset_releases / driver_releases | Liberación manual única por asignación, operación y fin efectivo. |
| transport_assignment_conflicts | Asignaciones ocupantes detectadas para una operación o intento rechazado; FK explícitas y XOR activo/conductor. |
| transport_assignment_guard | Fila técnica de serialización; no catálogo ni indicador de disponibilidad. |

Asignación y liberación son filas separadas e inmutables. La fila de asignación nunca
se actualiza para marcar fin: current deriva de ausencia de liberación. Actor/motivo de
cada asignación/liberación se obtienen de su operación inmutable, sin duplicar identidad.
Los intervalos efectivos [starts_at, ends_at) se forman por esas dos filas, con fin abierto
hasta liberación. TIMESTAMPTZ(6) preserva precisión del reloj real del servidor. No se
admiten fechas futuras ni anteriores a la operación para fingir liberación o disponibilidad.

Nuevas PK UUID; FK User bigint; FK restrictOnDelete. UNIQUE de operación/asignación
respalda cardinalidad de registros; UNIQUE de liberación impide liberar dos veces;
UNIQUE de conflicto por operación/asignación evita duplicación. Índices por viaje/fecha
y recurso/fecha. No UNIQUE global de recurso entre viajes, pues impediría RN-TR-04.

Un viaje in_progress exige exactamente un conductor, cabezal y cisterna sin liberar.
Pendientes/finalizados/cancelados permiten cero o uno por función; nunca dos por función.
La cardinalidad se verifica al final de transacción y al retornar el servicio. Liberar
individualmente un recurso de un viaje activo sin reemplazarlo dejaría esa condición
incumplida: se rechaza. En planificación o estados terminales, pueden liberarse uno a uno.
No se inventa un estado de pausa que permita un viaje activo incompleto.

La migración 000120 se detiene si ya existen viajes in_progress: 5.2A no registró sus
recursos, y no se inventan asignaciones históricas. Antes de desplegar en esa situación
se requiere revisión de datos autorizada. No se consulta ni migra transporte_db aquí.
000130 down se detiene para exigir recuperación revisada; no elimina historial para
restaurar un contrato de autorizaciones incompatible. No se ejecutó down.

## Modelos y coordinación

Seis modelos nuevos: TransportAssignmentOperation, TripAssetAssignment, DriverAssignment,
TripAssetRelease, DriverRelease y TransportAssignmentConflict. Reutilizan HasUuids y
PreservesTransportHistory. SQL y Eloquent rechazan modificar/eliminar historia; SQL
rechaza TRUNCATE. Se añaden relaciones a Trip y TransportAuthorizationApplication.
No se cambian Employee ni los modelos de flota/documentación.

Application/AssignmentAdministration ofrece assign, substitute, release,
requestAuthorization y decide. Toma actor User, valida Gate actual dentro de la
transacción, bloquea viaje, obtiene asignación/ocupantes y escribe unidad completa.
No acepta rutas, fechas de liberación libres ni estado de disponibilidad manual.
Gates manage-trip-assignments (administrador/secretaria) y
 authorize-trip-assignment-exception (solo administrador) reutilizan TransportCorePolicy.
No extienden permisos de personal, flota, catálogos o documentos.

Un intento de asignación con conflicto y sin decisión aplicable registra conflict_detected
con sus ocupantes y retorna un error fuera de la transacción confirmada del servicio.
No asigna ni consume aprobaciones. Si un llamador envuelve el servicio en una transacción
mayor y la revierte, también revierte ese registro: no se promete auditoría durable fuera
del commit real de esa unidad. Errores de integridad revierten la operación completa.

## Autorizaciones integradas

Se amplían solo los tipos de intención: assignment_conflict, driver_substitution,
tractor_substitution y tanker_substitution. Decisiones permanecen en las tablas de 5.2A.
Aplicación referencia change_id u operation_id, exactamente uno. La ruta de validación
de product_change se mantiene; las aplicaciones de asignación usan una validación propia.
UNIQUE(decision_id) conserva el consumo único incluso bajo concurrencia. Una operación
puede necesitar dos aprobaciones: sustitución y excepción de conflicto.

El contenido autorizado incluye acción, función, recurso entrante, asignación saliente
y lista ordenada exacta de ocupantes. Se comprueban destino, versión del viaje y contenido
JSON completo. Otra ocupación confirmada, un recurso distinto o una asignación saliente
nueva invalidan esa aprobación para la operación actual. No se usa autorización global.

Durante in_progress, conductor/cabezal requieren autorización de sustitución; conflicto
adicional requiere otra autorización específica. La liberación anterior y nueva asignación
son atómicas, conservan sus filas y la identidad del viaje. No se puede eludir RN-TR-12
mediante dos operaciones SQL que disfracen sustitución como liberar y asignar nuevamente.
La ejecución capturada al registrar operación impide evadir esa autorización cambiando
estado antes de la comprobación diferida. Cisterna continúa excluida por D05.

## Concurrencia, seguridad y compatibilidad

Todas las escrituras usan advisory lock 873210, compatible con AccessAndPersonnel,
y actualizan la fila guard antes de operar. READ COMMITTED vuelve a consultar ocupantes
confirmados tras esperar. La escritura de guard fuerza 40001 ante snapshot antiguo en
REPEATABLE READ; no confirma asignaciones basadas en ausencia obsoleta de conflictos.
SQL protege además elegibilidad, tipo de recurso, identidad, operaciones completas,
conflictos registrados y autorizaciones exigibles. Locks de usuarios/pivotes revalidan
revocaciones, incluidas decisiones específicas de asignación.

El mecanismo serializa un volumen administrativo pequeño; no se introduce una extensión
PostgreSQL ni un gestor genérico de reservas. Reservas programadas futuras, reintentos e
idempotencia de HTTP/PWA se diseñarán después. No se reintenta automáticamente una escritura.

Finalizar, cancelar, cerrar o alcanzar una fecha programada no crea liberaciones.
Desactivar una cuenta o cambiar el servicio de un activo tampoco borra asignaciones
históricas; la elegibilidad se valida al asignar. No se inventa recuperación automática.
Como en 5.2A, SQL valida actores representados, no autentica conexiones arbitrarias.
La futura API deberá derivar actor de sesión. SQL privilegiado puede alterar DDL/triggers.

## Pruebas reales

TripAssignmentTest: asignaciones válidas/rechazadas, empleado/cuenta/rol, tipo/servicio,
conflictos de cada recurso, excepción exclusiva, cambio del conjunto autorizado, consumo
único, sustitución autorizada y conflictos adicionales, liberación individual y cardinalidad,
ausencia de liberación automática, historia, permisos y revocación. Pruebas SQL directas
rechazan operaciones incompletas, viaje activo sin recursos y sustituciones sin aprobación.
Prueba de cisterna confirma que no se ejecuta un procedimiento pendiente.

TripAssignmentConcurrencyTest: conexiones PostgreSQL independientes, conflictos de los
tres recursos, espera 55P03, rechazo tras commit rival, asignación después de liberación
real y snapshot REPEATABLE READ rechazado con 40001. Se copian restricciones/triggers
instalados a un esquema sintético dentro del clúster aislado, sin algoritmo simulado.

TransportFixtures prepara los tres recursos cuando una prueba previa necesita un viaje
in_progress; no elimina ni debilita sus aserciones. Así se verifica compatibilidad con
la nueva cardinalidad y con cierre de cancelados, auditoría y producto histórico previos.

Comandos ejecutados con herramientas existentes:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
vendor/bin/pint --test
git diff --check
```

Backend final: 144 pruebas y 1.436 aserciones correctas, incluidas las 127 anteriores y
17 nuevas con 141 aserciones. PostgreSQL 18 temporal en /tmp/transporte-tests-I5TIAl,
detenido por runner. Pint y diff verificados al cierre de la revisión. No se ejecutaron
migraciones sobre transporte_db ni se instalaron dependencias. No se afirma cobertura
HTTP o Vue: no se añadieron endpoints/interfaz ni se avanzó a 5.3.
