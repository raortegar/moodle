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

namespace tool_moodlenet\local;

/**
 * Simple activity packager for POC work.
 *
 * This isn't intended to be used for anything other than probing backup APIs.
 *
 * @copyright 2022 Jake Dallimore <jrhdallimore@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_packager {

    protected \cm_info $cminfo;

    protected \backup_controller $controller;

    protected array $overriddensettings;

    /**
     * The activity_packager constructor.
     */
    public function __construct(\cm_info $cminfo, int $userid) {
        global $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Check backup/restore support.
        if (!plugin_supports('mod', $cminfo->modname , FEATURE_BACKUP_MOODLE2)) {
            throw new \coding_exception("Cannot backup module $cminfo->modname. This module doesn't support the backup feature.");
        }

        $this->cminfo = $cminfo;
        $this->overriddensettings = [];

        $this->controller = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cminfo->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $userid
        );
    }

    /**
     * Get the settings available for override.
     *
     * @return array the associative array of taskclass => settings instances
     */
    protected function get_all_task_settings(): array {
        $tasksettings = [];
        foreach ($this->controller->get_plan()->get_tasks() as $task) {
            $taskclass = get_class($task);
            $tasksettings[$taskclass] = $task->get_settings();
        }
        return $tasksettings;
    }

    /**
     * Debug: Return the task settings in a human readable way.
     *
     * @return array of task class name => readable setting name
     */
    public function get_readable_task_settings(): array {
        return array_map(function ($settings) {
            return array_map(function ($setting) {
                return $setting->get_ui_name();
            }, $settings);
        }, $this->get_all_task_settings());
    }

    public function override_task_setting($settingname, $settingvalue) {
        $alltasksettings = $this->get_all_task_settings();
        foreach ($alltasksettings as $taskclass => $settings) {
            if ($taskclass == \backup_root_task::class) {
                foreach ($settings as $setting) {
                    $name = $setting->get_ui_name();
                    if ($name == $settingname && $settingvalue != $setting->get_value()) {
                        $setting->set_value($settingvalue);
                        $this->overriddensettings[$settingname] = $settingvalue;
                    }
                }
            }
        }
    }

    public function get_overridden_task_settings() {
        return $this->overriddensettings;
    }

    /**
     * Package the activity identified by CMID.
     *
     * @return null|array the activity and file record information. E.g. [activity, filerecord]
     */
    public function package(): ?array {
        // Backup the activity.
        // Custom plan settings - similar to what backup_ui_stage_initial::process() does with the settings form data.
        // Here we can override the settings for a backup plan, by specifying them in the array.
        // Any setting dependent on a setting disabled this way will also be locked by reason of hierarchy, as would be
        // the case in regular interactive backups.
        //$this->override_task_setting('setting_root_users', 0);

        $this->controller->execute_plan();

        // Grab the result.
        $result = $this->controller->get_results();
        if (!isset($result['backup_destination'])) {
            throw new \moodle_exception('Failed to package activity.');
        }

        // Have finished with the controller, let's destroy it, freeing mem and resources.
        //$this->controller->destroy();

        // Grab the filename.
        $file = $result['backup_destination'];
        if (!$file->get_contenthash()) {
            throw new \moodle_exception('Failed to package activity (invalid file).');
        }

        // Record the activity, get an ID.
        $activity = new \stdClass();
        $activity->courseid = $this->cminfo->course;
        $activity->section = $this->cminfo->section;
        $activity->module = $this->cminfo->module;
        $activity->name = $this->cminfo->name;
        $activity->timecreated = time();
        //$binid = $DB->insert_record('tool_moodlenet_blah', $activity);

        // This should be an id representing the item within tool_moodlenet.
        // It's just hacked here, so we reuse the same id for each activity.
        $id = $this->cminfo->id;

        // Create the location we want to copy this file to.
        $fr = array(
            'contextid' => \context_course::instance($this->cminfo->course)->id,
            'component' => 'tool_moodlenet',
            'filearea' => 'moodlenet_activity',
            'itemid' => $id,
            'timemodified' => time()
        );

        // Move the file to our own special little place.
        $fs = get_file_storage();

        // Only for testing: Purge any area files for this component_filearea_itemid.
        // The script should generate a new backup file each time it is run.
        $fs->delete_area_files($fr['contextid'], $fr['component'], $fr['filearea'], $fr['itemid']);

        if (!$fs->create_file_from_storedfile($fr, $file)) {
            // Failed, cleanup local MoodleNet records, if present.
            //$DB->delete_records('tool_moodlenet_blah', array(
            //    'id' => $id
            //));
            throw new \moodle_exception("Failed to copy backup file to moodlenet_activity area.");
        }

        // Delete the old file.
        $file->delete();

        foreach ($fs->get_area_files($fr['contextid'], $fr['component'], $fr['filearea'], $fr['itemid']) as $file) {
            if (!$file->is_directory()) {
                $fr['file'] = $file;
                $fileurl = \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                );
                $fr['fileurl'] = $fileurl;
            }
        }

        return [$activity, $fr];
    }
}

