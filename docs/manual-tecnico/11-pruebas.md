# 11. Pruebas y lectura de resultados


## Niveles de evidencia

Una **prueba unitaria** verifica una pieza aislada; una **funcional** ejercita un caso HTTP/aplicación; una **prueba de integración** comprueba componentes reales juntos; una **E2E** recorre el sistema desde el navegador hasta PostgreSQL. Un **contrato** verifica forma/reglas de un intercambio. Una respuesta simulada sirve para probar interfaz, pero no demuestra que Laravel acepte la operación.

El proyecto usa PHPUnit para PHP, Python unittest/Playwright para frontend/E2E y scripts Node para contratos específicos. No atribuir los tests frontend a Vitest/Jest: no son las herramientas configuradas. TypeScript revisa tipos, Vite compila y Pint revisa estilo PHP; ninguno demuestra por sí solo el flujo empresarial.

## Comandos reales

Desde la raíz, con herramientas aprobadas ya disponibles:

```bash
bash tests/run-postgres.sh
npm run test:frontend
npm run test:frontend-api
npm run typecheck
npm run build
vendor/bin/pint --test
git diff --check
```

`tests/run-postgres.sh` crea PostgreSQL 18 temporal, socket privado sin TCP y base `transporte_test`. Configura credenciales/clave/caché/archivos sintéticos y detiene el clúster al terminar. `tests/E2E/bootstrap.php` evita cargar la configuración privada del desarrollo para construir las pruebas. No sustituir por `php artisan migrate`/test contra una conexión incierta, SQLite ni `transporte_db`.

`PLAYWRIGHT_PYTHON`, `CHROMIUM_EXECUTABLE` y, cuando el entorno lo requiera, bibliotecas por proceso permiten reutilizar el navegador instalado. El runner no es autorización para reinstalar herramientas ni cambiar `.env`.

## Suites E2E disponibles

Todas las opciones siguientes se pasan a `bash tests/run-postgres.sh` y usan infraestructura real aislada:

| Opción | Recorrido |
| --- | --- |
| `--e2e` | Documentación de flota |
| `--e2e-transport` | Solicitudes, viajes, operaciones y reglas de Transporte |
| `--e2e-workshop` | Núcleo web de Taller |
| `--e2e-workshop-execution` | Ejecución, tiempos y evidencias |
| `--e2e-workshop-circuit` | Ingreso, reparación, autorización y salida |
| `--e2e-administration` | Recursos/catálogos y su integración |
| `--e2e-purchase-requests` | Solicitudes de compra y decisiones |
| `--e2e-supply` | Inventario/Compras, custodia y recepción |
| `--e2e-navigation` | Inicio y navegación por roles |
| `--e2e-billing` | Importes, facturas administrativas y pagos |
| `--e2e-expenses-driver` | Gastos y registros del conductor |
| `--e2e-driver-offline` | Chromium persistente, desconexión/reinicio/sincronización |
| `--e2e-reports` | Reportes consolidados |
| `--e2e-document-alerts` | Vencimientos, filtros y revisión |
| `--e2e-audit` | Auditoría y filtros |

`package.json` también ofrece `test:e2e-workshop` y `test:e2e-workshop-execution` como accesos a dos opciones. Consultar `tests/run-postgres.sh` para los filtros admitidos y artefactos; no inventar una opción `--e2e-all`.

## Qué buscar en las pruebas PHP

En `tests/Feature` se verifican permisos HTTP, relaciones, estados, restricciones PostgreSQL, idempotencia, conflictos, documentos privados y dinero exacto. Los casos de concurrencia no se satisfacen enviando dos peticiones secuenciales que casualmente pasan: necesitan conexiones/procesos y coordinación del runner correspondiente. `QueryExceptionSafetyTest` comprueba que datos sensibles no aparecen en respuestas/logs. Las pruebas de altas P1-05 incluyen clave repetida, contenido diferente, concurrencia y respuesta perdida.

Las migraciones incluyen defensas ante SQL directo. Probar solamente que el Form Request devuelve 422 no demuestra esas defensas; revisar casos que insertan/escriben mediante conexión real y esperan rechazo del trigger/constraint.

## Frontend y contratos

`tests/Frontend` contiene interfaces con API controlada para reproducir estados concretos. Ejemplos: `test_transport.py`, `test_billing.py`, `test_expenses.py`, `test_driver_portal.py`, `test_driver_offline_storage.py` y `test_internal_responsive.py`. En contraste, `tests/E2E/test_driver_offline.py` usa Laravel/PostgreSQL reales y perfil persistente de Chromium.

`npm run test:frontend-api` ejecuta los archivos `.test.mjs` declarados en `package.json` para Taller, recuperación de Compras, Reportes y Auditoría. El nombre “API” aquí no debe confundirse con ejecutar todas las APIs reales. Verificar contratos sintéticos y E2E como categorías separadas.

## Cómo interpretar el resultado

- `Tests: N, Assertions: M` cuenta casos/aserciones de esa ejecución, no usuarios ni flujos de producción.
- Un test omitido o una suite no ejecutada no equivale a aprobado.
- Un timeout exige distinguir defecto, entorno o prueba; no aceptar éxito porque la página cargó.
- Un error de compilación/tipos debe resolverse antes de publicar código afectado.
- Capturas/logs de pruebas son artefactos privados temporales; no deben publicarse con credenciales o datos.

## Evidencia previa y verificación del manual

[Validación integral](../validacion-integral.md) registra **391 pruebas backend/4124 aserciones**, **106 frontend**, **29 contratos** y **53 E2E reales en 15 suites**, además de tipos/build/Pint/diff. Son resultados de aquella ejecución, no conteos recalculados aquí ni garantía de todas las versiones posteriores.

Correcciones posteriores tienen evidencia específica en [preparación para producción](../preparacion-produccion.md), [formularios](../mejoras-formularios.md) y [PWA](../conductor-offline.md). La mejora móvil 38b1972 registró 19 pruebas frontend/almacenamiento y 3 E2E; la revisión responsive posterior tiene su cobertura en `test_internal_responsive.py`. No sumar ejecuciones que incluyen los mismos casos para fabricar un total único.

**Durante esta tarea documental no se ejecutó la batería de aplicación.** Se reconstruyó el esquema en una base nueva aislada y se inspeccionó router/código para verificar el manual. Las comprobaciones de enlaces, cobertura del inventario y diff son controles documentales, no nuevas pruebas funcionales aprobadas.
