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
 * Learning Outcomes Alignment Report - lib functions.
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Injects the report link into the Course Reports navigation node.
 *
 * @param navigation_node $navigation
 * @param stdClass         $course
 * @param context          $context
 */
function report_learningoutcomes_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context $context
): void {
    global $CFG;

    if (empty($CFG->enableoutcomes)) {
        return;
    }
    if (!has_capability('report/learningoutcomes:view', $context)) {
        return;
    }

    $manager = new \core\learning_outcomes\manager();
    if (!$manager->is_enabled_for_course($course->id)) {
        return;
    }

    $url = new moodle_url('/report/learningoutcomes/index.php', ['id' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'report_learningoutcomes'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        null,
        new pix_icon('i/outcomes', '')
    );
}


