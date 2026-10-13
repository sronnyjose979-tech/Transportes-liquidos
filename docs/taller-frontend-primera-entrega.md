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
   consulta empleados; secretaria proporciona el UUID existente.
4. Como mecánico con asignación vigente o administrador, registrar el diagnóstico.
5. Solicitar autorización de alcance: seleccionar diagnóstico, indicar trabajos y,
   cuando corresponda, repuestos/cantidades y proveedores reales. UUID de catálogos
   existentes; cantidades decimales como texto, sin suponer stock ni costos.
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
```

`test:frontend-api` usa Node y TypeScript instalados; verifica contratos HTTP, errores,
paginación, CSRF, precisión y cancelación. `test:frontend` usa Playwright Python y
`/usr/bin/chromium`, con Blade/Vite real y API sintética interceptada; sin esas
herramientas no puede ejecutarse. No instalarlas automáticamente.

PostgreSQL se ejecuta en un clúster temporal exclusivo de pruebas mediante el script
revisado. Nunca se usa la base del proyecto. La prueba de navegador no demuestra un
recorrido con API/DB real; probarlo en un entorno autorizado con cuentas y datos de prueba.

La API no ofrece actividad consolidada ni búsqueda textual global. El dashboard lo
informa y la búsqueda opera sobre la página visible. Métricas y consultas respetan
el alcance del usuario. Los catálogos de empleados y repuestos no están accesibles a
todos los roles: se admiten UUID existentes donde no hay consulta autorizada.
