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

/**
 * Syncs core activity-form outcome checkboxes into the plugin's course_outcome_tags table.
 *
 * The standard Moodle activity edit form shows advcheckbox elements named outcome_N for each
 * course outcome. Advcheckbox always submits the field (0 or 1), so we can detect unchecked boxes
 * and remove tags. This makes the core "Learning outcomes" section the single UI for tagging.
 *
 * Called automatically by {@see plugin_extend_coursemodule_edit_post_actions()}.
 *
 * @param stdClass $data   Submitted module form data.
 * @param stdClass $course The course the module belongs to.
 * @return stdClass        The (unmodified) module data, as required by the callback contract.
 */
function report_learningoutcomes_coursemodule_edit_post_actions($data, $course): stdClass {
    global $CFG;

    if (empty($CFG->enableoutcomes)) {
        return $data;
    }

    $manager = new \core\learning_outcomes\manager();
    if (!$manager->is_enabled_for_course($course->id)) {
        return $data;
    }

    // Collect all submitted outcome_N fields (advcheckbox always submits 0 or 1).
    $outcomefields = [];
    foreach ((array) $data as $key => $value) {
        if (strpos($key, 'outcome_') === 0) {
            $outcomeid = (int) substr($key, 8);
            if ($outcomeid > 0) {
                $outcomefields[$outcomeid] = !empty($value);
            }
        }
    }

    // If no outcome fields were present the form didn't render the outcomes section
    // (e.g., decorative module or no course outcomes defined). Skip.
    if (empty($outcomefields)) {
        return $data;
    }

    $cmid     = (int) $data->coursemodule;
    $existing = array_map('intval', array_column($manager->get_tagged_outcomes($cmid), 'id'));

    foreach ($outcomefields as $outcomeid => $ischecked) {
        if ($ischecked && !in_array($outcomeid, $existing)) {
            $manager->tag_outcome($cmid, $outcomeid, $course->id);
        } elseif (!$ischecked && in_array($outcomeid, $existing)) {
            $manager->untag_outcome($cmid, $outcomeid);
        }
    }

    return $data;
}

/**
 * Renders a single activity row for an outcome's activities table.
 *
 * Shared by manage.php (initial render) and ajax.php (AJAX add).
 *
 * @param cm_info $cm        The course module.
 * @param int     $outcomeid Outcome ID.
 * @param int     $courseid  Course ID.
 * @param bool    $canmanage Whether to include the Remove button.
 * @return string HTML for a <tr> element.
 */
function report_learningoutcomes_activity_row(
    \cm_info $cm,
    int $outcomeid,
    int $courseid,
    bool $canmanage
): string {
    global $OUTPUT;

    $url  = $cm->url ?? new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
    $icon = $OUTPUT->pix_icon('monologo', '', $cm->modname, [
        'class'       => 'activityicon me-1',
        'width'       => '16',
        'height'      => '16',
        'aria-hidden' => 'true',
    ]);
    $activityname = format_string($cm->get_formatted_name());

    $cells  = html_writer::tag('td', html_writer::link($url, $icon . $activityname));
    $cells .= html_writer::tag('td',
        html_writer::tag('span', ucfirst($cm->modname), ['class' => 'badge bg-secondary text-dark'])
    );

    if ($canmanage) {
        $cells .= html_writer::tag('td',
            html_writer::tag('button',
                get_string('manage_removelink', 'report_learningoutcomes'),
                [
                    'type'              => 'button',
                    'class'             => 'btn btn-sm btn-outline-danger lo-remove-btn',
                    'data-cmid'         => $cm->id,
                    'data-outcomeid'    => $outcomeid,
                    'data-activityname' => $activityname,
                ]
            ),
            ['class' => 'text-end']
        );
    }

    return html_writer::tag('tr', $cells, [
        'class'     => 'lo-activity-row',
        'data-cmid' => $cm->id,
    ]);
}

