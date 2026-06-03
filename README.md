# Uni+ (local_unimas)

Plugin local para Moodle orientado al seguimiento estudiantil y apoyo docente mediante indicadores academicos e IA.

## Requisitos

- Moodle 4.5 o superior.
- PHP 8.1 o superior, segun la version soportada por la instancia de Moodle.
- Base de datos soportada por Moodle.
- Extension PHP `curl` habilitada para conectar con proveedores de IA.

## Instalacion

1. Copia la carpeta `unimas` dentro del directorio `local/` de Moodle.
2. Verifica que la ruta final sea `moodle/local/unimas`.
3. Ingresa a Moodle como administrador.
4. Ve a `Administracion del sitio > Notificaciones`.
5. Completa la instalacion o actualizacion del plugin cuando Moodle lo solicite.

## Configuracion

La configuracion de IA se realiza desde el dashboard del plugin. Desde el icono de configuracion puedes seleccionar proveedor, modelo y API key para habilitar los analisis y recomendaciones pedagogicas.

## Funcionalidades

- Tablero de seguimiento por curso.
- Indicadores de prioridad estudiantil.
- Analisis individual y global asistido por IA.
- Carga de contexto cualitativo desde archivos Excel.
- Registro de acciones docentes e intervenciones.

## Documentacion del plugin

La documentacion especifica del plugin esta disponible en `unimas/README.md`.

La IA asiste; el docente decide.
