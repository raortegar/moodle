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
 * Learning Outcomes Alignment Report - main report page.
 *
 * Displays two tables:
 *  1. Learning outcomes that have no activities aligned to them.
 *  2. Assessable activities that are not aligned to any learning outcome.
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$courseid = required_param('id', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('report/learningoutcomes:view', $context);

$PAGE->set_url('/report/learningoutcomes/index.php', ['id' => $courseid]);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_learningoutcomes'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('pluginname', 'report_learningoutcomes'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'report_learningoutcomes'));

$manager = new \core\learning_outcomes\manager();

if (empty($CFG->enableoutcomes) || !$manager->is_enabled_for_course($courseid)) {
    echo $OUTPUT->notification(
        get_string('reportnotavailable', 'report_learningoutcomes'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('reportintro', 'report_learningoutcomes'));

$report = $manager->get_alignment_report($courseid);

// =========================================================================
// Table 1 — Learning outcomes without an activity
// =========================================================================
echo $OUTPUT->heading(get_string('reportheading_outcomes', 'report_learningoutcomes'), 3);

if (empty($report['untagged_outcomes'])) {
    echo $OUTPUT->notification(
        get_string('nountaggedoutcomes', 'report_learningoutcomes'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    $table               = new html_table();
    $table->head         = [
        get_string('outcomesshortname', 'report_learningoutcomes'),
        get_string('outcomesfullname', 'report_learningoutcomes'),
        '',
    ];
    $table->attributes   = ['class' => 'generaltable'];
    $table->data         = [];

    $canmanage = has_capability('moodle/grade:manageoutcomes', $context);

    foreach ($report['untagged_outcomes'] as $outcome) {
        $editcell = '';
        if ($canmanage) {
            $editurl  = new moodle_url('/grade/edit/outcome/edit.php', [
                'id'       => $outcome->id,
                'courseid' => $courseid,
            ]);
            $editcell = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-secondary']);
        }
        $table->data[] = [
            format_string($outcome->shortname),
            format_string($outcome->fullname),
            $editcell,
        ];
    }

    echo html_writer::table($table);
}

// =========================================================================
// Table 2 — Assessable activities without an outcome
// =========================================================================
echo $OUTPUT->heading(get_string('reportheading_activities', 'report_learningoutcomes'), 3);

if (empty($report['untagged_activities'])) {
    echo $OUTPUT->notification(
        get_string('noassessableactivities', 'report_learningoutcomes'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    // Build a lookup of cm display names via fast modinfo.
    $modinfo = get_fast_modinfo($course);

    $table               = new html_table();
    $table->head         = [
        get_string('activityname', 'report_learningoutcomes'),
        get_string('activitytype', 'report_learningoutcomes'),
        '',
    ];
    $table->attributes   = ['class' => 'generaltable'];
    $table->data         = [];

    foreach ($report['untagged_activities'] as $cmrow) {
        $activityname = '';
        $activitytype = format_string($cmrow->modname);

        if (isset($modinfo->cms[$cmrow->id])) {
            $cminfo       = $modinfo->cms[$cmrow->id];
            $activityname = format_string($cminfo->name);
            $activitytype = $cminfo->modfullname;
        }

        $editurl  = new moodle_url('/course/modedit.php', [
            'update' => $cmrow->id,
            'return' => 0,
            'sr'     => 0,
        ]);
        $editcell = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-secondary']);

        $table->data[] = [$activityname, $activitytype, $editcell];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
