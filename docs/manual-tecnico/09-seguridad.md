# 09. Seguridad y controles existentes


## Autenticar, autorizar y validar son acciones diferentes

**Autenticar** identifica la cuenta. **Autorizar** decide si esa cuenta puede realizar una operación sobre ese recurso. **Validar** comprueba formato, contenido y relaciones de los datos. Una contraseña correcta no autoriza aprobar un gasto, y un UUID válido no concede acceso al viaje que identifica.

## Autenticación y sesión

`SessionController` recibe `login`/`password`, utiliza la autenticación de Laravel para cuentas activas y regenera la sesión después del acceso. `POST /api/logout` invalida sesión y regenera token CSRF. No hay registro público ni credenciales predeterminadas. `access:bootstrap-admin` es un comando administrativo interactivo para la primera cuenta: pide confirmación y contraseña oculta; no sirve para crear otro administrador cuando ya existe uno.

Las contraseñas se guardan mediante **hash**, transformación unidireccional para verificar sin conservar texto original; no es cifrado reversible. La administración exige confirmación y límites técnicos de 12 caracteres/72 bytes. `auth.session` permite invalidar otras sesiones cuando cambia la contraseña. El límite de login es cinco intentos por minuto/IP; su eficacia depende de configurar proxies correctamente, sin confiar arbitrariamente en cabeceras del cliente.

`EnsureActiveAccount` vuelve a comprobar la cuenta; si fue desactivada, cierra/invalida sesión y responde 403. Cuenta activa y empleado activo son controles distintos. Activar una cuenta no convierte automáticamente al empleado en conductor elegible.

### Sesión compartida entre pestañas

Laravel utiliza el guard `web` de sesión. Las pestañas del mismo perfil y sitio
comparten cookies: no representan sesiones independientes. Abrir `/login` con una
sesión válida consulta `/api/session` y `/api/navigation` y lleva esa pestaña al
inicio autorizado. Para probar simultáneamente cuentas distintas, utilizar perfiles
de navegador separados. No guardar tokens ni credenciales por pestaña.

El fallo detectado era de estado en memoria: cada raíz Vue conservaba su usuario y
sus datos; no existía un aviso común de login/logout. Solo la PWA escuchaba cambios
de `driver-offline-active-v1`. No hay Vue Router, BroadcastChannel ni código que
cierre ventanas; la navegación usa documentos y `history` en Transporte. Los usos
existentes de sessionStorage corresponden a recuperación/idempotencia, no a sesión.

`SessionBoundary` envuelve las raíces existentes. Tras login/logout confirmado, una
notificación aleatoria en `transport-session-changed-v1` avisa a otras pestañas sin
guardar identidad ni secretos. Cada receptora oculta e inhabilita el contenido
mientras consulta la sesión real. Si cambió usuario o roles, desmonta el contenido
anterior y pide recargar explícitamente; no cierra ni navega otras pestañas. Volver
al foco/visibilidad o restaurar desde la caché de navegación vuelve a verificar la
identidad. Una respuesta tardía de la identidad anterior se descarta.

Los clientes HTTP comparten `sessionFetch`, que añade `X-Session-User` con la
identidad consultada por esa pantalla. `EnsureSessionIdentity`, dentro del grupo
autenticado, compara el valor con el usuario de la cookie y responde 409 con
`code: session_changed` antes de ejecutar el controlador si difieren. Añade el
usuario efectivo a las respuestas para detectar también cruces en lecturas. El
header es una precondición adicional, **nunca una credencial ni autorización**:
auth, cuenta activa, auth.session, Gates/Policies y CSRF siguen vigentes. Los clientes
sin header conservan el contrato y las comprobaciones anteriores.

Una desconexión ordinaria conserva el modo offline existente. Un cambio de sesión
notificado mantiene ocultos los datos anteriores aunque falle su verificación.
No se cambian service worker, esquema IndexedDB, UUID, reintentos ni resolución de
conflictos; las capturas pendientes permanecen vinculadas a su autor original.
Una operación que ya llegó al servidor puede estar confirmada: consultar su estado
antes de repetirla. El navegador no puede comprobar cambios remotos mientras está
sin conexión; la sincronización sigue revalidando identidad y permisos en servidor.

La regresión E2E reproduce el problema con dos páginas del mismo BrowserContext y
el bundle anterior: tras logout de la segunda, Personal seguía visible en la
primera. Las nuevas pruebas comprueban ocultación, URL estable, pestaña abierta,
redirección de `/login`, permisos del usuario actual, cambio sin notificación y
aislamiento de perfiles. Las pruebas PHP cubren lectura, escritura y logout con
identidad anterior, incluso entre dos administradores, y demuestran que el header
no autentica invitados ni concede permisos.

Verificación ejecutada el 17 de octubre de 2026, con usuarios sintéticos y
PostgreSQL 18 temporal por socket privado (sin acceso a la base de la aplicación):

| Comprobación | Comando | Resultado consolidado |
| --- | --- | --- |
| Backend completo | `bash tests/run-postgres.sh` | 411 aprobadas, 5.334 aserciones |
| Frontend Chromium | `pnpm run test:frontend` | 137 casos ejecutados: 133 aprobados inicialmente; los cuatro de revocación aprobaron al adaptar sus expectativas al aviso común y repetirlos |
| Contratos y concurrencia de identidad | `pnpm run test:frontend-api` | 35 aprobados; seis nuevos casos de identidad |
| E2E real | `bash tests/run-postgres.sh --e2e-navigation` y las otras 14 suites existentes | 57 casos ejecutados: 55 aprobados inicialmente; los otros dos aprobaron al repetirse según se explica debajo |
| Tipos y compilación | `pnpm run typecheck`, `pnpm run build` | Correctos; aviso preexistente de importación estática/dinámica de `DriverPortal/local.ts` |
| Formato y diff | Pint sobre middleware/rutas/prueba PHP; `git diff --check` | Correctos |

Los cuatro casos frontend actualizados son las revocaciones de Documentación,
Inventario, Transporte y Taller; conservan las comprobaciones de contenido privado,
escrituras y CSRF correspondientes. La primera ejecución frontend se interrumpió
al detectar que el harness de IndexedDB no copiaba el nuevo módulo compartido; se
actualizó su lista de archivos y sus ocho casos aprobaron, además de completar la
suite de 137 casos. Esa ejecución interrumpida no cuenta como aprobada.

En E2E, Taller agotó la espera de carga de cinco segundos con consultas todavía
pendientes y presión de memoria/swap durante las suites simultáneas; el mismo caso
aprobó con menos carga, sin cambiar código ni timeout. Compras comprobaba persistencia
cuando el botón de recuperación ya era visible pero seguía deshabilitado por un
envío en curso: ahora espera que esté habilitado y comprueba que el proxy recibió
la respuesta confirmada antes de consultar la persistencia. La repetición aprobó.

Cobertura final consolidada: **640 casos distintos aprobados, ninguno omitido**;
no se suman las repeticiones. Los registros iniciales, finales y trazas temporales
se conservan bajo `/tmp/transport-qa/tabs-*` y los directorios de artefactos que
indican esos registros. No se declara exitosa la primera pasada con fallos.
Se verificó Chromium local, no otros navegadores ni un celular físico.

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
