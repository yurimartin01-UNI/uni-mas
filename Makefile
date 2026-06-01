SHELL := /usr/bin/env bash

.PHONY: init up up-tools down restart logs ps shell php fix-perms moodle-config purge reset

init:
	@if [[ ! -f .env ]]; then cp .env.example .env; fi
	@echo "Archivo .env listo."

up: init
	docker compose up -d
	@echo "Moodle:   http://localhost:$$(grep '^MOODLE_HTTP_PORT=' .env | cut -d= -f2)"
	@echo "Mailpit:  http://localhost:$$(grep '^MAILPIT_UI_PORT=' .env | cut -d= -f2)"
	@echo "Adminer (opcional): make up-tools"

up-tools: init
	docker compose --profile tools up -d
	@echo "Moodle:   http://localhost:$$(grep '^MOODLE_HTTP_PORT=' .env | cut -d= -f2)"
	@echo "Mailpit:  http://localhost:$$(grep '^MAILPIT_UI_PORT=' .env | cut -d= -f2)"
	@echo "Adminer:  http://localhost:$$(grep '^ADMINER_PORT=' .env | cut -d= -f2)"

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f --tail=200

ps:
	docker compose ps

shell:
	docker compose exec moodle bash

php:
	docker compose exec moodle php -v

fix-perms:
	docker compose exec moodle sh -lc 'sed -i "s/\$$CFG->directorypermissions = .*/\$$CFG->directorypermissions = 0777;/" /bitnami/moodle/config.php'
	docker compose exec moodle sh -lc 'chown daemon:root /bitnami/moodle/config.php && chmod 664 /bitnami/moodle/config.php'
	docker compose exec moodle sh -lc 'chown -R daemon:root /bitnami/moodledata/cache /bitnami/moodledata/localcache /bitnami/moodledata/temp /bitnami/moodledata/sessions'
	docker compose exec moodle sh -lc 'chmod -R 0777 /bitnami/moodledata/cache /bitnami/moodledata/localcache /bitnami/moodledata/temp /bitnami/moodledata/sessions'

moodle-config:
	./scripts/moodle-dev-config.sh

purge:
	docker compose exec --user daemon moodle /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/purge_caches.php

reset:
	docker compose down -v
