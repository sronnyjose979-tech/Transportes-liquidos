# Etapa 5.3 — Operaciones de viajes

Base: main 3453808. D02 aclarada y aprobada por el usuario para implementar la
finalización oficial. Se conserva 5.2A/5.2B, sin avanzar a 5.4.

## Funcionalidades implementadas

- Inicio manual por secretaria/administrador: viaje pendiente, asignaciones vigentes
  y elegibles, actor/fecha del servidor, versión y TransportChange exacto. No inicia por
  fecha programada, carga, descarga ni reporte. Recursos se conservan.
- Una cabecera de carga y una de descarga por viaje, con detalles por compartimento
  lógico/físico. Todo producto se deriva del Trip histórico; no hay elección de producto
  en cada detalle ni nueva copia de su identidad en cabeceras.
- Litros reales NUMERIC(15,2), no negativos y hasta dos decimales. Cero comunicado se
  conserva como dato, sin afirmar que la operación esté completa. Desconocido no se
  sustituye por cero; ausencia de detalles/totales sigue siendo falta de información.
- Carga exige cisterna vigente del viaje; compartimentos pertenecientes a esa cisterna
  y activos, cantidades dentro de capacidad. Snapshot de capacidad/código proviene de
  PostgreSQL al registrar, no de valores libres del cliente. No reconstruye capacidades
  pasadas anteriores al registro: FleetAndDocuments no dispone de ese historial.
- Descarga respeta la capacidad histórica del compartimento y referencia carga y detalles del mismo viaje; no se acepta otro LoadItem ni
  descarga duplicada por detalle. No se copian litros cargados como descargados.
- Reportes en TripEvent por oficina o conductor activo, con cuenta/empleado/rol vigentes
  y asignación actual a ese viaje. No otorgan privilegios de inicio/finalización ni de
  registro de cantidades definitivas. Liberación/reasignación revoca el acceso actual;
  resolución de capturas offline antiguas sigue fuera de alcance.
- Lecturas inicial/final por asignación histórica de cabezal; correcciones crean revisiones
  inmutables, sin UPDATE del original ni ramas concurrentes. Distancia de un mismo tramo
  deriva de lecturas actuales con NUMERIC exacto. Si faltan lecturas o final es inferior
  al inicial, retorna null; no inventa compensación de reinicio ni distancia negativa.
  Precisión del odómetro se conserva mediante NUMERIC sin imponer una escala no aprobada.
- Evidencia JSON, motivo, actor y fechas para registros operativos; reports usan information
  existente. Evidencia es información registrada, no nueva carga/descarga de archivos.

## Persistencia y archivos

Migraciones nuevas, sin modificar publicadas:

1. 2026_10_12_000140_create_trip_operational_records.php: trip_loads, load_items,
   trip_unloads, unload_items y trip_odometer_readings; actores, integridad y reportes.
2. 2026_10_12_000150_protect_recorded_volume_differences.php: aplicación específica de
   autorización de diferencias e integridad diferida de los valores registrados.
3. 2026_10_12_000160_require_official_trip_completion.php: requisitos D02 y cobertura
   de detalles en SQL, sin reinterpretar finalizaciones históricas ya existentes.

Nuevos modelos: TripLoad, LoadItem, TripUnload, UnloadItem, TripOdometerReading;
trait ValidatesTransportVolume. Reutilizan PreservesTransportHistory y UUID; FK a usuarios
bigint. Se añaden relaciones a Trip y discriminator validation_type al modelo existente
TransportAuthorizationApplication, sin duplicar el mecanismo de decisiones.

Application/TripOperations coordina start, finish, load, unload, report, reading,
correctReading y lectura autorizada de distancia. Se extiende
TransportCorePolicy y se registran Gates manage-trip-operations y report-trip-information.
No se añaden endpoints, Requests HTTP ni interfaz Vue; tampoco precios, facturación,
viáticos, taller, inventario, permisos de salida ni comprobaciones de cisterna vacía.

El servicio/modelos rechazan litros con más de dos decimales; NUMERIC(15,2) protege
la representación persistida. Una escritura SQL numérica directa recibe la conversión
de escala propia de PostgreSQL. Los futuros Requests deben conservar la validación
de precisión antes de enviar valores al motor.

## Integridad, historia y concurrencia

- FK restrictOnDelete y unicidad de cabeceras por viaje; unicidad de detalle cargado
  por compartimento, detalle descargado por LoadItem y sucesor por lectura corregida.
- Datos operativos append-only en SQL/Eloquent; rechazo de UPDATE/DELETE/TRUNCATE.
  Correcciones de kilometraje preservan identidad de viaje/cabezal/tipo y revision+1.
  Correcciones de volúmenes reales no se diseñan silenciosamente: requieren procedimiento
  separado de rectificación y revisión de autorizaciones; no hay edición destructiva.
- Snapshots de capacidad y código conservan lo registrado aunque el catálogo cambie.
- TIMESTAMPTZ con precisión de microsegundos en tablas nuevas. Modelos preservan offset
  comunicado y precisión; PostgreSQL conserva instante. Reportes normalizan el instante
  a UTC usando el esquema temporal existente de TripEvent.
- Se comparte lock 873210 y guard de 5.2B, más locks de viaje/compartimento/lectura.
  SERIALIZACIÓN evita capacidad leída antes de esperar y correcciones simultáneas;
  UNIQUE rechaza cabeceras o sucesores duplicados. No hay efectos externos en transacción.
- Inicio comprueba recursos vigentes y elegibles también ante UPDATE SQL; las reglas de
  5.2B mantienen cardinalidad. No se introducen liberaciones por ningún estado o fecha.
- Los valores capturados no equivalen a aceptación comercial, facturabilidad ni certificación
  de completitud. No se agregan estados, tolerancias, mezcla de productos o fórmulas nuevas.

## Finalización oficial: aclaración D02 aprobada

1. Inicio oficial registrado y asignaciones históricas válidas de conductor, cabezal y
   cisterna. El servicio requiere ejecución in_progress; conserva planificación y cierre
   administrativo independientes. Las relaciones históricas están protegidas por 5.2B.
2. Una carga y una descarga del viaje, con fecha, sitio, responsable y litros reales.
   Debe existir al menos un detalle cargado y cada detalle debe tener su descarga
   correspondiente al mismo compartimento. Cero explícito es válido; ausencia no es cero.
3. Cualquier diferencia por detalle, incluso cuando se compensan y el total coincide,
   requiere autorización administrativa específica RN-TR-10. No se impone igualdad,
   tolerancia ni porcentaje nuevo.
4. Secretaria/administrador confirma mediante finish: fecha del servidor, actor, versión,
   TransportChange y aplicación de autorización, si corresponde, en una transacción.
5. Reporte del conductor es opcional y nunca finaliza oficialmente. No se requieren
   evidencias documentales adicionales; evidence conserva las referencias cuando existen.
6. Finalizar no cierra administrativamente ni libera recursos. Continúan las reglas de
   cierre con advertencias y correcciones auditadas de 5.2A.
7. RN-TR-17 sigue pendiente; tampoco se habilita sustitución de cisterna antes de D05.

PostgreSQL valida cobertura al entrar a completed (INSERT/UPDATE) y al insertar detalles
sobre un viaje completado. No inventa registros operativos para finalizaciones anteriores
al despliegue: se conservan sus correcciones y auditoría. Una nueva finalización sí exige
D02. Se añaden pruebas directas de SQL y rollback, además del servicio transaccional.

## Autorización de diferencias RN-TR-10

Se reutilizan solicitudes/decisiones/aplicaciones de 5.2A. trip_volume_content(trip_id)
proporciona el contenido exacto: load_id, unload_id, loaded_liters, delivered_liters e
items ordenados por load_item_id. Cada elemento contiene load_item_id, compartment_id,
loaded_liters y delivered_liters; decimales como cadenas. La autorización cubre valores
reales e identidades, no solamente una resta o un total.

Solicitud vincula versión; decisión approved es exclusiva del administrador. finish recibe
el decision_id y consume su aplicación vinculada al TransportChange del mismo viaje y
versión, con validation_type='volume_difference'. Aprobación sola no permite finalizar.
UNIQUE de decisión impide reutilización; contenido/viaje/versión incorrectos o aprobación
rechazada provocan rollback del cambio, auditoría y aplicación. Los registros de cantidades
no se eliminan ni alteran durante la compensación transaccional.

validation_type='existing' conserva producto y asignaciones. Las correcciones ajenas a
litros conservan la autorización histórica que cubre los mismos valores. Agregar detalles
no permite invalidar silenciosamente la cobertura o el contenido aprobado.

## Decisiones pendientes y límites

- RN-TR-17/D01: no se implementa conducta ante permiso obligatorio ausente. start valida
  asignaciones; no afirma resolver, autorizar o certificar requisitos de permisos de sitio.
- D05: no se habilita sustitución de cisterna ni se deduce vacía de datos registrados.
- D07: aceptación, compensación de reinicio y agregación de distancias entre distintos
  cabezales/tramos, mediciones o tolerancias permanecen sin regla inventada.

## Pruebas y verificaciones

TripOperationsTest, TripCompletionTest y TripOperationsConcurrencyTest cubren: inicio/roles/asignaciones,
carga/descarga única y multicompartimento, rollback por capacidad/identidad, producto
histórico, snapshots, cifras inválidas/NaN y precisión, reportes propios/revocación,
lecturas/revisiones/distancia, diferencias exactas con aprobación aplicada y no reutilizable,
fecha con offset Costa Rica/microsegundos y ausencia de transiciones/liberaciones automáticas.

Conexiones PostgreSQL independientes verifican espera 55P03 y unicidad 23505 para dos
cargas del mismo viaje y dos correcciones del mismo registro; original no se sobrescribe.
También se verifica finalización concurrente: la segunda operación espera y no reemplaza
la confirmación ni su auditoría después del commit. Se copian las restricciones/triggers instalados a un esquema sintético del clúster temporal.
No se simula persistencia ni se usa SQLite.

La nueva FK load_items → compartments hace que PostgreSQL rechace TRUNCATE compartments
con 0A000 antes del trigger de flota. FleetIntegrityTest ajusta solo ese código esperado:
la operación sigue denegada y sus aserciones de conservación de compartimentos permanecen.
No se modifica funcionalidad de flota ni migraciones anteriores.

Comandos ejecutados con herramientas existentes:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
vendor/bin/pint --test
git diff --check
```

Backend: **170 pruebas y 1.574 aserciones correctas**, incluidas las 144 pruebas
anteriores y 26 nuevas de esta etapa. PostgreSQL 18 temporal en
/tmp/transporte-tests-rVjmXH, detenido por el runner. Pint --test y git diff --check
correctos. Las pruebas usan persistencia PostgreSQL real, sin respuestas API simuladas.
No se agregan pruebas de navegador porque esta etapa no introduce API ni frontend.

Las migraciones no se despliegan automáticamente; down requiere recuperación revisada y
no borra historia. No se alteran migraciones publicadas, no se migra transporte_db, no se
modifica .env ni se instalan dependencias. Publicación autorizada una vez superadas las
verificaciones. Sin frontend, endpoints, facturación ni avance a 5.4.
