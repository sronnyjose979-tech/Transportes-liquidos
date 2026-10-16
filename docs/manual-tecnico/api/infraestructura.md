# Rutas: Páginas, sesión y framework

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

## GET|HEAD /

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:14](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`.

## GET|HEAD /administracion/{section}

- **Nombre Laravel:** `administration.web`.
- **Entrada:** `Closure` en [routes/web.php:27](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`.

## GET|HEAD /alertas

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:19](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-fleet-documents`.

## GET|HEAD /api/csrf

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/backend.php:27](../../../routes/backend.php).
- **Middleware efectivo:** `web`.

## GET|HEAD /auditoria

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:17](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:view-administrative-audit`.

## GET|HEAD /compras

- **Nombre Laravel:** `purchasing.web`.
- **Entrada:** `Closure` en [routes/web.php:26](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-purchasing`.

## GET|HEAD /dashboard

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:15](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-personnel`.

## GET|HEAD /documentacion

- **Nombre Laravel:** `fleet.documents.web`.
- **Entrada:** `Closure` en [routes/web.php:23](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-fleet-documents`.

## GET|HEAD /driver-sw.js

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:10](../../../routes/web.php).
- **Middleware efectivo:** `web`.

## GET|HEAD /facturacion

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:21](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-billing`.

## GET|HEAD /gastos

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:20](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-expenses`.

## GET|HEAD /inventario

- **Nombre Laravel:** `inventory.web`.
- **Entrada:** `Closure` en [routes/web.php:25](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-inventory`.

## GET|HEAD /login

- **Nombre Laravel:** `login`.
- **Entrada:** `Closure` en [routes/web.php:12](../../../routes/web.php).
- **Middleware efectivo:** `web`.

## GET|HEAD /mis-viajes

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:16](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:view-assigned-trips`.

## GET|HEAD /reportes

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [routes/web.php:18](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:view-administrative-reports`.

## GET|HEAD /storage/{path}

- **Nombre Laravel:** `storage.local`.
- **Entrada:** `Closure` en [vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111](../../../vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php).
- **Middleware efectivo:** .

## PUT /storage/{path}

- **Nombre Laravel:** `storage.local.upload`.
- **Entrada:** `Closure` en [vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119](../../../vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php).
- **Middleware efectivo:** .

## GET|HEAD /taller

- **Nombre Laravel:** `workshop.web`.
- **Entrada:** `Closure` en [routes/web.php:22](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:access-workshop`.

## GET|HEAD /transporte

- **Nombre Laravel:** `transport.web`.
- **Entrada:** `Closure` en [routes/web.php:24](../../../routes/web.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage`, `can:manage-transport-core`.

## GET|HEAD /up

- **Nombre Laravel:** sin nombre.
- **Entrada:** `Closure` en [vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:224](../../../vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php).
- **Middleware efectivo:** .

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
