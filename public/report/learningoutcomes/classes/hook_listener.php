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
}
