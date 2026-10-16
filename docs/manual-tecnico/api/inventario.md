# Rutas: Inventario

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/catalogs/spare-parts

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SparePartController::index](../../../app/Modules/Inventory/Http/Controllers/SparePartController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::index](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## POST /api/catalogs/spare-parts

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SparePartController::store](../../../app/Modules/Inventory/Http/Controllers/SparePartController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::store](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/catalogs/spare-parts/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SparePartController::show](../../../app/Modules/Inventory/Http/Controllers/SparePartController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::show](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## PUT /api/catalogs/spare-parts/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SparePartController::update](../../../app/Modules/Inventory/Http/Controllers/SparePartController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-catalogs`.

- **Método heredado:** [CatalogController::update](../../../app/Http/Controllers/CatalogController.php); `rules()` y `model()` del catálogo determinan validación/persistencia. Lista paginada para index; `data` para detalle/alta/actualización, 201 para alta.

## GET|HEAD /api/inventory/corrections

- **Nombre Laravel:** sin nombre.
- **Entrada:** [InventoryController::corrections](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## POST /api/inventory/corrections

- **Nombre Laravel:** `inventory.corrections.store`.
- **Entrada:** [InventoryController::store](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** [InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php).
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## POST /api/inventory/corrections/{id}/decisions

- **Nombre Laravel:** `inventory.decisions.store`.
- **Entrada:** [InventoryController::decide](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** [InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php).
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## GET|HEAD /api/inventory/custody

- **Nombre Laravel:** sin nombre.
- **Entrada:** [InventoryController::custody](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## POST /api/inventory/decisions/{id}/apply

- **Nombre Laravel:** `inventory.decisions.apply`.
- **Entrada:** [InventoryController::apply](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** [InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php).
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## GET|HEAD /api/inventory/movements

- **Nombre Laravel:** sin nombre.
- **Entrada:** [InventoryController::movements](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## POST /api/inventory/returns

- **Nombre Laravel:** `inventory.returns.store`.
- **Entrada:** [InventoryController::returns](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** [InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php).
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## GET|HEAD /api/inventory/stock

- **Nombre Laravel:** sin nombre.
- **Entrada:** [InventoryController::stock](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\Inventory\Http\Middleware\InventoryApiErrors`, `can:access-inventory`.


## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
