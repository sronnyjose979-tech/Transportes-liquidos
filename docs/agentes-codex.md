# Perfiles de Codex del proyecto

Configuración local: [.codex/config.toml](../.codex/config.toml), con cinco capas TOML
bajo `.codex/agents/`. Se conserva AGENTS.md y se añade únicamente coordinación.
No se modifica `~/.codex/config.toml`, modelos, proveedores, credenciales ni permisos.
`.gitignore` permite exclusivamente estos seis TOML; otros archivos locales de Codex
siguen excluidos. No contienen secretos.

| Perfil | Responsabilidad reutilizable |
| --- | --- |
| coordinator | Organizar, delegar, consolidar, respetar decisiones y asignar un editor por archivo. |
| functional_analyst | Revisar requisitos, reglas, contradicciones y omisiones del módulo de la tarea. |
| database_architect | Revisar ER, claves, cardinalidades, integridad, concurrencia e idempotencia. |
| integration_specialist | Revisar contratos y capacidades reales entre módulos y futuras integraciones. |
| technical_auditor | Revisar seguridad, permisos, trazabilidad, documentación y alcance de cambios. |

Perfiles aplicables a Transporte, Flota, Taller, Inventario, Compras y Facturación.
RN-TAL, D-TAL y T01–T09 son contexto de la tarea 6.2, no instrucciones permanentes.
El coordinador principal sigue las responsabilidades añadidas en AGENTS.md; declarar
un rol coordinator no selecciona automáticamente ese rol para la sesión principal.
Cada agente hereda las fuentes y reglas de proyecto; solo edita si la tarea lo autoriza
y recibe propiedad del archivo. Si no hay delegación, realizar revisiones secuenciales.

## Formato y disponibilidad verificados — 12 de octubre de 2026

Instalación inspeccionada: `codex-cli 0.162.0-alpha.17.2`. Configuración global existente:
solo entrada de confianza para este repositorio; conservada. No había configuración
local previa. `codex features list` informa `multi_agent stable true`; v2 permanece
false y no se activa. PHP 8.5.4 y Composer 2.9.5 disponibles; no se instala nada.

Formato oficial utilizado: `features.multi_agent`, `agents.max_threads`,
`agents.<nombre>.description/config_file` y `developer_instructions` en cada capa.
`max_threads = 3` limita subagentes concurrentes, además del principal: cuatro perfiles
especializados no exigen cuatro sesiones simultáneas. Los paths relativos se resuelven
respecto de `.codex/config.toml`. Sin cambios de modelo ni esfuerzo de razonamiento.

Fuentes oficiales consultadas:
[referencia de configuración](https://learn.chatgpt.com/docs/config-file/config-reference) y
[subagentes](https://learn.chatgpt.com/docs/agent-configuration/subagents).

Validación realizada: parseo TOML de configuración y cinco perfiles, existencia de paths,
ausencia de reglas Taller específicas en perfiles y carga de flags por CLI sin errores.
La sesión actual delegó realmente a analista, arquitecto, especialista y auditor, y recibió
sus resultados. El principal fue el único editor; todos los subagentes trabajaron en lectura.
La herramienta de esta sesión no ofrece selección `agent_type/config_file`: se pidió a cada
subagente leer su perfil. Esto prueba delegación y uso de las instrucciones, **no** carga
automática de roles por el selector del cliente IDE en una sesión nueva.

Se intentó validación `app-server --strict-config --stdio`; el arranque falló al inicializar
el almacenamiento local de estado de Codex, protegido en este entorno. Por ello no se afirma
validación estricta completa ni selección automática de perfiles en VS Code. El modo strict
no se admite en generate-json-schema. No se cambió el estado global ni se inició otra sesión
con credenciales. Al abrir una nueva sesión local de VS Code, verificar que se carguen los
roles del proyecto; si el cliente no los expone, mantener revisión secuencial o pedir a los
subagentes leer las capas como en esta sesión. Esto no bloquea el cierre documental.

## Resultado de revisión del cierre 6.2

Analista: preservar RN/D-TAL literalmente; T01 resuelve reserva D-TAL-13; cierre ordinario
sin segunda aprobación; reapertura con destino y originales; tiempos y costos separados.
Arquitecto: asociación histórica justifica tabla 27; unicidad de ingreso entre tablas no
se garantiza con índice parcial; locks de sentencia antes de filas; escala antes del cast.
Integraciones: service_status sigue fuente Fleet; sin cancelación/liberación; catálogos
reales conservados, contratos futuros sin movimientos ni compras ficticias.
Auditor: concordancia ER/catálogo, cobertura de costos, permisos por orden, salida y otros
impedimentos, evidencia privada y ausencia de código funcional. Hallazgos incorporados.

Pendientes físicos: validar restricciones/concurrencia en PostgreSQL 18 aislado, conciliación
de estados históricos, protocolo privado de archivos, cobertura económica y presupuesto de
reintentos. T01–T09 están aprobadas; estas verificaciones no reabren decisiones funcionales.
No se ejecutaron migraciones, seeders, pruebas funcionales ni operaciones sobre bases del
proyecto; no hubo commit, push, merge o despliegue.
