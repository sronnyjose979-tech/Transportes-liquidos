# Facturación administrativa de viajes y pagos

## Alcance aprobado e implementado

`/facturacion` conserva el acceso y navegación existentes. Permite registrar
precios manuales por viaje, aprobarlos, crear documentos administrativos internos
y registrar pagos parciales o totales. No se emiten documentos fiscales, impuestos,
números fiscales, descuentos, recargos, conversiones ni comprobantes legales.

Reglas de esta entrega:

- Monedas CRC y USD; cantidades monetarias decimales exactas con dos decimales.
- Precio manual por viaje, propuesta de administrador o secretaria. Solo el
  administrador aprueba/rechaza el importe exacto y su moneda.
- Viaje facturable: ejecución `completed`, finalización oficial registrada,
  importe aprobado y ninguna factura previa del viaje.
- Una factura puede agrupar viajes finalizados del mismo cliente y moneda.
  Cada línea representa un viaje completo: cantidad 1, descripción del servicio
  y su importe aprobado. No se deducen tarifas de litros ni otros conceptos.
- La solicitud de factura identifica la versión exacta de precio aprobado;
  no se toma silenciosamente un importe diferente al revisado por el usuario.
- Las nuevas versiones de precio conservan las anteriores. Una propuesta
  pendiente o rechazada no sustituye el último importe aprobado. La nueva
  aprobación sustituye ese importe únicamente antes de facturar.
- Facturas confirmadas, detalles, decisiones y pagos son inmutables.
- RN-TR-33 permite múltiples pagos parciales hasta saldo cero. Cada pago es
  positivo, usa la moneda de la factura y no supera su saldo.
- Precio cero permitido: la factura conserva aprobación y detalle, con saldo
  cero y estado derivado `paid`, sin inventar un pago. Los pagos cero se rechazan.

## Persistencia e integridad

Una nueva migración incremental: `2026_10_14_000360_create_administrative_billing.php`.
No modifica migraciones publicadas ni datos históricos existentes.

| Tabla nueva | Propósito |
|---|---|
| `billing_trip_amounts` | Propuestas/versiones inmutables: viaje, importe, moneda, antecedente, motivo, usuario y fecha. |
| `billing_amount_decisions` | Decisión única por propuesta; actor administrador, fecha y motivo. |
| `billing_invoices` | Documento interno, cliente real y nombre histórico, moneda, motivo, actor y fecha. |
| `billing_invoice_items` | Referencia única al viaje y precio aprobado; concepto histórico, cantidad 1 e importe exacto. |
| `billing_payments` | Pagos recibidos: factura, importe, moneda, fecha, descripción opcional del medio, motivo y actor. |
| `billing_operations` | Resultado original e identidad de operación idempotente; actor, alcance, clave y hash del contenido. |
| `billing_guard` | Guardia técnica para serialización y detección de snapshots obsoletos; no entidad empresarial. |

Entidades nuevas UUID; FK de usuarios bigint, reutilizando `User`, roles,
clientes, solicitudes y viajes. No se duplican catálogos. PostgreSQL protege:

- Monedas, precisión, importes y referencias exactas, incluso ante SQL directo.
- Viaje finalizado, cliente de su solicitud y precio aprobado al confirmar factura.
- Un único detalle por viaje globalmente; no se puede facturar nuevamente con
  otro texto de concepto. Detalles de facturas confirmadas no admiten anexos.
- Decisiones exclusivas del administrador y actores activos de oficina.
- Cadena de revisiones por viaje, sin ramas, antecedentes ajenos o reutilización.
- Pagos sin sobrepasar el total y con moneda idéntica, sin existencias de anticipos.
- Auditoría idempotente obligatoria mediante triggers diferidos y resultado
  exacto vinculado al registro confirmado. UPDATE, DELETE y TRUNCATE históricos
  están protegidos.

Los servicios usan transacciones, el coordinador común `873210`, guardias con
versiones y bloqueo de usuarios/roles. La factura bloquea el viaje al validar
su estado; cada pago bloquea la factura. Los triggers vuelven a validar cuotas.
En aislamiento REPEATABLE READ, las guardias impiden usar un snapshot antiguo.
Los conflictos concurrentes deben consultarse antes de reenviar; no se ocultan.

El saldo y estado se derivan de detalles y pagos confirmados: `pending`,
`partially_paid`, `paid`. No hay contadores financieros modificables que puedan
quedar desincronizados ni cambios de estado independientes de los pagos.

### Precisión técnica

PHP utiliza cadenas decimales e enteros de centavos, sin `float`. Cada importe
admite hasta 12 dígitos enteros y dos decimales; hasta 100 viajes por documento,
con límite equivalente en PostgreSQL. Son límites técnicos de la entrada,
no tarifas ni límites comerciales. El total máximo permanece dentro de int64.
PostgreSQL utiliza `numeric` con CHECK de precisión, evitando el redondeo
silencioso de una columna con typmod. El cliente recibe montos como cadenas.

Las entradas monetarias deben tener hasta dos decimales; una entrada de mayor
precisión se rechaza para no alterar el importe registrado. `Money::roundHalfUp`
implementa redondeo decimal exacto si corresponde un cálculo explícito. Esta
entrega suma importes ya expresados en centavos; no requiere redondear esas
sumas. No se usa redondeo bancario ni conversión binaria de montos.

## API y permisos

Todos los endpoints utilizan sesión Laravel, cuenta activa, CSRF en escrituras
y las autorizaciones existentes. Gates `manage-billing` y `approve-trip-amount`
reutilizan `TransportCorePolicy`; no se crean roles.

| Endpoint | Función | Acceso |
|---|---|---|
| `GET /api/billing/trips` | Viajes, precios/versiones, aprobaciones, factura relacionada y elegibilidad. Filtros cliente, ejecución y `pending_only`, paginación. | Administrador/secretaria |
| `POST /api/billing/trips/{trip}/amounts` | Registrar precio o revisión con `supersedes_id`. | Administrador/secretaria |
| `POST /api/billing/amounts/{id}/decisions` | Aprobar/rechazar propuesta exacta. | Administrador |
| `GET /api/billing/invoices` | Filtrar por cliente, moneda, estado derivado y fechas de registro en Costa Rica; paginación. | Administrador/secretaria |
| `POST /api/billing/invoices` | Crear factura: cliente, líneas con viaje/precio aprobado/concepto, motivo y clave. | Administrador/secretaria |
| `GET /api/billing/invoices/{id}` | Detalles, total, pagado, saldo e historial de pagos. | Administrador/secretaria |
| `POST /api/billing/invoices/{id}/payments` | Registrar pago exacto, moneda, fecha con offset, motivo y medio opcional. | Administrador/secretaria |

Los identificadores de actor enviados por el cliente se rechazan. Conflictos de
clave/concurrencia/duplicación producen 409; datos incompatibles 422; sesión
expirada 401; permisos 403; recursos ausentes 404; CSRF 419. Los errores privados
no exponen SQL ni importes en bindings de logs; las respuestas no se cachean.

Idempotencia: cada escritura exige UUID `request_key`; alcance, usuario y hash
canónico del contenido vinculan la clave a una operación. El mismo reenvío
retorna sus identificadores originales, aunque el saldo o elegibilidad hayan
cambiado después. Una clave reutilizada con otro contenido devuelve 409. Los
recibos permanecen con la auditoría; no se define una expiración arbitraria.

## Interfaz y recuperación

Vue en `resources/js/modules/BillingAndPayments/`:

- `BillingApp`: permisos, confirmaciones, mensajes, sesión y comandos inciertos.
- `TripsPanel`: búsqueda de clientes, viajes facturables, propuestas, revisiones,
  aprobación administrativa, historial y creación agrupada de facturas.
- `InvoicesPanel`: consultas, filtros, detalle histórico y pagos parciales/totales.
- `contracts.ts`: contratos tipados y presentación monetaria basada en cadenas.

El wrapper existente de System integra esta interfaz sin rehacer dashboard ni
navegación. Diseño azul/blanco, formularios accesibles y tablas con scroll en
pantallas pequeñas. No hay cambios optimistas ni reintentos automáticos.

Una respuesta perdida no se interpreta como operación fallida. El comando
conserva contenido y clave en memoria por usuario, bloqueando otro envío y
navegación que descarte el pendiente. El reintento es explícito y revalida sesión,
identidad y roles. Si la sesión expira, el formulario de acceso en la misma
página conserva el comando para el usuario original. Una recarga completa puede
perder esa memoria; la interfaz advierte antes de salir. No se guardan datos
financieros en localStorage, IndexedDB ni almacenamiento offline.

## Límites y decisiones pendientes fuera del circuito aprobado

- Correcciones después de facturar, anulación de facturas y reversión de pagos:
  necesitan un procedimiento compensatorio aprobado. No existen endpoints
  que editen o borren registros confirmados; no se impide la consulta histórica.
- Cobros de viajes cancelados (RN-TR-37/38): excluidos; esta entrega factura
  únicamente viajes finalizados conforme a la regla nueva.
- Fiscalidad, impuestos, numeración, emisión electrónica, notas legales: excluidos.
- Anticipos, conversiones, crédito automático, vencimientos y reglas bancarias:
  no implementados ni inferidos.
- No existe catálogo de métodos de pago. Se admite una descripción administrativa
  opcional, sin reglas bancarias. El catálogo configurable de RN-TR-34 sigue
  como integración posterior, sin simular validaciones bancarias.

Entorno validado con PHP 8.4.24, compatible con Laravel13. No se afirma prueba
bajo PHP8.5 ni se instalan dependencias nuevas. Se mantienen `.env` y
`transporte_db` intactos. Las migraciones solo se ejecutan en clústeres temporales
PostgreSQL18 de `tests/run-postgres.sh`.

## Cómo probar

1. Finalizar un viaje mediante el circuito de Transporte ya existente.
2. Secretaria registra su importe/moneda en `/facturacion`, **Importes y viajes**.
3. Administrador revisa la propuesta y aprueba con motivo.
4. Secretaria selecciona el viaje facturable, registra concepto y crea el
   documento interno; puede agrupar otros viajes del mismo cliente y moneda.
5. En **Facturas y pagos**, consulta el documento y registra pagos parciales
   hasta su saldo exacto. El botón **Utilizar saldo completo** copia el saldo
   recibido del servidor, que se vuelve a validar al confirmar.

Pruebas reales reproducibles:

```bash
bash tests/run-postgres.sh
npm run typecheck
npm run build
npm run test:frontend
npm run test:frontend-api
CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-billing
vendor/bin/pint --test
git diff --check
```

Resultados reales se agregan al terminar la validación. Las pruebas frontend
con APIs interceptadas se reportan por separado de E2E con Laravel/PostgreSQL.

### Resultados ejecutados

| Comprobación | Resultado real |
|---|---|
| Suite backend completa, PostgreSQL18 aislado | 348 pruebas y 3.385 aserciones aprobadas, 131,976 s. |
| Backend específico de facturación | 14 pruebas y 149 aserciones aprobadas; incluyen dos conexiones PostgreSQL, SQL directo e importes agregados mayores a 2^53. |
| Suite frontend completa con APIs interceptadas | 76 pruebas aprobadas, 143,080 s. |
| Verificación final de interfaz de facturación | 8 pruebas aprobadas, 11,211 s; incluye las dos regresiones añadidas después de la suite completa: etiquetas exactas de moneda y evitar segundo clic tras confirmar un pago. |
| Interfaz compartida del inicio, verificación posterior | 4 pruebas aprobadas, 4,587 s. |
| Contratos/API frontend | 23 pruebas aprobadas. |
| E2E facturación real | 3 pruebas aprobadas, 31,178 s, Chromium + Laravel + PostgreSQL18, sin respuestas API simuladas. |
| E2E navegación real de regresión | 4 pruebas aprobadas, 33,641 s. |
| TypeScript y build | Aprobados; compilación Vite8. Aviso opcional de fuente `fontaine` preexistente, sin instalar paquetes. |
| Pint y diff check | Aprobados. |

E2E de facturación verifica importe y aprobación exclusiva, factura interna,
rechazo de duplicación, pagos CRC parciales y totales, respuesta confirmada
perdida, expiración de sesión durante recuperación con la misma clave, pagos
USD exactos (0.10 + 0.20 = 0.30), rechazo de moneda ajena y dos sesiones HTTP
simultáneas sin sobrepago. Las pruebas mantienen datos sintéticos y archivos de
trazas aislados; nunca se ejecutan sobre `transporte_db`.

Defectos de interfaz detectados en validación: etiquetas ambiguas de selectores
y formulario de pago que permanecía lleno después de una confirmación. Se
corrigieron con nombres accesibles explícitos, limpieza de campos tras confirmar
y deshabilitación durante consulta del estado. No se relajaron restricciones
monetarias ni de PostgreSQL para conseguir resultados positivos.
