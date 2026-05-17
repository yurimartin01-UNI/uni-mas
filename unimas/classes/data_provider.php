<?php
namespace local_unimas;
use stdClass;
use context_course;

defined('MOODLE_INTERNAL') || die();

/**
 * Data Provider for Uni+ Teacher Dashboard
 */
class data_provider {

    /**
     * Get all data required for the Teacher Dashboard (Uni+)
     */
    public static function get_dashboard_data($courseid) {
        global $DB, $USER;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = context_course::instance($courseid);
        
        // 1. Determine current course week
        $startdate = $course->startdate;
        $current_week = self::get_current_week($course);

        // 2. Fetch all enrolled participants
        $students = self::get_course_students_robust($courseid);
        
        // Ensure cache is fresh for this calculation session if needed
        // (Optional: $DB->delete_records('local_unimas_indicators', ['courseid' => $courseid, 'week' => $current_week]);)

        // Pre-fetch all cached AI recommendations for this course/week
        $ai_cache = $DB->get_records('local_unimas_ai_cache', ['courseid' => $courseid, 'week' => $current_week]);
        $cached_ai = ['global' => null, 'recommendations' => []];
        
        $temp_recs = []; // To avoid duplicates and handle priority
        
        foreach ($ai_cache as $cache) {
            $rec_data = json_decode($cache->recommendation, true);
            if ($cache->student_id === null) {
                // GLOBAL analysis: extract the 'global' summary and individual recs if present
                $cached_ai['global'] = $rec_data['global'] ?? null;
                if (isset($rec_data['recommendations']) && is_array($rec_data['recommendations'])) {
                    foreach ($rec_data['recommendations'] as $r) {
                        if (isset($r['uid'])) {
                            $uid = (int)$r['uid'];
                            // Only add if we don't have an individual (more specific) one yet
                            if (!isset($temp_recs[$uid])) {
                                $temp_recs[$uid] = $r;
                            }
                        }
                    }
                }
            } else {
                // INDIVIDUAL analysis: this always has priority
                $uid = (int)$cache->student_id;
                $rec_data['uid'] = $uid;
                $temp_recs[$uid] = $rec_data;
            }
        }
        $cached_ai['recommendations'] = array_values($temp_recs);

        $data = [
            'name' => $course->fullname,
            'semana' => (int)$current_week,
            // totalSem: derive from course end/start dates if available,
            // otherwise default to 16 (a standard 16-week semester).
            'totalSem' => self::get_total_weeks($course),
            'totalStudents' => count($students),
            'students' => [],
            'ai' => $cached_ai, // Inject pre-loaded AI reports
            'courseData' => self::get_course_patterns_and_metrics($courseid, $current_week),
            'config' => [
                'ai_provider' => get_config('local_unimas', 'ai_provider'),
                'ai_model'    => get_config('local_unimas', 'ai_model'),
                'ai_base_url' => get_config('local_unimas', 'ai_base_url')
            ]
        ];

        $counts = ['pri' => 0, 'aten' => 0, 'norm' => 0, 'nodata' => 0];

        // Pre-calculate group average for grades once
        $group_avg = self::calculate_group_average_grades($courseid);

        foreach ($students as $student) {
            $is_metrics = self::get_or_calculate_student_is($student->id, $courseid, $current_week, $group_avg);
            $last_action = self::get_last_student_action($student->id, $courseid);
            $context_note = $DB->get_field('local_unimas_context', 'context_text', ['userid' => $student->id, 'courseid' => $courseid]);

            // Level Logic based on new Uni_mas thresholds
            $status = 'norm';
            $is_val = $is_metrics['is'];

            if ($is_val === null || $is_metrics['no_info']) {
                $status = 'nodata';
                $counts['nodata']++;
            } else if ($is_val < 0.40) { 
                $status = 'crit'; 
                $counts['pri']++; 
            } else if ($is_val < 0.65) { 
                $status = 'aten'; 
                $counts['aten']++; 
            } else { 
                $counts['norm']++; 
            }

            $data['students'][] = [
                'id' => 'E-' . $student->id,
                'uid' => $student->id,
                'name' => $student->firstname . ' ' . $student->lastname,
                'email' => $student->email,
                'is' => $is_val !== null ? (float)$is_val : 0,
                'delta' => (float)$is_metrics['delta'],
                'level' => $status,
                'action' => $last_action ? $last_action->action : 'Sin registro',
                'note' => $last_action ? $last_action->note : '',
                'daysAgo' => $last_action ? floor((time() - $last_action->timecreated) / (24*3600)) : null,
                'hist' => $is_metrics['history'],
                'ctx' => $context_note ?: null,
                'comps' => [
                    'act' => $is_metrics['comp_activity'] !== null ? (float)$is_metrics['comp_activity'] : 0,
                    'rend' => $is_metrics['comp_grades'] !== null ? (float)$is_metrics['comp_grades'] : 0,
                    'ent' => $is_metrics['comp_submissions'] !== null ? (float)$is_metrics['comp_submissions'] : 0
                ],
                'no_info' => $is_metrics['no_info']
            ];
        }

        $data['counts'] = $counts;
        return $data;
    }

    private static function get_or_calculate_student_is($userid, $courseid, $week, $group_avg = null) {
        global $DB;
        
        $record = $DB->get_record('local_unimas_indicators', ['userid' => $userid, 'courseid' => $courseid, 'week' => $week]);
        
        if (!$record) {
            if ($group_avg === null) {
                $group_avg = self::calculate_group_average_grades($courseid);
            }

            // Calculation per Operational Definition
            $i_a = self::calculate_submissions_metric($userid, $courseid); // 0 or 1
            $i_r_raw = self::calculate_grades_metric($userid, $courseid, $group_avg); // 0, 1 or null
            $i_e = self::calculate_activity_metric($userid, $courseid); // 0 or 1

            $no_info = ($i_r_raw === null);
            $i_r = $no_info ? 0 : $i_r_raw;

            $is = (0.40 * $i_a) + (0.35 * $i_r) + (0.25 * $i_e);
            
            // Save it
            $record = new stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->week = $week;
            $record->is_value = $is;
            $record->comp_activity = $i_e;
            $record->comp_grades = $i_r;
            $record->comp_submissions = $i_a;
            $record->timecreated = time();
            $record->id = $DB->insert_record('local_unimas_indicators', $record);
        } else {
            // We assume stored indicators are correct, but need to check if they imply "no info"
            // For now, if activity and grades are 0, it might be no info. 
            // In a real system we'd store a 'null' or a flag in the DB.
            $no_info = ($record->is_value == 0 && $record->comp_activity == 0); 
        }

        // Fetch history
        $history_records = $DB->get_records_select('local_unimas_indicators', 
            'userid = ? AND courseid = ? AND week <= ?', [$userid, $courseid, $week], 'week ASC', 'is_value', 0, 4);
        
        $history = array_values(array_map(fn($r) => (float)$r->is_value, $history_records));
        while (count($history) < 4) array_unshift($history, 0.5); 

        $delta = 0;
        if (count($history) >= 2) {
            $delta = $history[count($history)-1] - $history[count($history)-2];
        }

        return [
            'is' => $no_info ? null : (float)$record->is_value,
            'comp_activity' => (float)$record->comp_activity,
            'comp_grades' => (float)$record->comp_grades,
            'comp_submissions' => (float)$record->comp_submissions,
            'history' => $history,
            'delta' => $delta,
            'no_info' => $no_info
        ];
    }

    private static function calculate_activity_metric($userid, $courseid) {
        global $DB;

        // Guard: logstore_standard_log is optional in Moodle.
        // If the admin has disabled the standard log store, the table won't
        // exist and the query would throw a DB exception.
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('logstore_standard_log')) {
            debugging('local_unimas: logstore_standard_log table not found — I_E set to 0.', DEBUG_DEVELOPER);
            return 0.0;
        }

        $period = 14 * 24 * 3600;
        $start  = time() - $period;

        // Count distinct calendar days with any activity in the last 14 days.
        $sql = "SELECT COUNT(DISTINCT(FLOOR(timecreated / 86400)))
                FROM {logstore_standard_log}
                WHERE userid = ? AND courseid = ? AND timecreated > ?";
        $days = $DB->count_records_sql($sql, [$userid, $courseid, $start]);

        // I_E = 1 if the student was active on at least 50 % of days (7 / 14).
        return ($days >= 7) ? 1.0 : 0.0;
    }

    private static function calculate_grades_metric($userid, $courseid, $group_avg) {
        global $DB;
        
        // Student's current average
        $sql = "SELECT AVG(finalgrade / grademax) as avg_grade
                FROM {grade_grades} gg 
                JOIN {grade_items} gi ON gi.id = gg.itemid 
                WHERE gi.courseid = ? AND gg.userid = ? 
                  AND gi.gradetype = 1 AND gg.finalgrade IS NOT NULL";
                  
        $res = $DB->get_record_sql($sql, [$courseid, $userid]);
        if ($res && $res->avg_grade !== null) {
            $student_avg = (float)$res->avg_grade;
            return ($student_avg >= $group_avg) ? 1.0 : 0.0;
        }
        
        return null; // No info
    }

    private static function calculate_group_average_grades($courseid) {
        global $DB;
        $sql = "SELECT AVG(finalgrade / grademax) as group_avg
                FROM {grade_grades} gg 
                JOIN {grade_items} gi ON gi.id = gg.itemid 
                WHERE gi.courseid = ? AND gi.gradetype = 1 AND gg.finalgrade IS NOT NULL";
        return (float)($DB->get_field_sql($sql, [$courseid]) ?: 0.5);
    }

    private static function calculate_submissions_metric($userid, $courseid) {
        global $DB;
        $now = time();
        
        // Get all assignments in course that have already passed their duedate
        $sql = "SELECT a.id, a.duedate 
                FROM {assign} a 
                WHERE a.course = ? AND a.duedate > 0 AND a.duedate < ?";
        $assigns = $DB->get_records_sql($sql, [$courseid, $now]);
        
        if (empty($assigns)) return 1.0; // Assume ideal if no tasks due yet
        
        foreach ($assigns as $a) {
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $a->id, 
                'userid' => $userid, 
                'status' => 'submitted'
            ]);
            
            // If missing, or if delivered after duedate
            if (!$submission || $submission->timemodified > $a->duedate) {
                return 0.0; // Any late/missing = 0
            }
        }
        
        return 1.0;
    }

    private static function get_last_student_action($userid, $courseid) {
        global $DB;
        $recs = $DB->get_records('local_unimas_actions', ['userid' => $userid, 'courseid' => $courseid], 'timecreated DESC', '*', 0, 1);
        return $recs ? reset($recs) : null;
    }

    /**
     * Centralized week calculation logic
     */
    public static function get_current_week($course) {
        $current_week = 1 + floor((time() - $course->startdate) / (7 * 24 * 3600));
        if ($current_week < 1) return 1;
        if ($current_week > 16) return 16;
        return (int)$current_week;
    }

    private static function get_course_patterns_and_metrics($courseid, $week) {
        global $DB;
        
        $avg_is = $DB->get_field_sql("SELECT AVG(is_value) FROM {local_unimas_indicators} WHERE courseid = ? AND week = ?", [$courseid, $week]);
        $prev_avg = $DB->get_field_sql("SELECT AVG(is_value) FROM {local_unimas_indicators} WHERE courseid = ? AND week = ?", [$courseid, $week-1]);
        $delta = $prev_avg ? ($avg_is - $prev_avg) : 0;

        return [
            'isAvg' => (float)($avg_is ?: 0.5),
            'isAvgDelta' => (float)$delta,
            'hotPractice' => 'Cálculo de IS basado en definiciones operativas Uni_mas (14 días)',
            'hotLate' => 0,
            'patterns' => [
                ['color' => '#6366f1', 'text' => "Métricas actualizadas según modelo 0/1", 'sub' => "Evaluando Entregas (40%), Rendimiento vs Grupo (35%) y Enganche 7/14 (25%).", 'pills' => [['cls' => 'sug-blue', 'txt' => 'Modelo OK']]]
            ]
        ];
    }

    private static function get_course_students_robust($courseid) {
        global $DB;
        $context = context_course::instance($courseid);
        
        // Fetch users who can participate in the course (typical for students)
        $students = get_enrolled_users($context, 'moodle/course:participate', 0, 'u.id, u.firstname, u.lastname, u.email, u.suspended, u.deleted, u.username');
        
        if (empty($students)) {
            $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username
                    FROM {user} u 
                    JOIN {user_enrolments} ue ON ue.userid = u.id 
                    JOIN {enrol} e ON e.id = ue.enrolid 
                    WHERE e.courseid = ? AND u.deleted = 0";
            $students = $DB->get_records_sql($sql, [$courseid]);
        }

        // Final filter: Exclude site admins and non-student profiles
        return array_filter($students, function($u) use ($context) {
            // Exclude common admin usernames or users with manager-level capabilities
            if ($u->username === 'admin') return false;
            // Only include users who DO NOT have the capability to update the course (teachers/managers)
            if (has_capability('moodle/course:update', $context, $u->id)) return false;
            
            return $u->deleted == 0 && $u->suspended == 0;
        });
    }

    /**
     * Stub: extract course files for the RAG sync pipeline.
     * Not used by the student dashboard — only by the RAG flow.
     * Returns empty array if no RAG pipeline is configured.
     */
    public static function get_course_files(int $courseid, bool $force = false): array {
        // The full implementation requires the RAG microservice.
        // Return an empty array so handle_sync() does not crash.
        debugging('local_unimas: get_course_files() is a stub — RAG pipeline not configured.', DEBUG_DEVELOPER);
        return [];
    }

    /**
     * Stub: build a course summary payload for the RAG sync endpoint.
     */
    public static function get_course_summary(int $courseid): array {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, summary');
        return $course ? (array)$course : [];
    }

    /**
     * Stub: create a Moodle assignment activity from generated instrument content.
     * Not used by the student dashboard — only by the RAG export flow.
     */
    public static function create_assign_activity(int $courseid, string $name, string $description): object {
        debugging('local_unimas: create_assign_activity() is a stub — RAG pipeline not configured.', DEBUG_DEVELOPER);
        $result = new \stdClass();
        $result->coursemodule = 0;
        return $result;
    }


    /**
     * Calculate the total number of weeks in a course.
     * Uses enddate if set, otherwise falls back to the plugin config or 16.
     */
    private static function get_total_weeks(\stdClass $course): int {
        // If the course has a defined end date, calculate weeks from it.
        if (!empty($course->enddate) && $course->enddate > $course->startdate) {
            $weeks = (int)ceil(($course->enddate - $course->startdate) / (7 * 24 * 3600));
            if ($weeks >= 4 && $weeks <= 52) {
                return $weeks;
            }
        }
        // Fallback: site-level config or hardcoded default.
        $configured = (int)get_config('local_unimas', 'total_weeks');
        return ($configured >= 4 && $configured <= 52) ? $configured : 16;
    }

}
