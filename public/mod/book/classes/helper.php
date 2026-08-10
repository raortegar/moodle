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
namespace mod_book;

/**
 * Book helper
 *
 * @package    mod_book
 * @copyright  2023 Laurent David <laurent.david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Minimum number of seconds between updates to a chapter's last viewed time.
     *
     * This reduces unnecessary database writes caused by repeated page refreshes.
     */
    public const CHAPTER_VIEW_DEBOUNCE_SECONDS = 5;

    /**
     * Check if we are on the last visible chapter of the book.
     *
     * @param int $chapterid
     * @param array $chapters chapter list provided by book_preload_chapters
     * @see book_preload_chapters
     * @return bool
     */
    public static function is_last_visible_chapter(int $chapterid, array $chapters): bool {
        $lastchapterid = 0;
        foreach ($chapters as $ch) {
            if ($ch->hidden) {
                continue;
            }
            $lastchapterid = $ch->id;
        }
        return $chapterid == $lastchapterid;
    }

    /**
     * Check if the user completed the book read based on its completion read percent requirement.
     *
     * Note: This method is unused, but it is kept for future use.
     *
     * @param int $bookid
     * @param int $userid
     * @return bool
     */
    public static function is_book_read_completed(int $bookid, int $userid): bool {
        global $DB;

        $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);

        if (!$book->completionreadpercent) {
            return false;
        }

        $percentviewed = self::get_book_userview_progress($book->id, $userid);

        if ($percentviewed >= $book->completionreadpercent) {
            return true;
        }

        return false;
    }

    /**
     * Returns the user progress in a book based on their userviews.
     *
     * @param int $bookid
     * @param int $userid
     * @return int
     */
    public static function get_book_userview_progress(int $bookid, int $userid): int {
        global $DB;

        $chapters = $DB->get_records('book_chapters', ['bookid' => $bookid, 'hidden' => 0], 'id', 'id');

        $userviewedchapters = self::get_book_userviews($bookid, $userid);

        if (empty($chapters) || empty($userviewedchapters)) {
            return 0;
        }

        // Truncate rather than round so the "read at least X%" condition is never satisfied early (e.g. 2 of
        // 3 chapters gives 66%, not 67%).
        return (int)((count($userviewedchapters) / count($chapters)) * 100);
    }

    /**
     * Returns all chapters views of a user.
     *
     * @param int $bookid
     * @param int $userid
     * @return array
     */
    public static function get_book_userviews(int $bookid, int $userid): array {
        global $DB;

        // The chapterid-userid unique index guarantees one row per chapter.
        $userviewedchapterssql = "SELECT uv.chapterid
                                    FROM {book_chapters_userviews} uv
                                    JOIN {book_chapters} bc ON bc.id = uv.chapterid
                                   WHERE bc.bookid = :bookid
                                         AND uv.userid = :userid
                                         AND bc.hidden = 0";
        $parameters = [
            'bookid' => $bookid,
            'userid' => $userid,
        ];

        return $DB->get_records_sql($userviewedchapterssql, $parameters);
    }

    /**
     * Recalculate the activity completion of every tracked user after the set of visible chapters changed.
     *
     * The completionreadpercent rule is relative to the number of visible chapters, so adding, deleting,
     * hiding or showing a chapter invalidates the cached completion state of every user. Without this the
     * stored state stays stale until the user happens to view another chapter, which may never happen.
     *
     * @param \stdClass $book The book instance record. Must include the completionreadpercent field.
     * @param \stdClass|null $course The course record. Fetched from the book if not provided.
     */
    public static function reset_completion_state(\stdClass $book, ?\stdClass $course = null): void {
        global $CFG;

        if (empty($book->completionreadpercent)) {
            // Read percent rule is not in use, so the chapter count does not affect completion.
            return;
        }

        // The completion_info class is not autoloaded and callers do not all include completionlib.
        require_once($CFG->libdir . '/completionlib.php');

        $course = $course ?? get_course($book->course);

        if (!$cm = get_coursemodule_from_instance('book', $book->id, $course->id)) {
            return;
        }

        $cminfo = \cm_info::create($cm);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled($cminfo)) {
            return;
        }

        $completion->reset_all_state($cminfo);
    }
}
