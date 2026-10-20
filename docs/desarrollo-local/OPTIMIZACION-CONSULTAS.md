# Optimización de Navigation y Dashboard

Continuación de la instrumentación del commit `4f57c66`. No cambia contratos API,
permisos, esquema, triggers, bloqueos ni configuración de PostgreSQL.

## Backend

- Navigation obtiene los enlaces una vez y deriva `home` de esos mismos enlaces,
  conservando su orden y el error cuando no existe inicio autorizado.
- `EnsureActiveAccount` conserva la lectura actualizada del usuario y crea un
  `ReadAuthorizationContext` solamente para GET. Los Gates comparten una lectura
  de roles, sin reutilización entre peticiones. El contexto se elimina al salir
  del middleware, incluso ante excepciones.
- Fuera del middleware, para otro actor, en escrituras y dentro de transacciones,
  se mantienen las consultas anteriores. Una autorización transaccional descarta
  el contexto de lectura para evitar su reutilización posterior. No se reutilizan
  asignaciones, propiedad de registros ni resultados de permisos por recurso.
- Dashboard ejecuta siete subconsultas `count(*)` en una sentencia. Mantiene
  nombres, orden, enlaces y valores enteros de los contadores.
- Alertas ejecuta sus tres conteos en una sentencia usando las mismas consultas
  y bindings de `query()`. Conserva vigencia, revisiones, configuración histórica,
  límite inclusivo de quince días y fecha de Costa Rica.

## Vue

`SessionBoundary` proporciona un cargador de navegación por aplicación montada.
Inicio, barra lateral y accesos rápidos comparten la promesa y su resultado para
el mismo objeto de sesión. Una sesión renovada, cambio de identidad/roles o
cambio detectado por la protección entre pestañas invalida esa reutilización.
Los errores permiten reintentar. No se usa localStorage, caché HTTP, service worker
ni una caché global de permisos; el servidor sigue autorizando cada petición.

## Consultas y verificaciones

| Recorrido | Antes, estimación del código anterior | Después, medido en pruebas |
| --- | ---: | ---: |
| GET Navigation | 35 | 2 |
| GET Dashboard | 10 | 3 |
| Servicio de resumen documental, sin middleware | 3 | 1 |
| Solicitudes de Navigation en Dashboard, por página | 2 | 1 |

Las mediciones HTTP usan PostgreSQL 18 temporal, `actingAs` y sesión en memoria:
incluyen la recarga del usuario, roles y consultas del endpoint; excluyen la
búsqueda inicial del usuario por cookie y el almacenamiento remoto de sesiones.
En Azure esas operaciones agregan consultas según configuración. La reducción
del resumen documental elimina dos viajes; la agrupación conserva las tres
subconsultas en PostgreSQL. Tampoco desaparece el trabajo de contar las siete
tablas del Dashboard. Los conteos agrupados usan una misma instantánea de sentencia.

Pruebas específicas: `SystemPerformanceTest`, `SystemNavigationTest`,
`DocumentAlertsTest`, `SessionIdentityTest` y `RequestPerformanceTest`. Regresión:
permisos de Transporte, seguridad de cuentas, revocación de catálogos y
autorización/disponibilidad/concurrencia de Taller. Se utiliza exclusivamente el
runner `tests/run-postgres.sh`, que crea PostgreSQL 18 temporal con socket privado
y verifica su identidad antes de preparar datos sintéticos.

Frontend: `node tests/Frontend/navigation.test.mjs`, pruebas de identidad de sesión,
`test_system` con APIs interceptadas, `npm run typecheck` y `npm run build`.
Las pruebas de navegador comprueban la solicitud única y el recorrido login →
Dashboard. La instrumentación sigue desactivada por defecto y conserva sus pruebas.

No se midió Azure/Oracle ni se confirma una corrección de `08006`. Para comparar
tiempos, activar temporalmente el [diagnóstico](DIAGNOSTICO-RENDIMIENTO.md) y
correlacionar peticiones equivalentes. El cambio no resuelve el coste de conexión,
la contención del bloqueo global ni el crecimiento de historiales fuera del alcance.
