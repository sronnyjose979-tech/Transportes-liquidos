# Inventario: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`inventory_applications`](../base-de-datos/inventario.md#inventory_applications), [`inventory_corrections`](../base-de-datos/inventario.md#inventory_corrections), [`inventory_decisions`](../base-de-datos/inventario.md#inventory_decisions), [`inventory_movements`](../base-de-datos/inventario.md#inventory_movements), [`inventory_operations`](../base-de-datos/inventario.md#inventory_operations), [`inventory_returns`](../base-de-datos/inventario.md#inventory_returns), [`spare_parts`](../base-de-datos/inventario.md#spare_parts).

## Contratos HTTP

[Referencia de las 12 rutas registradas](../api/inventario.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [InventoryLedger](../../../app/Modules/Inventory/Application/InventoryLedger.php) | `Application` | `correction`, `decide`, `apply`, `returnParts`, `receive` |
| [InventoryController](../../../app/Modules/Inventory/Http/Controllers/InventoryController.php) | `Http/Controllers` | `stock`, `movements`, `custody`, `corrections`, `store`, `decide`, `apply`, `returns` |
| [SparePartController](../../../app/Modules/Inventory/Http/Controllers/SparePartController.php) | `Http/Controllers` | heredados / propiedades |
| [InventoryApiErrors](../../../app/Modules/Inventory/Http/Middleware/InventoryApiErrors.php) | `Http/Middleware` | `handle` |
| [InventoryWriteRequest](../../../app/Modules/Inventory/Http/Requests/InventoryWriteRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [SparePart](../../../app/Modules/Inventory/Models/SparePart.php) | `Models` | heredados / propiedades |
