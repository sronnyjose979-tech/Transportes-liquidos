# Taller: circuito de ingreso y salida

## Pantallas y permisos

Se mantiene `/taller` y su diseño existente. La navegación de oficina incorpora
**Ingresos y salidas**, con paginación, filtro por vehículo y consulta de abiertos
más historial. El detalle muestra estado actual del activo, órdenes vinculadas,
impedimentos, advertencias de viajes, solicitudes, decisiones y salidas.

- Secretaria/administrador: registrar ingreso correctivo o preventivo, crear órdenes,
  vincularlas explícitamente al ingreso, asignar y liberar mecánicos manualmente.
- Administrador: resolver impedimentos permitidos, solicitar salida, aprobar/rechazar
  y aplicar la decisión mediante las operaciones existentes.
- Mecánico: conserva consulta histórica y las operaciones técnicas existentes sobre
  sus participaciones vigentes; no registra ingreso ni autoriza salida.

La salida física y la disponibilidad son conceptos diferentes. El impedimento propio
del ingreso solo se resuelve mediante la salida autorizada. Otros impedimentos
pueden conservar el bloqueo incluso después de una salida física. Cerrar una orden,
resolver una causa o registrar salida nunca libera recursos de Transporte.

## Contratos reutilizados

Todos bajo `/api/workshop`:

- Consultas paginadas `admissions`, `admission-links`, `orders`, `impediments`,
  `impediment-resolutions`, `warnings`, `exits`, `mechanic-assignments`,
  `mechanic-releases` y autorizaciones.
- `POST assets/{asset}/admissions`.
- `POST orders/{order}/admission-links`.
- `POST impediments/{impediment}/resolve` (administrador; excluye causa canónica).
- `POST admissions/{admission}/exit-requests`, indicando causas explícitas y fecha.
- `POST authorization-requests/{authorization}/decisions`.
- `POST decisions/{decision}/exit` aplica únicamente el contenido exacto aprobado.
- `POST mechanic-assignments/{assignment}/release` con fecha y motivo.
- `GET assets/{asset}/availability` consulta proyección actual confirmada.

Única API nueva: `GET lookups/mechanics`, paginada con búsqueda por nombre, devuelve
solo `id` y `name`. Permite a oficina seleccionar empleados y cuentas activos con rol
mecánico aunque no existan asignaciones anteriores. No concede acceso a Personal.
La elegibilidad vuelve a validarse al asignar. Las consultas de vínculos de ingreso
exponen `is_invalidated`; el vínculo original se conserva sin reutilizar el par único.

## Recuperación e integridad

Cada formulario conserva la clave y contenido exactos en memoria ante una respuesta
incierta. No hay reintentos automáticos ni cambios optimistas. Solo el usuario puede
reenviar el mismo comando idempotente; no se genera una clave nueva para ese reintento.
Sesión y roles se comprueban antes de escribir. Se manejan 401/403/404/409/419/422 y
fallos de conexión/servidor, y se distingue confirmación de actualización posterior.
La revisión de salida y la aplicación única siguen protegidas por los bloqueos,
versiones, auditoría y restricciones PostgreSQL existentes. Sin migraciones nuevas.

## Recorrido manual

1. Administrador registra cabezal/cisterna en `/administracion/flota`.
2. Oficina registra ingreso desde Taller y consulta el bloqueo.
3. Crea una orden por reparación y la vincula al ingreso; asigna mecánicos elegibles.
4. Mecánico registra diagnóstico y alcance; administrador decide y aplica autorización.
5. Mecánico registra ejecución, tiempos y terminación; administrador revisa y cierra.
6. Administrador resuelve las causas permitidas y solicita salida seleccionando
   explícitamente los impedimentos que se resolverán; revisa, decide y aplica.
7. Consulta salida e historial. Transporte vuelve a comprobar disponibilidad real:
   otros impedimentos o asignaciones sin liberación pueden seguir impidiendo asignar.

## Verificación

Con las herramientas existentes activadas:

```sh
npm run typecheck
npm run build
npm run test:frontend
npm run test:frontend-api
bash tests/run-postgres.sh
CHROMIUM_EXECUTABLE=/usr/bin/chromium bash tests/run-postgres.sh --e2e-workshop-circuit
vendor/bin/pint --test
git diff --check
```

El runner utiliza Laravel y PostgreSQL 18 temporal, sesiones y almacenamiento propios,
usuarios sintéticos y Chromium real; no simula respuestas de API ni modifica `.env`
o `transporte_db`. La suite frontend interceptada se reporta por separado.

No se resuelven reglas pendientes de Transporte (RN-TR-17, D05 ni cambios de producto).
Inventario y Compras siguen fuera del alcance: no se simulan existencias ni movimientos.

### Resultados ejecutados

- TypeScript, build, Pint y `git diff --check`: correctos.
- Backend completo PostgreSQL 18: **312 pruebas, 2.929 aserciones** (incluye concurrencia).
- Frontend Chromium con API interceptada: **50 pruebas correctas**, 19 de Taller.
- Contratos frontend: **22 pruebas correctas**.
- E2E real del circuito: **2 recorridos correctos**, 101,206 s, PostgreSQL 18.3.
  Registro desde Flota, ingreso y vínculo, primera asignación de mecánico por secretaria,
  ejecución y autorización, cierre, resolución, salida y disponibilidad en Transporte.
  También pérdida de respuesta confirmada con reenvío idempotente, permisos por rol,
  liberación manual con consulta histórica y disposición responsive.

Los controles posteriores no sustituyen la autorización real: otras causas o recursos
sin liberar pueden mantener impedida una nueva asignación aunque la reparación termine.
