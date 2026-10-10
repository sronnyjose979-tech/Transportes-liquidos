# Backend inicial

Módulos bajo app/Modules/{AccessAndPersonnel,TransportAndDispatch,Inventory,Purchasing}.
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
administradores activos, política de protección del último administrador y futuras
reglas de asociación empleado/funciones. No se impone que empleado inactivo desactive
su cuenta: son atributos distintos y esa dependencia no está aprobada.
