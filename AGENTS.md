# Reglas de desarrollo para Codex

## 1. Contexto y fuentes de verdad

Sistema empresarial de transporte de líquidos, con una PWA para conductores.
Stack objetivo: Laravel 13, PostgreSQL 18, Vue 3, TypeScript y PHP compatible
con las dependencias aprobadas. Distinguir el stack objetivo del instalado y
verificar versiones en manifiestos, archivos de bloqueo y entorno.

Las instrucciones explícitas del usuario determinan el alcance autorizado.
El modelo entidad-relación y las reglas de negocio aprobados son la fuente
de verdad del esquema, el comportamiento y los procesos del sistema.
Las decisiones arquitectónicas y los contratos aprobados los complementan;
las convenciones existentes solo se conservan si son compatibles.

No inventar entidades, atributos, relaciones, estados, permisos, cálculos,
procesos ni reglas de negocio. El esqueleto de Laravel y el código existente
no constituyen aprobación del diseño de negocio. Si falta una definición
necesaria o existe una contradicción, solicitar aclaración antes de implementar
lo que dependa de ella.

## 2. Alcance y autorización

Antes de implementar, revisar el código y describir alcance, archivos afectados
y validación prevista.

Durante análisis o propuestas, trabajar en modo de lectura: no modificar
archivos, instalar dependencias ni ejecutar migraciones o seeders.

Durante una implementación autorizada:
- Mantener los cambios dentro del alcance y preservar cambios ajenos.
- Solicitar aprobación antes de añadir dependencias, alterar el esquema aprobado
  o ejecutar operaciones destructivas.
- No desplegar ni modificar producción sin autorización explícita.
- Revisar los efectos de los scripts antes de ejecutarlos. No ejecutar
  `composer setup` como preparación automática.
- No actualizar versiones ni instalar herramientas automáticamente.

## 3. Arquitectura modular, SOLID, DRY y KISS

Organizar el código por capacidades de negocio aprobadas. Documentar límites,
nombres y estructura física de los módulos antes de crear el primero.

Separar responsabilidades:
- HTTP: validación, autorización y representación.
- Aplicación: coordinación de casos de uso y transacciones.
- Dominio: reglas de negocio aprobadas.
- Infraestructura: persistencia e integraciones externas.

Mantener controladores pequeños y lógica de negocio fuera de rutas, vistas y
componentes de interfaz. Evitar dependencias circulares y acceso indiscriminado
entre módulos.

Preferir soluciones simples y explícitas. Extraer abstracciones ante necesidades
concretas; no crear capas, repositorios o interfaces que solo dupliquen Laravel.
Usar inyección de dependencias en los límites que lo requieran.

## 4. Backend y contratos

Seguir convenciones de Laravel y PSR-4. Usar tipos explícitos cuando corresponda,
Form Requests para validación cuando resulte apropiado y Policies o Gates
para autorización en el servidor.

Definir contratos de entrada, salida y errores; utilizar Resources para API
cuando corresponda. No introducir API, Inertia u otro mecanismo de integración
con el frontend sin una decisión arquitectónica aprobada.

Controlar asignación masiva con campos modificables explícitos. Prevenir
consultas N+1 y usar paginación cuando corresponda.

## 5. Modelo de datos

Antes de implementar persistencia, contrastar con el modelo aprobado:
- Entidades, atributos, claves primarias y foráneas.
- Cardinalidades, nulabilidad, unicidad e integridad.
- Tipos, precisión, unidades y reglas de actualización y eliminación.

No eliminar tablas del esqueleto sin revisar dependencias. No introducir borrado
lógico, auditoría, multitenencia o campos adicionales sin necesidad aprobada.

Proteger integridad con restricciones de base de datos. Elegir índices según
restricciones y consultas previstas. No usar coma flotante para valores que
requieran precisión exacta. Definir fechas, zonas horarias y unidades antes de
implementar operaciones que dependan de ellas.

Revisar migraciones antes de ejecutarlas y documentar riesgos de bloqueo,
pérdida de datos y recuperación. No modificar migraciones ya desplegadas;
crear nuevas migraciones.

## 6. Transacciones ACID, concurrencia e idempotencia

Definir la unidad atómica de cada operación. Usar transacciones para cambios
que deban confirmarse juntos y mantenerlas breves.

Evitar llamadas externas dentro de transacciones cuando prolonguen bloqueos o
produzcan efectos irreversibles. Despachar efectos externos después del commit;
si se requiere entrega fiable, diseñar un mecanismo durable aprobado.

No confiar solo en validaciones previas para garantizar unicidad o evitar
conflictos. Elegir restricciones, actualizaciones condicionales, control optimista
o bloqueos de filas dentro de transacciones según el caso de uso. Documentar
el aislamiento y los conflictos relevantes; adquirir bloqueos en orden consistente.

Para operaciones expuestas a reintentos, definir idempotencia cuando corresponda:
- Conservar la misma clave para los reintentos de una operación lógica.
- Validar su alcance y detectar reutilización con contenido incompatible.
- Proteger atómicamente el registro de la operación y sus cambios de negocio.
- Devolver resultados coherentes ante duplicados, incluso concurrentes.
- Definir retención y expiración según la ventana de reintentos aprobada.

Aplicar reintentos limitados solo a operaciones seguras de repetir. Los mecanismos
que requieran cambios de esquema deben pasar por aprobación.

## 7. PWA de conductores y trabajo offline

Definir qué datos y operaciones aprobadas estarán disponibles offline, su duración
y permisos. No crear procesos de negocio para resolver limitaciones de conectividad.

Usar IndexedDB para datos locales y operaciones pendientes autorizadas. Separar
conceptualmente caché de lectura y cola de sincronización. Versionar la estructura
local y definir actualización, recuperación y tratamiento de cuotas o almacenamiento
eliminado, sin descartar cambios pendientes en silencio.

La sincronización debe:
- Aplicar la idempotencia de la sección 6 a cada operación pendiente.
- Tolerar interrupciones, duplicados, respuestas perdidas y sincronizaciones
  simultáneas desde pestañas o dispositivos.
- Reintentar errores transitorios con espera progresiva y límites definidos;
  tratar aparte validación, autorización y conflictos.
- Retirar pendientes solo tras confirmación inequívoca del servidor.
- Respetar orden y dependencias exigidos por procesos aprobados.
- Ofrecer sincronización manual y al recuperar conexión; no depender únicamente
  de Background Sync ni asumir conectividad por `navigator.onLine`.

El servidor debe volver a validar y autorizar cada operación al sincronizar.
Una operación local no garantiza que siga siendo válida. Mostrar conexión,
pendientes, errores y conflictos sin presentar cambios locales como confirmados.

Definir resolución de conflictos por caso de uso con reglas aprobadas. Detectar
cambios concurrentes con versiones u otro mecanismo acordado. No sobrescribir
silenciosamente ni aplicar automáticamente «última escritura gana». Conservar
información para revisión y solicitar intervención cuando no exista resolución
determinista aprobada. No usar la hora del dispositivo como autoridad de orden.

Minimizar datos sensibles en IndexedDB y cachés del service worker. No guardar
contraseñas ni tokens de sesión de larga duración en ese almacenamiento. Separar
datos por usuario y definir limpieza al cerrar sesión o cambiar de cuenta, incluido
el tratamiento explícito de pendientes. No cachear respuestas privadas indiscriminadamente.
Revisar controles adicionales para datos sensibles: IndexedDB no protege frente a XSS.

## 8. Seguridad por entorno, respaldos y restauración

### Controles comunes

Autorizar cada operación y acceso al recurso en el servidor. Usar consultas
parametrizadas y protección CSRF y XSS según la autenticación. Evitar HTML no
confiable; aplicar límites de solicitudes y validación de archivos cuando corresponda.

No publicar ni registrar secretos o datos sensibles, ni incluirlos en código,
pruebas, documentación o commits. Leer solo variables de entorno necesarias y
acceder mediante configuración de Laravel desde el código de aplicación.
Aplicar privilegios mínimos.

### Desarrollo local y pruebas

Usar credenciales, claves, bases y servicios separados de producción. Mantener
`.env` fuera del control de versiones y `.env.example` sin secretos. Usar datos
sintéticos o anonimizados; no copiar datos reales sin autorización y controles acordados.

Permitir `APP_DEBUG=true` solo en entorno local controlado, sin exponer el depurador
a redes no confiables. Proteger respaldos locales. Verificar destino antes de restaurar
y no apuntar pruebas a producción.

### Producción

Usar HTTPS, cookies seguras según la autenticación y `APP_DEBUG=false`. Separar
credenciales de aplicación, despliegue, administración y respaldos según sus funciones.
Gestionar secretos fuera del repositorio y definir rotación y revocación. Restringir
acceso a base de datos y almacenamiento.

Antes de operar, aprobar política de respaldo y recuperación con RPO, RTO,
frecuencia, retención y responsables. Cifrar respaldos, restringir acceso y mantener
copias aisladas del sistema principal. Verificar integridad y ensayar restauraciones
periódicas en entorno aislado, documentando resultados. La finalización de un
respaldo no demuestra por sí sola que pueda restaurarse.

Antes de cambios de datos con riesgo, comprobar recuperación viable. Restaurar o
sobrescribir datos requiere autorización explícita, comprobar destino y documentar
impacto y verificaciones posteriores.

## 9. Vue y TypeScript

Una vez aprobada su incorporación, usar Vue 3 con Composition API, componentes
coherentes y TypeScript estricto. Tipar props, eventos y contratos; evitar `any`
y justificar excepciones.

Mantener reglas autoritativas en backend. Manejar carga, errores y accesibilidad.
No introducir estado global ni librerías sin necesidad concreta.

## 10. Pruebas automatizadas

Añadir pruebas proporcionales al comportamiento implementado:
- Unitarias para lógica aislada.
- Funcionales para validación, autorización y casos de uso.
- Integración PostgreSQL para persistencia e integridad.
- Concurrencia para operaciones susceptibles a conflictos.
- Frontend para interacciones relevantes una vez configurado.
- PWA para cola local, reconexión, duplicados, confirmaciones perdidas,
  expiración de sesión, conflictos y aislamiento entre usuarios.

Las pruebas dependientes de PostgreSQL deben usar PostgreSQL 18 en una base
exclusiva de pruebas; SQLite no sustituye estas verificaciones. Probar éxitos y
errores relevantes, incluyendo accesos no autorizados, rollback y conflictos.
No usar datos reales ni servicios de producción. Verificar aislamiento antes de ejecutar.

## 11. Calidad, CI y entrega

Usar herramientas instaladas y aprobadas. Configurar mediante tarea autorizada:
formato PHP con Laravel Pint, pruebas, análisis estático PHP con herramienta
aprobada, tipos, lint y compilación frontend, e integración PostgreSQL.

Explicar comportamiento cambiado, pruebas ejecutadas y limitaciones. No afirmar
que una comprobación pasó si no se ejecutó. Documentar decisiones arquitectónicas;
mantener cambios pequeños y revisables, sin refactorizaciones ajenas al objetivo.

## 12. Laravel Boost y preparación del entorno

Conservar de las instrucciones iniciales la verificación de PHP y Composer:

```sh
php -v
composer -V
```

Si falta un requisito, identificar el sistema operativo y proponer instalación
compatible; no instalar automáticamente. Si una instalación autorizada requiere
reiniciar la terminal, indicarlo antes de continuar.

No instalar Laravel Boost en esta etapa. Una futura instalación requiere aprobación
explícita y revisión de archivos que pueda generar o reemplazar. Después de instalarlo,
releer `AGENTS.md` y preservar estas reglas aprobadas. Incorporar únicamente
instrucciones de Boost compatibles con el alcance, las fuentes de verdad y las
políticas del proyecto.
