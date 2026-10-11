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
