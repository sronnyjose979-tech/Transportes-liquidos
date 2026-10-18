# Rutas: Acceso y Personal

[Volver a API y backend](../06-api-y-backend.md).

Listado obtenido del router de Laravel después de cargar los archivos reales. `GET|HEAD` es una sola declaración con dos métodos. Los parámetros entre llaves se sustituyen por identificadores; no son texto literal. Una ruta registrada no demuestra por sí sola que todas sus operaciones sean accesibles al rol actual.

Control común entre pestañas: al middleware listado para cada API autenticada se
suma `EnsureSessionIdentity`. El cliente puede enviar `X-Session-User` con el ID
consultado por su pantalla. Si difiere del usuario de la cookie, recibe 409 con
`code: session_changed`, sin ejecutar la operación. Las respuestas que atraviesan
este middleware incluyen `X-Session-User` con el actor efectivo. No aplica a login
ni CSRF, no sustituye autenticación/autorización y no cambia los cuerpos JSON
existentes. Ver [sesión compartida entre pestañas](../09-seguridad.md#sesión-compartida-entre-pestañas).

## GET|HEAD /api/dashboard

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SystemController::dashboard](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SystemController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [SystemNavigation](../../../app/Modules/AccessAndPersonnel/Application/SystemNavigation.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.


## GET|HEAD /api/employees

- **Nombre Laravel:** sin nombre.
- **Entrada:** [EmployeeController::index](../../../app/Modules/AccessAndPersonnel/Http/Controllers/EmployeeController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.


## POST /api/employees

- **Nombre Laravel:** sin nombre.
- **Entrada:** [EmployeeController::store](../../../app/Modules/AccessAndPersonnel/Http/Controllers/EmployeeController.php)().
- **Form Request identificado en la firma:** [EmployeeRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/EmployeeRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.


## GET|HEAD /api/employees/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [EmployeeController::show](../../../app/Modules/AccessAndPersonnel/Http/Controllers/EmployeeController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.


## PATCH /api/employees/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [EmployeeController::update](../../../app/Modules/AccessAndPersonnel/Http/Controllers/EmployeeController.php)().
- **Form Request identificado en la firma:** [EmployeeRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/EmployeeRequest.php).
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.


## POST /api/login

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SessionController::store](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SessionController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `throttle:login`.


## POST /api/logout

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SessionController::destroy](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SessionController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`.


## GET|HEAD /api/navigation

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SystemController::navigation](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SystemController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [SystemNavigation](../../../app/Modules/AccessAndPersonnel/Application/SystemNavigation.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`.


## GET|HEAD /api/roles

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AccountController::roles](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/session

- **Nombre Laravel:** sin nombre.
- **Entrada:** [SessionController::show](../../../app/Modules/AccessAndPersonnel/Http/Controllers/SessionController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`.


## GET|HEAD /api/users

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AccountController::index](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## POST /api/users

Alta guiada compatible: además de los campos de cuenta anteriores, admite el objeto
opcional `employee` con solo `name` (texto obligatorio, máximo 255) e `is_active`
(booleano obligatorio). Se crean cuenta, roles y empleado en una sola transacción.
El servidor asigna user_id al empleado: el cliente no puede aportarlo. Si falla
cualquier escritura se revierte todo; los permisos siguen siendo manage-personnel.
La respuesta 201 conserva el Resource y su employee_id. Sin el objeto, el alta de
cuenta funciona como antes. No se permite este objeto en PATCH. Los duplicados
siguen rechazándose mediante las restricciones y validaciones existentes; no se
promete replay idempotente. Ante resultado incierto se consulta antes de reenviar.


- **Nombre Laravel:** sin nombre.
- **Entrada:** [AccountController::store](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** [AccountRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/AccountRequest.php).
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## GET|HEAD /api/users/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AccountController::show](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** sin clase específica en la firma; consultar las validaciones del controlador/servicio, no asumir entrada libre.
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/users/{id}

- **Nombre Laravel:** sin nombre.
- **Entrada:** [AccountController::update](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** [AccountRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/AccountRequest.php).
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## PATCH /api/users/{id}/activation

- **Nombre Laravel:** `users.activation`.
- **Entrada:** [AccountController::update](../../../app/Modules/AccessAndPersonnel/Http/Controllers/AccountController.php)().
- **Form Request identificado en la firma:** [AccountRequest](../../../app/Modules/AccessAndPersonnel/Http/Requests/AccountRequest.php).
- **Coordinadores referidos por el controlador:** [AccountAdministration](../../../app/Modules/AccessAndPersonnel/Application/AccountAdministration.php). Son referencias de la clase, no necesariamente todas llamadas por este método.
- **Middleware efectivo:** `web`, `auth`, `App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount`, `auth.session`, `can:manage-personnel`.

- **Representaciones utilizadas por esta clase:** [AccountResource](../../../app/Modules/AccessAndPersonnel/Http/Resources/AccountResource.php). Revisar el método enlazado para seleccionar la de esta operación.

## Cómo interpretar el contrato

Las operaciones JSON usan sesión y CSRF. Las reglas de campos se detallan en [Form Requests](validaciones.md); las respuestas se construyen con los Resources/controladores enlazados. No todos los endpoints comparten envoltura: una lista paginada, un recibo de comando y una descarga binaria son contratos diferentes. Para el código exacto del cuerpo seguir el método de entrada y su Resource, no trasladar un ejemplo de otro módulo.
