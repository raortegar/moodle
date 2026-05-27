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

namespace report_learningoutcomes;

use core_course\hook\after_form_definition;
use core_course\hook\after_form_definition_after_data;
use core_course\hook\after_form_submission;
use core\hook\output\after_standard_main_region_html_generation;

/**
 * Hook listener for the Learning Outcomes course-edit form integration.
 *
 * Adds the per-course enable/disable toggle to the course edit form and
 * persists the selection when the form is saved — without modifying any
 * core course files.
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {

    /**
     * Adds the "Enable learning outcomes for this course" toggle to the course
     * edit form definition.
     *
     * @param after_form_definition $hook
     */
    public static function add_form_elements(after_form_definition $hook): void {
        global $CFG;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        $mform = $hook->mform;

        $mform->addElement('header', 'learningoutcomeshdr',
            get_string('learningoutcomes', 'grades'));
        $mform->addElement('selectyesno', 'learningoutcomes_enabled',
            get_string('learningoutcomes_courseenabled', 'grades'));
        $mform->addHelpButton('learningoutcomes_enabled', 'learningoutcomes_courseenabled', 'grades');
        $mform->setDefault('learningoutcomes_enabled',
            (int) get_config('core', 'learningoutcomes_coursedefault'));
        $mform->setType('learningoutcomes_enabled', PARAM_INT);
        // Placeholder filled in set_form_data when the course is below the minimum threshold.
        $mform->addElement('static', 'learningoutcomes_nudge', '', '');
    }

    /**
     * Pre-populates the toggle from the database when editing an existing course.
     *
     * @param after_form_definition_after_data $hook
     */
    public static function set_form_data(after_form_definition_after_data $hook): void {
        global $CFG;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        $mform    = $hook->mform;
        $courseid = $mform->getElementValue('id');

        if (empty($courseid) || !$mform->elementExists('learningoutcomes_enabled')) {
            return;
        }

        $manager = new \core\learning_outcomes\manager();
        $mform->setDefault('learningoutcomes_enabled',
            (int) $manager->is_enabled_for_course((int) $courseid));

        $msg = $manager->get_nudge_message((int) $courseid);
        if ($msg !== null && $mform->elementExists('learningoutcomes_nudge')) {
            $mform->getElement('learningoutcomes_nudge')->setValue(
                '<div class="alert alert-warning mt-2 mb-0">' . $msg . '</div>'
            );
        }
    }

    /**
     * Persists the per-course enable/disable flag after the form is saved.
     *
     * @param after_form_submission $hook
     */
    public static function save_form_data(after_form_submission $hook): void {
        global $CFG;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        $data = $hook->get_data();

        if (!isset($data->learningoutcomes_enabled)) {
            return;
        }

        $manager = new \core\learning_outcomes\manager();
        $manager->set_enabled_for_course((int) $data->id, !empty($data->learningoutcomes_enabled));
    }

    // =========================================================================
    // Layer 4 — Student surfaces
    // =========================================================================

    /**
     * Injects the outcomes card on course pages and outcome badges on activity
     * pages, visible to all users who can view the course.
     *
     * Fires via {@see \core\hook\output\after_standard_main_region_html_generation}
     * on every page render; bails out immediately when neither page type matches
     * or when learning outcomes are not active for the course.
     *
     * @param after_standard_main_region_html_generation $hook
     */
    public static function inject_student_surfaces(
        after_standard_main_region_html_generation $hook
    ): void {
        global $CFG, $PAGE;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        $page = $PAGE;

        // ── Course page ──────────────────────────────────────────────────────
        if (strpos($page->pagetype, 'course-view-') === 0) {
            $courseid = (int) $page->course->id;
            if ($courseid <= SITEID) {
                return;
            }
            $manager = new \core\learning_outcomes\manager();
            if (!$manager->is_enabled_for_course($courseid)) {
                return;
            }
            $outcomes = $manager->get_course_outcomes($courseid);
            if (empty($outcomes)) {
                return;
            }
            $hook->add_html(self::render_course_card($outcomes));
            return;
        }

        // ── Activity page ────────────────────────────────────────────────────
        if (strpos($page->pagetype, 'mod-') === 0) {
            $courseid = (int) $page->course->id;
            $manager  = new \core\learning_outcomes\manager();
            if (!$manager->is_enabled_for_course($courseid)) {
                return;
            }
            $cm = $page->cm;
            if (empty($cm)) {
                return;
            }
            $tagged = $manager->get_tagged_outcomes((int) $cm->id);
            if (empty($tagged)) {
                return;
            }
            $hook->add_html(self::render_activity_badges($tagged));
        }
    }

    /**
     * Renders the learning outcomes card for the course page.
     *
     * Uses Moodle's standard output API (box/heading) so the markup is
     * theme-aware and RTL-safe.
     *
     * @param \stdClass[] $outcomes Rows from grade_outcomes.
     * @return string HTML fragment.
     */
    private static function render_course_card(array $outcomes): string {
        global $OUTPUT;

        $heading = get_string('courseoutcomes_heading', 'report_learningoutcomes');
        $intro   = get_string('courseoutcomes_intro',   'report_learningoutcomes');

        $items = '';
        foreach ($outcomes as $outcome) {
            $badge = \html_writer::tag(
                'span',
                format_string($outcome->shortname),
                ['class' => 'badge bg-primary me-2 flex-shrink-0']
            );
            $items .= \html_writer::tag(
                'li',
                $badge . format_string($outcome->fullname),
                ['class' => 'list-group-item d-flex align-items-center px-0 border-0']
            );
        }

        $content = \html_writer::tag('p', format_string($intro), ['class' => 'text-muted small mb-2'])
                 . \html_writer::tag('ul', $items, ['class' => 'list-group list-group-flush']);

        return $OUTPUT->box(
            $OUTPUT->heading($heading, 3, 'h5 mb-3') . $content,
            'generalbox learningoutcomes-card mb-3'
        );
    }

    /**
     * Renders the outcome badges shown on an activity page.
     *
     * Uses Moodle's standard notification output so the markup is theme-aware.
     *
     * @param \stdClass[] $outcomes Tagged outcome rows (from get_tagged_outcomes).
     * @return string HTML fragment.
     */
    private static function render_activity_badges(array $outcomes): string {
        global $OUTPUT;

        $heading = get_string('activityoutcomes_heading', 'report_learningoutcomes');

        $badges = '';
        foreach ($outcomes as $outcome) {
            $label = format_string($outcome->shortname);
            if (!empty($outcome->fullname)) {
                $label .= ' \u2014 ' . format_string($outcome->fullname);
            }
            $badges .= \html_writer::tag(
                'span',
                $label,
                ['class' => 'badge bg-info text-dark me-1 mb-1']
            );
        }

        $message = \html_writer::tag('strong', format_string($heading) . ':', ['class' => 'me-2'])
                 . $badges;

        return $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO, false);
    }
}
