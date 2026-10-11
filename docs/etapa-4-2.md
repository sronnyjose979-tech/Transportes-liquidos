# Entrega de etapa 4.2 — API y gestión documental de flota

Base: etapa 4.1, commit `6f406e9`. Sin dependencias nuevas, frontend, despacho,
commit ni push. No se ejecutaron migraciones contra `transporte_db`.

## Archivos creados

- `app/Modules/FleetAndDocuments/Application/DocumentAdministration.php`
- `app/Modules/FleetAndDocuments/Console/ReconcileDocumentStorage.php`
- `app/Modules/FleetAndDocuments/Domain/DocumentOperationFailed.php`
- `app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php`
- `app/Modules/FleetAndDocuments/Http/Controllers/DocumentTypeController.php`
- `app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php`
- `app/Modules/FleetAndDocuments/Http/Requests/DocumentTypeRequest.php`
- `app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php`
- `app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php`
- `app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php`
- `app/Modules/FleetAndDocuments/Infrastructure/PrivateDocumentStorage.php`
- `app/Modules/FleetAndDocuments/Models/DocumentConfigurationSnapshot.php`
- `database/migrations/2026_10_11_000050_track_current_fleet_documents.php`
- `tests/Feature/FleetDocumentApiTest.php`
- `tests/Feature/FleetDocumentConcurrencyTest.php`
- `tests/Fixtures/fleet-documents/valid.jpg`
- `tests/Fixtures/fleet-documents/valid.pdf`
- `tests/Fixtures/fleet-documents/valid.png`
- `docs/etapa-4-2.md` (este informe).

## Archivos modificados

- `ARCHITECTURE.md`
- `app/Modules/FleetAndDocuments/Models/Document.php`
- `app/Modules/FleetAndDocuments/Models/DocumentType.php`
- `app/Providers/AppServiceProvider.php`
- `config/filesystems.php`
- `routes/backend.php`
- `tests/Feature/FleetDocumentTest.php`

## Endpoints

Todas las rutas usan autenticación por sesión, cuenta activa y CSRF en escrituras.
Administrador y secretaria consultan documentos y tipos; solamente el administrador
crea/configura tipos. Otros roles no tienen acceso.

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | `/api/document-types` | Tipos paginados. |
| POST | `/api/document-types` | Crear tipo; administrador. |
| PATCH | `/api/document-types/{id}` | Configurar tipo; administrador. |
| GET | `/api/documents` | Documentos actuales y filtros de vigencia, activo y tipo. |
| GET | `/api/documents/{id}` | Documento actual o histórico, con configuración congelada. |
| GET | `/api/documents/{id}/download` | Archivo privado autorizado como attachment. |
| GET | `/api/fleet-assets/{assetId}/documents` | Documentos actuales del activo. |
| POST | `/api/fleet-assets/{assetId}/documents` | Registrar nueva revisión. |
| GET | `/api/fleet-assets/{assetId}/documents/history` | Historial paginado, incluidos anteriores. |
| GET | `/api/fleet-assets/{assetId}/documents/missing` | Tipos obligatorios actuales sin documento actual. |
| POST | `/api/fleet-assets/{assetId}/documents/{id}/renew` | Nueva revisión del mismo activo y tipo. |

Registro y renovación usan multipart con `file`, `issued_on` opcional y
`expires_on` obligatorio cuando el tipo lo exige. Registro incluye
`document_type_id`; renovación lo deriva del documento referenciado. Fecha de
registro y autor son internos. No existen endpoints de eliminación/edición de
historial. La API rechaza campos privados y adicionales de escritura.

Filtros de vigencia: `valid`, `expiring_soon`, `expired`, `no_expiration`.
Calendario America/Costa_Rica: vencido antes del día actual; próximo a vencer entre
hoy y 15 días incluidos; vigente después de 15 días. Todo el día de vencimiento es
válido. Las consultas habituales/avisos toman exclusivamente referencias actuales;
el historial permite consultar estados de registros anteriores. No hay bloqueo de
viajes, correo, push ni tareas programadas.

## Integridad y almacenamiento

Las dos tablas aprobadas conservan una configuración inmutable por documento y una
referencia actual única por activo/tipo con FK compuesta. El INSERT de documento
crea copia y cambia referencia mediante trigger dentro de la misma transacción.
Los locks y la PK del UPSERT serializan registros concurrentes; rollback preserva
la referencia anterior. No se elige por fecha ni se modifica el documento anterior.
El cambio de configuración se aplica al commit administrativo y a registros futuros;
no existe un flujo de aprobación adicional.

El disco local `fleet_documents` está fuera de las raíces públicas/servidas, sin
URLs. Nombres UUID y claves internas verificadas evitan rutas suministradas por el
cliente. Se comprueban extensión, MIME detectado, estructura básica PDF/JPG/PNG y
límite exacto de 10.000.000 bytes. Descargas son attachment, no-store y nosniff.
`storage_key` y rutas físicas nunca se incluyen en Resources. Fallos operativos de
carga devuelven 503 genérico.

El archivo y un recibo privado se escriben antes de la transacción PostgreSQL.
Compensación comprueba referencias antes de borrar; preserva cualquier archivo
histórico. Si la base/limpieza falla, conserva el recibo y un aviso con UUID del
intento. La recuperación manual `php artisan fleet-documents:reconcile-storage`
coordina con cargas mediante mutex local y difiere backends con transacciones
pendientes. Se retira el recibo tras el commit real, no tras un savepoint.

## Validación ejecutada

Herramientas existentes: PHP 8.4.24, Laravel 13.35.0, PostgreSQL 18.3.

| Comando | Resultado real |
| --- | --- |
| `bash tests/run-postgres.sh` | 92 pruebas correctas, 1.047 aserciones; PostgreSQL 18 aislado. |
| `vendor/bin/pint --test` | Correcto. |
| `git diff --check` | Correcto. |

El runner final conservó su código de salida usando `set -e`. Artefactos del último
clúster: `/tmp/transporte-tests-BEy3n2`; el servidor se detuvo al terminar.
Las pruebas nuevas cubren permisos, CSRF, archivos reales, límite exacto, formato
inválido, descargas, fechas, configuración histórica, ausencia/vencimiento,
renovación, rollback, fallos parciales, fallos de limpieza, recuperación y acceso
privado. Conexiones PostgreSQL independientes ejercitan los triggers instalados,
la serialización, lectura durante commits pendientes y selección independiente de
las fechas; esquemas sintéticos se limpian después de liberar los locks de prueba.

## Límites y decisiones pendientes

- La nueva migración se detiene ante documentos previos: su configuración pasada y
  selección actual no pueden inferirse de la etapa 4.1. Se necesita una revisión
  explícita de esos datos antes de desplegar en una base con historial. No se
  aplicó ninguna migración a una base de aplicación persistente.
- PostgreSQL y filesystem no forman una transacción distribuida. Fallos del proceso
  requieren ejecutar la recuperación manual; corrupción física y archivos
  históricos ausentes requieren restauración coherente, nunca borrar historial.
- La coordinación de archivos requiere el mismo almacenamiento local compartido
  por los workers. Otro backend o réplicas independientes requieren otro diseño.
- En desarrollo se configuró PHP con `upload_max_filesize=10M` y
  `post_max_size=12M`. Los servidores deben reiniciarse para leerlo. El despliegue
  debe conservar límites suficientes también en su proxy HTTP, sin modificar el
  máximo exacto de aplicación. No se cambió producción.
- Las validaciones de formato no sustituyen un analizador completo de PDF; no se
  añadieron dependencias. No hay cambios ni decisiones implementadas de despacho.

Detalles del diseño y contratos: sección «Etapa 4.2» de `ARCHITECTURE.md`.
