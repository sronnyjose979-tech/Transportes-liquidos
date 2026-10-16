# Respaldos y recuperación: mapa de implementación

[Volver a módulos](../03-modulos.md).

## Persistencia propia

No crea tablas propias: reutiliza datos persistidos por los módulos operativos..

## Contratos HTTP

No tiene API web propia; Respaldos opera mediante comandos de consola.

## Clases y responsabilidades

`Models` representa persistencia/relaciones; `Http/Requests` valida; `Http/Controllers` coordina HTTP; `Http/Resources` delimita JSON; `Application` ejecuta los casos de uso; `Infrastructure` trata archivos u operaciones concretas. Una clase sin métodos públicos propios puede heredar comportamiento: no se considera vacía ni inexistente.

| Clase / archivo | Ubicación funcional | Métodos públicos declarados |
| --- | --- | --- |
| [BackupArchive](../../../app/Modules/BackupRecovery/Application/BackupArchive.php) | `Application` | `safePath`, `create`, `verify`, `restore`, `prune` |
