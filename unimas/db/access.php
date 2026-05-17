<?php
/**
 * Capability definitions for local_unimas.
 *
 * @package    local_unimas
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Allows a user to view the Uni+ teacher dashboard for a course.
    // Assigned by default to editingteacher and manager.
    'local/unimas:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

];
