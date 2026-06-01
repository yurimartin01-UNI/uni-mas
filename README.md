# uni+ (local_unimas)
Plugin experimental para Moodle orientado al seguimiento estudiantil y apoyo docente mediante indicadores académicos e IA.

## Entorno de desarrollo con Docker Compose

Este repo queda listo para levantar un ambiente completo de testing/desarrollo con:
- Moodle
- MariaDB
- Mailpit (SMTP + visor de correos)
- Adminer (inspección de base de datos, opcional con profile `tools`)
- Plugin `local_unimas` montado en vivo (hot reload de código del plugin)

### Requisitos
- Docker Engine + Docker Compose v2

### Arranque rápido
1. Inicializar variables:
```bash
make init
```

2. Levantar servicios:
```bash
make up
```

3. Entrar a Moodle:
- URL: `http://localhost:18080` (o el puerto definido en `.env`)
- Usuario admin: valor de `MOODLE_ADMIN_USER` en `.env`
- Clave admin: valor de `MOODLE_ADMIN_PASSWORD` en `.env`

4. Aplicar configuración de desarrollo en Moodle (debug + menos caché):
```bash
make moodle-config
```

5. Instalar/actualizar el plugin en Moodle:
- Ir a `Administración del sitio > Notificaciones`
- Completar instalación/upgrade del plugin `local_unimas` si Moodle lo solicita

## Hot reload del plugin

El código local `./unimas` se sincroniza en caliente por el servicio `plugin-sync` hacia:
`/bitnami/moodle/local/unimas` dentro del contenedor.

Eso significa que al editar PHP/JS/CSS del plugin:
- Los cambios quedan disponibles en segundos dentro del contenedor.
- `plugin-sync` comienza a sincronizar automáticamente después de la instalación inicial de Moodle.
- Para ver cambios front-end al instante, usa recarga dura del navegador.
- Si Moodle cachea algo, ejecuta:
```bash
make purge
```

## URLs útiles
- Moodle: `http://localhost:18080`
- Mailpit UI: `http://localhost:18025`
- Adminer (opcional): `http://localhost:18081`

Para levantar Adminer también:
```bash
make up-tools
```

## Comandos útiles
```bash
make up         # Levanta entorno
make up-tools   # Levanta entorno + Adminer
make down       # Detiene entorno
make logs       # Logs de servicios
make ps         # Estado de contenedores
make shell      # Shell dentro de contenedor moodle
make fix-perms  # Repara permisos de /bitnami/moodle* si aparece error 500 por permisos
make purge      # Purga cachés de Moodle
make reset      # Baja y borra volúmenes (reset total)
```

## Notas
- La primera subida puede tardar mientras Moodle inicializa.
- Si cambias puertos/credenciales, edita `.env`.
- El script `scripts/moodle-dev-config.sh` aplica flags útiles para desarrollo (debug, display errors, cachejs off, themedesignermode on).
- Si aparece `Invalid permissions detected when trying to create a directory`, ejecuta:
```bash
make fix-perms
make purge
```

La IA asiste; el docente decide.
