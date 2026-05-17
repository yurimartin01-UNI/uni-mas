<?php
/**
 * Upgrade logic for local_unimas
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_unimas_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026041401) {

        // 1. Define Table 'local_unimas_indicators'
        $table = new xmldb_table('local_unimas_indicators');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('week', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('is_value', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('comp_activity', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('comp_grades', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('comp_submissions', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_user_week', XMLDB_INDEX_UNIQUE, ['courseid', 'userid', 'week']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Define Table 'local_unimas_actions'
        $table = new xmldb_table('local_unimas_actions');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 3. Define Table 'local_unimas_context'
        $table = new xmldb_table('local_unimas_context');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('context_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_user', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Save progress point
        upgrade_plugin_savepoint(true, 2026041401, 'local', 'unimas');
    }

    if ($oldversion < 2026041402) {
        // 1. Repair/Ensure 'local_unimas_indicators'
        $table = new xmldb_table('local_unimas_indicators');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('week', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('is_value', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comp_activity', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comp_grades', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comp_submissions', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('course_user_week', XMLDB_INDEX_UNIQUE, ['courseid', 'userid', 'week']);
            $dbman->create_table($table);
        }

        // 2. Repair/Ensure 'local_unimas_actions'
        $table = new xmldb_table('local_unimas_actions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // 3. Repair/Ensure 'local_unimas_context'
        $table = new xmldb_table('local_unimas_context');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('context_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('course_user', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041402, 'local', 'unimas');
    }

    if ($oldversion < 2026041403) {
        // Define Table 'local_unimas_ai_cache'
        $table = new xmldb_table('local_unimas_ai_cache');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('week', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('student_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, "Null means global recommendation");
        $table->add_field('recommendation', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_week_student', XMLDB_INDEX_UNIQUE, ['courseid', 'week', 'student_id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041403, 'local', 'unimas');
    }


    if ($oldversion < 2026051300) {
        // Migration from local_areteia to local_unimas.
        // If the site previously had local_areteia installed, rename its tables
        // so all data is preserved. On a clean install the old tables wont
        // exist, so each iteration is simply skipped without error.
        $rename_map = [
            'local_areteia_indicators' => 'local_unimas_indicators',
            'local_areteia_actions'    => 'local_unimas_actions',
            'local_areteia_context'    => 'local_unimas_context',
            'local_areteia_ai_cache'   => 'local_unimas_ai_cache',
        ];

        foreach ($rename_map as $old_name => $new_name) {
            $old_table = new xmldb_table($old_name);
            $new_table = new xmldb_table($new_name);

            if ($dbman->table_exists($old_table) && !$dbman->table_exists($new_table)) {
                $dbman->rename_table($old_table, $new_name);
            }
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'unimas');
    }

    return true;
}
