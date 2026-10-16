# Rutas: Gastos

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /api/expenses

- **Nombre Laravel:** `expenses.`.
- **Entrada:** [ExpenseController::index](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/expenses

- **Nombre Laravel:** `expenses.store`.
- **Entrada:** [ExpenseController::store](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** [ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/expenses/receipts/{receipt}/download

- **Nombre Laravel:** `expenses.`.
- **Entrada:** [ExpenseController::download](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/expenses/{id}

- **Nombre Laravel:** `expenses.`.
- **Entrada:** [ExpenseController::show](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/expenses/{id}/corrections

- **Nombre Laravel:** `expenses.correct`.
- **Entrada:** [ExpenseController::correct](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** [ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`, `can:manage-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/expenses/{id}/decisions

- **Nombre Laravel:** `expenses.decide`.
- **Entrada:** [ExpenseController::decide](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** [ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`, `can:approve-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/expenses/{id}/receipts

- **Nombre Laravel:** `expenses.receipts.store`.
- **Entrada:** [ExpenseController::upload](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php)().
- **Form Request identificado en la firma:** [ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php).
- **Coordinadores referidos por el controlador:** [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-expenses`.

- **Representaciones utilizadas por esta clase:** [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
