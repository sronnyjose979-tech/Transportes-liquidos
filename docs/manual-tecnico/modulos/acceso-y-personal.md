# Acceso y Personal: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

[`employees`](../base-de-datos/acceso-y-personal.md#employees), [`roles`](../base-de-datos/acceso-y-personal.md#roles), [`user_roles`](../base-de-datos/acceso-y-personal.md#user_roles), [`users`](../base-de-datos/acceso-y-personal.md#users).

## Contratos HTTP

[Referencia de las 15 rutas registradas](../api/acceso-y-personal.md).

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php) | `Application` | `save` |
| [SystemNavigation](../../../app/Modules/AccessAndPersonnel/Application/SystemNavigation.php) | `Application` | `links`, `home` |
| [BootstrapAdministrator](../../../app/Modules/AccessAndPersonnel/Console/BootstrapAdministrator.php) | `Console` | `handle` |
| [AccountController](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php) | `Http/Controllers` | `index`, `show`, `store`, `update`, `roles` |
| [EmployeeController](../../../app/Modules/AccessAndPersonnel/Http/Controllers/EmployeeController.php) | `Http/Controllers` | `index`, `show`, `store`, `update` |
| [SessionController](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SessionController.php) | `Http/Controllers` | `store`, `show`, `destroy` |
| [SystemController](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SystemController.php) | `Http/Controllers` | `navigation`, `dashboard` |
| [EnsureActiveAccount](../../../app/Modules/AccessAndPersonnel/Http/Middleware/EnsureActiveAccount.php) | `Http/Middleware` | `handle` |
| [PrivatePage](../../../app/Modules/AccessAndPersonnel/Http/Middleware/PrivatePage.php) | `Http/Middleware` | `handle` |
| [AccountRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/AccountRequest.php) | `Http/Requests` | `authorize`, `rules`, `messages`, `after` |
| [EmployeeRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/EmployeeRequest.php) | `Http/Requests` | `authorize`, `rules`, `after`, `messages` |
| [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php) | `Http/Resources` | `toArray` |
| [Employee](../../../app/Modules/AccessAndPersonnel/Models/Employee.php) | `Models` | `user` |
| [Role](../../../app/Modules/AccessAndPersonnel/Models/Role.php) | `Models` | `users` |
