# Rutas: Flota y Documentación

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/document-alerts

- **Nombre Laravel:** sin nombre.
- **Entrada:** [DocumentAlertController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentAlertController.php)().
- **Form Request identificado en la firma:** [DocumentAlertQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAlerts](../../../app/Modules/FleetAndDocuments/Application/DocumentAlerts.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentAlertResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentAlertResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/document-alerts/summary

- **Nombre Laravel:** sin nombre.
- **Entrada:** [DocumentAlertController::summary](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentAlertController.php)().
- **Form Request identificado en la firma:** [DocumentAlertQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAlerts](../../../app/Modules/FleetAndDocuments/Application/DocumentAlerts.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentAlertResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentAlertResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/document-alerts/{id}/review

- **Nombre Laravel:** sin nombre.
- **Entrada:** [DocumentAlertController::review](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentAlertController.php)().
- **Form Request identificado en la firma:** [DocumentAlertReviewRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentAlertReviewRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAlerts](../../../app/Modules/FleetAndDocuments/Application/DocumentAlerts.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentAlertResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentAlertResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/document-types

- **Nombre Laravel:** `fleet.documents.`.
- **Entrada:** [DocumentTypeController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentTypeController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/document-types

- **Nombre Laravel:** `fleet.documents.`.
- **Entrada:** [DocumentTypeController::store](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentTypeController.php)().
- **Form Request identificado en la firma:** [DocumentTypeRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentTypeRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`, `can:manage-document-types`.

- **Representaciones utilizadas por esta clase:** [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/document-types/{id}

- **Nombre Laravel:** `fleet.documents.`.
- **Entrada:** [DocumentTypeController::update](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentTypeController.php)().
- **Form Request identificado en la firma:** [DocumentTypeRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentTypeRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`, `can:manage-document-types`.

- **Representaciones utilizadas por esta clase:** [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/documents

- **Nombre Laravel:** `fleet.documents.index`.
- **Entrada:** [DocumentController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/documents/{id}

- **Nombre Laravel:** `fleet.documents.show`.
- **Entrada:** [DocumentController::show](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/documents/{id}/download

- **Nombre Laravel:** `fleet.documents.download`.
- **Entrada:** [DocumentController::download](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/fleet-assets

- **Nombre Laravel:** `fleet.documents.assets`.
- **Entrada:** [FleetAssetLookupController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/FleetAssetLookupController.php)().
- **Form Request identificado en la firma:** [FleetAssetLookupRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/FleetAssetLookupRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [FleetAssetLookupResource](../../../app/Modules/FleetAndDocuments/Http/Resources/FleetAssetLookupResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/fleet-assets/{assetId}/documents

- **Nombre Laravel:** `fleet.documents.asset`.
- **Entrada:** [DocumentController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/fleet-assets/{assetId}/documents

- **Nombre Laravel:** `fleet.documents.store`.
- **Entrada:** [DocumentController::store](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentUploadRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/fleet-assets/{assetId}/documents/history

- **Nombre Laravel:** `fleet.documents.history`.
- **Entrada:** [DocumentController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/fleet-assets/{assetId}/documents/missing

- **Nombre Laravel:** `fleet.documents.missing`.
- **Entrada:** [DocumentController::missing](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentQueryRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/fleet-assets/{assetId}/documents/{id}/renew

- **Nombre Laravel:** `fleet.documents.renew`.
- **Entrada:** [DocumentController::renew](../../../app/Modules/FleetAndDocuments/Http/Controllers/DocumentController.php)().
- **Form Request identificado en la firma:** [DocumentUploadRequest](../../../app/Modules/FleetAndDocuments/Http/Requests/DocumentUploadRequest.php).
- **Coordinadores referidos por el controlador:** [DocumentAdministration](../../../app/Modules/FleetAndDocuments/Application/DocumentAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet-documents`.

- **Representaciones utilizadas por esta clase:** [DocumentResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php), [DocumentTypeResource](../../../app/Modules/FleetAndDocuments/Http/Resources/DocumentTypeResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/tankers

- **Nombre Laravel:** `fleet.tankers.index`.
- **Entrada:** [TankerController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/TankerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## POST /api/tankers

- **Nombre Laravel:** `fleet.tankers.store`.
- **Entrada:** [TankerController::store](../../../app/Modules/FleetAndDocuments/Http/Controllers/TankerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## GET|HEAD /api/tankers/{id}

- **Nombre Laravel:** `fleet.tankers.show`.
- **Entrada:** [TankerController::show](../../../app/Modules/FleetAndDocuments/Http/Controllers/TankerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## PATCH /api/tankers/{id}

- **Nombre Laravel:** `fleet.tankers.update`.
- **Entrada:** [TankerController::update](../../../app/Modules/FleetAndDocuments/Http/Controllers/TankerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## GET|HEAD /api/tractor-units

- **Nombre Laravel:** `fleet.tractor-units.index`.
- **Entrada:** [TractorUnitController::index](../../../app/Modules/FleetAndDocuments/Http/Controllers/TractorUnitController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## POST /api/tractor-units

- **Nombre Laravel:** `fleet.tractor-units.store`.
- **Entrada:** [TractorUnitController::store](../../../app/Modules/FleetAndDocuments/Http/Controllers/TractorUnitController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## GET|HEAD /api/tractor-units/{id}

- **Nombre Laravel:** `fleet.tractor-units.show`.
- **Entrada:** [TractorUnitController::show](../../../app/Modules/FleetAndDocuments/Http/Controllers/TractorUnitController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## PATCH /api/tractor-units/{id}

- **Nombre Laravel:** `fleet.tractor-units.update`.
- **Entrada:** [TractorUnitController::update](../../../app/Modules/FleetAndDocuments/Http/Controllers/TractorUnitController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-fleet`.


## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
