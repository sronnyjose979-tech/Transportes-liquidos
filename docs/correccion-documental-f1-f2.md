# Corrección documental F1 y F2

Alcance: documentación de flota. No cambia la PWA, los permisos ni las reglas
sobre vigencia, configuración histórica o selección del documento actual.

## Errores seguros

El registro estándar de Laravel solo interceptaba `QueryException` directa.
`DocumentOperationFailed` conservaba esa excepción como causa; el registro del
contenedor podía incluir SQL y parámetros. El filtro ahora recorre las causas:
registra `database.operation_failed` con SQLSTATE validado y la correlación de
rendimiento disponible, sin mensajes, bindings, conexiones, trazas ni objetos de
excepción. Los fallos documentales sin SQL generan
`fleet_document.operation_failed`, categoría `storage_or_runtime`.

La respuesta documental sigue siendo genérica, incluso con debug habilitado.
Descarga, streaming y reconciliación envuelven fallos del adaptador. El comando
`fleet-documents:reconcile-storage` informa el fallo, registra el evento seguro
y termina con código 1 sin imprimir causas privadas. No se silencian fallos.
Este control cubre el registro de aplicación; no modifica logs del servidor
PostgreSQL, proxy o servicios externos.

## Contrato idempotente

Los POST de registro y renovación requieren `request_key` UUID generado por el
cliente **antes del primer envío**. La misma operación conserva esa clave,
archivo y parámetros en todos los reintentos; una operación nueva usa otra clave.
No se generan claves implícitas en el servidor.

La migración `2026_10_20_000410_create_document_upload_operations.php` añade un
recibo técnico inmutable, sin datos de archivos ni contraseñas. Su clave primaria
es `(actor_id, request_key)` y cada documento tiene como máximo un recibo. Las FK
y el trigger comprueban autor, activo y destino de renovación. No requiere
reconstruir ni modificar documentos históricos. No tiene expiración automática;
retirar recibos requiere una política explícita de retención/idempotencia.

Documento, configuración histórica, referencia actual y recibo se confirman en
la misma transacción PostgreSQL. Se conserva el advisory lock existente y se
reautoriza dentro de la transacción antes de registrar o resolver una carrera.
La creación de `.pending` también ocurre dentro del bloqueo de archivos, evitando
la carrera entre dos primeras cargas sobre un almacenamiento vacío.
La huella SHA-256 incluye bytes, tamaño, MIME detectado, extensión, activo, tipo,
fechas y documento renovado. El nombre local del archivo no es dato de negocio.

- Repetición idéntica: 201 con la misma identidad documental, sin otra versión.
- Clave reutilizada con contenido/destino diferente: 409, sin escritura adicional.
- `GET /api/fleet-assets/{assetId}/document-uploads/{requestKey}`: 200 con el recurso
  documental confirmado, o 404 si no hay un recibo visible de esa cuenta/activo.
  El cliente envía `X-Upload-Fingerprint` para comprobar además que el recibo
  coincide con los bytes y parámetros retenidos antes de retirar el pendiente;
  una discrepancia devuelve 409 y conserva el archivo.
  Sigue exigiendo sesión activa y el Gate documental. Una cuenta distinta no
  puede consultar el recibo original aunque conserve otros permisos documentales.
- Un recibo ausente **no demuestra cancelación**. Reintentar conserva la clave.
- El documento original puede pasar a histórico tras otra carga: la identidad,
  contenido y configuración original se conservan; `is_current` y vigencia se
  calculan con el estado/fecha de consulta, como en el recurso existente.

El archivo se copia, verifica y sincroniza (`fsync` de archivo/directorios) antes
de abrir la transacción. Un candidato concurrente que pierde la carrera se
reconcilia tras el commit: se elimina solo su archivo no referenciado. Cualquier
fallo de compensación conserva el marcador `.pending` y genera un aviso seguro.
La reconciliación existente sigue preservando archivos históricos y esperando
transacciones aún activas. No se eliminan archivos empresariales como limpieza.

## Recuperación del navegador

IndexedDB `transport-document-upload-recovery`, almacén `pending`, guarda solo
intenciones sin confirmación, separadas por cuenta: UUID, destinos, fechas y File.
Esos datos son necesarios para reproducir exactamente el envío. No guarda
credenciales, permisos, cookies, documentos consultados ni copias confirmadas.
Si falla el almacenamiento local, la carga no se envía. No hay sincronización ni
reintentos automáticos.

La interfaz «Recuperación de cargas» permite consultar el resultado o reintentar
el mismo archivo. Los pendientes sobreviven a recargas/cierre y se muestran solo
al usuario propietario autenticado. Las respuestas inciertas, 409 y rechazos de
reintentos no eliminan pendientes. Solo la confirmación positiva retira el File;
la excepción son rechazos definitivos del primer intento, que dejan editable el
formulario original. Se bloquean cambios de módulo/cierre durante un envío y se
advierte al intentar abandonar el documento mientras está activo. Tras un fallo,
la intención durable permite salir sin perder el archivo.

IndexedDB no protege frente a XSS, inspección del perfil local, eliminación manual,
cuotas o desalojo por el navegador. No borrar el almacenamiento mientras haya
pendientes; usar perfiles privados separados en equipos compartidos. No se
solicita persistencia ilimitada ni se almacena documentación confirmada.
Un reintento que sigue rechazado por cambios de permisos/requisitos conserva la
operación para revisión: no se fuerza una nueva clave ni se descarta el archivo.

## Aplicación posterior y límites

No se ejecutó esta migración en ninguna base existente. Antes de un despliegue
posterior autorizado, coordinar la migración y el frontend/API: los clientes
antiguos que no envíen clave recibirán 422. Ningún historial previo se vuelve
idempotente retroactivamente; no se debe reenviar una carga antigua incierta como
si fuera una operación nueva. No se cambió infraestructura ni se hizo push.

PostgreSQL y filesystem no son una transacción distribuida. La coordinación
requiere el disco privado local compartido por los workers, como antes. Los
marcadores y la reconciliación cubren archivos nuevos sobrantes; corrupción,
pérdida del volumen o archivos históricos ausentes requieren restauración
coherente. `fsync` no prueba las garantías físicas del proveedor ni sustituye
respaldos. No se ha simulado pérdida eléctrica, fallo de volumen o recuperación
en Azure/Oracle.

## Verificación ejecutada

- Regresiones F1: dos casos anidados fallaron antes de corregir el filtro.
- Regresión F2: con el servicio original, repetir la solicitud produjo otro UUID.
- Suite PHP completa: 439 pruebas, 5.981 aserciones correctas.
- Tras cerrar los casos adicionales de streaming y coordinación del directorio:
  `bash tests/run-postgres.sh --filter 'QueryExceptionSafetyTest|FleetDocument|DocumentUpload'`:
  47 pruebas, 1.700 aserciones correctas sobre PostgreSQL 18.6 desechable.
- `npm run test:frontend-api`: 41 pruebas Node correctas.
- `npm run typecheck`, `npm run build`: correctos. La compilación conserva el aviso
  de la dependencia opcional `fontaine`; no se instaló ni cambió ninguna dependencia.
- Chromium: 27 pruebas correctas (`test_fleet_documents` y
  `test_driver_offline_storage`). Incluyen conservación de clave/bytes, respuesta
  perdida, recarga, cuenta distinta, sesión expirada, 409, fallo de IndexedDB y fallo
  al retirar el pendiente después de una confirmación del servidor.
- `bash tests/run-postgres.sh --e2e`: 15 pruebas E2E finales correctas sobre
  Vue, Laravel, PostgreSQL 18.6 temporal y disco privado sintético.
- E2E aislado con dos POST HTTP reales simultáneos de igual clave: correcto.
  Documento, referencia actual y archivo únicos. Esta prueba descubrió la carrera
  de creación de `.pending`; se añadió también una regresión determinista del lock.
- Reautenticación tras un 401 durante la recuperación, sin recargar: correcta.
- `vendor/bin/pint --test --dirty` y `git diff --check`: correctos.
- La primera ejecución de suites amplias simultáneas produjo presión de memoria,
  swap y timeouts; se interrumpió y se repitieron las verificaciones secuencialmente.
  Esas ejecuciones iniciales no se cuentan como aprobadas.

Se verificaron repetición, conflicto por bytes/parámetros/destino, consulta con
huella, concurrencia entre procesos/conexiones, pérdida de respuesta tras commit,
rollback conjunto de documento/configuración/referencia/recibo, almacenamiento
parcial, compensación fallida, reconciliación y preservación de historial.
Los permisos existentes, CSRF y restricciones PostgreSQL permanecen cubiertos.
No se realizaron pruebas ni modificaciones en las bases existentes o producción.

## Archivos modificados

- `app/Modules/FleetAndDocuments/Application/DocumentAdministration.php`
- `app/Modules/FleetAndDocuments/Console/ReconcileDocumentStorage.php`
- `app/Modules/FleetAndDocuments/Domain/DocumentUploadConflict.php`
- `app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php`
- `app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php`
- `app/Modules/FleetAndDocuments/Infrastructure/PrivateDocumentStorage.php`
- `app/Support/SafeOperationalFailure.php`
- `bootstrap/app.php`
- `database/migrations/2026_10_20_000410_create_document_upload_operations.php`
- `docs/correccion-documental-f1-f2.md`
- `package.json`
- `resources/js/modules/FleetAndDocuments/FleetDocumentsApp.vue`
- `resources/js/modules/FleetAndDocuments/api.ts`
- `resources/js/modules/FleetAndDocuments/components/AssetDocuments.vue`
- `resources/js/modules/FleetAndDocuments/components/DocumentUploadForm.vue`
- `resources/js/modules/FleetAndDocuments/components/DocumentUploadRecovery.vue`
- `resources/js/modules/FleetAndDocuments/uploadRecovery.ts`
- `routes/backend.php`
- `tests/E2E/console.php`
- `tests/E2E/test_document_alerts.py`
- `tests/E2E/test_document_module.py`
- `tests/Feature/DocumentUploadConcurrencyTest.php`
- `tests/Feature/DocumentUploadIdempotencyTest.php`
- `tests/Feature/FleetDocumentApiTest.php`
- `tests/Feature/FleetDocumentWebTest.php`
- `tests/Feature/QueryExceptionSafetyTest.php`
- `tests/Frontend/document_uploads.test.mjs`
- `tests/Frontend/test_fleet_documents.py`
- `tests/Support/document-upload-worker.php`
