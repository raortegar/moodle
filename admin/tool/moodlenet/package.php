<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * POC page: wrap up an activity into a non-interactive backup, without PII, and present the link to the file.
 *
 * @package     tool_moodlenet
 * @copyright   2022 Jake Dallimore <jrhdallimore@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
use tool_moodlenet\local\activity_packager;

$id = required_param('id', PARAM_INT); // The cmid.
$download = optional_param('download', false, PARAM_BOOL);

[$course, $cm ] = get_course_and_cm_from_cmid($id);
require_course_login($course, false);

// Display the page.
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_pagelayout('standard');
$url = new moodle_url('/admin/tool/moodlenet/package.php', ['id' => $id]);
$PAGE->set_url($url);
//$PAGE->set_secondary_active_tab('coursehome');
$PAGE->set_title("Backup test");
$PAGE->set_heading(" Backup test");

// Backup the CM using non-interactive, 'MODE_GENERAL' and by overriding specific plan settings.
$packager = new activity_packager($cm, $USER->id);
$packager->override_task_setting('setting_root_users', 0);
$packager->override_task_setting('setting_root_blocks', 0);
[$activity, $fileinfo] = $packager->package();

// Quick and dirty hack to support file download without implementing _pluginfile callback.
if ($download) {
    send_stored_file($fileinfo['file']);
    exit();
}

// Display the page.
$renderer = $PAGE->get_renderer('core');
echo $OUTPUT->header();

echo html_writer::div("Packaged activity '$cm->name'.");
$url->param('download', true);
echo html_writer::div(html_writer::link($url, get_string('download')));
echo html_writer::empty_tag('br');

echo html_writer::tag('h2', 'Debug');
echo html_writer::tag('h4', 'Task settings which can be overridden');
print_object($packager->get_readable_task_settings());
echo html_writer::tag('h4', 'Task settings currently overridden');
print_object($packager->get_overridden_task_settings());
//echo html_writer::tag('h4', 'Activity:');
//print_object($activity);
echo html_writer::tag('h4', 'File info:');
print_object($fileinfo);

//echo $renderer->render_from_template('tool_moodlenet/xxx', $context);
echo $OUTPUT->footer();
