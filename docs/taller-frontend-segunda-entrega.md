# Taller: segunda entrega frontend

Con el backend autorizado ya configurado, iniciar `php artisan serve --host=127.0.0.1`
y `npm run dev` en terminales separadas. Abrir `/taller` con cuentas existentes.
No se crean usuarios ni se aplican migraciones automáticamente.

1. Oficina crea y asigna la orden. Administrador aplica la autorización del alcance.
2. Mecánico abre su orden: en Ejecución registra actividades sobre trabajos aplicados.
   En Tiempos registra inicio, pausa, reanudación y fin. La API valida intervalos;
   el resumen muestra tiempo confirmado e informa intervalos abiertos.
3. En Materiales, oficina registra entregas físicas y mecánico registra utilización.
   Necesidades, entregas y usos conservan historial. No hay descuento de inventario.
4. En Evidencias, seleccionar archivo y motivo: reserva → transferencia → confirmación.
   PDF/JPEG/PNG, hasta 10.000.000 bytes y 20 archivos/reservas por orden. Una transferencia
   al 100% todavía necesita confirmación. Ante interrupción, consultar recibo o repetir
   la misma operación. Tras recarga, reseleccionar el mismo archivo si es necesario.
5. Oficina consulta y registra costos. Administrador registra reparaciones externas y
   solicita rectificaciones; en Autorizaciones decide y aplica. Totales por base/moneda
   provienen de PostgreSQL con precisión decimal y excluyen componentes cubiertos.
6. Administrador puede solicitar la rectificación de evidencia, aprobarla y cargar el
   archivo exacto aprobado desde la orden. Originales se conservan. Los lotes de
   rectificaciones se ordenan explícitamente y se confirman atómicamente en el backend.
7. Finalizar reparación y revisión/cierre son operaciones independientes. No liberan
   el vehículo ni cierran automáticamente intervalos de mecánicos.

Los selectores respetan catálogos y registros visibles; un elemento no accesible requiere
intervención del administrador. Las pruebas no usan bases de desarrollo ni producción.

```sh
npm run typecheck
npm run build
npm run test:frontend-api
npm run test:frontend
npm run test:e2e-workshop-execution
bash tests/run-postgres.sh --filter Workshop
vendor/bin/pint --test
```

Para Chromium aislado instalado durante esta sesión, utilizar las variables de
[la primera entrega](taller-frontend-primera-entrega.md#entorno-de-navegador-aislado-de-esta-validación).
Las claves de recuperación no son tokens de sesión; se conservan solo en la pestaña y
se limpian al cambiar de usuario. Si el navegador elimina ese almacenamiento, la API
no expone una colección de reservas para descubrirlas sin su recibo: se necesita
intervención administrativa; no se simula recuperación ni se crea otra operación a ciegas.

Validación realizada: 104 pruebas backend de Taller (806 aserciones), 43 pruebas de
interfaz Chromium, 22 pruebas de contratos y 3 recorridos E2E con API y PostgreSQL 18
aislado. TypeScript, build, Pint e integridad del diff aprobados. Se verificaron escritorio,
tablet y móvil; los recorridos operativos incluyen permisos, conflictos, reintentos,
costos decimales y evidencias privadas.
