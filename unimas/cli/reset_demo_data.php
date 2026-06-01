<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

$help = <<<HELP
Resetea datos de local_unimas y crea usuarios demo globales.

Uso:
  php local/unimas/cli/reset_demo_data.php --force [--password='Demo123!']

Opciones:
  --force        Ejecuta el reseteo (obligatorio).
  --password     Password para los 4 usuarios demo (default: Demo123!).
  -h, --help     Muestra esta ayuda.

Qué hace:
  1) Borra datos funcionales del plugin local_unimas:
     - local_unimas_indicators
     - local_unimas_actions
     - local_unimas_context
     - local_unimas_ai_cache
  2) Habilita cuentas con mismo email para pruebas.
  3) Elimina usuarios demo existentes (por username/email).
  4) Crea usuarios demo globales:
     - Estudiante Demo 1 / student1@example.com
     - Estudiante Demo 2 / student2@example.com
     - Estudiante Demo 3 / student3@example.com
     - Estudiante Demo 4 / student4@example.com

HELP;

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'force' => false,
        'password' => 'Demo123!',
    ],
    [
        'h' => 'help',
        'f' => 'force',
        'p' => 'password',
    ]
);

if (!empty($unrecognized)) {
    cli_error('Parámetros no reconocidos: ' . implode(', ', $unrecognized) . "\n\n" . $help);
}

if ($options['help'] || !$options['force']) {
    echo $help;
    exit($options['help'] ? 0 : 1);
}

$userspecs = [
    ['username' => 'demo1', 'firstname' => 'Estudiante', 'lastname' => 'Demo 1', 'email' => 'student1@example.com'],
    ['username' => 'demo2', 'firstname' => 'Estudiante', 'lastname' => 'Demo 2', 'email' => 'student2@example.com'],
    ['username' => 'demo3', 'firstname' => 'Estudiante', 'lastname' => 'Demo 3', 'email' => 'student3@example.com'],
    ['username' => 'demo4', 'firstname' => 'Estudiante', 'lastname' => 'Demo 4', 'email' => 'student4@example.com'],
];

$tables = [
    'local_unimas_indicators',
    'local_unimas_actions',
    'local_unimas_context',
    'local_unimas_ai_cache',
];

cli_writeln('== Uni+ demo reset ==');
cli_writeln('Iniciando transacción...');

$transaction = $DB->start_delegated_transaction();

try {
    // 1) Clear plugin data.
    foreach ($tables as $table) {
        $DB->delete_records($table, []);
    }
    cli_writeln('Datos del plugin limpiados.');

    // 2) Allow duplicate emails for this testing scenario.
    set_config('allowaccountssameemail', 1);
    cli_writeln('Config aplicada: allowaccountssameemail=1');

    // 3) Delete existing demo users (if any).
    $usernames = array_map(fn($u) => $u['username'], $userspecs);
    $emails = array_values(array_unique(array_map(fn($u) => $u['email'], $userspecs)));

    [$uinsql, $uparams] = $DB->get_in_or_equal($usernames, SQL_PARAMS_NAMED, 'u');
    [$einsql, $eparams] = $DB->get_in_or_equal($emails, SQL_PARAMS_NAMED, 'e');

    $select = "(username {$uinsql} OR email {$einsql}) AND deleted = 0 AND mnethostid = :mnet";
    $params = $uparams + $eparams + ['mnet' => $CFG->mnet_localhost_id];
    $existing = $DB->get_records_select('user', $select, $params, 'id ASC', 'id,username,email,deleted,mnethostid');

    foreach ($existing as $user) {
        if (is_siteadmin($user->id)) {
            continue;
        }
        user_delete_user($user);
    }
    cli_writeln('Usuarios demo previos eliminados (si existían).');

    // 4) Create fresh demo users.
    $password = (string)$options['password'];
    foreach ($userspecs as $spec) {
        $user = new stdClass();
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = $spec['username'];
        $user->password = $password;
        $user->firstname = $spec['firstname'];
        $user->lastname = $spec['lastname'];
        $user->email = $spec['email'];
        $user->lang = 'es';
        $user->maildisplay = 2;

        user_create_user($user, false, false);
    }

    $transaction->allow_commit();
    cli_writeln('Usuarios demo creados correctamente.');
    cli_writeln('Password común: ' . $password);
    cli_writeln('Proceso completado.');
} catch (Throwable $e) {
    try {
        $transaction->rollback($e);
    } catch (Throwable $ignored) {
        // rollback() rethrows; ignore to return controlled CLI error below.
    }
    cli_error('Fallo en reset demo: ' . $e->getMessage());
}
