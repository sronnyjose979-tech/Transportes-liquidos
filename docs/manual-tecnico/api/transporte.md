# Rutas: Transporte y Despacho

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/catalogs/customers

- **Nombre Laravel:** sin nombre.
- **Entrada:** [CustomerController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/CustomerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::index](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## POST /api/catalogs/customers

- **Nombre Laravel:** sin nombre.
- **Entrada:** [CustomerController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/CustomerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::store](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/customers/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [CustomerController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/CustomerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::show](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## PUT /api/catalogs/customers/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [CustomerController::update](../../../app/Modules/TransportAndDispatch/Http/Controllers/CustomerController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::update](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/liquid-products

- **Nombre Laravel:** sin nombre.
- **Entrada:** [LiquidProductController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/LiquidProductController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::index](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## POST /api/catalogs/liquid-products

- **Nombre Laravel:** sin nombre.
- **Entrada:** [LiquidProductController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/LiquidProductController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::store](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/liquid-products/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [LiquidProductController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/LiquidProductController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::show](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## PUT /api/catalogs/liquid-products/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [LiquidProductController::update](../../../app/Modules/TransportAndDispatch/Http/Controllers/LiquidProductController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::update](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/operation-sites

- **Nombre Laravel:** sin nombre.
- **Entrada:** [OperationSiteController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/OperationSiteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::index](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## POST /api/catalogs/operation-sites

- **Nombre Laravel:** sin nombre.
- **Entrada:** [OperationSiteController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/OperationSiteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::store](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/operation-sites/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [OperationSiteController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/OperationSiteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::show](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## PUT /api/catalogs/operation-sites/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [OperationSiteController::update](../../../app/Modules/TransportAndDispatch/Http/Controllers/OperationSiteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::update](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/routes

- **Nombre Laravel:** sin nombre.
- **Entrada:** [RouteController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/RouteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [RouteAdministration](../../../app/Modules/TransportAndDispatch/Application/RouteAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Representaciones utilizadas por esta clase:** [RouteResource](../../../app/Modules/TransportAndDispatch/Http/Resources/RouteResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/catalogs/routes

- **Nombre Laravel:** sin nombre.
- **Entrada:** [RouteController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/RouteController.php)().
- **Form Request identificado en la firma:** [RouteWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/RouteWriteRequest.php).
- **Coordinadores referidos por el controlador:** [RouteAdministration](../../../app/Modules/TransportAndDispatch/Application/RouteAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Representaciones utilizadas por esta clase:** [RouteResource](../../../app/Modules/TransportAndDispatch/Http/Resources/RouteResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/catalogs/routes/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [RouteController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/RouteController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [RouteAdministration](../../../app/Modules/TransportAndDispatch/Application/RouteAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Representaciones utilizadas por esta clase:** [RouteResource](../../../app/Modules/TransportAndDispatch/Http/Resources/RouteResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/catalogs/routes/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [RouteController::update](../../../app/Modules/TransportAndDispatch/Http/Controllers/RouteController.php)().
- **Form Request identificado en la firma:** [RouteWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/RouteWriteRequest.php).
- **Coordinadores referidos por el controlador:** [RouteAdministration](../../../app/Modules/TransportAndDispatch/Application/RouteAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Representaciones utilizadas por esta clase:** [RouteResource](../../../app/Modules/TransportAndDispatch/Http/Resources/RouteResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/authorizations/{id}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportAuthorizationController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportAuthorizationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/authorizations/{id}/decisions

- **Nombre Laravel:** `transport.authorizations.decide`.
- **Entrada:** [TransportAuthorizationController::decide](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportAuthorizationController.php)().
- **Form Request identificado en la firma:** [AuthorizationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AuthorizationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`, `can:decide-transport-authorization`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/availability

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripAssignmentController::availability](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php), [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/lookups/{kind}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportLookupController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportLookupController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportLookupResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportLookupResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/my-records

- **Nombre Laravel:** `transport.`.
- **Entrada:** [DriverTripRecordController::history](../../../app/Modules/TransportAndDispatch/Http/Controllers/DriverTripRecordController.php)().
- **Form Request identificado en la firma:** [AssignedTripQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignedTripQueryRequest.php).
- **Coordinadores referidos por el controlador:** [DriverTripRecords](../../../app/Modules/TransportAndDispatch/Application/DriverTripRecords.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:view-assigned-trips`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/my-trips

- **Nombre Laravel:** `transport.`.
- **Entrada:** [AssignedTripController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/AssignedTripController.php)().
- **Form Request identificado en la firma:** [AssignedTripQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignedTripQueryRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:view-assigned-trips`.

- **Representaciones utilizadas por esta clase:** [AssignedTripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/AssignedTripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/my-trips/{id}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [AssignedTripController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/AssignedTripController.php)().
- **Form Request identificado en la firma:** [AssignedTripQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignedTripQueryRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:view-assigned-trips`.

- **Representaciones utilizadas por esta clase:** [AssignedTripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/AssignedTripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/my-trips/{id}/odometer

- **Nombre Laravel:** `transport.driver.odometer`.
- **Entrada:** [DriverTripRecordController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/DriverTripRecordController.php)().
- **Form Request identificado en la firma:** [DriverRecordWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/DriverRecordWriteRequest.php).
- **Coordinadores referidos por el controlador:** [DriverTripRecords](../../../app/Modules/TransportAndDispatch/Application/DriverTripRecords.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:view-assigned-trips`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/my-trips/{id}/reports

- **Nombre Laravel:** `transport.driver.report`.
- **Entrada:** [DriverTripRecordController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/DriverTripRecordController.php)().
- **Form Request identificado en la firma:** [DriverRecordWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/DriverRecordWriteRequest.php).
- **Coordinadores referidos por el controlador:** [DriverTripRecords](../../../app/Modules/TransportAndDispatch/Application/DriverTripRecords.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:view-assigned-trips`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/requests

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportRequestController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/requests

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportRequestController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php)().
- **Form Request identificado en la firma:** [TransportRequestWrite](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportRequestWrite.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/requests/{id}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportRequestController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/transport/requests/{id}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportRequestController::update](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php)().
- **Form Request identificado en la firma:** [TransportRequestWrite](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportRequestWrite.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/requests/{id}/history

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportRequestController::history](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TransportRequestResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripCreateRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripCreateRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/assignments

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripAssignmentController::history](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php), [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/assignments/assign

- **Nombre Laravel:** `transport.assignments.assign`.
- **Entrada:** [TripAssignmentController::write](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php)().
- **Form Request identificado en la firma:** [AssignmentWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignmentWriteRequest.php).
- **Coordinadores referidos por el controlador:** [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php), [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/assignments/release

- **Nombre Laravel:** `transport.assignments.release`.
- **Entrada:** [TripAssignmentController::write](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php)().
- **Form Request identificado en la firma:** [AssignmentWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignmentWriteRequest.php).
- **Coordinadores referidos por el controlador:** [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php), [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/assignments/substitute

- **Nombre Laravel:** `transport.assignments.substitute`.
- **Entrada:** [TripAssignmentController::write](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripAssignmentController.php)().
- **Form Request identificado en la firma:** [AssignmentWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AssignmentWriteRequest.php).
- **Coordinadores referidos por el controlador:** [AssignmentAdministration](../../../app/Modules/TransportAndDispatch/Application/AssignmentAdministration.php), [AssignmentAvailability](../../../app/Modules/TransportAndDispatch/Application/AssignmentAvailability.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/authorizations

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportAuthorizationController::index](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportAuthorizationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/authorizations

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TransportAuthorizationController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/TransportAuthorizationController.php)().
- **Form Request identificado en la firma:** [AuthorizationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/AuthorizationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/cancel

- **Nombre Laravel:** `transport.trips.cancel`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/close

- **Nombre Laravel:** `transport.trips.close`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/distance

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripOperationController::distance](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/finish

- **Nombre Laravel:** `transport.trips.finish`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/history

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripController::history](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/load

- **Nombre Laravel:** `transport.operations.show-load`.
- **Entrada:** [TripOperationController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/load

- **Nombre Laravel:** `transport.operations.load`.
- **Entrada:** [TripOperationController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/load/items

- **Nombre Laravel:** `transport.operations.load-items`.
- **Entrada:** [TripOperationController::addDetails](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/odometer

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripOperationController::readings](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/odometer

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripOperationController::reading](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OdometerWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OdometerWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/odometer/{readingId}/corrections

- **Nombre Laravel:** `transport.odometer.correct`.
- **Entrada:** [TripOperationController::correct](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OdometerWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OdometerWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/reports

- **Nombre Laravel:** `transport.operations.report`.
- **Entrada:** [TripOperationController::report](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [ReportWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/ReportWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/reports

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripOperationController::reports](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/reschedule

- **Nombre Laravel:** `transport.trips.reschedule`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/schedule

- **Nombre Laravel:** `transport.trips.schedule`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/start

- **Nombre Laravel:** `transport.trips.start`.
- **Entrada:** [TripController::action](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TripActionRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TripActionRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/unload

- **Nombre Laravel:** `transport.operations.show-unload`.
- **Entrada:** [TripOperationController::show](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/unload

- **Nombre Laravel:** `transport.operations.unload`.
- **Entrada:** [TripOperationController::store](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/transport/trips/{id}/unload/items

- **Nombre Laravel:** `transport.operations.unload-items`.
- **Entrada:** [TripOperationController::addDetails](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [OperationWriteRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/OperationWriteRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/volume-differences

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripOperationController::differences](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripOperationController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TripOperations](../../../app/Modules/TransportAndDispatch/Application/TripOperations.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/transport/trips/{id}/warnings

- **Nombre Laravel:** `transport.`.
- **Entrada:** [TripController::warnings](../../../app/Modules/TransportAndDispatch/Http/Controllers/TripController.php)().
- **Form Request identificado en la firma:** [TransportQueryRequest](../../../app/Modules/TransportAndDispatch/Http/Requests/TransportQueryRequest.php).
- **Coordinadores referidos por el controlador:** [TransportAdministration](../../../app/Modules/TransportAndDispatch/Application/TransportAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors`, `can:manage-transport-core`.

- **Representaciones utilizadas por esta clase:** [TransportRecordResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TransportRecordResource.php), [TripResource](../../../app/Modules/TransportAndDispatch/Http/Resources/TripResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
