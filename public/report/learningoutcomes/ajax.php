<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for the Manage learning outcomes page.
 *
 * Actions:
 *  - tag   — links an activity to an outcome; returns the new <tr> HTML.
 *  - untag — removes the link.
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

header('Content-Type: application/json');

try {
    $courseid  = required_param('courseid',  PARAM_INT);
    $cmid      = required_param('cmid',      PARAM_INT);
    $outcomeid = required_param('outcomeid', PARAM_INT);
    $action    = required_param('action',    PARAM_ALPHA);

    require_sesskey();

    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    require_login($course);
    require_capability('report/learningoutcomes:manage', $context);

    $manager = new \core\learning_outcomes\manager();

    if ($action === 'untag') {
        $manager->untag_outcome($cmid, $outcomeid);
        echo json_encode(['success' => true]);

    } else if ($action === 'tag') {
        $manager->tag_outcome($cmid, $outcomeid, $courseid);

        // Build the new table row so the JS can insert it without a page reload.
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($cmid);

        $rowhtml = report_learningoutcomes_activity_row($cm, $outcomeid, $courseid, true);

        echo json_encode([
            'success' => true,
            'rowhtml' => $rowhtml,
            'activityname' => format_string($cm->get_formatted_name()),
        ]);

    } else {
        throw new \moodle_exception('invalidparameter', 'error');
    }

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
