# Taller Mecánico: primera entrega frontend

## Inicio y recorrido

Con las dependencias del proyecto ya instaladas y el backend configurado en su entorno
autorizado, iniciar en dos terminales:

```sh
php artisan serve --host=127.0.0.1
npm run dev
```

Abrir http://127.0.0.1:8000/taller (también disponible en `/`). Usar una cuenta activa
existente de administrador, secretaria o mecánico. No se crean usuarios ni se aplican
migraciones automáticamente. La base debe tener las migraciones de Taller previamente
aprobadas y aplicadas en su entorno correspondiente.

1. Registrar una avería y consultar su detalle.
2. Como secretaria/administrador, crear una orden para esa avería; la orden abarca una
   sola reparación. En el detalle, «Vincular esta avería a la orden» confirma el vínculo
   mediante una operación independiente.
3. Asignar un empleado activo vinculado a una cuenta mecánica activa. Administrador
   busca en empleados elegibles; secretaria elige entre asignaciones vigentes visibles.
   Si falta un empleado en su contexto, debe intervenir el administrador.
4. Como mecánico con asignación vigente o administrador, registrar el diagnóstico.
5. Solicitar autorización de alcance: seleccionar diagnóstico, indicar trabajos y,
   cuando corresponda, repuestos/cantidades y proveedores reales mediante selectores
   con búsqueda; cantidades decimales como texto, sin suponer stock ni costos.
   Administrador consulta catálogos completos; los demás roles ven referencias existentes
   en sus órdenes accesibles. No hay entrada manual de identificadores.
6. Como administrador, abrir Autorizaciones y registrar aprobación/rechazo motivado.
   Aprobar no aplica automáticamente: confirmar «Aplicar autorización» por separado.
7. Consultar la orden actualizada. Ejecución, tiempos, inventario, costos y adjuntos
   quedan para la segunda entrega. Terminar una orden no equivale a liberar el vehículo.

Los estados de éxito corresponden a respuestas confirmadas. Si se pierde la respuesta,
se conserva el comando exacto mientras el formulario siga abierto; «Reintentar la misma
operación» recupera el resultado con su UUID original. No cerrar la pestaña durante esa
reconciliación: esta entrega no incluye persistencia offline. Un conflicto de versión
requiere cerrar el formulario, actualizar y revisar una nueva operación.

## Verificación y límites

```sh
npm run typecheck
npm run build
npm run test:frontend-api
npm run test:frontend
bash tests/run-postgres.sh --filter Workshop
npm run test:e2e-workshop
```

`test:frontend-api` usa Node y TypeScript instalados; verifica contratos HTTP, errores,
paginación, CSRF, precisión y cancelación. `test:frontend` usa Playwright Python y
Chromium, con Blade/Vite real y API sintética interceptada. El runner acepta
`PLAYWRIGHT_PYTHON` y `CHROMIUM_EXECUTABLE`; sin esas herramientas no puede ejecutarse.
La ejecución no instala herramientas. Usa el build aunque Vite dev esté activo, sin
tocar `public/hot`.

PostgreSQL se ejecuta en un clúster temporal exclusivo de pruebas mediante el script
revisado. Nunca se usa la base del proyecto. La suite `test:e2e-workshop` sí ejecuta el recorrido con API/DB reales y comprueba
registros persistidos, permisos, respuesta perdida y clave idempotente. Sus datos
sintéticos se crean exclusivamente tras verificar la identidad del clúster temporal.

La API no ofrece actividad consolidada ni búsqueda textual global. El dashboard lo
informa y la búsqueda opera sobre la página visible. Métricas y consultas respetan
el alcance del usuario. Los catálogos de empleados y repuestos no están accesibles a
todos los roles: los selectores se limitan al contexto visible y requieren intervención
administrativa para elementos no accesibles. No se amplía la matriz de permisos.


## Entorno de navegador aislado de esta validación

Playwright 1.63.0 y Chromium 153 se instalaron fuera del proyecto en /tmp. Las
bibliotecas NSPR/NSS/ALSA faltantes se extrajeron de paquetes Ubuntu dentro de /tmp;
no se instalaron paquetes del sistema ni se cambiaron los locks de Laravel/Vue.
Mientras esos directorios temporales existan, se puede repetir así:

```sh
export PLAYWRIGHT_PYTHON=/tmp/transporte-browser-tools/bin/python
export CHROMIUM_EXECUTABLE=/tmp/transporte-playwright-browsers/chromium-1243/chrome-linux64/chrome
export LD_LIBRARY_PATH=/tmp/transporte-chromium-libs/usr/lib/x86_64-linux-gnu
npm run test:frontend
npm run test:e2e-workshop
```

Esos paths son herramientas temporales de esta sesión, no requisitos de producción.
Si se eliminan, configurar instalaciones autorizadas equivalentes mediante las mismas
variables. En entornos con sandbox, los servidores locales y Chromium pueden necesitar
permiso de ejecución por sus sockets; nunca redirigir pruebas a una base existente.

## Resultados de la revisión

- Se corrigieron los dos formatos de paginación existentes, la búsqueda por código
  de vehículo, acciones durante carga, el acceso a diagnósticos efectivos y mensajes
  de confirmación separados de la actualización posterior.
- Se eliminaron campos UUID manuales. Los selectores tienen búsqueda, vacío explícito
  y conservación de selección; se mantienen los límites de lectura de cada rol.
- Se corrigió un alias SQL sin citar que impedía consultar decisiones/aplicaciones
  a mecánicos. PHPUnit ahora cubre las colecciones vacías y con registros confirmados.
- Chromium validó recorrido real con secretaria, administrador y mecánico; cantidades
  `1.000001`, autorización aplicada y versión de orden se comprobaron en PostgreSQL 18.
- Cinco E2E reales aprobados, incluidos respuesta perdida, sesión expirada y acceso
  mecánico histórico. Capturas y trazas: `/tmp/transporte-tests-QspeX4/e2e/browser`.
- Doce pruebas de interfaz de Taller con API interceptada y nueve pruebas de contratos
  Node aprobadas. Las pruebas de backend de Taller pasaron 102 casos; tras corregir SQL,
  las siete pruebas de consulta pasaron 262 aserciones. Tipos, build y Pint aprobados.

No se implementó la segunda entrega ni se añadieron endpoints o permisos. La ausencia
de un elemento en el contexto permitido de secretaria/mecánico requiere al administrador;
no se hace un fallback de entrada libre que eluda ese límite.

La suite completa de interfaz también pasó **36/36** casos: Flota/Documentos (14),
Transporte (10) y Taller (12), incluyendo navegación activa en detalles. Capturas de
Taller: `/tmp/transporte-web-tests-nuo72znz`. El recorrido E2E se repitió con la
navegación final y pasó; capturas definitivas en escritorio/tablet/móvil:
`/tmp/transporte-tests-PkaeQh/e2e/browser`.
