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
