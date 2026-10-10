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
No se ofrecen eliminaciones ni gestión pública de roles/usuarios.

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
