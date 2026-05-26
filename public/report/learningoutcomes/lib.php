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
 * Injects the "Align to learning outcomes" autocomplete field into every
 * activity/resource edit form.
 *
 * Called automatically by {@see moodleform_mod::plugin_extend_coursemodule_standard_elements()}.
 *
 * @param moodleform     $formwrapper The quick-form wrapper.
 * @param MoodleQuickForm $mform      The underlying form object.
 */
function report_learningoutcomes_coursemodule_standard_elements($formwrapper, $mform): void {
    global $CFG, $COURSE;

    if (empty($CFG->enableoutcomes)) {
        return;
    }

    $manager = new \core\learning_outcomes\manager();

    if (!$manager->is_enabled_for_course($COURSE->id)) {
        return;
    }

    // Skip decorative/non-assessable module types.
    $modname = $formwrapper->get_current()->modulename ?? '';
    if ($manager->is_decorative($modname)) {
        return;
    }

    $courseoutcomes = $manager->get_course_outcomes($COURSE->id);
    if (empty($courseoutcomes)) {
        return;
    }

    $options = [];
    foreach ($courseoutcomes as $lo) {
        $options[$lo->id] = format_string($lo->shortname) . ' — ' . format_string($lo->fullname);
    }

    $mform->addElement('header', 'learningoutcomestags',
        get_string('learningoutcomes_tagactivity', 'grades'));
    $mform->addElement('autocomplete', 'learningoutcomes', '',
        $options, ['multiple' => true, 'noselectionstring' => get_string('none')]);

    // Pre-populate with existing tags when editing an activity.
    $cm = $formwrapper->get_coursemodule();
    if (!empty($cm->id)) {
        $tagged = $manager->get_tagged_outcomes($cm->id);
        $mform->setDefault('learningoutcomes', array_column($tagged, 'id'));
    }
}

/**
 * Saves the learning outcome tags after an activity is created or updated.
 *
 * Called automatically by {@see plugin_extend_coursemodule_edit_post_actions()}
 * from both {@see add_moduleinfo()} and {@see update_moduleinfo()}.
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

    // Form field absent means the feature was not displayed (disabled course or decorative module).
    if (!isset($data->learningoutcomes)) {
        return $data;
    }

    $manager = new \core\learning_outcomes\manager();

    $cmid   = $data->coursemodule;
    $newids = !empty($data->learningoutcomes)
        ? array_map('intval', (array) $data->learningoutcomes)
        : [];

    // Remove deselected tags.
    foreach ($manager->get_tagged_outcomes($cmid) as $existing) {
        if (!in_array((int) $existing->id, $newids)) {
            $manager->untag_outcome($cmid, (int) $existing->id);
        }
    }

    // Add newly selected tags.
    foreach ($newids as $outcomeid) {
        $manager->tag_outcome($cmid, $outcomeid, $course->id);
    }

    return $data;
}
