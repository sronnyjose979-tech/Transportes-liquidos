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
cada sentencia, incluido COMMIT y sus restricciones diferidas. Se reutiliza
`set_config(..., true)` de Taller, antes del advisory. Son límites locales; no se
cambia PostgreSQL global ni la sesión de otras operaciones. No constituyen un
presupuesto total de duración del comando. No se usa transaction_timeout, que
puede terminar la conexión y vuelve más compleja la confirmación del resultado.

Se conserva exactamente el orden de bloqueos existente, advisory 873210,
guardias, validaciones, autorización, restricciones, triggers y recibos.
El ejecutor hace un intento explícito. Laravel revierte la transacción antes de
traducir 55P03, 57014, 40P01 y 40001 a 409 sin SQL ni parámetros. Los errores de
integridad siguen en los manejadores originales. Ante una respuesta perdida,
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
