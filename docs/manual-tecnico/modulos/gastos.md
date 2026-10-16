# Gastos: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`expense_decisions`](../base-de-datos/gastos.md#expense_decisions), [`expense_operations`](../base-de-datos/gastos.md#expense_operations), [`expense_receipts`](../base-de-datos/gastos.md#expense_receipts), [`expense_revisions`](../base-de-datos/gastos.md#expense_revisions), [`expenses`](../base-de-datos/gastos.md#expenses).

## Contratos HTTP

[Referencia de las 7 rutas registradas](../api/gastos.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [ExpenseAdministration](../../../app/Modules/Expenses/Application/ExpenseAdministration.php) | `Application` | `office`, `assigned`, `create`, `correct`, `decide`, `receipt` |
| [ExpenseController](../../../app/Modules/Expenses/Http/Controllers/ExpenseController.php) | `Http/Controllers` | `index`, `show`, `store`, `correct`, `decide`, `upload`, `download` |
| [ExpenseWriteRequest](../../../app/Modules/Expenses/Http/Requests/ExpenseWriteRequest.php) | `Http/Requests` | `authorize`, `rules`, `after` |
| [ExpenseResource](../../../app/Modules/Expenses/Http/Resources/ExpenseResource.php) | `Http/Resources` | `toArray` |
| [PrivateReceiptStorage](../../../app/Modules/Expenses/Infrastructure/PrivateReceiptStorage.php) | `Infrastructure` | `register`, `reconcile`, `download` |
| [Expense](../../../app/Modules/Expenses/Models/Expense.php) | `Models` | `revisions`, `receipts` |
| [ExpenseDecision](../../../app/Modules/Expenses/Models/ExpenseDecision.php) | `Models` | heredados / propiedades |
| [ExpenseReceipt](../../../app/Modules/Expenses/Models/ExpenseReceipt.php) | `Models` | heredados / propiedades |
| [ExpenseRevision](../../../app/Modules/Expenses/Models/ExpenseRevision.php) | `Models` | `decision` |
