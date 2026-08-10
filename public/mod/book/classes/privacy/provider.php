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
 * Privacy Subsystem implementation for mod_book.
 *
 * @package    mod_book
 * @copyright  2018 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_book\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The mod_book module does not store any data.
 *
 * @package    mod_book
 * @copyright  2018 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('book_chapters_userviews', [
            'chapterid' => 'privacy:metadata:book_chapters_userviews:chapterid',
            'userid' => 'privacy:metadata:book_chapters_userviews:userid',
            'timecreated' => 'privacy:metadata:book_chapters_userviews:timecreated',
            'timeviewed' => 'privacy:metadata:book_chapters_userviews:timeviewed',
        ], 'privacy:metadata:book_chapters_userviews');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Fetch all data records that the user wrote.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {book} b ON b.id = cm.instance
                  JOIN {book_chapters} bc ON bc.bookid = b.id
                  JOIN {book_chapters_userviews} bcu ON bcu.chapterid = bc.id
                 WHERE bcu.userid = :userid";

        $params = [
            'contextlevel'  => CONTEXT_MODULE,
            'modname'       => 'book',
            'userid'        => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!is_a($context, \core\context\module::class)) {
            return;
        }

        // Find users with data records.
        $sql = "SELECT bcu.userid
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {book} b ON b.id = cm.instance
                  JOIN {book_chapters} bc ON bc.bookid = b.id
                  JOIN {book_chapters_userviews} bcu ON bcu.chapterid = bc.id
                 WHERE c.id = :contextid";

        $params = [
            'modname' => 'book',
            'contextid' => $context->id,
            'contextlevel' => CONTEXT_MODULE,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $user = $contextlist->get_user();

        $sqlandparams = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $contextsql = $sqlandparams[0];
        $contextparams = $sqlandparams[1];

        $sql = "SELECT
                    c.id AS contextid,
                    cm.id AS cmid,
                    bc.title AS chaptertitle,
                    bcu.id,
                    bcu.chapterid,
                    bcu.userid,
                    bcu.timecreated,
                    bcu.timeviewed
                  FROM {book_chapters_userviews} bcu
                  JOIN {book_chapters} bc ON bc.id = bcu.chapterid
                  JOIN {book} b ON b.id = bc.bookid
                  JOIN {course_modules} cm ON b.id = cm.instance
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {context} c ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                 WHERE c.id {$contextsql}
                   AND bcu.userid = :userid
              ORDER BY bcu.id, cm.id";

        $params = [
                'userid' => $user->id,
                'modulename' => 'book',
                'contextlevel' => CONTEXT_MODULE,
            ] + $contextparams;

        $chapterviews = $DB->get_recordset_sql($sql, $params);
        foreach ($chapterviews as $chapterview) {
            $context = \core\context\module::instance($chapterview->cmid);
            $chaptertitle = format_string($chapterview->chaptertitle, true, ['context' => $context]);

            $data = new \stdClass();
            $data->chapterid = $chapterview->chapterid;
            $data->chaptertitle = $chaptertitle;
            $data->timecreated = transform::datetime($chapterview->timecreated);
            $data->timeviewed = transform::datetime($chapterview->timeviewed);

            $subcontext = [
                get_string('chapters', 'mod_book'),
                $chaptertitle . " ({$chapterview->chapterid})",
            ];

            writer::with_context($context)->export_data($subcontext, $data);
        }

        $chapterviews->close();
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context $context   The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\core\context $context) {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        if (!$cm = get_coursemodule_from_id('book', $context->instanceid)) {
            return;
        }

        $bookid = (int) $cm->instance;
        if (!$bookid) {
            return;
        }

        // Delete all chapters views items.
        $DB->delete_records_select(
            'book_chapters_userviews',
            'chapterid IN (SELECT id FROM {book_chapters} WHERE bookid = :bookid)',
            ['bookid' => $bookid]
        );
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist as $context) {
            // Get the book instance, ignoring any context which is not a book activity.
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            if (!$cm = get_coursemodule_from_id('book', $context->instanceid)) {
                continue;
            }

            $bookid = (int) $cm->instance;
            if (!$bookid) {
                continue;
            }

            // Delete all user view items.
            $DB->delete_records_select(
                'book_chapters_userviews',
                'userid = :userid AND chapterid IN (SELECT id FROM {book_chapters} WHERE bookid = :bookid)',
                ['userid' => $user->id, 'bookid' => $bookid]
            );
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        if (!$cm = get_coursemodule_from_id('book', $context->instanceid)) {
            return;
        }

        $bookid = (int) $cm->instance;
        if (!$bookid) {
            return;
        }

        $sqlandparams = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $userinsql = $sqlandparams[0];
        $userinparams = $sqlandparams[1];

        $params = array_merge(['bookid' => $bookid], $userinparams);

        // Delete all user view items.
        $DB->delete_records_select(
            'book_chapters_userviews',
            "chapterid IN (SELECT id FROM {book_chapters} WHERE bookid = :bookid) AND userid {$userinsql}",
            $params
        );
    }
}
