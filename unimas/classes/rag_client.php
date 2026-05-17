<?php
namespace local_unimas;

defined('MOODLE_INTERNAL') || die();

/**
 * RAG (Retrieval-Augmented Generation) client stub.
 *
 * The sync/ingest pipeline requires an external Python microservice that is
 * NOT bundled with this plugin. If that service is not configured or
 * available, these methods return a safe error instead of crashing.
 *
 * @package    local_unimas
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_client {

    /**
     * Sync course content to the external RAG service.
     *
     * @param  mixed $summary  Course summary data.
     * @return object|null     Response object or null on failure.
     */
    public static function sync($summary) {
        $endpoint = get_config('local_unimas', 'rag_endpoint');

        if (empty($endpoint)) {
            debugging('local_unimas: rag_endpoint not configured — sync skipped.', DEBUG_DEVELOPER);
            return null;
        }

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post(
            rtrim($endpoint, '/') . '/sync',
            json_encode($summary),
            ['CURLOPT_TIMEOUT' => 30]
        );

        if ($curl->get_errno()) {
            debugging('local_unimas rag_client::sync error: ' . $curl->error, DEBUG_DEVELOPER);
            return null;
        }

        return json_decode($response);
    }

    /**
     * Trigger embedding ingestion on the external RAG service.
     *
     * @param  int        $course_id
     * @return object|null
     */
    public static function ingest(int $course_id) {
        $endpoint = get_config('local_unimas', 'rag_endpoint');

        if (empty($endpoint)) {
            debugging('local_unimas: rag_endpoint not configured — ingest skipped.', DEBUG_DEVELOPER);
            return null;
        }

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post(
            rtrim($endpoint, '/') . '/ingest',
            json_encode(['course_id' => $course_id]),
            ['CURLOPT_TIMEOUT' => 120]
        );

        if ($curl->get_errno()) {
            debugging('local_unimas rag_client::ingest error: ' . $curl->error, DEBUG_DEVELOPER);
            return null;
        }

        return json_decode($response);
    }
}
