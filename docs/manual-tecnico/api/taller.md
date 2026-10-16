# Rutas: Taller Mecánico

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/workshop/activities

- **Nombre Laravel:** `workshop.query.activities.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/activities/{id}

- **Nombre Laravel:** `workshop.query.activities.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/admission-links

- **Nombre Laravel:** `workshop.query.admission-links.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/admission-links/{id}

- **Nombre Laravel:** `workshop.query.admission-links.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/admission-links/{link}/invalidate

- **Nombre Laravel:** `workshop.core.admission-link-invalidate`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/admissions

- **Nombre Laravel:** `workshop.query.admissions.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/admissions/{admission}/exit-requests

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::requestExit](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/admissions/{id}

- **Nombre Laravel:** `workshop.query.admissions.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/assets/{asset}/admissions

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::asset](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/assets/{asset}/availability

- **Nombre Laravel:** `workshop.query.availability`.
- **Entrada:** [WorkshopQueryController::asset](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/assets/{asset}/historical-observations

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::asset](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/assets/{asset}/impediments

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::asset](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/attachment-reservations/{reservation}

- **Nombre Laravel:** `workshop.attachments.receipt`.
- **Entrada:** [WorkshopAttachmentController::receipt](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/attachment-reservations/{reservation}/confirm

- **Nombre Laravel:** `workshop.attachments.confirm`.
- **Entrada:** [WorkshopAttachmentController::confirm](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/attachment-reservations/{reservation}/release

- **Nombre Laravel:** `workshop.attachments.release`.
- **Entrada:** [WorkshopAttachmentController::release](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/attachments/{attachment}/download

- **Nombre Laravel:** `workshop.attachments.download`.
- **Entrada:** [WorkshopAttachmentController::download](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-applications

- **Nombre Laravel:** `workshop.query.authorization-applications.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-applications/{id}

- **Nombre Laravel:** `workshop.query.authorization-applications.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-decisions

- **Nombre Laravel:** `workshop.query.authorization-decisions.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-decisions/{id}

- **Nombre Laravel:** `workshop.query.authorization-decisions.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-requests

- **Nombre Laravel:** `workshop.query.authorization-requests.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/authorization-requests/{authorization}/decisions

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::decide](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/authorization-requests/{id}

- **Nombre Laravel:** `workshop.query.authorization-requests.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/costs

- **Nombre Laravel:** `workshop.query.costs.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/costs/{id}

- **Nombre Laravel:** `workshop.query.costs.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/decisions/{decision}/apply-order

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::apply](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/decisions/{decision}/apply-scope

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::apply](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/decisions/{decision}/exit

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::apply](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/decisions/{decision}/facts/{kind}/apply

- **Nombre Laravel:** `workshop.material.revise`.
- **Entrada:** [WorkshopMaterialController::revise](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/deliveries

- **Nombre Laravel:** `workshop.query.deliveries.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/deliveries/{id}

- **Nombre Laravel:** `workshop.query.deliveries.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/diagnoses

- **Nombre Laravel:** `workshop.query.diagnoses.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/diagnoses/{diagnosis}/revisions

- **Nombre Laravel:** `workshop.core.diagnosis-revise`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/diagnoses/{id}

- **Nombre Laravel:** `workshop.query.diagnoses.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/exits

- **Nombre Laravel:** `workshop.query.exits.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/exits/{id}

- **Nombre Laravel:** `workshop.query.exits.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/external-repairs

- **Nombre Laravel:** `workshop.query.external-repairs.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/external-repairs/{id}

- **Nombre Laravel:** `workshop.query.external-repairs.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/facts/{kind}/{fact}/rectifications

- **Nombre Laravel:** `workshop.material.rectify`.
- **Entrada:** [WorkshopMaterialController::rectify](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/impediment-resolutions

- **Nombre Laravel:** `workshop.query.impediment-resolutions.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/impediment-resolutions/{id}

- **Nombre Laravel:** `workshop.query.impediment-resolutions.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/impediments

- **Nombre Laravel:** `workshop.query.impediments.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/impediments/{id}

- **Nombre Laravel:** `workshop.query.impediments.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/impediments/{impediment}/resolve

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::resolve](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/lookups/mechanics

- **Nombre Laravel:** `workshop.lookups.mechanics`.
- **Entrada:** [WorkshopQueryController::mechanics](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopMechanicLookupRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMechanicLookupRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/material-needs

- **Nombre Laravel:** `workshop.query.material-needs.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/material-needs/{id}

- **Nombre Laravel:** `workshop.query.material-needs.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/mechanic-assignments

- **Nombre Laravel:** `workshop.query.mechanic-assignments.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/mechanic-assignments/{assignment}/release

- **Nombre Laravel:** `workshop.core.mechanic-release`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/mechanic-assignments/{id}

- **Nombre Laravel:** `workshop.query.mechanic-assignments.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/mechanic-releases

- **Nombre Laravel:** `workshop.query.mechanic-releases.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/mechanic-releases/{id}

- **Nombre Laravel:** `workshop.query.mechanic-releases.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders

- **Nombre Laravel:** `workshop.core.order-create`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders

- **Nombre Laravel:** `workshop.query.orders.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{id}

- **Nombre Laravel:** `workshop.query.orders.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/workshop/orders/{order}

- **Nombre Laravel:** `workshop.core.order-update`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/activities

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/activities

- **Nombre Laravel:** `workshop.query.order.activities`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/admission-links

- **Nombre Laravel:** `workshop.core.admission-link`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/admission-links

- **Nombre Laravel:** `workshop.query.order.admission-links`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/attachment-reservations

- **Nombre Laravel:** `workshop.attachments.reserve`.
- **Entrada:** [WorkshopAttachmentController::reserve](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/attachments

- **Nombre Laravel:** `workshop.attachments.index`.
- **Entrada:** [WorkshopAttachmentController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopAttachmentController.php)().
- **Form Request identificado en la firma:** [WorkshopAttachmentRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopAttachmentRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAttachments](../../../app/Modules/Workshop/Application/WorkshopAttachments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php), [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/authorization-requests

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/authorization-requests

- **Nombre Laravel:** `workshop.query.order.authorization-requests`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/close

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/cost-totals

- **Nombre Laravel:** `workshop.material.totals`.
- **Entrada:** [WorkshopMaterialController::totals](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/costs

- **Nombre Laravel:** `workshop.material.cost`.
- **Entrada:** [WorkshopMaterialController::cost](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/costs

- **Nombre Laravel:** `workshop.query.order.costs`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/deliveries

- **Nombre Laravel:** `workshop.material.delivery`.
- **Entrada:** [WorkshopMaterialController::delivery](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/deliveries

- **Nombre Laravel:** `workshop.query.order.deliveries`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/diagnoses

- **Nombre Laravel:** `workshop.core.diagnosis-record`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/diagnoses

- **Nombre Laravel:** `workshop.query.order.diagnoses`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/external-repairs

- **Nombre Laravel:** `workshop.material.external`.
- **Entrada:** [WorkshopMaterialController::external](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/external-repairs

- **Nombre Laravel:** `workshop.query.order.external-repairs`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/finish

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/material-needs

- **Nombre Laravel:** `workshop.material.need`.
- **Entrada:** [WorkshopMaterialController::need](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/material-needs

- **Nombre Laravel:** `workshop.query.order.material-needs`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/mechanic-assignments

- **Nombre Laravel:** `workshop.core.mechanic-assign`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/mechanic-assignments

- **Nombre Laravel:** `workshop.query.order.mechanic-assignments`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/report-links

- **Nombre Laravel:** `workshop.core.report-link`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/report-links

- **Nombre Laravel:** `workshop.query.order.report-links`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/scopes

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/scopes

- **Nombre Laravel:** `workshop.query.order.scopes`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/time-events

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::order](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopProcessRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopProcessRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/time-events

- **Nombre Laravel:** `workshop.query.order.time-events`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/times

- **Nombre Laravel:** sin nombre.
- **Entrada:** [WorkshopProcessController::times](../../../app/Modules/Workshop/Http/Controllers/WorkshopProcessController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopAuthorizations](../../../app/Modules/Workshop/Application/WorkshopAuthorizations.php), [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopExecution](../../../app/Modules/Workshop/Application/WorkshopExecution.php), [WorkshopOrderRequests](../../../app/Modules/Workshop/Application/WorkshopOrderRequests.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/orders/{order}/usages

- **Nombre Laravel:** `workshop.material.usage`.
- **Entrada:** [WorkshopMaterialController::usage](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/orders/{order}/usages

- **Nombre Laravel:** `workshop.query.order.usages`.
- **Entrada:** [WorkshopQueryController::forOrder](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/rectifications/apply-batch

- **Nombre Laravel:** `workshop.material.batch`.
- **Entrada:** [WorkshopMaterialController::batch](../../../app/Modules/Workshop/Http/Controllers/WorkshopMaterialController.php)().
- **Form Request identificado en la firma:** [WorkshopMaterialRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopMaterialRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php), [WorkshopCosts](../../../app/Modules/Workshop/Application/WorkshopCosts.php), [WorkshopMaterialCorrections](../../../app/Modules/Workshop/Application/WorkshopMaterialCorrections.php), [WorkshopMaterials](../../../app/Modules/Workshop/Application/WorkshopMaterials.php), [WorkshopRectifications](../../../app/Modules/Workshop/Application/WorkshopRectifications.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/report-links

- **Nombre Laravel:** `workshop.query.report-links.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/report-links/{id}

- **Nombre Laravel:** `workshop.query.report-links.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/report-links/{link}/invalidate

- **Nombre Laravel:** `workshop.core.report-link-invalidate`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/reports

- **Nombre Laravel:** `workshop.core.report-create`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/reports

- **Nombre Laravel:** `workshop.query.reports.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/reports/{id}

- **Nombre Laravel:** `workshop.query.reports.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/workshop/reports/{report}/revisions

- **Nombre Laravel:** `workshop.core.report-revise`.
- **Entrada:** [WorkshopCoreController::__invoke](../../../app/Modules/Workshop/Http/Controllers/WorkshopCoreController.php)().
- **Form Request identificado en la firma:** [WorkshopCoreRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopCoreRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAvailability](../../../app/Modules/Workshop/Application/WorkshopAvailability.php), [WorkshopCore](../../../app/Modules/Workshop/Application/WorkshopCore.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopResultResource](../../../app/Modules/Workshop/Http/Resources/WorkshopResultResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scope-parts

- **Nombre Laravel:** `workshop.query.scope-parts.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scope-parts/{id}

- **Nombre Laravel:** `workshop.query.scope-parts.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scope-tasks

- **Nombre Laravel:** `workshop.query.scope-tasks.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scope-tasks/{id}

- **Nombre Laravel:** `workshop.query.scope-tasks.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scopes

- **Nombre Laravel:** `workshop.query.scopes.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/scopes/{id}

- **Nombre Laravel:** `workshop.query.scopes.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/time-events

- **Nombre Laravel:** `workshop.query.time-events.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/time-events/{id}

- **Nombre Laravel:** `workshop.query.time-events.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/usages

- **Nombre Laravel:** `workshop.query.usages.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/usages/{id}

- **Nombre Laravel:** `workshop.query.usages.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/warnings

- **Nombre Laravel:** `workshop.query.warnings.index`.
- **Entrada:** [WorkshopQueryController::index](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/workshop/warnings/{id}

- **Nombre Laravel:** `workshop.query.warnings.show`.
- **Entrada:** [WorkshopQueryController::show](../../../app/Modules/Workshop/Http/Controllers/WorkshopQueryController.php)().
- **Form Request identificado en la firma:** [WorkshopQueryRequest](../../../app/Modules/Workshop/Http/Requests/WorkshopQueryRequest.php).
- **Coordinadores referidos por el controlador:** [WorkshopAccess](../../../app/Modules/Workshop/Application/WorkshopAccess.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Workshop\Http\Middleware\WorkshopApiErrors`, `can:access-workshop`.

- **Representaciones utilizadas por esta clase:** [WorkshopMechanicResource](../../../app/Modules/Workshop/Http/Resources/WorkshopMechanicResource.php), [WorkshopRecordResource](../../../app/Modules/Workshop/Http/Resources/WorkshopRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
