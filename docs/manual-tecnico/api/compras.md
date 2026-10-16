# Rutas: Compras

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/catalogs/suppliers

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SupplierController::index](../../../app/Modules/Purchasing/Http/Controllers/SupplierController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::index](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## POST /api/catalogs/suppliers

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SupplierController::store](../../../app/Modules/Purchasing/Http/Controllers/SupplierController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::store](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/suppliers/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SupplierController::show](../../../app/Modules/Purchasing/Http/Controllers/SupplierController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::show](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## PUT /api/catalogs/suppliers/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SupplierController::update](../../../app/Modules/Purchasing/Http/Controllers/SupplierController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::update](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/purchasing/lookups

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseOrderController::lookups](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/purchasing/orders

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseOrderController::index](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/purchasing/orders

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseOrderController::store](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php)().
- **Form Request identificado en la firma:** [PurchaseFulfillmentRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseFulfillmentRequest.php).
- **Coordinadores referidos por el controlador:** [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/purchasing/orders/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseOrderController::show](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/purchasing/orders/{id}/receipts

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseOrderController::receive](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php)().
- **Form Request identificado en la firma:** [PurchaseFulfillmentRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseFulfillmentRequest.php).
- **Coordinadores referidos por el controlador:** [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/purchasing/requests

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseRequestController::index](../../../app/Modules/Purchasing/Http/Controllers/PurchaseRequestController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [PurchaseRequestAdministration](../../../app/Modules/Purchasing/Application/PurchaseRequestAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseRequestResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/purchasing/requests

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseRequestController::store](../../../app/Modules/Purchasing/Http/Controllers/PurchaseRequestController.php)().
- **Form Request identificado en la firma:** [PurchaseWriteRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [PurchaseRequestAdministration](../../../app/Modules/Purchasing/Application/PurchaseRequestAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`, `can:manage-purchase-requests`.

- **Representaciones utilizadas por esta clase:** [PurchaseRequestResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/purchasing/requests/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseRequestController::show](../../../app/Modules/Purchasing/Http/Controllers/PurchaseRequestController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [PurchaseRequestAdministration](../../../app/Modules/Purchasing/Application/PurchaseRequestAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`.

- **Representaciones utilizadas por esta clase:** [PurchaseRequestResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/purchasing/requests/{id}/decisions

- **Nombre Laravel:** sin nombre.
- **Entrada:** [PurchaseRequestController::decide](../../../app/Modules/Purchasing/Http/Controllers/PurchaseRequestController.php)().
- **Form Request identificado en la firma:** [PurchaseWriteRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [PurchaseRequestAdministration](../../../app/Modules/Purchasing/Application/PurchaseRequestAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors`, `can:access-purchasing`, `can:decide-purchase-request`.

- **Representaciones utilizadas por esta clase:** [PurchaseRequestResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseRequestResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
