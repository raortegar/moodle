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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/book/locallib.php');

/**
 * Unit tests for mod_book locallib functions.
 *
 * @package    mod_book
 * @copyright  2026 Anupama Sarjoshi <anupama.sarjoshi@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locallib_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Inserts a chapter user view record for testing.
     *
     * @param int $chapterid The ID of the chapter.
     * @param int $userid The ID of the user.
     * @param int $timeviewed The timestamp of when the chapter was viewed.
     */
    private function insert_userview(int $chapterid, int $userid, int $timeviewed = 0): void {
        global $DB;
        $now = $timeviewed ?: time();
        $DB->insert_record('book_chapters_userviews', (object)[
            'chapterid'   => $chapterid,
            'userid'      => $userid,
            'timecreated' => $now,
            'timeviewed'  => $now,
        ]);
    }

    /**
     * Creates a book activity, plugin generator and test user.
     *
     * @return array [$book, $generator, $user]
     */
    private function create_book_test_data(): array {
        $course = $this->getDataGenerator()->create_course();

        return [
            $this->getDataGenerator()->create_module('book', ['course' => $course->id]),
            $this->getDataGenerator()->get_plugin_generator('mod_book'),
            $this->getDataGenerator()->create_user(),
        ];
    }

    /**
     * Returns null when user has not viewed any chapter.
     *
     * @covers ::book_get_last_viewed_chapter
     */
    public function test_book_get_last_viewed_chapter_no_views(): void {
        [$book, $gen, $user] = $this->create_book_test_data();

        $this->setUser($user);
        $this->assertNull(book_get_last_viewed_chapter($book->id));
    }

    /**
     * Returns the most recently viewed chapter ID.
     *
     * @covers ::book_get_last_viewed_chapter
     */
    public function test_book_get_last_viewed_chapter_returns_latest(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1    = $gen->create_chapter(['bookid' => $book->id]);
        $ch2    = $gen->create_chapter(['bookid' => $book->id]);

        $this->setUser($user);
        $base = time() - 100;
        $this->insert_userview($ch1->id, $user->id, $base);
        $this->insert_userview($ch2->id, $user->id, $base + 50);

        $this->assertEquals($ch2->id, book_get_last_viewed_chapter($book->id));
    }

    /**
     * Hidden chapters must not be returned as last viewed.
     *
     * @covers ::book_get_last_viewed_chapter
     */
    public function test_book_get_last_viewed_chapter_ignores_hidden(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $visible = $gen->create_chapter(['bookid' => $book->id]);
        $hidden  = $gen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        $this->setUser($user);
        $base = time() - 100;
        $this->insert_userview($visible->id, $user->id, $base);
        // Hidden chapter viewed more recently.
        $this->insert_userview($hidden->id, $user->id, $base + 50);

        // Should return the visible chapter, not the hidden one.
        $this->assertEquals($visible->id, book_get_last_viewed_chapter($book->id));
    }

    /**
     * Returns null when only a hidden chapter has been viewed.
     *
     * @covers ::book_get_last_viewed_chapter
     */
    public function test_book_get_last_viewed_chapter_all_hidden(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $hidden = $gen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        $this->setUser($user);
        $this->insert_userview($hidden->id, $user->id);

        $this->assertNull(book_get_last_viewed_chapter($book->id));
    }

    /**
     * Returns null when user has no views.
     *
     * @covers ::book_get_chapter_to_display
     */
    public function test_book_get_chapter_to_display_no_views(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $gen->create_chapter(['bookid' => $book->id]);
        $chapters = book_preload_chapters($book);

        $this->setUser($user);
        $this->assertNull(book_get_chapter_to_display($book->id, $chapters));
    }

    /**
     * Returns the last viewed chapter when it is visible.
     *
     * @covers ::book_get_chapter_to_display
     */
    public function test_book_get_chapter_to_display_returns_last_viewed(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1 = $gen->create_chapter(['bookid' => $book->id]);
        $ch2 = $gen->create_chapter(['bookid' => $book->id]);

        $this->setUser($user);
        $base = time() - 100;
        $this->insert_userview($ch1->id, $user->id, $base);
        $this->insert_userview($ch2->id, $user->id, $base + 50);

        $chapters = book_preload_chapters($book);
        $this->assertEquals($ch2->id, book_get_chapter_to_display($book->id, $chapters));
    }
}
