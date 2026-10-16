# Compras: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`purchase_fulfillment_operations`](../base-de-datos/compras.md#purchase_fulfillment_operations), [`purchase_order_items`](../base-de-datos/compras.md#purchase_order_items), [`purchase_orders`](../base-de-datos/compras.md#purchase_orders), [`purchase_receipt_items`](../base-de-datos/compras.md#purchase_receipt_items), [`purchase_receipts`](../base-de-datos/compras.md#purchase_receipts), [`purchase_request_decisions`](../base-de-datos/compras.md#purchase_request_decisions), [`purchase_request_items`](../base-de-datos/compras.md#purchase_request_items), [`purchase_requests`](../base-de-datos/compras.md#purchase_requests), [`suppliers`](../base-de-datos/compras.md#suppliers).

## Contratos HTTP

[Referencia de las 13 rutas registradas](../api/compras.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [PurchaseFulfillment](../../../app/Modules/Purchasing/Application/PurchaseFulfillment.php) | `Application` | `record` |
| [PurchaseRequestAdministration](../../../app/Modules/Purchasing/Application/PurchaseRequestAdministration.php) | `Application` | `record` |
| [PurchaseOrderController](../../../app/Modules/Purchasing/Http/Controllers/PurchaseOrderController.php) | `Http/Controllers` | `index`, `show`, `store`, `receive`, `lookups` |
| [PurchaseRequestController](../../../app/Modules/Purchasing/Http/Controllers/PurchaseRequestController.php) | `Http/Controllers` | `index`, `show`, `store`, `decide` |
| [SupplierController](../../../app/Modules/Purchasing/Http/Controllers/SupplierController.php) | `Http/Controllers` | heredados / propiedades |
| [PurchasingApiErrors](../../../app/Modules/Purchasing/Http/Middleware/PurchasingApiErrors.php) | `Http/Middleware` | `handle` |
| [PurchaseFulfillmentRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseFulfillmentRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [PurchaseWriteRequest](../../../app/Modules/Purchasing/Http/Requests/PurchaseWriteRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [PurchaseOrderResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseOrderResource.php) | `Http/Resources` | `toArray` |
| [PurchaseRequestResource](../../../app/Modules/Purchasing/Http/Resources/PurchaseRequestResource.php) | `Http/Resources` | `toArray` |
| [PurchaseOrder](../../../app/Modules/Purchasing/Models/PurchaseOrder.php) | `Models` | `items`, `receipts`, `supplier` |
| [PurchaseOrderItem](../../../app/Modules/Purchasing/Models/PurchaseOrderItem.php) | `Models` | `requestedItem`, `receiptItems` |
| [PurchaseReceipt](../../../app/Modules/Purchasing/Models/PurchaseReceipt.php) | `Models` | `items` |
| [PurchaseReceiptItem](../../../app/Modules/Purchasing/Models/PurchaseReceiptItem.php) | `Models` | heredados / propiedades |
| [PurchaseRequest](../../../app/Modules/Purchasing/Models/PurchaseRequest.php) | `Models` | `supplier`, `requestedBy`, `items`, `decision` |
| [PurchaseRequestDecision](../../../app/Modules/Purchasing/Models/PurchaseRequestDecision.php) | `Models` | `decidedBy` |
| [PurchaseRequestItem](../../../app/Modules/Purchasing/Models/PurchaseRequestItem.php) | `Models` | heredados / propiedades |
| [Supplier](../../../app/Modules/Purchasing/Models/Supplier.php) | `Models` | heredados / propiedades |
