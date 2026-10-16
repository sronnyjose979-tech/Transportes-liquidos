# 14. Glosario

## ACID

Propiedades de transacciones: atomicidad, consistencia, aislamiento y durabilidad.

## Actor

Usuario responsable de una operación; el servidor lo obtiene de la sesión.

## Advisory lock

Candado explícito de PostgreSQL identificado por una clave; el transaccional termina con commit/rollback.

## Agregación

Cálculo sobre varias filas, como SUM o COUNT. Un join uno-a-muchos puede multiplicarlo indebidamente.

## API

Contrato para intercambiar operaciones/datos entre programas; aquí normalmente HTTP y JSON.

## Aplicación de autorización

Hecho que consume una decisión aprobada para una operación/contenido concreto.

## Autenticación

Comprobación de identidad de una cuenta.

## Autorización

Decisión sobre permiso para una operación/recurso; no equivale a autenticación.

## Backend

Programa del servidor que procesa HTTP, reglas y persistencia.

## Backup / respaldo

Copia destinada a recuperar datos; necesita verificación y ensayo de restauración.

## Binding

Valor que acompaña a una consulta parametrizada; puede contener datos sensibles y no debe registrarse crudo.

## Blade

Motor de plantillas HTML de Laravel; entrega raíces donde se monta Vue.

## Blob / File

Bytes binarios y objeto de archivo del navegador; pueden persistirse en IndexedDB para comprobantes pendientes.

## Bloqueo de fila

Protección temporal de una fila frente a cambios incompatibles, por ejemplo SELECT FOR UPDATE.

## Caché

Copia temporal para reutilizar lecturas/recursos; no reemplaza el dato canónico.

## Canonicalización

Normalización estable de contenido antes de comparar huellas de reintentos.

## Cardinalidad

Cantidad de filas relacionadas: uno-a-uno, uno-a-muchos o muchos-a-muchos.

## CHECK

Restricción PostgreSQL que exige una condición sobre valores.

## Checksum SHA-256

Huella de bytes para detectar cambios; por sí sola no autentica un manifiesto frente a un atacante.

## Cifrado

Transformación reversible con clave para proteger confidencialidad.

## Cola

Conjunto ordenado de trabajos/capturas pendientes; la cola local PWA no es la tabla jobs de Laravel.

## Commit

Confirmación de una transacción de base de datos; en Git significa un registro versionado de cambios, distinto concepto.

## Compensación

Nueva operación que contrarresta un efecto sin borrar el hecho original.

## Concurrencia

Ejecución simultánea que puede observar/alterar el mismo estado.

## Constraint

Restricción de integridad: PK, FK, UNIQUE, CHECK o reglas diferibles.

## Controller

Adaptador HTTP: recibe entrada validada, llama caso de uso y devuelve respuesta.

## Cookie

Dato administrado por el navegador para el origen; la sesión usa su cookie para identificar contexto.

## CRC / USD

Códigos de moneda del colón costarricense y dólar estadounidense. No se suman ni convierten automáticamente.

## CSRF

Ataque que induce operaciones con la sesión de otro sitio; token de Laravel protege escrituras.

## Custodia

Cantidad entregada a un mecánico/orden y aún no declarada consumida ni devuelta.

## Deadlock

Ciclo de espera entre transacciones; PostgreSQL aborta una para resolverlo.

## Deferred / diferible

Restricción cuya verificación puede posponerse al final de la transacción.

## Delta

Variación positiva o negativa; el ledger suma deltas de bodega/custodia/consumido.

## DOM

Representación de la página HTML que Vue actualiza.

## Dominio

Área de reglas y conceptos del negocio, como Taller o Facturación.

## E2E

Prueba de extremo a extremo con navegador y componentes reales.

## Eloquent

Capa de Laravel para representar tablas mediante modelos y relaciones.

## Enum

Conjunto limitado de valores permitidos, como estados o monedas.

## Estado derivado

Valor calculado a partir de hechos; por ejemplo pagada cuando saldo es cero.

## FK

Clave foránea que obliga a referenciar una fila existente.

## Float

Número de coma flotante binaria; no se usa para cálculos monetarios exactos.

## Form Request

Clase Laravel que autoriza/valida una petición antes del controlador.

## Frontend

Interfaz ejecutada en el navegador.

## Gate

Condición de autorización nombrada en Laravel.

## Guardia técnica

Fila/contador usado para coordinar escrituras; no es un estado comercial.

## Hash de contraseña

Representación unidireccional usada para verificar contraseña sin guardarla en claro.

## HMAC

Código de autenticación del mensaje calculado con clave; protege integridad/autenticidad del manifiesto.

## HTTP

Protocolo de peticiones/respuestas con métodos, cabeceras y códigos.

## HTTPS

HTTP protegido por TLS; necesario para confidencialidad y APIs seguras del navegador.

## Idempotencia

Repetir la misma intención produce el resultado original sin duplicar efectos.

## IndexedDB

Almacenamiento transaccional local del navegador para objetos y archivos.

## Inmutable

Hecho que no se sobrescribe ni borra; corrección mediante versión o compensación.

## Join

Combinación SQL de filas de tablas relacionadas.

## JSON / jsonb

Formato de intercambio; jsonb es su tipo estructurado en PostgreSQL.

## Ledger

Libro de movimientos confirmados cuyo conjunto permite derivar saldos.

## Locale / collation

Reglas de idioma/ordenamiento del clúster; deben ser compatibles al restaurar.

## Lock

Bloqueo de coordinación; no confundir un Web Lock del navegador con un lock PostgreSQL.

## Log

Registro técnico de diagnóstico; no garantiza auditoría empresarial completa.

## Middleware

Código que interviene antes/después del controlador, como sesión, cuenta activa o traductor de errores.

## Migración

Cambio versionado del esquema y sus funciones/restricciones.

## MIME

Tipo de contenido detectado, por ejemplo application/pdf; no basta la extensión del nombre.

## MVC

Separación de modelo, vista y controlador; se complementa con servicios de aplicación.

## NUMERIC

Tipo decimal exacto de PostgreSQL; precisión/escala se muestran en el diccionario.

## Offset

Diferencia horaria explícita respecto a UTC; Costa Rica usa -06:00.

## Paginación

División de resultados en páginas con orden estable y metadatos.

## PK

Clave primaria que identifica de forma única una fila.

## Policy

Clase de reglas de autorización vinculadas a recursos.

## PWA

Aplicación web progresiva; instalación/cache no implican sincronización cerrada.

## Query Builder

Constructor de consultas parametrizadas Laravel sin exigir un modelo por tabla.

## Reactivo

Estado cuya modificación actualiza automáticamente consumidores en Vue.

## Recibo idempotente

Persistencia de clave, alcance, contenido/huella y resultado de una operación.

## Recurso / Resource

Representación JSON explícita que evita exponer todo el modelo.

## Responsive

Interfaz que se adapta al ancho sin volver inaccesibles acciones/contenido.

## Rollback

Reversión de escrituras de una transacción; no revierte por sí solo archivos externos.

## ROUND_HALF_UP

Redondeo en el que la mitad se aproxima alejándose de cero; Money lo implementa sin float.

## RPO / RTO

Pérdida máxima de datos por antigüedad y tiempo objetivo de recuperación, respectivamente.

## Savepoint

Punto interno de reversión parcial; una transacción anidada no confirma independientemente todo el caso de uso.

## Scheduler

Planificador Laravel; necesita invocación periódica desde el servidor.

## Service worker

Script del navegador que controla recursos dentro de un ámbito y permite shell offline.

## Sesión

Contexto autenticado mantenido por Laravel y su cookie; no es el marcador local de la PWA.

## Snapshot

Copia del estado/datos observados; puede preservar historia o una lectura transaccional consistente.

## SQLSTATE

Código normalizado del fallo SQL; permite diagnóstico sin imprimir consulta/bindings.

## Staging

Área temporal de una operación, por ejemplo archivo pendiente o dump antes de cifrar/publicar.

## Transacción

Conjunto de cambios que confirman o se revierten juntos.

## Trigger

Función activada por PostgreSQL al cambiar datos; protege integridad fuera de HTTP.

## TypeScript estricto

Configuración que exige comprobaciones de tipos más rigurosas durante desarrollo/compilación.

## UNION ALL

Combinación de resultados sin deduplicación implícita; Auditoría elige fuentes para no duplicar hechos.

## UUID

Identificador de 128 bits usado para entidades nuevas y claves de intención.

## Validación optimista

Verificar versión esperada para rechazar cambios basados en estado antiguo.

## Web Locks

API del navegador para coordinar pestañas del mismo origen.

## Worker

Proceso que ejecuta trabajos de una cola; no es el service worker del navegador.

## XSS

Ejecución de contenido no confiable como código dentro del origen de la aplicación.

## Índice

Estructura que facilita búsquedas; UNIQUE también impide duplicados de una combinación.
