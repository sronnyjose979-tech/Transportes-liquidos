# F4, segunda etapa: límites de espera

Base: `360d26c3abe71f2cf932245b2262ddab383346a6`.

## Alcance y transacciones exteriores

Se revisaron los controladores y llamadas de BillingPayments,
ExpenseAdministration, InventoryLedger, PurchaseRequestAdministration y
PurchaseFulfillment. Los controladores llaman directamente al servicio, sin una
transacción exterior. PrivateReceiptStorage persiste el archivo antes del comando
SQL y reconcilia después. InventoryLedger::receive es deliberadamente interno a
PurchaseFulfillment: entrada, recepción y recibo conservan una sola transacción.
Taller conserva su ejecutor y sus límites existentes; no se altera su integración.

No se identificó una llamada de producción que envuelva estos cinco comandos y
adquiera bloqueos previamente. BoundedTransaction rechaza una transacción exterior
antes de ejecutar SQL o efectos: una integración futura debe revisar el orden de
bloqueos y la unidad atómica antes de incorporar ese uso. No se admite envolver
estos comandos en savepoints y convertir sus errores en un commit exterior.

## Comportamiento

`config/operations.php` fija 2000 ms para cada espera de bloqueo y 10000 ms para
cada sentencia mediante configuración local. Se reutiliza
`set_config(..., true)` de Taller, antes del advisory. Son límites locales; no se
cambia PostgreSQL global ni la sesión de otras operaciones. No constituyen un
presupuesto total de duración del comando. No se usa transaction_timeout, que
puede terminar la conexión y vuelve más compleja la confirmación del resultado.

Se conserva exactamente el orden de bloqueos existente, advisory 873210,
guardias, validaciones, autorización, restricciones, triggers y recibos.
El ejecutor hace un intento explícito con beginTransaction/commit de Laravel.
A diferencia de transaction(), commit() conserva el contador si PDO falla:
el ejecutor intenta rollback físico y lógico antes de traducir 55P03, 57014,
40P01 y 40001 a 409 sin SQL ni parámetros, también durante COMMIT diferido.
Si falla la limpieza, descarta la conexión sin sustituir el error original.
Las PDOException directas restantes descartan la conexión y reciben una respuesta
503 que pide conservar la clave y consultar el recibo. No se infiere rollback
por una desconexión: la confirmación puede haberse realizado. No hay reintentos
automáticos. El registro reconoce PDOException (incluidas QueryException y causas
anidadas) y conserva solo SQLSTATE validado y la correlación existente. Los errores
de integridad envueltos en QueryException siguen en los manejadores originales. Ante una respuesta perdida,
consultar el resultado y reenviar con la misma clave; no generar otra clave.

## Verificación y límites

BoundedTransactionTest comprueba con conexiones independientes la contención del
advisory en los cinco servicios, ausencia de efectos y recibos, timeout de sentencia
después de una escritura y rollback completo, restauración de configuración tras
commit/rollback, rechazo de transacciones exteriores y un deadlock real sin
reintento. Las suites de negocio existentes comprueban permisos, recibos,
reenvíos e integridad de saldos, movimientos y recepciones.

La configuración limita SQL, no esperas de archivos o código PHP. Una desconexión
al confirmar sigue requiriendo consultar el recibo; ninguna respuesta de error
prueba por sí sola que el cliente conozca el resultado de un commit interrumpido.
No se retiran guardias ni advisory, ni se cambia esquema o aislamiento.

Resultados ejecutados en PostgreSQL 18.6 temporal:

- Batería ampliada: 68 pruebas, 978 aserciones, todas satisfactorias. Filtro:
  `BoundedTransactionTest|BillingPaymentsTest|InventoryLedgerTest|PurchaseRequestTest|PurchaseFulfillmentTest|ExpenseApiTest|WorkshopMaterialsTest|WorkshopMaterialApiTest|AdministrativeReportsTest|AdministrativeAuditTest`.
  Artefactos: `/tmp/transporte-tests-9luRDt`.
- Tras añadir la prueba del recibo y comprobar explícitamente SQLSTATE 40P01:
  `BoundedTransactionTest`, 5 pruebas y 50 aserciones satisfactorias.
  Artefactos: `/tmp/transporte-tests-Y9ZdGe`.
- `vendor/bin/pint --test --dirty` y `git diff --check`: satisfactorios.

Las pruebas antiguas que envolvían comandos ahora confirman el comando primero y
mantienen el coordinador en una transacción independiente para comprobar que SQL
directo rival espera y que después sigue sin poder superar saldos o cantidades.
El fixture de conteo sintético usa las tres transacciones públicas del proceso,
sin envolverlas en otra transacción. No se cambió código ni esquema de producción.
Las migraciones se ejecutaron únicamente en los clústeres temporales recién creados,
que el script detuvo al finalizar. No se ejecutó la suite global ni E2E navegador.

## Corrección P2 de la auditoría independiente (23 de octubre de 2026)

Se confirmó el hallazgo contra Laravel instalado: transaction() propaga
PDOException de COMMIT y reduce el contador sin rollback explícito. La corrección
se limita al ejecutor, reconocimiento/sanitización de PDO, pruebas y este documento;
no modifica servicios, migraciones, advisory, guardias ni reglas de negocio.

Las pruebas nuevas insertan efectos y recibo de inventario antes de activar un
trigger de restricción INITIALLY DEFERRED. Cubren los cuatro SQLSTATE mediante
RAISE en COMMIT, cancelación real, espera real de advisory y violación 23514;
comprueban HTTP, estado PDO, límites restaurados, liberación del advisory de negocio,
rollback visible desde otra conexión y reenvío con la misma clave confirmado una vez.
Una terminación real del backend durante COMMIT verifica descarte y respuesta 503.
PDO/libpq puede identificar esa terminación como HY000 u 08006; ninguno se reintenta.
Dos inyecciones en PDO, usando persistencia real, cubren pérdida de acuse tras un
commit exitoso y fallo durante la limpieza. La primera conserva efectos/recibo y
el reenvío no duplica; la segunda descarta también callbacks pendientes de Laravel.
No se simula que una respuesta perdida pruebe ausencia de confirmación.

Límite observado durante la verificación: un pg_sleep(1) en el trigger diferido
no fue cancelado por statement_timeout=100ms. Se usa cancelación real del backend
para verificar 57014 durante COMMIT. Los valores configurados se conservan, pero
no se certifica un plazo máximo para todo el trabajo diferido de COMMIT ni para
la operación completa. Cambiar esa política queda fuera de esta corrección P2.

Validación de esta corrección, ejecutada en WSL con PostgreSQL 18.6 recién
inicializado, solo socket privado y sin TCP:

- `bash tests/run-postgres.sh --filter 'BoundedTransactionTest|BillingPaymentsTest|InventoryLedgerTest|PurchaseRequestTest|PurchaseFulfillmentTest|ExpenseApiTest|WorkshopMaterialsTest|WorkshopMaterialApiTest|AdministrativeReportsTest|AdministrativeAuditTest|QueryExceptionSafetyTest|RequestPerformanceTest'`:
  87 pruebas y 2407 aserciones satisfactorias, incluidas las 8 de BoundedTransactionTest.
  Artefactos sintéticos: `/tmp/transporte-tests-dYpHNK`.
- `vendor/bin/pint --test --dirty` y `git diff --check`: satisfactorios.
- No se ejecutaron suite global ni E2E navegador. Sin acceso a servidores remotos,
  bases existentes, push ni despliegue. Los primeros ensayos detectaron expectativas
  incorrectas del fixture, corregidas antes de la batería final satisfactoria.
