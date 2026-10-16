# Rutas: Facturación administrativa y pagos

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## POST /api/billing/amounts/{id}/decisions

- **Nombre Laravel:** `billing.amounts.decide`.
- **Entrada:** [BillingController::decide](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** [BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php).
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`, `can:approve-trip-amount`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/billing/invoices

- **Nombre Laravel:** `billing.`.
- **Entrada:** [BillingController::index](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/billing/invoices

- **Nombre Laravel:** `billing.invoices.store`.
- **Entrada:** [BillingController::store](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** [BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php).
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/billing/invoices/{id}

- **Nombre Laravel:** `billing.`.
- **Entrada:** [BillingController::show](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/billing/invoices/{id}/payments

- **Nombre Laravel:** `billing.payments.store`.
- **Entrada:** [BillingController::pay](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** [BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php).
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/billing/trips

- **Nombre Laravel:** `billing.`.
- **Entrada:** [BillingReviewController::index](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingReviewController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [Money](../../../app/Modules/BillingAndPayments/Application/Money.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.


## POST /api/billing/trips/{trip}/amounts

- **Nombre Laravel:** `billing.amounts.store`.
- **Entrada:** [BillingController::propose](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php)().
- **Form Request identificado en la firma:** [BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php).
- **Coordinadores referidos por el controlador:** [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-billing`.

- **Representaciones utilizadas por esta clase:** [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
