# Backend inicial

Módulos bajo app/Modules/{AccessAndPersonnel,FleetAndDocuments,TransportAndDispatch,Inventory,Purchasing}.
Cada módulo agrupa Models y Http según necesidad; no se crean capas vacías.
Cada catálogo tiene controlador en su módulo y comparte solo mecánica HTTP básica; los futuros procesos
se implementarán en servicios de aplicación de su módulo, sin CRUD genérico de negocio.

API JSON bajo /api usa middleware web, sesión y CSRF: obtener /api/csrf primero,
enviar X-XSRF-TOKEN y cookie de sesión en escrituras. POST /api/login recibe login
y password; GET /api/session consulta identidad; POST /api/logout invalida sesión.
Sin registro público, usuarios iniciales ni credenciales generadas automáticamente.
Roles técnicos: administrator, secretary, driver, mechanic. Solo administrator activo
accede a los catálogos mientras se define la matriz detallada de otros actores.
No se ofrecen eliminaciones ni registro público. Administración de cuentas y empleados solo para administrator activo.

Compatibilidad: users conserva la PK bigint del esqueleto; user_roles y employees
usan FK del mismo tipo. No es conformidad total con la propuesta UUID de User:
la conversión requiere inspeccionar despliegue/datos y un plan separado aprobado.
login se añade nullable para no inventar nombres de acceso de usuarios existentes;
is_active=false exige activación explícita. password/email existentes se conservan.
Los nuevos catálogos, roles y empleados tienen UUID, sin timestamps de negocio añadidos.

Migraciones listas para revisión, no aplicadas a transporte_db. Las pruebas usan
una conexión pgsql_testing separada, con TEST_DB_* y base transporte_test, comprobada
antes de migrar. Nunca reutilizar credenciales o DB_URL del entorno real.
Facturación, reservas, transbordos, stock y procedimientos pendientes no se implementan.

Verificación reproducible (PostgreSQL 18 local instalado):

```sh
bash tests/run-postgres.sh
vendor/bin/pint --test
```

El script crea un clúster UTF-8 exclusivo bajo /tmp, sin TCP, con socket privado,
credenciales de prueba y base transporte_test. Ejecuta PHPUnit, detiene el servidor
al terminar y conserva artefactos para inspección. No conecta con transporte_db.
No ejecuta composer setup ni instala dependencias. Las credenciales de aplicación
no se leen para construir esta conexión. Los límites de longitud y paginación
HTTP son límites técnicos de entrada, no unicidad o reglas comerciales nuevas.

## Segunda etapa: acceso y personal

GET/POST /api/users, GET/PATCH /api/users/{id}, PATCH /api/users/{id}/activation
con is_active explícito. GET /api/roles lista roles existentes permitidos; roles en
creación/actualización se envían como lista de UUID existentes. GET/POST /api/employees
y GET/PATCH /api/employees/{uuid} administran name, is_active y user_id opcional único.
Las cuentas conservan name/email por compatibilidad; no se copian ni sincronizan
con Employee.name, que es la fuente del nombre del trabajador.

Primera cuenta: php artisan access:bootstrap-admin solicita confirmación y contraseña
oculta, sin opciones de contraseña en argumentos. Ejecutarlo solo en el destino
administrativo autorizado y después de migraciones revisadas. No se ejecuta automáticamente.
El bootstrap crea los cuatro roles confirmados sin usuarios adicionales. Una vez existe cualquier administrador, el comando no crea otro ni sirve para recuperación.
No crea empleados ni asigna roles por características del empleado.

Las escrituras de acceso/personal se serializan mediante advisory lock transaccional
873210; revalidan administrador vigente dentro de la transacción. FK/UNIQUE preservan
vínculos. Los registros de auditoría incluyen actor, destino y campos afectados,
nunca contraseñas. No constituyen todavía un libro de auditoría de negocio durable.
Contraseñas tienen confirmación y límites técnicos de 12 caracteres/72 bytes;
se almacenan con hashing configurado Laravel. Cambio de contraseña invalida otras
sesiones mediante auth.session; desactivación bloquea siguientes peticiones y elimina
sesiones persistidas en el driver database.

Pendientes: matriz de permisos adicional, recuperación de acceso si no quedan
administradores activos por operaciones externas al servicio y futuras
reglas de asociación empleado/funciones. No se impone que empleado inactivo desactive
su cuenta: son atributos distintos y esa dependencia no está aprobada.

## Correcciones de seguridad

La regla aprobada impide desactivar o quitar el rol al último administrador activo.
AccountAdministration revalida actor, roles y existencia de otro administrador
activo dentro de la transacción con advisory lock 873210; todas las escrituras
administrativas HTTP de cuentas, empleados, catálogos y flota usan ese bloqueo.
Con READ COMMITTED, quien espera vuelve a comprobar los datos confirmados antes
de escribir. Error 422 en is_active o roles conserva toda la operación anterior.
El bloqueo requiere usar estos servicios: SQL administrativo externo puede eludir
la regla del último administrador y no forma parte del contrato de la API.

EnsureActiveAccount recarga la cuenta antes de auth.session por prioridad de
middleware. Así una identidad ya cargada no conserva estado activo ni contraseña
obsoletos. Los Gates consultan roles vigentes en PostgreSQL; quitar administrator
revoca acciones administrativas de sesiones existentes sin inventar un gestor de
sesiones. La sesión puede continuar para operaciones no administrativas mientras
la cuenta permanezca activa. Se conserva hashing de contraseña de Laravel,
rotación de remember_token y comprobación de password_hash_web de auth.session.
La revalidación dentro de las escrituras serializa también acciones que compiten
con una revocación. Los vínculos de empleados se conservan sin cambiar su validación:
UUID excluido en actualización, cuenta opcional, UNIQUE y FK en PostgreSQL.

## Tercera etapa: flota

Decisiones aprobadas explícitamente por el usuario para esta etapa, complementando
el borrador de docs/ sin aprobar sus demás propuestas:

- FleetAsset, TractorUnit, Tanker y Compartment pertenecen a FleetAndDocuments.
  Cada activo tiene exactamente una especialización coherente con type.
  TractorUnit/Tanker usan fleet_asset_id como PK/FK compartida; los demás IDs son UUID.
- FleetAsset.code es obligatorio, editable y único en toda la flota.
  service_status admite available, maintenance y out_of_service; inicialmente available.
  Es condición de servicio y no disponibilidad para asignaciones. Estos valores
  aprobados sustituyen, para este campo, los estados de activo propuestos en docs/.
- Una cisterna tiene al menos un compartimento; sin divisiones físicas, exactamente
  uno lógico. has_physical_partitions e is_active de cada compartimento deben
  indicarse explícitamente al crear, sin defaults.
- Compartment.code es obligatorio y único por tanker_id. capacity_liters es
  NUMERIC(12,2), obligatorio y positivo. La capacidad total suma todos los
  compartimentos, incluidos los inactivos, y se deriva sin campo persistido adicional.
- Solo se actualizan código del activo y códigos/capacidades de compartimentos.
  Tipo, estado de servicio, divisiones físicas, is_active, IDs y relaciones se
  conservan. No se agregan ni retiran compartimentos mediante PATCH.

Estructura física: app/Modules/FleetAndDocuments/Models contiene los cuatro modelos;
Http/Requests valida entradas, Http/Resources representa salidas y
Http/Controllers expone cabezales/cisternas. Application/FleetAdministration
coordina autorización, transacciones y escritura del agregado; save() mantiene el
límite transaccional y delega creación/actualización en dos métodos privados. No se crean capas
vacías, ni documentos, asignaciones, mantenimiento, despacho o facturación.

Contrato JSON por sesión/CSRF existente, provisionalmente solo administrator activo:

| Método | Ruta | Entrada |
| --- | --- | --- |
| GET | /api/tractor-units y /api/tankers | page/per_page; default 25, máximo técnico 100. |
| POST | /api/tractor-units | code. |
| POST | /api/tankers | code, has_physical_partitions, compartments con code, capacity_liters e is_active. |
| GET | /api/tractor-units/{uuid} y /api/tankers/{uuid} | Consulta por UUID del activo. |
| PATCH | /api/tractor-units/{uuid} | code opcional. |
| PATCH | /api/tankers/{uuid} | code opcional y/o lista parcial compartments: id existente de esa cisterna, code/capacity_liters opcionales. |

type usa los identificadores técnicos tractor_unit/tanker determinados por endpoint.
Resources devuelven data con id, code, type, service_status; cisternas incluyen
has_physical_partitions, compartments y total_capacity_liters. Cantidades se
representan como cadenas decimales con dos posiciones. La consulta de carga anticipada
de compartimentos usa SUM(capacity_liters) OVER (PARTITION BY tanker_id)::text:
PostgreSQL suma NUMERIC sin limitar el total a NUMERIC(12,2), sin coma flotante.
Total y detalles provienen de la misma consulta/snapshot; no hay consultas adicionales
por cisterna ni campo persistido de total. El alias derivado es interno y el Resource
solo presenta el total de la primera fila (siempre hay al menos un compartimento).
La ventana repite ese valor en las filas del detalle, con un pequeño coste de transferencia;
solo procesa los compartimentos de las cisternas cargadas. Listados
incluyen links/meta. Respuestas privadas llevan no-store. Errores: 401 sin sesión,
403 sin autorización, 404 para UUID inexistente/tipo distinto, 419 por CSRF y
422 por validación, unicidad o configuración inválida. No se exponen excepciones SQL.
Los límites de texto de 255 son técnicos; PostgreSQL preserva unicidad exacta
según su comparación de texto, sin normalización comercial adicional inventada.
La API rechaza más de dos decimales, evitando redondeo implícito al persistir.

Integridad: NOT NULL, FK sin cascadas, UNIQUE global/per cisterna, CHECK de valores
de servicio/tipo/códigos no vacíos/capacidad positiva. Constraint triggers diferidos
validan totalidad/XOR, coherencia con type y cardinalidad de compartimentos al final
de la transacción, permitiendo crear todas sus filas atómicamente. La aplicación
fuerza su comprobación antes de responder, conservando la validación al commit
para SQL directo. Los cambios de hijos escriben la raíz mediante UPDATE sin cambio
de ID para serializar escritores del mismo agregado: READ COMMITTED revisa el
estado final; REPEATABLE READ/Serializable con snapshot obsoleto puede abortar con
40001, sin aceptar un agregado incoherente. TRUNCATE de tablas hijas se bloquea
para no eludir triggers de fila. Las garantías no cubren operaciones privilegiadas
que deshabiliten triggers o alteren DDL. SQL libre que toca varios agregados
puede sufrir deadlock; ordenar UUID de raíces y reintentar solo unidades seguras.
Los endpoints actuales tocan un agregado por transacción y no añaden reintentos.

Migración nueva, sin modificar anteriores: crea únicamente tablas/funciones/triggers
de flota. Índices respaldan PK, unicidad de activo y de compartimentos; el índice
compuesto tanker_id/code cubre consultas por cisterna. Se revisó y ejecutó solo en
PostgreSQL 18 temporal de pruebas. No se aplicó a transporte_db. La creación toma
locks de DDL sobre los objetos nuevos; down elimina esas tablas y sus datos, por
lo que no se ha ejecutado ni debe usarse como recuperación de datos desplegados.
Antes de despliegue se requiere destino autorizado, revisión de nombres de funciones
y recuperación aprobada conforme a AGENTS.md.

Pendientes fuera de esta etapa: documentos obligatorios, disponibilidad derivada,
asignaciones, cambios de configuración y estados, historial de capacidad al cargar
y matriz detallada de permisos. La edición de capacidades está autorizada para la
administración básica actual; futuros procesos de carga deben definir conservación
histórica antes de permitir cambios sobre compartimentos utilizados.

## Etapa 4.1: persistencia y dominio documental de flota

Aprobación específica de esta etapa: documentación independiente por cabezal/cisterna,
renovaciones mediante registros nuevos, vigencia derivada y advertencias sin bloqueo
técnico de asignación. Esta última decisión sustituye para flota el bloqueo por
vencimiento del borrador de docs/; no representa autorización legal para circular.
No hay integración con despacho en esta etapa.

FleetAndDocuments incorpora DocumentType y Document en Models y el enum
Domain/DocumentValidity. Document pertenece directamente a FleetAsset, DocumentType
y User (uploaded_by_id bigint compatible con users.id). No se crea DocumentLink:
un registro tiene un activo obligatorio; las siete referencias del borrador y el
archivo compartido entre destinos no se implementan. Los nuevos IDs son UUID.
DocumentType contiene name, tracks_expiration, required_for_tractor_units y
required_for_tankers, todos explícitos. No se impone unicidad comercial del nombre
ni se crean tipos iniciales mediante seeders. DEKRA, marchamo y permisos de transporte
son tipos reconocidos; pesos/dimensiones conserva su procedimiento pendiente.

Document guarda storage_key (referencia en almacenamiento privado, sin binarios),
issued_on opcional, expires_on y registered_at TIMESTAMPTZ automático del servidor.
No hay status persistido ni timestamps genéricos. CHECK impide claves/nombres vacíos
y emisión posterior a vencimiento. FK sin cascadas preservan referencias; índices
cubren historial por activo/tipo/registro, vencimientos por tipo y autor.
Triggers impiden UPDATE, DELETE y TRUNCATE de documentos: una renovación es INSERT
sin sustituir la referencia histórica. No se comprueba existencia física ni privacidad
del archivo; no se carga, descarga, elimina ni sobrescribe archivo alguno.

Vigencia interpretada en America/Costa_Rica por fecha calendario: más de 15 días
valid, de 0 a 15 expiring_soon, fechas anteriores expired, tipo sin requisito de
vencimiento no_expiration. Incluye todo el día de vencimiento; emisión no define
inicio de aplicabilidad. Document::validity y withValidity comparten estos límites;
las consultas pueden combinarse con FleetAsset::documents. Cargar documentType
anticipadamente al evaluar colecciones evita N+1. Sin requisito, se permite fecha
opcional, pero la vigencia sigue siendo no_expiration según la regla aprobada.

DocumentType::requiredFor consulta requisitos por clase; missingFor detecta solamente
requisitos sin registros. Historial y consultas de vigencia no eligen el documento
más reciente ni certifican cumplimiento/aplicabilidad. Falta decidir selección de
documento aplicable, aceptación y efecto histórico de cambiar la configuración de
un tipo. Actualmente la vigencia y los requisitos usan su configuración vigente.
Activar tracks_expiration se rechaza si existen registros sin expires_on; no se
inventan fechas ni se modifican documentos históricos para completar esa transición.

INSERT de documento realiza UPDATE sin cambio de tracks_expiration en su tipo,
serializando con cambios de configuración antes de verificar vencimiento obligatorio.
READ COMMITTED revisa datos confirmados; un snapshot obsoleto REPEATABLE READ o
SERIALIZABLE puede abortar con 40001. No se agregan reintentos ni servicio HTTP.
La unidad atómica es cada INSERT y su validación; futuras cargas deberán coordinar
archivo y registro sin efectos externos dentro de una transacción prolongada.
Operaciones sobre varios tipos deberán ordenar sus locks para evitar deadlocks.

PDF/JPG/PNG y máximo 10 MB quedan expresados como constantes de Document (10.000.000
bytes); no validan contenido físico en esta etapa. La comprobación de MIME, tamaño,
archivo privado y permisos de registro pertenece a la futura capa de carga autorizada.
No se crean endpoints, controladores, requests, resources, interfaz ni notificaciones.

Migración nueva crea únicamente dos tablas y sus funciones/triggers. Toma locks DDL
sobre objetos nuevos, sin alterar tablas existentes. down elimina historial y requiere
un plan de recuperación autorizado; no se ejecutó. La inmutabilidad no protege frente
a administradores PostgreSQL que deshabiliten triggers o eliminen objetos. Antes de
desplegar se debe aprobar destino y recuperación coherente de archivos/base. Pruebas
se ejecutan exclusivamente con tests/run-postgres.sh sobre PostgreSQL 18 temporal.

Validación de esta etapa: 73 pruebas y 739 aserciones correctas en PostgreSQL 18.6
temporal, incluidas vigencia, historial, FK y serialización con conexiones independientes;
Pint --test y git diff --check correctos. No se migró transporte_db. El sandbox
requirió ejecución ampliada para crear el socket Unix privado del clúster temporal.

## Etapa 4.2: API y gestión documental privada

La aprobación de esta etapa añade dos tablas mediante una migración nueva:
`document_configuration_snapshots` (PK/FK `document_id`) conserva nombre,
`tracks_expiration` y requisitos por cabezal/cisterna al registrar; sus filas son
inmutables. `current_fleet_documents` tiene PK `(fleet_asset_id, document_type_id)`,
`document_id` único y FK compuesta a la identidad completa de `documents`.
No se modifica ninguna migración anterior ni se actualizan documentos históricos.

La migración bloquea registros/configuración durante su comprobación y se detiene
si encuentra documentos anteriores: el esquema 4.1 no registra las configuraciones
pasadas ni una selección actual verificable. No intenta completar información ni
seleccionar por fecha. Requiere resolver explícitamente esa información en una tarea
aprobada antes de desplegar sobre una base con historial. La reversión también se
rechaza si hay documentos: restaurar vigencia por configuración viva reinterpretaría
el historial. Nunca usar `down` como recuperación de datos desplegados.

El trigger AFTER INSERT crea la configuración histórica y cambia la referencia
actual en la misma transacción que el documento. Se conserva el trigger 4.1 que
serializa cada INSERT con cambios de tipo y exige vencimiento según configuración
actual. El bloqueo del tipo y el UPSERT sobre la PK de referencia serializan
registros concurrentes; rollback revierte documento, copia y referencia juntos.
Una fecha de registro mayor no determina cuál es actual. Los triggers impiden
escrituras directas de copias/referencias fuera del registro documental y mantienen
las prohibiciones de UPDATE/DELETE/TRUNCATE del historial. Las garantías no cubren
SQL privilegiado que deshabilite triggers o altere DDL.

`Document::validity` y `withValidity` conservan los límites de la etapa 4.1 en
America/Costa_Rica, utilizando ahora la configuración congelada. La configuración
de tipos cambia al confirmar la transacción administrativa, sin un flujo adicional
de aprobaciones. Activar vencimiento exige fecha en registros futuros y no cambia
los documentos previos. Los requisitos obligatorios actuales se consultan de manera
prospectiva: ausente significa que no existe referencia actual de ese tipo; un
actual vencido no se presenta como ausente. Ningún estado bloquea viajes.

Permisos: `manage-document-types` solo para `administrator` activo;
`manage-fleet-documents` para `administrator` o `secretary` activos. La secretaria
consulta tipos pero no los modifica. Las escrituras revalidan cuenta y roles dentro
de la transacción, compartiendo advisory lock 873210 con administración de cuentas
para serializar revocaciones. No se amplían permisos de administración de flota.
La API conserva sesión, CSRF, respuestas privadas `no-store` y paginación hasta 100.

| Método | Ruta | Contrato |
| --- | --- | --- |
| GET / POST | `/api/document-types` | Listar tipos / crear con nombre y tres booleanos explícitos. |
| PATCH | `/api/document-types/{id}` | Cambiar nombre y/o requisitos; solo administrador. |
| GET | `/api/documents` | Documentos actuales; filtros `fleet_asset_id`, `document_type_id`, `validity`. |
| GET | `/api/documents/{id}` | Documento individual, incluido histórico. |
| GET | `/api/documents/{id}/download` | Descarga autorizada como attachment. |
| GET / POST | `/api/fleet-assets/{assetId}/documents` | Listar actuales / registrar archivo y tipo. |
| GET | `/api/fleet-assets/{assetId}/documents/history` | Historial completo; filtros de tipo/vigencia. |
| GET | `/api/fleet-assets/{assetId}/documents/missing` | Tipos obligatorios actuales sin documento actual. |
| POST | `/api/fleet-assets/{assetId}/documents/{id}/renew` | Crear otro documento del mismo activo/tipo, sin editar el anterior. |

Carga/renovación usa multipart con `file`, `issued_on` opcional y `expires_on`
obligatorio cuando la configuración vigente lo requiere. Registro incluye
`document_type_id`; renovación deriva el tipo del documento de referencia y
rechaza otro tipo suministrado. Un registro adicional del mismo par activo/tipo
es una nueva revisión actual; renovar un documento histórico del mismo par es
válido y no devuelve a ese registro antiguo la condición de actual. No hay endpoints
de eliminación ni actualización de documentos. Se rechazan claves de almacenamiento,
autor, fecha de registro, IDs y campos extra suministrados por el cliente.

Respuestas documentales incluyen IDs, configuración congelada, fechas, autor,
`is_current` y `validity`; nunca `storage_key` ni rutas físicas. La fecha de registro
se genera en PostgreSQL y se presenta con zona America/Costa_Rica. Avisos se obtienen
con `validity=expiring_soon` (0–15 días incluidos) o `validity=expired`; por defecto
excluyen revisiones anteriores. El historial permite usar los mismos filtros sin
perder registros antiguos. No hay correo, push, tareas programadas ni frontend.

`Application/DocumentAdministration` coordina operaciones; Requests validan,
Resources representan y Controllers exponen HTTP. `Infrastructure/PrivateDocumentStorage`
es concreto para el disco local privado `fleet_documents`, fuera de las raíces de
los discos servidos `local` y `public`, con `serve=false` y sin enlaces/URLs públicas.
La carga comprueba extensión, MIME detectado desde el contenido, estructura básica
PDF/JPG/PNG y límite exacto de 10.000.000 bytes. No agrega un analizador completo de
PDF ni dependencias. Nombres internos UUID evitan sobrescrituras; la descarga valida
la forma de la clave, transmite attachment con MIME fijo y `nosniff` y devuelve 404
si falta el archivo. Fallos operativos de carga devuelven 503 genérico, sin SQL,
claves privadas ni rutas, incluso con debug local habilitado.

El archivo se escribe antes de abrir la transacción. Un recibo privado `.pending`
se crea antes de escribirlo. Un mutex de filesystem coordina cargas y recuperación
en el almacenamiento local compartido. Ante errores se consulta PostgreSQL antes
de borrar: cualquier archivo referenciado por historial se conserva. Si falla la
limpieza o no se puede confirmar el estado de la base, se conserva el recibo y se
registra únicamente el UUID del intento. El recibo se retira tras el commit real,
no después de un savepoint. La recuperación difiere intentos cuyo backend aún tiene
una transacción abierta; no confunde datos aún invisibles con archivos huérfanos.

Recuperación manual, sin tarea programada: `php artisan fleet-documents:reconcile-storage`.
Procesa solo recibos del almacenamiento privado, conserva todos los archivos con
referencia histórica y compensa únicamente cargas sin referencia confirmada. Es
repetible; recibos corruptos, base no disponible o archivos históricos ausentes
requieren revisión/recuperación y no eliminación del historial. Esta coordinación
no es una transacción distribuida ni sustituye respaldos coherentes de base/archivos.
El disco debe ser local compartido por los workers que lo utilizan; no cambiarlo a
S3 ni replicarlo por host sin diseñar otra coordinación. SQL externo que registre
claves sin el servicio de carga no tiene garantía de existencia física.

El servidor PHP debe admitir `upload_max_filesize=10M` y `post_max_size=12M` (o límites
mayores), y el proxy HTTP debe admitir multipart de ese tamaño. La validación de
aplicación mantiene el límite menor exacto de 10.000.000 bytes. Estos límites se
configuraron en el PHP del entorno de desarrollo, sin cambiar producción.

Validación reproducible, activando las herramientas ya instaladas:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
vendor/bin/pint --test
git diff --check
```

Las pruebas documentales usan fixtures PDF/JPG/PNG reales y Storage::fake. Se cubren
permisos, CSRF, límite exacto, MIME/extensión/estructura, fechas, configuración
congelada, historial, descargas, filtros, rollback, escrituras parciales, limpieza
fallida y recuperación. Conexiones PostgreSQL independientes ejercitan los triggers
instalados, la visibilidad antes/después del commit, la serialización y conservación
de archivos durante transacciones activas. Solo se usan esquemas sintéticos en el
clúster temporal de PostgreSQL 18; no se migra ni consulta `transporte_db`.

## Etapa 4.3: interfaz web documental

Interfaz en español en `/documentacion`, enlazada desde la bienvenida existente.
Blade entrega únicamente el contenedor público, sin identidad ni datos privados,
con `Cache-Control: no-store`; Vue consulta la sesión antes de solicitar información.
Vite conserva la entrada original y añade `resources/js/app.ts`. Los componentes,
contratos, cliente HTTP y presentación pertenecen a
`resources/js/modules/FleetAndDocuments/`, con Composition API y TypeScript estricto,
`noUncheckedIndexedAccess` y `exactOptionalPropertyTypes`. No hay router, estado
compartido global, biblioteca visual adicional ni funcionalidad de otros módulos.

Dependencias autorizadas: Vue 3.5.43, @vitejs/plugin-vue 6.0.10, TypeScript 5.9.3 y
vue-tsc 3.3.13. Se conservaron Vite 8.3.4, Tailwind 4.3.3 y las demás dependencias
directas existentes; el nuevo `package-lock.json` fija las versiones instaladas.
TypeScript 5.9 es compatible con el compilador de componentes instalado.

Se amplía solamente la lectura necesaria aprobada:

- `GET /api/fleet-assets`: `FleetAssetLookupController`, Request y Resource propios;
  devuelve exclusivamente `id`, `code`, `type`, ordenados por código/ID y paginados
  (25 por defecto, máximo 100). `manage-fleet-documents` permite administrador y
  secretaria activos. No concede `manage-fleet`, ni expone capacidades o estado de
  servicio, ni añade escrituras de activos.
- `DocumentResource` conserva `uploaded_by_id` y añade
  `uploaded_by: {id, name}`; carga anticipada `uploadedBy:id,name` evita N+1 y lectura
  de otros atributos de la cuenta. Listados, registros, renovaciones e historial
  comparten la representación. Es el nombre visible actual de la cuenta, no una
  nueva copia histórica del nombre ni el nombre de Employee. No modifica documentos
  ni configuración histórica; no hay cambios de esquema.

Pantallas: acceso/cierre de sesión existente; selector paginado de cabezales y
cisternas; documentos actuales, indicadores por estado y requisitos faltantes;
registro/renovación multipart; historial completo o por tipo; tipos documentales
paginados, con creación/configuración visibles solo al administrador. Tabla en
escritorio y tarjetas en móvil/tablet, etiquetas, estados de carga/vacío/error,
mensajes en español, indicadores con texto y color, recorrido por teclado y foco
restaurado al cancelar o confirmar formularios. Las versiones históricas solo
ofrecen descarga; no se muestran edición/eliminación. La condición `is_current`
se presenta separada de `validity`, incluidos actuales vencidos.

`api.ts` utiliza `fetch` al mismo origen, sesión mediante cookies y
`X-XSRF-TOKEN` obtenido de la cookie emitida por `/api/csrf`. Los cuerpos JSON y
multipart conservan los contratos aprobados; renovación no envía otro tipo,
autor ni fecha de registro. Todas las autorizaciones y validaciones de contenido,
tamaño, vencimiento y transacciones permanecen en Laravel/PostgreSQL. La interfaz
solo usa `required`/`accept` como ayudas y requisitos recibidos del tipo actual;
no recalcula vigencia ni identifica actuales por fecha. Los indicadores se obtienen
con consultas paginadas y filtros de la API, no contando la página visible.

Lecturas tienen `AbortController`; cambiar activo, filtros, página o desmontar
componentes cancela consultas y descarta respuestas obsoletas. Se deshabilitan
formularios y navegación durante escrituras para evitar envíos accidentales
simultáneos. No se añaden reintentos automáticos ni garantías nuevas de idempotencia.
Ante conexión interrumpida o error de servidor, se pide consultar los documentos
antes de reenviar una carga: la respuesta perdida no permite saber si se confirmó.
Se conservan entradas/archivo ante validación y errores operativos.

401 retira datos privados de la interfaz y presenta acceso; 403 vuelve a consultar
roles y desmonta componentes privilegiados; 404 informa recurso/archivo ausente;
419 exige actualización explícita de CSRF antes de reenviar; 422 muestra errores
por campo. También se manejan 413, 429 y fallos de servidor/conexión sin imprimir
el cuerpo de errores internos. Contratos JSON se verifican en ejecución además de
sus tipos estáticos. La descarga usa el endpoint autorizado, Blob en memoria y
nombre local generado; libera su URL temporal. Nunca usa claves de almacenamiento,
rutas físicas, URLs públicas, localStorage, sessionStorage ni IndexedDB. No hay
service worker, caché privada persistente ni trabajo offline.

Verificación: PHPUnit mediante `bash tests/run-postgres.sh` exclusivamente en
PostgreSQL 18 temporal, `npm run typecheck`, `npm run build`,
`npm run test:frontend`, `vendor/bin/pint --test` y `git diff --check`.
Las pruebas de navegador usan Playwright/Python y Chromium ya disponibles; no se
instala otra dependencia. Arrancan un servidor PHP temporal para la vista Blade y
artefactos Vite reales, con sesión/cache en memoria, e interceptan todas las llamadas
`/api/*` con datos sintéticos. No realizan conexiones de base de datos. Prueban
interacciones, roles, formularios, errores, carreras de lectura, descargas, teclado
y tamaños 390/768/1440. Estas pruebas no sustituyen una prueba end-to-end de sesión
real: los permisos, cookies/CSRF, persistencia y almacenamiento se validan por
separado en las pruebas HTTP/backend PostgreSQL. No se accede a `transporte_db`.

Los indicadores corresponden al activo seleccionado y se actualizan al cargar,
registrar/renovar o pulsar Actualizar; no hay polling ni notificaciones. Consultas
separadas pueden observar commits distintos bajo concurrencia y no constituyen
un snapshot global. La descarga carga en memoria un archivo limitado por la API
(10.000.000 bytes). No se aprovisionaron usuarios ni datos de aplicación; iniciar
uso real requiere las migraciones/datos autorizados previamente. La precondición
4.2 de detener migración ante historial no determinable sigue vigente.

## Etapa 4.4: integración documental de extremo a extremo

La limitación de 4.3 (navegador con API simulada) queda cubierta por una suite
adicional real. `bash tests/run-postgres.sh --e2e` reutiliza el runner PostgreSQL 18:
crea un clúster UTF-8 sin TCP, socket privado y `transporte_test` con `transport_test`.
La ejecución normal sin argumento conserva PHPUnit. No se cambia ningún esquema,
contrato, dependencia, regla de negocio ni código de producción del módulo.

Instrumentación exclusivamente en `tests/E2E/`, fuera del directorio público:

- `bootstrap.php` exige la marca de aislamiento, ruta canónica del clúster temporal,
  directorio de datos y workspace derivado. Antes de migrar o atender HTTP verifica
  configuración efectiva y `current_database`, `current_user`, versión mayor 18.
  Conserva únicamente `pgsql_testing`, sin conexiones secundarias ni DB_URL.
  Evita leer `.env` y configuración/rutas/eventos cacheados del desarrollo;
  genera clave efímera y usa almacenamiento, vistas, logs y caché bajo el clúster.
- `console.php` aplica las migraciones aprobadas únicamente después de esa guardia,
  crea usuarios/flota/tipos sintéticos, inspecciona filas y hashes de archivos,
  controla el reloj de dominio y la caducidad de sesiones, ejerce restricciones y
  la recuperación manual. No expone endpoints de prueba.
- `server.php` ejecuta el kernel HTTP y rutas reales de Laravel con CSRF activo;
  sirve los artefactos Vite compilados. PHP usa cuatro workers y sesiones database
  en la misma base temporal. El filesystem privado es real, compartido entre esos
  workers y separado del almacenamiento del entorno de desarrollo.
- `network.py` transmite las peticiones y respuestas reales por un proxy localhost
  de biblioteca estándar. Conserva íntegros los bytes multipart. Para una prueba
  puede retirar metadatos de origen, dirigir una descarga a un UUID inexistente o
  cerrar la conexión después de recibir un 201 real de Laravel. No genera ni
  reemplaza payloads de API; no hay `route.fulfill`, Storage::fake ni SQLite.
- `test_document_module.py` controla Chromium/Playwright ya instalados, utiliza Vue
  y las cookies reales y guarda capturas y trazas por prueba. Cierra navegador,
  servidor/proxy y workers; el runner detiene PostgreSQL incluso ante fallos.
  Los artefactos sintéticos quedan bajo `/tmp/transporte-tests-*/e2e/` para revisión.

Las 14 pruebas reales cubren acceso/cierre, administrador/secretaria, rechazo de
conductor/mecánico y anónimos, consulta/escritura de tipos por rol, PDF/JPG/PNG en
cabezales/cisternas, renovación, una sola referencia actual, historial completo,
configuración congelada y descarga binaria autorizada. SQL y hashes demuestran que
renovar/cambiar requisitos no edita documentos anteriores ni sobrescribe archivos.
Un archivo de exactamente 10.000.000 bytes llega al disco real; uno mayor y otros
contenidos inválidos se rechazan con 422 sin registros/recibos pendientes.

El reloj de `CarbonImmutable` se fija solo en el bootstrap de prueba: 05:59:59 UTC
corresponde a 23:59:59 America/Costa_Rica del día anterior. Se ejercitan +15, +16 y
0 días, paso a 06:00:00 UTC (medianoche local), vencidos, sin vencimiento y requisitos
faltantes. Se mantiene el timestamp de registro generado por PostgreSQL. La sesión
usa el reloj real; para comprobar caducidad se envejece `sessions.last_activity`
después de finalizar lecturas que podrían renovarla legítimamente.

Laravel 13 acepta `Sec-Fetch-Site: same-origin` o un token CSRF válido como parte
de su protección existente. Las pruebas conservan ese comportamiento: verifican
401 por sesión caducada y 419 mediante peticiones sin origen válido ni token.
La interfaz conserva entradas/archivo y necesita actualización manual de CSRF,
sin reenvío automático. No se deshabilita middleware ni se cambia autorización.

Ante red interrumpida antes del envío se comprueba el estado real de PostgreSQL,
sin inferirlo solo del error. Ante respuesta perdida tras una renovación confirmada,
el proxy ve 201, el navegador recibe fallo de conexión y la inspección confirma dos
versiones, una referencia actual y ambos archivos intactos. La interfaz no reintenta;
tras cancelar y actualizar se consulta la versión confirmada. No se añade una regla
nueva de idempotencia: reenviar deliberadamente sigue creando otra versión.

Dos renovaciones HTTP de sesiones independientes se solapan con una barrera de
advisory lock; las lecturas ven la referencia anterior durante el bloqueo y después
hay una sola referencia actual y tres versiones/archivos intactos. Se induce también
un rechazo de persistencia real desde el servicio de aplicación y se verifica rollback
y compensación del archivo nuevo. La recuperación manual procesa recibos reales
históricos/huérfanos y es repetible. Se reutilizan las pruebas PHPUnit de conexiones
independientes para espera SQL, snapshots inmutables, fallos parciales y recuperación
diferida ante transacciones abiertas; no se reemplazan por simulación del servidor.

Validación separada: PHPUnit sobre PostgreSQL 18 aislado, 14 pruebas UI con API
simulada (`npm run test:frontend`) y 14 E2E reales (`bash tests/run-postgres.sh --e2e`),
además de tipos, build, Pint y diff. La suite E2E necesita los artefactos de
`npm run build`, sin Vite dev activo, PHP, binarios PostgreSQL 18, Python/Playwright
y Chromium disponibles. No instala nada ni despliega; valida el stack local con
HTTP/Chromium y filesystem, no TLS/proxy/almacenamiento de producción ni otros
navegadores. No se inicia Transporte y Despacho.

Resultados finales 4.4: 96 pruebas PHPUnit y 1.113 aserciones, 14 pruebas UI simuladas
y 14 E2E reales correctas; typecheck, build, Pint y diff correctos. Clúster E2E
`/tmp/transporte-tests-FdqbHi` detenido; trazas/capturas bajo `e2e/browser/`. Informe
completo y comando reproducible en `docs/etapa-4-4.md`.

## Etapa 5.1: diseño documental de Transporte y Despacho

Base e1aaae8, sin cambios de código, migraciones, esquema ni dependencias. Fuente
íntegra: [docs/REGLAS_TRANSPORTE_DESPACHO.md](docs/REGLAS_TRANSPORTE_DESPACHO.md).
Diseño y trazabilidad de RN-TR-01..39 y RN-SEG-01 en
[docs/etapa-5-1.md](docs/etapa-5-1.md), con ER conceptual en
[docs/modelo-er-transporte-5-1.mmd](docs/modelo-er-transporte-5-1.mmd).
RN-TR-17 permanece pendiente; las demás reglas están confirmadas, pero campos,
restricciones y estados propuestos aún requieren aprobación antes de 5.2.

Reutilizar catálogos de transporte, Employee/User/Role y flota/documentación vigente.
Conservar producto histórico por viaje: derivarlo siempre de solicitud mutable
violaría RN-TR-02/26. Solicitud admite cambios auditados de volumen y cambios
administrativamente autorizados de producto; viajes cargados/completados no cambian.

Detectar conflictos concurrentes de recursos y admitir excepciones específicas solo
con autorización administrativa (RN-TR-04); no UNIQUE absoluto entre viajes que impida
la regla. Un conductor/cabezal/cisterna por viaje a la vez. Liberación manual de oficina,
sin automatismo de reloj/finalización/cancelación. Conductor empleado activo con cuenta
activa e identificación como conductor; correspondencia con rol driver por aprobar.

Inicio/finalización/cierre oficiales son de secretaria/administrador. Conductor reporta
fin e información; oficina registra volúmenes definitivos, kilometraje y gastos.
Administrador autoriza diferencias de litros antes de finalización, sustituciones
(cisterna anterior vacía), gastos sin comprobante, cada viático, cambios de producto
y precios y cargos por cancelación. Cierre puede conservar pendientes, pero no omite
esas autorizaciones. Correcciones cerradas y cambios de solicitud preservan auditoría.

Permiso de salida configurable por sitio; dato y adjunto de viaje requieren diseño
propio. No ampliar Document con referencias ficticias ni modificar almacenamiento 4.4.
RN-TR-17 no se resuelve con advertencia de documentos de flota ni bloqueo automático.
Las alertas documentales mantienen vigencia y snapshots existentes, sin bloqueo.

Persistencia propuesta separa hechos, confirmaciones, autorizaciones, aplicación,
liberación, evidencia, pendientes al cierre y auditoría. Nuevas estructuras de permiso,
lecturas, gastos y desembolsos son propuestas; evitar duplicar futuros gastos/lecturas.
No crear motor genérico de aprobación ni implementar facturación o PWA en etapa 5.

Contratos futuros RN-TR-30..38: factura individual/agrupada mismo cliente; viajes
completados pueden facturarse antes de terminar solicitud según acuerdo. Contado/crédito,
un pago íntegro por factura, métodos configurables administrados por administrador.
Facturas emitidas no se editan; anulación/corrección fiscal autorizada por administrador.
Modelo comercial exacto RN-TR-03 pendiente, sin fórmula fija por litro/viaje.

Diseño funcional consolidado y fuente recuperada; 5.2 aún no autorizada. Aprobar
propuestas técnicas y concretar pendientes necesarios del alcance elegido antes de DDL.
El ER global previo permanece como borrador histórico señalado, no fuente de migraciones.

## Etapa 5.2A: núcleo de persistencia implementado

Autorización específica: cinco migraciones nuevas 000060–000100, once modelos y relaciones,
Gates de persistencia y cinco grupos de pruebas. Informe en [docs/etapa-5-2a.md](docs/etapa-5-2a.md).
Se reutilizan catálogos y usuarios existentes; UUID nuevos, FK User bigint, NUMERIC(15,2).
Planificación scheduled/rescheduled, ejecución pending/in_progress/completed/cancelled y
administración open/closed son dimensiones distintas; no hay transiciones automáticas.
Producto histórico de Trip y nombre capturado no cambian por edición de solicitud/catálogo.

Raíces actualizables con version+1; trigger diferido exige auditoría exacta OLD/NEW en la
misma transacción. Historia SQL/Eloquent inmutable. Cierre con pendientes conserva evidencia;
corrección de closed no implica reapertura ni modificación de otro módulo.
Solicitudes, decisiones y aplicaciones de autorización son registros separados e inmutables;
decide solo administrator activo, solicita/gestiona administrator o secretary activos.
Aplicación única, vinculada a versión/destino/contenido del cambio. Solo aplicación de cambio
de producto de solicitud está representada; no se implementan operaciones económicas.

Se comparte advisory lock 873210 para coordinar actores y revocaciones; actualización de
raíces toma lock de sentencia antes de filas. Concurrencia de aplicación probada en PostgreSQL
real. No se incorporan asignaciones/conflictos/sustituciones/liberaciones de 5.2B.
Gates no amplían permisos anteriores; driver sin relación de asignación no recibe acceso
privado de viaje. SQL comprueba actores registrados, no autentica conexiones arbitrarias.

Sin servicios, API, frontend, precio, viáticos, facturación, carga/descarga ni comportamiento
para RN-TR-17. Las restricciones de estado son de persistencia, no autorización de un flujo
operativo todavía incompleto. Las propuestas 5.1 restantes conservan su estado pendiente.

Verificación final 5.2A: 123 pruebas y 1.261 aserciones correctas en PostgreSQL 18 aislado,
incluidas 27 nuevas; Pint --test y git diff --check correctos. No se migró transporte_db.

## Corrección 5.2A: cierre de viajes cancelados

Aclaración aprobada después de e98198f: cancelled puede cerrarse administrativamente
sin officially_completed_at ni completed_by_id ficticios. Nueva migración 000110,
sin modificar las publicadas, admite closed cuando ejecución es completed o cancelled
y valida cronología frente al hecho terminal correspondiente. Actores/fechas de cierre
y cancelación siguen separados; permisos, motivo, auditoría, producto y avisos intactos.
No se añaden asignaciones ni liberaciones; 5.2B continúa fuera de alcance.

TransportInformationRecordsTest añade cuatro pruebas. Suite completa: 127 pruebas,
1.295 aserciones correctas, PostgreSQL 18 temporal; Pint --test y diff correctos.
Detalles y límites de reversión/despliegue en docs/etapa-5-2a.md.

## Etapa 5.2B: asignaciones y disponibilidad efectiva

Base 524fad0. Nuevas migraciones 000120/000130 incorporan asignaciones de activos y
conductores, liberaciones separadas, operaciones/conflictos inmutables y guard de
serialización. Detalles y límites en [docs/etapa-5-2b.md](docs/etapa-5-2b.md).
No se modifican migraciones publicadas, catálogos, producto histórico ni funciones 4.4.

Current deriva de ausencia de liberación; cancelar/finalizar/cerrar/programación no
liberan. in_progress exige un recurso vigente por función. Liberación individual válida
en planificación/estados terminales; sustitución activa debe preservar cardinalidad.
Empleado activo + cuenta activa + rol driver; activo de tipo correcto con servicio available.
Excepciones de ocupación no dispensan elegibilidad ni garantizan disponibilidad física.

AssignmentAdministration coordina transacciones y permisos vigentes. Decisiones reutilizan
5.2A; aplicaciones tienen XOR de cambio/operación. Aprobación administrativa específica,
contenido y ocupantes exactos, consumo único. Sustituciones de cabezal/conductor atómicas;
si hay ocupación adicional se requieren las dos aprobaciones. Cisterna excluida mientras
D05 no defina comprobación de estado vacío; no se inventa evidencia ni trasvase.

Se comparte lock 873210 y se escribe guard para invalidar snapshots obsoletos. PostgreSQL
protege integridad incluso ante SQL directo; tests comprueban concurrencia READ COMMITTED
y REPEATABLE READ con conexiones independientes. La migración se detiene ante viajes ya
in_progress sin historia verificable, sin inventar recursos. Sin frontend, API ni 5.3.

Validación: 144 pruebas y 1.436 aserciones correctas en PostgreSQL 18 aislado; 17 nuevas.
Pint --test y git diff --check correctos. No se migra transporte_db ni se instalan dependencias.

## Etapa 5.3 — Operaciones de viajes y finalización oficial

Base 3453808. Tres migraciones nuevas 000140/000150/000160, cinco modelos operativos y
TripOperations coordinan inicio, carga/descarga y detalles por compartimento, reportes
del conductor asignado, lecturas/correcciones inmutables y finalización oficial D02.
Sin frontend ni API. Diseño, contratos y límites: [docs/etapa-5-3.md](docs/etapa-5-3.md).

Litros NUMERIC(15,2), capacidad/código capturados desde flota, producto obtenido del Trip
histórico, actor/fecha/evidencia y unicidad de operaciones. Descarga conserva la capacidad
histórica; cero comunicado se distingue de ausencia. Lecturas conservan precisión reportada
y trazabilidad por cabezal; distancia por tramo sin compensación inventada de reinicio.

Se reutilizan auditoría, lock/guard y autorizaciones existentes. Finalizar requiere inicio,
asignaciones históricas válidas, carga/descarga y cobertura de cada detalle. Cualquier
diferencia por compartimento, incluso con totales iguales, exige autorización exclusiva
del administrador con contenido/versión exactos y consumo único. Fecha/actor/auditoría y
aplicación son atómicos; SQL protege cobertura y diferencias ante escrituras directas.
Las finalizaciones previas no se reconstruyen ni invalidan durante correcciones ajenas.

Planificación, ejecución y cierre permanecen separados. Reporte del conductor opcional;
no finaliza ni se exige evidencia adicional. Recursos nunca se liberan automáticamente.
RN-TR-17 y comprobación de cisterna vacía D05 siguen excluidos; no se avanza a 5.4.

Validación: 170 pruebas y 1.574 aserciones correctas en PostgreSQL 18 aislado, Pint --test
y git diff --check correctos. Tres suites nuevas cubren reglas, roles, SQL y concurrencia.
Fixtures de estados históricos incorporan operaciones reales para respetar D02; el test
de TRUNCATE de compartimentos conserva rechazo, ahora por FK 0A000. No se migró
transporte_db ni se modificaron migraciones publicadas o dependencias.

## Etapa 5.4 — API REST de Transporte y Despacho

37 rutas bajo /api/transport reutilizan sesión, CSRF, cuenta activa y auth.session.
Administrator/secretary gestionan el núcleo; decisiones solo administrator y reportes
propios solo driver elegible/asignado. Detalle, listados e historias usan Resources con
campos explícitos y paginación, sin cuentas completas ni referencias privadas de archivos.
Contratos completos: [docs/etapa-5-4.md](docs/etapa-5-4.md).

TransportAdministration coordina solicitudes, creación, programación, cancelación, cierre
con advertencias y decisiones. TripOperations y AssignmentAdministration conservan la
operación de 5.2B/5.3. Versiones obligatorias para modificar raíces, auditoría exacta,
lock/guard existentes y comprobaciones diferidas son atómicos. Disponibilidad informativa;
AssignmentAvailability revalida recursos y ocupación efectiva, también al reprogramar.
No se transforma programación en reserva física ni se reutilizan autorizaciones.

Migración correctiva 000170 refuerza finalización: inicio oficial auditado y vínculo
correcto de cisterna/carga/operación, evitando usar solo historia antigua liberada.
No modifica migraciones publicadas, tablas ni producto histórico. Las correcciones de
finalizaciones anteriores siguen conservándose. Regresiones SQL y HTTP verifican carga
de otro viaje, consumo único y ausencia de efectos parciales.

HTTP se organiza en Controllers, Requests y Resources del módulo, routes/transport.php y
middleware TransportApiErrors. bootstrap/app.php limita la representación segura de errores
PostgreSQL a esta API; 409 para concurrencia/versiones/ocupación, 422 para integridad.
Fechas HTTP requieren ISO 8601 con offset explícito y se conserva el instante en UTC.
No se añaden frontend, dependencias, almacenamiento público, facturación o políticas pendientes.

Validación: 189 pruebas y 1.797 aserciones correctas en PostgreSQL 18 aislado (19 nuevas),
Pint --test y git diff --check correctos. Concurrencia HTTP con dos conexiones reales;
sin API simulada, nuevas dependencias, migraciones en transporte_db ni avance de etapa.

## Etapa 5.5 — Interfaz web de Transporte y Despacho

/transporte sirve Blade/Vite sin datos privados y monta TransportApp.vue. La navegación
interna enlaza con documentación de flota, conservando su sesión y funcionalidades.
Módulo Vue 3 Composition API y TypeScript estricto en resources/js/modules/TransportAndDispatch.
Tailwind y Pagination/FieldError existentes; sin router/framework/dependencias adicionales.
Diseño y ejecución: [docs/etapa-5-5.md](docs/etapa-5-5.md).

Panel con meta.total real, solicitudes y viajes paginados, filtro por solicitud/estados,
formularios y detalle con secciones de recursos, captura, kilometraje, autorizaciones e
historia. Se utilizan únicamente la API 5.4 y autenticación/CSRF existentes. Administración
para administrator/secretary; decisiones reservadas a administrator. No se implementa PWA.

Contratos tipados, fetch con same-origin/no-store, fechas Costa Rica, cantidades exactas
y confirmación/bloqueo durante escrituras. Aprobaciones filtradas por contenido, versión,
viaje y consumo; el backend sigue autorizando y revalidando operaciones/concurrencia.
Respuesta perdida, 409 o 500 requieren consulta de estado antes de otra escritura;
no hay reenvío automático ni datos privados persistidos en almacenamiento del navegador.

No se cambia backend/esquema ni se inventan operaciones sin contrato. Continúan pendientes
RN-TR-17, D05, producto, idempotencia offline, rutas/evidencias y correcciones de captura.
Los registros históricos permanecen inmutables y el cierre no libera recursos.

Validación final: 190 pruebas y 1.806 aserciones en PostgreSQL 18 aislado; 24 pruebas
Chromium con API simulada (14 previas/10 nuevas) y 2 flujos E2E reales con Laravel/CSRF/PG18,
incluyendo respuesta perdida después de commit y kilometrajes de cabezal liberado en viaje
cerrado. TypeScript, compilación, Pint --test y git diff --check correctos. Sin migrar
transporte_db, modificar .env, instalar dependencias ni avanzar a otra etapa.

## Estabilización de Transporte y Despacho (5.6)

La integración real se amplía a cuatro escenarios Chromium/Vue/Laravel/sesión/CSRF/
PostgreSQL 18 aislado: flujo completo con reporte del conductor, kilometrajes y liberación
manual de todos los recursos; diferencia autorizada; captura incompleta recuperada tras
respuesta perdida; dos sesiones, conflicto, cancelación y cierre con avisos.

TripOperations::addDetails coordina captura progresiva mediante dos POST de detalles,
load/items y unload/items, sin cambiar cabeceras ni detalles anteriores. Reutiliza modelos,
Resources, Gates, bloqueo/guard transaccional y restricciones PostgreSQL existentes.
Cada nuevo detalle audita actor de sesión, fecha, motivo y evidencia. No requiere esquema
nuevo; no implementa edición de cantidades históricas ni correcciones terminales. Añadir
contenido invalida una autorización de diferencia que ya no coincide exactamente.

OperationsPanel ofrece solo registros omitidos y muestra auditoría por detalle; TripDetail
informa la recuperación y nombra el selector de historial; OdometerPanel limpia distancia al
cambiar el tramo. Se conservan estados, asignaciones, permisos, CSRF y estilo actuales.
Detalles, comandos, resultados y límites en [docs/etapa-5-6.md](docs/etapa-5-6.md).
RN-TR-17, D05 y demás decisiones pendientes no se resuelven; no se declara aptitud productiva.

## Taller Mecánico — análisis 6.1, sin implementación

Workshop está previsto conceptualmente; todavía no tiene código ni esquema propio.
[docs/REGLAS_TALLER_MECANICO.md](docs/REGLAS_TALLER_MECANICO.md) conserva las 28 RN
aprobadas. [docs/etapa-6-1.md](docs/etapa-6-1.md) documenta actores, permisos, estados,
flujos, aceptación y decisiones abiertas. Prevalece una reparación/servicio por orden
sobre WorkOrder/WorkTask del borrador histórico. No se aprueban tablas o APIs en 6.1.

La futura integración reutiliza FleetAsset y AssignmentAvailability: ingreso por avería
bloquea nuevas asignaciones, conserva viajes/recursos existentes y genera advertencias.
Terminar/cerrar/cancelar órdenes no desbloquea; salida requiere administrador y revisión
de todos los impedimentos. Service_status aislado no acredita dicha revisión. Diseño
transaccional/proyección de impedimentos y políticas concretas requieren revisión en 6.2.

User/Employee/Role, SparePart y Supplier son reutilizables. Inventario/Compras solo tienen
catálogos; no hay movimientos o compras reales para simular. Autorizaciones/auditoría y
almacenamiento privado de Transporte/Flota sirven como patrones, pero sus clases están
acopladas a sus entidades y no se presentan como servicios genéricos de Taller.
No se modifican módulos existentes. Las preguntas funcionales de 6.1 se aclararon
posteriormente mediante D-TAL-01..13: ver la propuesta documental 6.2 a continuación.

## Taller Mecánico — cierre documental 6.2, sin implementación

[docs/etapa-6-2.md](docs/etapa-6-2.md) consolida RN-TAL-01..28, D-TAL-01..13
y T01–T09 aprobadas. El modelo lógico propone **27 tablas**: se añade la asociación
histórica orden–ingreso para conservar ingresos anteriores tras una reapertura. No crea
esquema, servicios, endpoints, interfaz ni funcionalidades de Inventario/Compras.

Una orden representa una reparación/servicio; scopes inmutables iniciales y ampliaciones
independientes identifican trabajos/cantidades. Solicitud, decisión y aplicación permanecen
separadas, con aplicación única. Cierre administrativo ordinario es operación auditable,
sin segunda solicitud de aprobación. Reapertura y rectificación sensible sí requieren
autorización explícita, destino/versiones y preservación de registros originales.

Mecánico asignado solicita repuestos y confirma uso; administrador autoriza alcance y
supervisa; secretarias activas registran entrega física, sin grants ni rol de bodeguero.
Entrega/uso no representan stock ni movimientos hasta integración real con Inventario.
SparePart y Supplier existentes conservan identidad. Reparación no autoriza compra;
contratos futuros usarán IDs reales y deduplicación, sin implementar esos módulos aquí.

Un ingreso físico abierto por activo, varias órdenes independientes y orden sin ingreso
permitida; reportes N:M órdenes justificados, mismo activo. Ingreso bloquea nuevas
asignaciones incompatibles sin cancelar viajes/liberar recursos. Alerta en carretera
requiere confirmación de impedimento por oficina. Solo administrador autoriza salida.
Cada causa se resuelve individualmente, cierre/cancelación no la elimina. Fleet conserva
service_status como proyección única protegida; otra causa mantiene no disponibilidad.
Preservar estados previos desconocidos sin inventar historia. AssignmentAvailability y
SQL de Transporte mantienen elegibilidad; integrar coordinador 873210/guard antes de filas,
respetando trips→activos y revalidación de actor/roles. Validación física futura en PG18.

Eventos de tiempo por mecánico excluyen pausas; horas-persona se suman, indisponibilidad
se calcula por unión de intervalos. Cantidades NUMERIC(18,6), costos NUMERIC(20,6),
validación exacta antes del cast. Estimados/reales y monedas separados (CRC principal);
fuentes/cobertura evitan doble conteo. Secretaria registra costos documentados/consulta
coordinación; mecánico ve repuestos/tiempos de asignadas sin reportes financieros generales.
Factura adjunta no confirma pago.

Evidencia Taller privada propia: PDF/JPG/JPEG/PNG, 10 MB por archivo, 20 archivos por
orden inicialmente y límite configurable; originales/rectificaciones preservados. Validar
contenido real y permisos por orden; compensar/conciliar almacenamiento sin borrar historia.
Document de Flota no se reutiliza como adjunto. Recibos idempotentes en workshop_operations
con ámbito/clave/huella/resultado se confirman junto con efectos y se conservan con historia.

El relevo ya existe mediante AssignmentAdministration::substitute y DriverAssignment/
DriverRelease. No elimina impedimentos del equipo; sustitución de cisterna sigue bloqueada
por D05 de Transporte, distinta de D-TAL-05. User/Employee/Role y FleetAsset no se duplican.
Autorizaciones y evidencias tienen ámbito propio. Publicar el cierre no autoriza implementación.

## Taller Mecánico — fase 0, diseño físico documentado

[docs/etapa-6-2-diseno-fisico.md](docs/etapa-6-2-diseno-fisico.md) completa el diccionario
PostgreSQL 18 de las mismas 27 tablas con decisiones físicas F01–F07 aprobadas.
Conserva RN-TAL, D-TAL y T01–T09. No hay migraciones ni funcionalidad Taller instaladas.

Decimal exacto se valida desde texto antes del cast mediante escritura SQL controlada
y privilegios separados. Estados previos desconocidos conservan impedimento observado
hasta conciliación administrativa. Reservas de archivos son operaciones técnicas
append-only en workshop_operations; adjuntos solo confirmados. UNIQUE común y validación
diferida impiden confirmación/liberación dobles y adjuntos con reserva liberada.

Primera versión de almacenamiento: privado local, servidor único, exclusión por carga,
recuperación de commit incierto y respaldo/restauración de PostgreSQL+archivos coherentes.
Reintentos configurables: tres intentos y diez segundos iniciales, solo transitorios
seguros con misma clave. Permisos específicos incluyen lectura histórica del mecánico
sin nuevas escrituras y coordinación de secretaria; costo sin clasificación no suma.

Diseño preparado para futura autorización de migraciones y pruebas aisladas; garantías
de concurrencia/durabilidad todavía no comprobadas experimentalmente. Política operativa
RPO/RTO/retención/frecuencia/responsables y aplicación real requieren aprobación posterior.

## Taller Mecánico — backend fase 1: núcleo de persistencia

Implementación autorizada de las tablas #1–7, #13 y #27 mediante nuevas migraciones
000180/000190. Módulo `app/Modules/Workshop/Models`: nueve modelos Eloquent y protección
de historial. Sin servicios, controladores, rutas ni frontend. El diseño físico 6.2
conserva autoridad; no se alteran RN-TAL ni decisiones T01–T09/F01–F07.

El núcleo conserva UUID, actores bigint, TIMESTAMPTZ(6), revisiones enlazadas, índices,
FK RESTRICT, ámbitos, participación vigente, auditoría y recibos únicos. Triggers
de sentencia comparten `transport_assignment_lock` (873210 y guard), con comprobaciones
diferidas de hechos/versiones y sin disponibilidad alternativa. Estados iniciales
y cambios de metadatos de órdenes se protegen; transiciones que necesitan autorización
o ejecución posteriores siguen rechazadas.

Ingresos y vínculos a ingresos están bloqueados explícitamente en PostgreSQL; el
modelo también rechaza crear ingresos. Faltan #22–25 y la integración atómica de
impedimentos/proyección para habilitarlos. No se cambian `service_status`, viajes ni
asignaciones de Transporte. #13 contiene solamente FK a destinos existentes del
núcleo; columnas/FK y tipos de operación dependientes se incorporarán por migraciones
nuevas en las fases correspondientes, sin UUID libres hacia tablas inexistentes.

Verificación del núcleo: `bash tests/run-postgres.sh --filter WorkshopCore` usa
únicamente PostgreSQL 18 temporal con socket privado bajo /tmp. El servidor de pruebas
fija UTC explícitamente para no depender de la zona del host. Se cubren estructura,
roles, relaciones, historial, revisiones, recibos, snapshots, ingresos deshabilitados,
reversión/reaplicación y concurrencia RC/RR con conexiones independientes. No se
aplican migraciones a la base del proyecto ni se instalan dependencias.

## Taller Mecánico — backend fases 2 y 3

Las migraciones nuevas 000200/000210 incorporan #8–12 y #14: revisiones de alcances,
tareas, repuestos, solicitudes, decisiones y aplicación única de autorizaciones.
000220/000230 incorporan #22–25: impedimentos de Flota, resoluciones, salidas y
advertencias. Amplían las protecciones anteriores sin editar migraciones desplegadas.

`WorkshopCommands` coordina recibos idempotentes, autorización vigente y reintentos
transitorios configurables. `WorkshopAuthorizations` compara versiones y contenido
histórico revisado; `WorkshopAvailability` integra ingreso y salida con la exclusión
existente de Transporte (873210 y guard). Las asignaciones existentes se conservan;
las nuevas incompatibles se rechazan. El cierre de una orden no implica salida ni
resolución de impedimentos. Un estado histórico desconocido requiere observación
administrativa explícita y conserva su efecto hasta resolución autorizada.

Los ingresos quedan habilitados exclusivamente con causa, proyección, advertencias
y auditoría atómicas. No se incorporan rutas, interfaces ni ejecución de reparaciones.
La cantidad exacta de repuestos se valida como texto mediante función SQL controlada.
El despliegue debe separar propietario de esquema y rol de ejecución: este último
no recibe escritura directa sobre cantidades y obtiene EXECUTE explícito sobre la
función aprobada; no se modifican credenciales ni privilegios del entorno del proyecto.

Las pruebas utilizan únicamente `tests/run-postgres.sh` y PostgreSQL 18 temporal;
incluyen permisos, aplicación única, escrituras SQL incompatibles, reintentos seguros
y conexiones independientes para conflictos de ingreso, asignación y salida.

## Taller Mecánico — frontend, primera entrega

Montaje dentro del frontend existente: `/` y `/taller` sirven
`resources/views/workshop.blade.php`; `resources/js/app.ts` monta `WorkshopApp`.
El módulo físico `resources/js/modules/Workshop/` contiene integración HTTP,
presentación y componentes de formularios, tablas, diálogo accesible y alcance.
Consume la API JSON existente por sesión/CSRF; no incorpora dependencias,
migraciones, almacenamiento offline ni un frontend separado.

Incluye inicio con totales por los siete estados reales, solicitudes pendientes y
conteo de activos distintos con impedimentos abiertos. El conteo recorre toda la
paginación; los indicadores se limitan al acceso del usuario y no presentan valores
simulados cuando falla la consulta. La API no ofrece feed consolidado de actividad.
Órdenes tienen filtros reales de estado, prioridad, vehículo y fechas con offset
America/Costa_Rica; la búsqueda textual indica expresamente «en esta página».

Flujo: reporte → creación de orden por oficina → vínculo explícito con avería →
asignación → diagnóstico → alcance con tareas/repuestos → solicitud automática →
decisión administrativa → aplicación explícita. Cada escritura es independiente y
actualiza las consultas tras su confirmación. Cancelación, reapertura y rectificación
usan solicitudes del backend, sin asignar estados desde Vue. No incorpora pantallas
de ejecución, tiempos, inventario, costos ni adjuntos. Autorizaciones que corresponden
a esos hechos muestran su dependencia y no se aplican como cambios de orden.

La consulta existente de asignaciones añade `employee_name`, `is_current_actor` e
`is_current` (empleado activo, fecha de inicio alcanzada y sin liberación). Permite
seleccionar participación propia vigente y ocultar acciones mecánicas históricas;
no concede permisos ni sustituye la autorización del comando. No hay nuevo endpoint.
Los selectores con búsqueda consumen consultas existentes: Flota para oficina,
empleados/cuentas y catálogos para administrador; vehículos de órdenes accesibles
para mecánico. Secretaría selecciona mecánicos de asignaciones vigentes accesibles.
Sin acceso a catálogos completos, repuestos y proveedores provienen de referencias
existentes en órdenes visibles. No hay entrada manual de UUID ni ampliación de permisos;
un elemento ausente del contexto requiere intervención administrativa.

Las versiones se capturan al abrir el formulario. Respuestas perdidas conservan en
memoria método, ruta, contenido y `request_key` exactos; solo un reintento explícito
repite el comando. No hay reenvío automático. 409 o 422 de versión/clave exigen cerrar,
actualizar y revisar. Errores 401/403 limpian pantallas privadas; cambio de identidad
o roles detectado en consulta limpia caches y formularios. Cancelación de lecturas y
generaciones evitan sobrescribir consultas nuevas. Se actualiza cada 30 segundos con
la página visible y sin formulario activo; el usuario también puede actualizar.

Validación: `npm run typecheck`, `npm run build`, `npm run test:frontend-api`,
`npm run test:frontend` (requiere Playwright Python y Chromium ya disponibles),
`bash tests/run-postgres.sh --filter Workshop`, Pint y `git diff --check`.
La suite de navegador usa API sintética interceptada para fallos deterministas.
`npm run test:e2e-workshop` cubre además Chromium → Laravel → PostgreSQL 18 temporal
sin simular respuestas, con comprobación posterior de registros y recibos.


### Validación y ajustes de la primera entrega

Se corrige la lectura de paginación de empleados/catálogos: Laravel devuelve un
paginador directo, mientras los Resources de Taller contienen `meta`. La integración
soporta ambos contratos y filtra empleados administrativos por cuenta y empleado
activos con rol mecánico. Las consultas de selectores no impiden mostrar indicadores
cuando un catálogo falla; se informa su indisponibilidad.

No se muestran acciones de detalle hasta completar su consulta. Los diagnósticos
históricos no habilitan un selector vacío de alcance. La búsqueda de órdenes incluye
el código visible del vehículo. Un 403 puntual no revoca el módulo en el navegador:
se verifica identidad/roles, se retira el registro rechazado y se conserva navegación.
Revocación efectiva o cuenta bloqueada sí limpian los datos privados. Confirmación
de comando y éxito de actualización se comunican por separado; el POST confirmado
no se vuelve a enviar porque falle la consulta posterior.

Las pruebas usan claves, sesiones, cachés, vistas compiladas y configuración propios
en /tmp. Ignoran el hot file de desarrollo sin modificarlo. El runner acepta
`PLAYWRIGHT_PYTHON` y `CHROMIUM_EXECUTABLE`; bibliotecas adicionales pueden configurarse
solo para el proceso con `LD_LIBRARY_PATH`. No se instalan herramientas automáticamente
al ejecutar pruebas ni se modifican dependencias de aplicación.

La validación E2E detectó además un alias SQL `authorization` sin citar en el filtro
no administrativo de decisiones/aplicaciones. Se corrige usando el Grammar existente
para identificar `content`, manteniendo intacta la exclusión de solicitudes económicas.
Las pruebas cubren consultas mecánicas vacías, decisiones y aplicaciones confirmadas,
acceso por orden y el recorrido completo con la API y PostgreSQL reales.

## Taller Mecánico — frontend, segunda entrega

La orden integra `OrderOperations` (ejecución, tiempos, materiales y costos) y
`EvidencePanel` (archivos privados). Reutiliza sesión/CSRF, diálogos, selectores y
recibos idempotentes existentes. Las actividades y eventos de tiempo inician la
reparación únicamente mediante la transición del backend con versión vigente.
Las correcciones técnicas y las rectificaciones administrativas conservan originales;
las decisiones se aplican por el endpoint correspondiente, incluido el lote atómico.
Los resúmenes de segundos-persona y costos por base/moneda provienen del servidor;
no se calcula stock, conversión monetaria ni doble contabilización en el navegador.

Las evidencias usan SHA-256, reserva, transferencia, confirmación y consulta de recibo.
El progreso representa bytes enviados; no acredita commit. Las operaciones interrumpidas
conservan metadatos mínimos, versión de recuperación y claves en sessionStorage por
actor/orden, sin bytes de archivo ni tokens. Recargar consulta el recibo y exige volver
a seleccionar el mismo archivo cuando corresponda; no repite POST automáticamente.
El cierre de sesión requiere resolver el pendiente; la expiración oculta datos y permite
recuperar con la misma cuenta. Cambiar de cuenta limpia descriptores de otros usuarios.
La cuota de 20 incluye originales, revisiones y reservas; el backend sigue como autoridad.

La validación real detectó que los dos modelos de ejecución descartaban el offset al
serializar fechas. WorkshopActivity y WorkshopTimeEvent conservan microsegundos y offset
con `Y-m-d H:i:s.uP`, sin cambio de esquema ni reparación automática de datos históricos.
Las pruebas PostgreSQL comprueban el instante UTC y un intervalo de `1.000001` segundos.
Pruebas: tipos/build, contratos frontend, Chromium aislado y
`npm run test:e2e-workshop-execution` sobre PostgreSQL 18 temporal.
