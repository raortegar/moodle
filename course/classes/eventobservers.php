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

namespace core_course;

use core\event\base as event;

/**
 * Event observers for core_course.
 *
 * This class contains methods for handling various course-related events
 * to trigger necessary actions, such as marking a course for backup.
 *
 * @package    core_course
 * @copyright  2024 Raquel Ortega <raquel.ortega@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eventobservers {

    /**
     * Handles the course update event.
     *
     * This observer marks a course as needing backup if the event meets
     * the criteria for requiring a backup.
     * It excludes certain events based on hooks and other configurations.
     *
     * @param  event $event
     * @return void
     */
    public static function course_updated(event $event): void {
        global $COURSE;

        // Ignore events with invalid or site-wide course IDs.
        if (empty($event->courseid) || $event->courseid == SITEID) {
            return;
        }

        // Skip events where the action was a "read-only" operation.
        if ($event->crud == 'r') {
            return;
        }

        // Skip if the course is already flagged for backup.
        if (!empty($COURSE->id) && !empty($COURSE->needsbackup)
            && $COURSE->id == $event->courseid
            && $COURSE->needsbackup == 1
        ) {
            return;
        }

        // Exclude events defined by hook.
        $hook = new \core_backup\hook\before_course_modified_check();
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $excludeevents = $hook->get_excluded_events();

        // Prevent logs of previous backups causing a false positive.
        $excludeevents[] = '\core\event\course_backup_created';

        if (in_array($event->eventname, $excludeevents)) {
            return;
        }

        $levels = [event::LEVEL_TEACHING, event::LEVEL_OTHER];

        // Include LEVEL_PARTICIPATING if any relevant backup settings are enabled.
        $backupparticipating = [
            'backup_auto_comments',
            'backup_auto_userscompletion',
        ];
        foreach ($backupparticipating as $conf) {
            if (get_config('backup', $conf)) {
                $levels[] = event::LEVEL_PARTICIPATING;
                break;
            }
        }
        if (!in_array($event->edulevel, $levels)) {
            return;
        }

        self::set_course_needsbackup($event->courseid, 1);
    }

    /**
     * Handles the course backup creation event.
     *
     * Marks the course as no longer needing a backup after a successful backup is created.
     *
     * @param event $event The event data.
     * @return void
     */
    public static function course_backup_created(event $event): void {
        self::set_course_needsbackup($event->objectid, 0);
    }

    /**
     * Handles the course restoration event.
     *
     * Marks the course as no longer needing a backup after it has been restored.
     *
     * @param event $event The event data.
     * @return void
     */
    public static function course_restored(event $event): void {
        self::set_course_needsbackup($event->objectid, 0);
    }

    /**
     * Sets the `needsbackup` flag for a course.
     *
     * Updates the `needsbackup` field in the course database table and,
     * if the course is in the global context, updates the global `$COURSE` object.
     *
     * @param int $courseid The ID of the course.
     * @param int $needsbackup The value to set for the `needsbackup` flag (0 or 1).
     * @return void
     */
    private static function set_course_needsbackup(int $courseid, int $needsbackup): void {
        global $DB, $COURSE;

        // Update the `needsbackup` field in the database.
        $DB->set_field('course', 'needsbackup', $needsbackup, ['id' => $courseid]);

        // Update the global $COURSE object if it matches the course being updated.
        if (!empty($COURSE->id) && $COURSE->id == $courseid) {
            $COURSE->needsbackup = $needsbackup;
        }
    }
}
