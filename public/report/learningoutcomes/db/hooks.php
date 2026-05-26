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
 * Learning Outcomes Alignment Report - hook listener registrations.
 *
 * @package   report_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    // Add the enable/disable toggle to the course edit form.
    [
        'hook'     => \core_course\hook\after_form_definition::class,
        'callback' => \report_learningoutcomes\hook_listener::class . '::add_form_elements',
    ],
    // Pre-populate the toggle from DB when editing an existing course.
    [
        'hook'     => \core_course\hook\after_form_definition_after_data::class,
        'callback' => \report_learningoutcomes\hook_listener::class . '::set_form_data',
    ],
    // Persist the per-course flag when the course form is saved.
    [
        'hook'     => \core_course\hook\after_form_submission::class,
        'callback' => \report_learningoutcomes\hook_listener::class . '::save_form_data',
    ],
];
