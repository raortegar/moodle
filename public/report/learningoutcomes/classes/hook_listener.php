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
use core\hook\output\before_standard_top_of_body_html_generation;

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

        // ── Activity page — outcome badge strip ──────────────────────────────
        // Course-page activity labels are handled in inject_course_page_labels()
        // via before_standard_top_of_body_html_generation, which fires before the
        // course content is rendered and sets afterlink on each cm_info object.
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
     * Injects outcome shortname badges on each activity card on the course page.
     *
     * Fires via {@see \core\hook\output\before_standard_top_of_body_html_generation}
     * inside $OUTPUT->header(), before the course content is rendered.
     * Calls cm_info::set_after_link() on the statically-cached cm_info objects so
     * that the course format output class picks them up naturally via
     * $data->afterlink = $this->mod->afterlink in its export_for_template().
     *
     * @param before_standard_top_of_body_html_generation $hook
     */
    public static function inject_course_page_labels(
        before_standard_top_of_body_html_generation $hook
    ): void {
        global $CFG, $DB, $PAGE;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        if (strpos($PAGE->pagetype, 'course-view-') !== 0) {
            return;
        }

        $courseid = (int) $PAGE->course->id;
        if ($courseid <= SITEID) {
            return;
        }

        $manager = new \core\learning_outcomes\manager();
        if (!$manager->is_enabled_for_course($courseid)) {
            return;
        }

        // One query: cmid → [{shortname, fullname}, …] for every activity with
        // an outcome assigned via the standard activity edit form checkboxes.
        $tagset = $DB->get_recordset_sql(
            'SELECT cm.id AS cmid, go.shortname, go.fullname
               FROM {grade_items} gi
               JOIN {grade_outcomes} go ON go.id = gi.outcomeid
               JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                       AND cm.course   = gi.courseid
               JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
              WHERE gi.courseid = :courseid
                AND gi.itemtype = :itemtype
                AND gi.outcomeid IS NOT NULL
           ORDER BY cm.id, go.shortname',
            ['courseid' => $courseid, 'itemtype' => 'mod']
        );
        $byactivity = [];
        foreach ($tagset as $row) {
            $byactivity[$row->cmid][] = (object) [
                'shortname' => $row->shortname,
                'fullname'  => $row->fullname,
            ];
        }
        $tagset->close();

        if (empty($byactivity)) {
            return;
        }

        // Get the cached modinfo object (created once per request).
        // We modify the cm_info objects in place; the course format output class
        // reads $cm->afterlink during export_for_template(), so modifications
        // here are reflected in the rendered HTML without any JavaScript.
        $modinfo = get_fast_modinfo($courseid);
        $allcms  = $modinfo->get_cms();

        // Translatable prefix shown to screen readers and in the tooltip.
        $outcomestr = get_string('outcome', 'grades');

        foreach ($byactivity as $cmid => $outcomes) {
            if (!array_key_exists($cmid, $allcms)) {
                continue;
            }
            $cm = $allcms[$cmid];

            // Read $cm->afterlink first so that the module's own cm_info_view
            // callback runs (obtain_view_data) before we append our badges.
            $existing = $cm->afterlink;

            $badges = '';
            foreach ($outcomes as $outcome) {
                $shortname = format_string($outcome->shortname);
                $fullname  = format_string($outcome->fullname);
                // Pill badge: shortname visible, full name in tooltip + aria-label.
                $badges .= \html_writer::tag(
                    'span',
                    $shortname,
                    [
                        'class'      => 'lo-outcome-badge badge rounded-pill bg-primary text-white me-1',
                        'title'      => $fullname,
                        'aria-label' => $outcomestr . ': ' . $fullname,
                        'role'       => 'note',
                    ]
                );
            }

            // Wrap all badges in a labelled container for screen readers.
            $wrapper = \html_writer::tag(
                'span',
                $badges,
                [
                    'class'      => 'lo-outcome-labels ms-1',
                    'aria-label' => get_string('learningoutcomes', 'report_learningoutcomes'),
                ]
            );

            $cm->set_after_link($existing . $wrapper);
        }
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
