# Facturación administrativa y pagos: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`billing_amount_decisions`](../base-de-datos/facturacion.md#billing_amount_decisions), [`billing_guard`](../base-de-datos/facturacion.md#billing_guard), [`billing_invoice_items`](../base-de-datos/facturacion.md#billing_invoice_items), [`billing_invoices`](../base-de-datos/facturacion.md#billing_invoices), [`billing_operations`](../base-de-datos/facturacion.md#billing_operations), [`billing_payments`](../base-de-datos/facturacion.md#billing_payments), [`billing_trip_amounts`](../base-de-datos/facturacion.md#billing_trip_amounts).

## Contratos HTTP

[Referencia de las 7 rutas registradas](../api/facturacion.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [BillingPayments](../../../app/Modules/BillingAndPayments/Application/BillingPayments.php) | `Application` | `propose`, `decide`, `invoice`, `payment`, `approved` |
| [Money](../../../app/Modules/BillingAndPayments/Application/Money.php) | `Application` | `minor`, `format`, `roundHalfUp` |
| [BillingController](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingController.php) | `Http/Controllers` | `propose`, `decide`, `store`, `pay`, `index`, `show` |
| [BillingReviewController](../../../app/Modules/BillingAndPayments/Http/Controllers/BillingReviewController.php) | `Http/Controllers` | `index` |
| [BillingWriteRequest](../../../app/Modules/BillingAndPayments/Http/Requests/BillingWriteRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [AdministrativeInvoiceResource](../../../app/Modules/BillingAndPayments/Http/Resources/AdministrativeInvoiceResource.php) | `Http/Resources` | `toArray` |
| [AdministrativeInvoice](../../../app/Modules/BillingAndPayments/Models/AdministrativeInvoice.php) | `Models` | `items`, `payments` |
| [AdministrativeInvoiceItem](../../../app/Modules/BillingAndPayments/Models/AdministrativeInvoiceItem.php) | `Models` | heredados / propiedades |
| [AdministrativePayment](../../../app/Modules/BillingAndPayments/Models/AdministrativePayment.php) | `Models` | heredados / propiedades |
