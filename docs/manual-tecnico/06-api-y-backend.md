# 06. API y backend


## HTTP y contratos

HTTP identifica una operación mediante método y dirección. `GET` consulta, `POST` crea un hecho o ejecuta un comando y `PATCH` actualiza campos permitidos. No se ofrece un DELETE universal para borrar historia. Una respuesta JSON tiene un código y un cuerpo; el código 201 significa creación confirmada, no que cualquier dato introducido fuera válido.

Estas APIs son del mismo origen que la web y usan sesión. Aunque su URL comience por `/api`, no son una autenticación separada con tokens. `routes/web.php` incluye `routes/backend.php`, que registra grupos por módulo. Los parámetros UUID se restringen cuando la ruta lo declara. La autorización fina puede estar además en Request, Policy o servicio.

## Referencia completa de rutas

Se inventariaron **268 declaraciones** del router cargado. La referencia incluye rutas privadas, login, páginas, service worker y soporte del framework; no oculta entradas porque no sean CRUD.

- [Acceso y Personal: 15 rutas](api/acceso-y-personal.md).
- [Flota y Documentación: 23 rutas](api/flota-y-documentacion.md).
- [Transporte y Despacho: 60 rutas](api/transporte.md).
- [Taller Mecánico: 108 rutas](api/taller.md).
- [Inventario: 12 rutas](api/inventario.md).
- [Compras: 13 rutas](api/compras.md).
- [Facturación administrativa y pagos: 7 rutas](api/facturacion.md).
- [Gastos: 7 rutas](api/gastos.md).
- [Reportes administrativos: 1 rutas](api/reportes.md).
- [Auditoría administrativa: 2 rutas](api/auditoria.md).
- [Páginas y framework: 20 rutas](api/infraestructura.md).

- [Campos y puntos de validación de todos los Form Requests](api/validaciones.md).

Cada entrada enlaza el controlador/método y muestra middleware efectivo. Las páginas con closure conservan referencia al archivo de rutas. Estos enlaces son preferibles a repetir una lista incompleta de “endpoints principales”. Los nombres Laravel, aunque alguno resulte poco descriptivo por herencia de grupo, se transcriben como están registrados.

## Ejemplo real: crear solicitud

Una **Form Request** es una clase de Laravel que prepara/valida la entrada antes del controlador. `TransportRequestWrite::rules()` distingue POST de PATCH. POST exige `request_key`, `customer_id`, `liquid_product_id`, `route_id`, `requested_liters` y `requested_at`. Los UUID deben existir; litros se envían como cadena positiva con hasta dos decimales; la fecha lleva zona horaria. PATCH exige versión y motivo y solo admite los cambios previstos, no un cambio de producto arbitrario.

En [`TransportRequestController`](../../app/Modules/TransportAndDispatch/Http/Controllers/TransportRequestController.php):

- `index()` pagina con `TransportQueryRequest`, cuenta viajes y ordena por fecha/ID.
- `show()` utiliza `findOrFail()`: un ID inexistente produce 404.
- `store()` entrega únicamente datos validados y usuario autenticado a `TransportAdministration::createRequest()`; responde mediante `TransportRequestResource` con 201.
- `update()` usa el coordinador y `TransportRequestResource`.
- `history()` presenta cambios paginados mediante `TransportRecordResource`.

El controlador no obtiene una conexión distinta, no acepta un actor del cliente y no decide con un `if` superficial si el recurso está disponible. El caso de uso y PostgreSQL completan esas garantías.

## Validación en varias capas

| Capa | Ejemplo | Lo que no sustituye |
| --- | --- | --- |
| Vue/HTML | Campo obligatorio, mensaje junto al campo, botón ocupado | No protege contra cliente manipulado |
| Form Request | `exists`, UUID, enum, tamaño/formato, rechazo de campos ajenos | No garantiza pertenencia entre dos relaciones ni disponibilidad concurrente |
| Servicio | Rol vigente, versión, contenido autorizado, lock y unidad transaccional | No protege por sí solo toda escritura SQL directa |
| PostgreSQL | FK, UNIQUE, CHECK y triggers de integridad | No convierte automáticamente datos en respuesta pública segura |

`OperationWriteRequest` cambia `compartment_id` de carga por `load_item_id` de descarga. En anexos de detalles prohíbe `operation_site_id`: se está completando una operación existente, no creando otra con lugar diferente. D02 requiere correspondencia de todos los detalles al finalizar.

## Respuestas y errores

Un **Resource** selecciona campos del modelo para JSON. Algunos listados usan `data` y `meta`; otros exponen paginador Laravel con metadatos diferentes, y comandos devuelven IDs/recibos. No tratar todos como un objeto `data.id`. Descargas devuelven bytes mediante autorización, no una URL pública al archivo.

| Código | Interpretación y actuación |
| --- | --- |
| 200 | Consulta, actualización o comando confirmado según endpoint |
| 201 | Alta/hecho confirmado; P1-05 puede devolver el mismo snapshot 201 en reintento |
| 204 | Respuesta sin contenido cuando el controlador lo dispone; no intentar parsear JSON universalmente |
| 401 | Falta sesión válida; recuperar autenticación sin reenviar comandos inciertos |
| 403 | Cuenta/rol/recurso no autorizado; no es prueba de que el registro no exista |
| 404 | Registro/ruta no disponible para esa consulta |
| 409 | Versión, clave/contenido, conflicto concurrente o estado incompatible; consultar antes de cambiar intención |
| 419 | CSRF/sesión de seguridad caducó; refrescar de forma explícita |
| 422 | Datos inválidos o restricción de integridad traducida según módulo; mostrar campos/mensaje |
| 429 | Límite de inicio de sesión u otro limitador aplicado |
| 500/503 | Fallo interno/no disponibilidad; no afirmar que hubo rollback solo por perder respuesta |

No toda excepción SQL tiene igual código HTTP: `TransportApiErrors` y `WorkshopApiErrors` conservan contratos distintos. El saneamiento global de [`bootstrap/app.php`](../../bootstrap/app.php) impide que el diagnóstico revele SQL/bindings. Ver [seguridad](09-seguridad.md) para el mapeo preciso.

## Idempotencia y concurrencia por contrato

`request_key` representa una intención, no una clave comercial. Para solicitudes/viajes P1-05 persiste recibo por actor/clave y contenido normalizado. Misma clave y contenido: mismo resultado; contenido diferente: 409. Una solicitud puede originar varios viajes legítimos con claves distintas.

Taller, Inventario, Compras, Facturación, Gastos y registros del conductor tienen recibos propios. No añadir `request_key` a un endpoint que no lo admite: asignación y ciertas operaciones históricas tienen contratos diferentes. Primero leer su Form Request y estrategia de recuperación. Un 500 o timeout no autoriza generar una clave nueva automáticamente.

Una **versión** es el número o referencia esperada del estado observado. Evita sobrescribir cambios de otra sesión. Se combina con locks/restricciones: validar la versión fuera de la transacción no resuelve una carrera.

## Cómo localizar una operación sin adivinar

Partir de la entrada del inventario → método del controlador → Form Request → servicio → modelos/consultas → triggers de sus tablas → Resource. Buscar el nombre de ruta si el controlador usa `routeIs()`: varios comandos comparten método pero cambian reglas por nombre. Esta técnica es especialmente importante en `WorkshopCoreController::__invoke()` y `WorkshopCore::execute()`.
