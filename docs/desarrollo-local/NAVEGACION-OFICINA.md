# Navegación persistente de oficina · fase 1

Alcance: `/dashboard`, `/transporte` y `/administracion/flota`. Las demás rutas,
incluida `/mis-viajes`, conservan navegación de documento. No se cambian API,
reglas de negocio, persistencia ni procesos offline.

## Límites y estructura

- `OfficePage` (Acceso y personal): representación HTML o contexto JSON después
  de los middleware y Gates de la ruta existente. No concede permisos.
- `resources/views/office.blade.php`: raíz y contexto inicial autorizado,
  codificado de forma segura, con respuesta privada sin caché.
- `resources/js/modules/Office`: contrato de contexto, registro de rutas,
  coordinación de transiciones y raíz persistente. Vue Router gestiona historial.
- Los módulos reciben la sesión validada al entrar y registran sus bloqueos de
  salida mediante un contexto inyectado. Conservan validaciones de escrituras.
- `SessionBoundary` permanece por encima de la oficina: mantiene verificación
  por foco, pestañas y cambios de identidad. No hay caché global de permisos.

## Contrato nuevo de representación

GET a una de las tres rutas con `X-Office-Navigation: 1`, `Accept: application/json`
y, cuando existe identidad conocida, `X-Session-User`. Devuelve `data` con `page`,
`session` (`id`, `login`, `roles`) y `navigation` (`home`, `modules`). Se ejecutan
autenticación, cuenta activa, sesión, identidad esperada y el Gate del destino.
Sin esa cabecera se entrega HTML para acceso directo y recarga.

El contexto se obtiene de nuevo en cada cambio de módulo; no se guarda en
localStorage ni en el service worker. Una transición no necesita consultar luego
`/api/session` ni `/api/navigation`. Las consultas de los datos del módulo siguen
autorizándose en sus API actuales.

## Salida y fallos

No se abandona un módulo durante una escritura o con resultado incierto. Los
formularios modificados requieren confirmación para descartar cambios al salir
del módulo. Los cambios de módulo por atrás, adelante y enlaces usan las mismas
protecciones. Una respuesta tardía no puede
instalar el contexto de una transición cancelada. Los errores de sesión/acceso
ocultan el contenido privado; los errores de red conservan el módulo de origen.
Los módulos se desmontan al salir; no se utiliza `KeepAlive` con datos privados.

## Extensión

Para incorporar otro módulo: conservar su Gate en Laravel, usar `OfficePage`
para su representación, registrar ruta/componente en Office, consumir la sesión
de entrada y registrar los bloqueos del módulo. No añadir otra raíz, gestor de
sesión ni lógica de historial. El conductor continúa fuera de este registro.

## Verificación y límites

- PostgreSQL 18 temporal: 43 pruebas distintas de rutas, permisos, identidad,
  revocación, instrumentación y contratos del conductor, incluidos los nuevos
  casos de `OfficeNavigationTest`. No se accede a bases reales.
- 38 pruebas Node de contratos, identidad y reutilización de navegación.
- 11 pruebas de navegador de oficina: documento persistente, contexto autorizado
  por transición, ausencia de `/api/session` y `/api/navigation` redundantes,
  atrás/adelante, recarga, formularios, escritura incierta, sesión expirada,
  cambio de cuenta, foco, recuperación de red y respuestas tardías. La integración
  de query strings conserva filtros y la solicitud seleccionada al crear viajes.
- 16 pruebas de navegador de regresión: páginas tradicionales, historial de
  Transporte, cierre con pendientes, respuesta perdida y almacenamiento offline.
- Tipos, Pint y compilación frontend satisfactorios.

Las verificaciones legítimas por foco/pestañas y las comprobaciones previas a
escrituras siguen consultando la sesión. El contenido de un módulo se desmonta
al abandonarlo; sus consultas de datos vuelven a ejecutarse al regresar. Los
formularios modificados requieren confirmación conservadora de salida, que
puede aparecer también después de guardar si hubo entradas de formulario.

No se midió latencia en Azure. La compilación avisa del bundle principal de unos
510 kB (147 kB gzip), además de los avisos existentes de fuentes opcionales y una
importación dinámica inefectiva. No se cambia la política de caché/actualización
de la PWA para resolver ese tamaño. Esta fase no hace push ni despliegue.
