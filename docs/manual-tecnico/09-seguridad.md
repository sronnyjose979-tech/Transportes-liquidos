# 09. Seguridad y controles existentes


## Autenticar, autorizar y validar son acciones diferentes

**Autenticar** identifica la cuenta. **Autorizar** decide si esa cuenta puede realizar una operación sobre ese recurso. **Validar** comprueba formato, contenido y relaciones de los datos. Una contraseña correcta no autoriza aprobar un gasto, y un UUID válido no concede acceso al viaje que identifica.

## Autenticación y sesión

`SessionController` recibe `login`/`password`, utiliza la autenticación de Laravel para cuentas activas y regenera la sesión después del acceso. `POST /api/logout` invalida sesión y regenera token CSRF. No hay registro público ni credenciales predeterminadas. `access:bootstrap-admin` es un comando administrativo interactivo para la primera cuenta: pide confirmación y contraseña oculta; no sirve para crear otro administrador cuando ya existe uno.

Las contraseñas se guardan mediante **hash**, transformación unidireccional para verificar sin conservar texto original; no es cifrado reversible. La administración exige confirmación y límites técnicos de 12 caracteres/72 bytes. `auth.session` permite invalidar otras sesiones cuando cambia la contraseña. El límite de login es cinco intentos por minuto/IP; su eficacia depende de configurar proxies correctamente, sin confiar arbitrariamente en cabeceras del cliente.

`EnsureActiveAccount` vuelve a comprobar la cuenta; si fue desactivada, cierra/invalida sesión y responde 403. Cuenta activa y empleado activo son controles distintos. Activar una cuenta no convierte automáticamente al empleado en conductor elegible.

## Gates, Policies y recurso concreto

Un **Gate** es una condición nombrada registrada en [`AppServiceProvider`](../../app/Providers/AppServiceProvider.php). Una **Policy** agrupa condiciones para un recurso. `TransportCorePolicy` y `WorkshopWorkOrderPolicy` complementan los Gates; `WorkshopAccess` vuelve a comprobar roles/participación en operaciones.

| Capacidad | Roles habituales | Control real de referencia |
| --- | --- | --- |
| Recursos, personal y catálogos | Administrador | `manage-personnel`, `manage-fleet`, `manage-catalogs` |
| Tipos documentales | Administrador | `manage-document-types` |
| Documentos/alertas | Administrador, secretaria | `manage-fleet-documents` |
| Coordinación Transporte | Administrador, secretaria | `manage-transport-core`, `manage-trip-assignments`, `manage-trip-operations` |
| Excepciones/importes/decisiones | Administrador | Gates exclusivos y validación en servicios/DB |
| Taller | Administrador, secretaria, mecánico | `access-workshop` y acceso a orden/acción |
| Inventario | Oficina, mecánico según operación | `access-inventory`; ajustes/aplicaciones reservadas según servicio |
| Compras/facturación | Administrador, secretaria; decisiones exclusivas del administrador | `access-purchasing`, `manage-billing`, `decide-purchase-request`, `approve-trip-amount` |
| Gastos | Oficina; conductor limitado a su asignación/propios registros | `access-expenses`, `manage-expenses`, `approve-expenses` |
| Reportes/auditoría | Administrador | `view-administrative-reports`, `view-administrative-audit` |

Las filas no son una concesión nueva. Leer el Gate y el servicio para cada operación: un mecánico puede consultar historial de una participación, pero no escribir como si aún estuviera asignado. El conductor conserva consulta de sus registros, no permiso para registrar nuevos datos después de la liberación.

La **protección contra acceso a objetos ajenos** exige filtrar por dueño/asignación, además de validar UUID. La API limitada de `/api/transport/my-trips` no devuelve automáticamente el detalle administrativo general.

## CSRF, XSS y datos del cliente

**CSRF** es inducir a un navegador autenticado a ejecutar una operación desde otro sitio. Laravel exige token de la sesión; Vue obtiene `/api/csrf` y envía `X-XSRF-TOKEN` junto a cookie. Un 419 requiere recuperación explícita. No desactivar middleware para facilitar pruebas.

**XSS** es ejecutar contenido ajeno como código dentro de la aplicación. Vue escapa interpolaciones de texto; no convertir observaciones en HTML confiable. IndexedDB y cookies accesibles al navegador no son una barrera frente a código malicioso del mismo origen. La autorización del servidor sigue siendo obligatoria aunque una interfaz o caché local oculten recursos.

Form Requests limitan campos, tipos y enums. Actor/fechas de registro provienen de sesión/servidor cuando corresponde. Los archivos no aceptan una ruta libre del cliente. SQL usa parámetros y los filtros de consultas consolidadas eligen fuentes/columnas permitidas, no fragmentos arbitrarios.

## Archivos privados

`PrivateDocumentStorage`, `PrivateReceiptStorage` y `PrivateWorkshopFiles` usan raíces privadas, nombres internos únicos y descarga autorizada. Documentos/Gastos validan PDF/JPG/PNG reales, extensión, MIME y contenido, hasta 10.000.000 bytes; Taller aplica además sus controles de reserva, hash/tamaño y cuota configurada. El límite de un módulo no debe copiarse como requisito universal sin revisar su Request/configuración.

No se devuelve `storage_key` como URL pública. No usar `storage:link` para hacer públicos estos discos. Los marcadores/reservas y reconciliadores distinguen archivos nuevos pendientes de documentos confirmados; una compensación no elimina un archivo histórico ajeno.

## Saneamiento central de errores SQL: P0-04

Una `QueryException` puede incluir consulta y parámetros. [`bootstrap/app.php`](../../bootstrap/app.php) registra solo `database.operation_failed` y un SQLSTATE validado de cinco caracteres alfanuméricos en mayúscula, o `unknown`. Su callback devuelve false para impedir el reporte crudo. No registra consulta, bindings, credenciales, conexión completa ni objeto de excepción. Se aplica también a consola.

**SQLSTATE** es un código normalizado de tipo de fallo. Conserva diagnóstico sin contenido sensible. Las respuestas existentes se traducen según middleware:

| Middleware | SQLSTATE | HTTP |
| --- | --- | --- |
| `TransportApiErrors` | `40001`, `40P01`, `55P03` | 409 |
| `TransportApiErrors` | `23505`, `23514`, `23503`, `22003` | 422 |
| `TransportApiErrors` | Otro | 500 |
| `WorkshopApiErrors` y reutilizaciones | `40001`, `40P01`, `55P03`, `23505` | 409 |
| `WorkshopApiErrors` y reutilizaciones | `23514`, `23503`, `23502`, `22003`, `22007`, `22P02`, `P0002` | 422 |
| `WorkshopApiErrors` y reutilizaciones | clase `08` o `57014` | 503 |
| `WorkshopApiErrors` y reutilizaciones | Otro | 500 |

Las rutas sin traductor específico reciben un error 500 genérico seguro, incluso ante debug, sin detalles PostgreSQL. Las respuestas añaden controles de caché/tipo pertinentes. `QueryExceptionSafetyTest` comprueba respuestas y logs, incluidas rutas antes descubiertas. No convertir un fallo de integridad en éxito para ocultarlo.

## Datos sensibles, auditoría y límites productivos

Auditoría consolida fuentes con lista segura de cambios, no contraseñas, tokens, sesiones, claves idempotentes privadas ni referencias de archivos. No inventa historial para operaciones que antes no lo registraban. `users`, `sessions` y tokens técnicos existen en el esquema, pero no deben exponerse por un Resource genérico.

Producción requiere HTTPS, cookies seguras, `APP_DEBUG=false`, secretos externos, privilegios mínimos, configuración correcta del proxy, protección de discos/logs y restauración ensayada. Este manual no demuestra que esos valores estén aplicados en un servidor. La evaluación vigente y sus estados están en [preparación para producción](../preparacion-produccion.md). P0-04/P1-05 corregidos no equivalen a autorización de despliegue.
