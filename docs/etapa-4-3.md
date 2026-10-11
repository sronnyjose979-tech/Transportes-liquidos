# Etapa 4.3 — Interfaz web de documentación de flota

Base: `1b636ba`. Entrada: `/documentacion`, con enlace desde `/`.

## Pantallas y permisos

- Inicio/cierre de sesión con la API existente.
- Selección paginada de cabezal o cisterna por código y tipo.
- Documentos actuales con emisión, vencimiento, vigencia, autor y fecha de registro.
- Indicadores del activo: vigentes, próximos a vencer, vencidos, sin vencimiento y
  obligatorios faltantes; filtro de vigencia y paginación de documentos/requisitos.
- Registro y renovación con archivo privado PDF/JPG/PNG, fechas y errores por campo.
- Historial completo o de un tipo con nombres/requisitos conservados al registro,
  identificación actual/anterior y descarga privada; sin editar ni eliminar.
- Tipos documentales: consulta para administrador y secretaria; creación y
  configuración solamente para administrador. Otros roles ven acceso denegado.

Laravel conserva la autorización efectiva y todas las reglas de negocio. No se
calculan vencimientos ni selección actual en el navegador. No se cambia el esquema.

## Dependencias y archivos

Se instalaron únicamente las cuatro dependencias aprobadas y sus transitivas:
`vue@3.5.43`, `@vitejs/plugin-vue@6.0.10`, `typescript@5.9.3`, `vue-tsc@3.3.13`.
Vite 8.3.4 y Tailwind 4.3.3 conservaron sus versiones instaladas.

Creados:

- `package-lock.json`, `tsconfig.json`, `resources/js/app.ts`.
- `resources/views/fleet-documents.blade.php`.
- `resources/js/modules/FleetAndDocuments/FleetDocumentsApp.vue`.
- En ese módulo: `api.ts`, `contracts.ts`, `presentation.ts`.
- En `components/`: `AssetPicker.vue`, `AssetDocuments.vue`, `DocumentList.vue`,
  `DocumentActions.vue`, `DocumentUploadForm.vue`, `DocumentTypes.vue`,
  `Pagination.vue`, `FieldError.vue`, `ValidityBadge.vue`,
  `HistoricalConfiguration.vue`.
- `app/Modules/FleetAndDocuments/Http/Controllers/FleetAssetLookupController.php`.
- `app/Modules/FleetAndDocuments/Http/Requests/FleetAssetLookupRequest.php`.
- `app/Modules/FleetAndDocuments/Http/Resources/FleetAssetLookupResource.php`.
- `tests/Feature/FleetDocumentWebTest.php`.
- `tests/Frontend/test_fleet_documents.py`.
- `docs/etapa-4-3.md`.

Modificados:

- `.gitignore` para excluir caché Python de las pruebas de navegador.
- `package.json`, `vite.config.js`, `resources/css/app.css`.
- `resources/views/welcome.blade.php`, `routes/web.php`, `routes/backend.php`.
- `app/Modules/FleetAndDocuments/Application/DocumentAdministration.php`.
- `app/Modules/FleetAndDocuments/Http/Resources/DocumentResource.php`.
- `ARCHITECTURE.md`.

## API e integración

Único endpoint JSON añadido: `GET /api/fleet-assets`, lectura autorizada mediante
`manage-fleet-documents`, paginación máxima 100, y solo `id`, `code`, `type`.
No amplía permisos de administración ni crea acciones de escritura de flota.

Las respuestas documentales incorporan `uploaded_by: {id, name}` mediante eager
loading limitado a esos atributos. Se conserva `uploaded_by_id` por compatibilidad.
El nombre visible es el actual de la cuenta; no se añadió una copia histórica ni
se expone email, login u otro dato de cuentas.

Vue se monta en Blade/Vite, con Composition API y TypeScript estricto. `fetch`
utiliza el mismo origen, sesión, CSRF y `no-store`. Contratos se comprueban también
en ejecución. Errores 401/403 retiran contenido o revalidan roles; 404 informa
ausencia; 419 exige actualización manual de seguridad; 422 conserva formulario y
archivo y muestra errores por campo. Errores internos no se imprimen. No hay
reenvíos automáticos de escrituras ni almacenamiento privado persistente.

## Verificación

Activar las herramientas del entorno y ejecutar:

```sh
source /workspace/.tools/activate.sh
bash tests/run-postgres.sh
npm run typecheck
npm run build
npm run test:frontend
vendor/bin/pint --test
git diff --check
```

Las pruebas de navegador requieren Python con Playwright y `/usr/bin/chromium`,
ya disponibles aquí. Arrancan PHP en un puerto localhost temporal, usando la vista
Blade y artefactos compilados; interceptan la API con datos sintéticos y no conectan
con ninguna base. Los permisos/persistencia y almacenamiento reales se prueban por
separado mediante PHPUnit sobre PostgreSQL 18 aislado.

Resultados finales en este entorno:

| Verificación | Resultado |
| --- | --- |
| `bash tests/run-postgres.sh` | 96 pruebas correctas, 1.113 aserciones; PostgreSQL 18.3 temporal. |
| `npm run typecheck` | Correcto, TypeScript estricto y componentes Vue. |
| `npm run build` | Correcto, Vite 8.3.4. |
| `npm run test:frontend` | 14 pruebas correctas en Chromium; API sintética y Blade/Vite reales. |
| `vendor/bin/pint --test` | Correcto. |
| `git diff --check` | Correcto; también revisados los archivos nuevos. |

Clúster final: `/tmp/transporte-tests-fNVkZC`, detenido por el runner.
Capturas responsive y de cada prueba: `/tmp/transporte-web-tests-d0c50cfz`.
Las pruebas cubren permisos mínimos y revocación, representación pública del autor,
formularios, carga/renovación, historial congelado, descarga binaria, filtros,
paginación, validación preservando entradas, errores de sesión/CSRF/servidor,
prevención de doble envío, respuestas tardías, teclado y ausencia de almacenamiento
privado persistente. Se inspeccionaron también las capturas de las tres resoluciones.

## Limitaciones

- No se probó un recorrido completo navegador–API–PostgreSQL con sesión real;
  se combinaron pruebas del navegador con API simulada y pruebas HTTP reales del
  backend en PostgreSQL aislado. No se modificó `transporte_db`.
- Indicadores por activo, refrescados manualmente o tras registro/renovación;
  consultas independientes pueden observar cambios concurrentes distintos.
- Sin reintentos automáticos ni nueva idempotencia; ante respuesta perdida se debe
  consultar el estado actual antes de reenviar una carga.
- Sin offline, correo, push, tareas programadas ni cambios de despacho.
- La compilación mantiene el aviso previo de fuentes opcionales `fontaine`;
  no impide compilar y no se instaló esa dependencia.
- No se generaron cuentas ni migraciones nuevas, ni se aprovisionó una base real.
  La restricción de migración 4.2 para historial no verificable permanece vigente.
- Sin commit ni push.
