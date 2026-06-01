#!/usr/bin/env bash
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker no está instalado o no está en PATH."
  exit 1
fi

if ! docker compose ps moodle >/dev/null 2>&1; then
  echo "No se detecta el servicio moodle. Levanta primero con: make up"
  exit 1
fi

echo "Aplicando configuración de desarrollo en Moodle..."

# Ensure directory permissions are dev-friendly and cache dirs are writable.
docker compose exec moodle sh -lc 'sed -i "s/\$CFG->directorypermissions = .*/\$CFG->directorypermissions = 0777;/" /bitnami/moodle/config.php'
docker compose exec moodle sh -lc 'chown daemon:root /bitnami/moodle/config.php && chmod 664 /bitnami/moodle/config.php'
docker compose exec moodle sh -lc 'chown -R daemon:root /bitnami/moodledata/cache /bitnami/moodledata/localcache /bitnami/moodledata/temp /bitnami/moodledata/sessions'
docker compose exec moodle sh -lc 'chmod -R 0777 /bitnami/moodledata/cache /bitnami/moodledata/localcache /bitnami/moodledata/temp /bitnami/moodledata/sessions'

docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/cfg.php --name=debug --set=32767
docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/cfg.php --name=debugdisplay --set=1
docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/cfg.php --name=cachejs --set=0
docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/cfg.php --name=themedesignermode --set=1
docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/purge_caches.php

echo "Listo. Configuración dev aplicada."
