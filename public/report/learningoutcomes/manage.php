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
 * Manage learning outcomes — bird's-eye alignment page.
 *
 * Teachers can add/remove activity links for each outcome via AJAX.
 * Students see a read-only version (no controls, no untagged-activities section).
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('report/learningoutcomes:view', $context);

$canmanage = has_capability('report/learningoutcomes:manage', $context);

$PAGE->set_url('/report/learningoutcomes/manage.php', ['id' => $courseid]);
$PAGE->set_pagelayout('incourse');
$pagetitle = $canmanage
    ? get_string('manage_pagetitle', 'report_learningoutcomes')
    : get_string('learningoutcomes', 'report_learningoutcomes');
$PAGE->set_title($pagetitle);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add($pagetitle);

$manager = new \core\learning_outcomes\manager();

if (empty($CFG->enableoutcomes) || !$manager->is_enabled_for_course($courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('reportnotavailable', 'report_learningoutcomes'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

$outcomes = $manager->get_course_outcomes($courseid);

if (empty($outcomes)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($pagetitle, 2);
    if ($canmanage) {
        echo html_writer::tag(
            'p',
            get_string('manage_pagedesc', 'report_learningoutcomes'),
            ['class' => 'mb-3']
        );
    }
    echo $OUTPUT->notification(
        get_string('manage_nooutcomes', 'report_learningoutcomes'),
        \core\output\notification::NOTIFY_INFO
    );
    if ($canmanage) {
        $gradeoutcomesurl = new moodle_url('/grade/edit/outcome/index.php', ['id' => $courseid]);
        echo html_writer::tag(
            'p',
            $OUTPUT->pix_icon('i/outcomes', '', 'moodle', ['class' => 'me-1']) .
            html_writer::link(
                $gradeoutcomesurl,
                get_string('manage_grade_outcomes_link', 'report_learningoutcomes')
            )
        );
    }
    echo $OUTPUT->footer();
    exit;
}

// Load all course module info once.
$modinfo   = get_fast_modinfo($course);
$allcms    = $modinfo->get_cms();
$decorative = $manager->get_decorative_modules();

// Build the list of assessable activities available for linking.
$assessable = [];
foreach ($allcms as $cmid => $cm) {
    if ($cm->deletioninprogress) {
        continue;
    }
    if (in_array($cm->modname, $decorative)) {
        continue;
    }
    // Show hidden activities only to teachers.
    if (!$cm->uservisible && !$canmanage) {
        continue;
    }
    $assessable[$cmid] = $cm;
}

// Initialise the AMD module (AJAX + undo).
if ($canmanage) {
    $PAGE->requires->js_call_amd('report_learningoutcomes/manage', 'init', [
        ['courseid' => $courseid],
    ]);
}

// ── Page output ──────────────────────────────────────────────────────────────

echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle, 2);

if ($canmanage) {
    echo html_writer::tag(
        'p',
        get_string('manage_pagedesc', 'report_learningoutcomes'),
        ['class' => 'mb-3']
    );
}

// Link to the gradebook outcomes page so teachers can add/edit/delete outcomes.
if ($canmanage) {
    $gradeoutcomesurl = new moodle_url('/grade/edit/outcome/index.php', ['id' => $courseid]);
    echo html_writer::tag(
        'p',
        $OUTPUT->pix_icon('i/outcomes', '', 'moodle', ['class' => 'me-1']) .
        html_writer::link(
            $gradeoutcomesurl,
            get_string('manage_grade_outcomes_link', 'report_learningoutcomes')
        ),
        ['class' => 'mb-3 small']
    );
}

foreach ($outcomes as $outcome) {
    $outcomeid  = (int) $outcome->id;
    $taggedcms  = $manager->get_activities_for_outcome($outcomeid, $courseid);
    $taggedids  = array_column($taggedcms, 'id');

    // ── Outcome card ─────────────────────────────────────────────────────────
    $badge = html_writer::tag(
        'span',
        format_string($outcome->shortname),
        [
            'class' => 'badge me-2 align-middle',
            'style' => 'background-color:#cce6ea;border:1px solid #99cdd5;color:#00343c;',
        ]
    );

    echo html_writer::start_div(
        'lo-outcome-section card mb-5',
        ['data-outcomeid' => $outcomeid]
    );

    // Card header.
    echo html_writer::start_div('card-header d-flex align-items-center');
    echo $badge;
    echo html_writer::tag('span', format_string($outcome->fullname), ['class' => '']);
    echo html_writer::end_div();

    echo html_writer::start_div('card-body');

    // Optional description.
    if (!empty($outcome->description)) {
        echo html_writer::tag(
            'p',
            format_text($outcome->description, $outcome->descriptionformat ?? FORMAT_HTML),
            ['class' => 'text-muted small mb-3']
        );
    }

    // ── Activities table ──────────────────────────────────────────────────────
    echo html_writer::start_tag('table', [
        'class'          => 'table table-sm generaltable mb-2 lo-activities-table',
        'data-outcomeid' => $outcomeid,
    ]);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('activityname', 'report_learningoutcomes'));
    echo html_writer::tag('th', get_string('activitytype', 'report_learningoutcomes'));
    if ($canmanage) {
        echo html_writer::tag('th', '', ['class' => 'text-end', 'style' => 'width:1%']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    if (empty($taggedcms)) {
        $colspan = $canmanage ? 3 : 2;
        echo html_writer::tag('tr',
            html_writer::tag('td',
                html_writer::tag(
                    'em',
                    get_string('manage_noactivitieslinked', 'report_learningoutcomes'),
                    ['class' => 'text-muted']
                ),
                ['colspan' => $colspan, 'class' => 'lo-empty-row']
            )
        );
    } else {
        foreach ($taggedcms as $cmrow) {
            if (!isset($allcms[$cmrow->id])) {
                continue;
            }
            echo report_learningoutcomes_activity_row(
                $allcms[$cmrow->id], $outcomeid, $courseid, $canmanage
            );
        }
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    // ── Add-activity row (teacher only) ───────────────────────────────────────
    if ($canmanage) {
        $available = ['' => get_string('choosedots')];
        foreach ($assessable as $cmid => $cm) {
            if (!in_array($cmid, $taggedids)) {
                $available[$cmid] = format_string($cm->get_formatted_name())
                    . ' (' . ucfirst($cm->modname) . ')';
            }
        }

        echo html_writer::start_div('lo-add-form d-flex align-items-center gap-2 mt-1');
        echo html_writer::tag(
            'label',
            get_string('manage_addactivity', 'report_learningoutcomes'),
            ['for' => "lo-add-select-{$outcomeid}", 'class' => 'mb-0 me-1 small fw-semibold']
        );
        echo html_writer::select($available, "lo-add-{$outcomeid}", '', null, [
            'id'             => "lo-add-select-{$outcomeid}",
            'class'          => 'lo-add-select form-select form-select-sm',
            'style'          => 'max-width:320px',
            'data-outcomeid' => $outcomeid,
        ]);
        echo html_writer::tag('button',
            get_string('manage_linkbtn', 'report_learningoutcomes'),
            [
                'type'           => 'button',
                'class'          => 'btn btn-sm btn-primary lo-add-btn',
                'data-outcomeid' => $outcomeid,
            ]
        );
        echo html_writer::end_div();
    }

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // lo-outcome-section card
}

// ── Untagged activities (teacher only) ───────────────────────────────────────
if ($canmanage) {
    $report   = $manager->get_alignment_report($courseid);
    $untagged = $report['untagged_activities'] ?? [];

    if (!empty($untagged)) {
        echo html_writer::start_div('card border-warning mb-4');
        echo html_writer::start_div('card-header bg-warning bg-opacity-25 fw-semibold');
        echo get_string('manage_untagged_heading', 'report_learningoutcomes');
        echo html_writer::end_div();
        echo html_writer::start_div('card-body');
        echo html_writer::tag('p',
            get_string('manage_untagged_intro', 'report_learningoutcomes'),
            ['class' => 'text-muted small']
        );

        echo html_writer::start_tag('table', ['class' => 'table table-sm generaltable mb-0']);
        echo html_writer::tag('thead',
            html_writer::tag('tr',
                html_writer::tag('th', get_string('activityname', 'report_learningoutcomes')) .
                html_writer::tag('th', get_string('activitytype', 'report_learningoutcomes'))
            )
        );
        echo html_writer::start_tag('tbody');

        foreach ($untagged as $cmrow) {
            if (!isset($allcms[$cmrow->id])) {
                continue;
            }
            $cm  = $allcms[$cmrow->id];
            $url = $cm->url ?? new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
            $icon = $OUTPUT->pix_icon('monologo', '', $cm->modname,
                ['class' => 'activityicon me-1', 'width' => '16', 'height' => '16', 'aria-hidden' => 'true']);

            echo html_writer::tag('tr',
                html_writer::tag('td', html_writer::link($url, $icon . format_string($cm->get_formatted_name()))) .
            html_writer::tag('td', html_writer::tag('span', $cm->modfullname, ['class' => 'badge bg-secondary text-dark']))
            );
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
    }
}

echo $OUTPUT->footer();
