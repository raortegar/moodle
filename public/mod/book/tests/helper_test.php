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
require_once($CFG->dirroot . '/mod/book/lib.php');
require_once($CFG->dirroot . '/mod/book/locallib.php');

/**
 * Helper test class
 *
 * @package    mod_book
 * @copyright  2023 Laurent David <laurent.david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class helper_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Inserts a chapter user view record for testing.
     *
     * @param int $chapterid The ID of the chapter.
     * @param int $userid The ID of the user.
     */
    private function insert_userview(int $chapterid, int $userid): void {
        global $DB;
        $now = time();
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
            $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'completionreadpercent' => 100]),
            $this->getDataGenerator()->get_plugin_generator('mod_book'),
            $this->getDataGenerator()->create_user(),
        ];
    }

    /**
     * Test view_book
     * @covers \mod_book\helper::is_last_visible_chapter
     */
    public function test_is_last_chapter(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $firstchapter =
            $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 1]); // Create a first chapter to check that
        // viewing the last chapter is enough for completing the activity.
        $chapterhidden = $bookgenerator->create_chapter(['bookid' => $book->id, 'hidden' => 1, 'pagenum' => 2]);
        $lastchapter = $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 3]);
        $chapters = book_preload_chapters($book);
        $this->assertFalse(helper::is_last_visible_chapter($firstchapter->id, $chapters));
        $this->assertFalse(helper::is_last_visible_chapter($chapterhidden->id, $chapters));
        $this->assertTrue(helper::is_last_visible_chapter($lastchapter->id, $chapters));
    }

    /**
     * Returns an empty array when the user has no recorded chapter views.
     *
     * @covers \mod_book\helper::get_book_userviews
     */
    public function test_get_book_userviews_no_views(): void {
        [$book, $notused, $user] = $this->create_book_test_data();

        $result = helper::get_book_userviews($book->id, $user->id);
        $this->assertEmpty($result);
    }

    /**
     * Returns only the chapters viewed by the specified user.
     *
     * @covers \mod_book\helper::get_book_userviews
     */
    public function test_get_book_userviews_returns_viewed_chapters(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1    = $gen->create_chapter(['bookid' => $book->id]);
        $ch2    = $gen->create_chapter(['bookid' => $book->id]);

        $this->insert_userview($ch1->id, $user->id);

        $result = helper::get_book_userviews($book->id, $user->id);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey($ch1->id, $result);
        $this->assertArrayNotHasKey($ch2->id, $result);
    }

    /**
     * Hidden chapters must not appear in user-views results.
     *
     * @covers \mod_book\helper::get_book_userviews
     */
    public function test_get_book_userviews_excludes_hidden_chapters(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $visible = $gen->create_chapter(['bookid' => $book->id]);
        $hidden  = $gen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        $this->insert_userview($visible->id, $user->id);
        $this->insert_userview($hidden->id, $user->id);

        $result = helper::get_book_userviews($book->id, $user->id);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey($visible->id, $result);
        $this->assertArrayNotHasKey($hidden->id, $result);
    }

    /**
     * Returns zero progress when the user has not viewed any chapters.
     *
     * @covers \mod_book\helper::get_book_userview_progress
     */
    public function test_get_book_userview_progress_no_views(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $gen->create_chapter(['bookid' => $book->id]);

        $this->assertEquals(0, helper::get_book_userview_progress($book->id, $user->id));
    }

    /**
     * Returns the correct progress for a partially read book.
     *
     * @covers \mod_book\helper::get_book_userview_progress
     */
    public function test_get_book_userview_progress_partial(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1    = $gen->create_chapter(['bookid' => $book->id]);
        $gen->create_chapter(['bookid' => $book->id]);
        $gen->create_chapter(['bookid' => $book->id]);

        $this->insert_userview($ch1->id, $user->id);

        $this->assertEquals(33, helper::get_book_userview_progress($book->id, $user->id));
    }

    /**
     * Hidden chapters must not count towards total or viewed.
     *
     * @covers \mod_book\helper::get_book_userview_progress
     */
    public function test_get_book_userview_progress_ignores_hidden_chapters(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $visible = $gen->create_chapter(['bookid' => $book->id]);
        $hidden  = $gen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        // Only the visible chapter is viewed; the hidden one should not count.
        $this->insert_userview($visible->id, $user->id);

        // 1 visible chapter viewed out of 1 visible total = 100%.
        $this->assertEquals(100, helper::get_book_userview_progress($book->id, $user->id));
    }

    /**
     * Book with no chapters should return 0 progress.
     *
     * @covers \mod_book\helper::get_book_userview_progress
     */
    public function test_get_book_userview_progress_no_chapters(): void {
        [$book, $gen, $user] = $this->create_book_test_data();

        $this->assertEquals(0, helper::get_book_userview_progress($book->id, $user->id));
    }

    /**
     * When readpercent is disabled, always returns false.
     *
     * @covers \mod_book\helper::is_book_read_completed
     */
    public function test_is_book_read_completed_no_readpercent(): void {
        $course = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $gen  = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $user = $this->getDataGenerator()->create_user();

        $ch = $gen->create_chapter(['bookid' => $book->id]);

        $this->insert_userview($ch->id, $user->id);

        // Readpercent defaults to 0, so should always be false.
        $this->assertFalse(helper::is_book_read_completed($book->id, $user->id));
    }

    /**
     * User has not read enough chapters to meet the threshold.
     *
     * @covers \mod_book\helper::is_book_read_completed
     */
    public function test_is_book_read_completed_below_threshold(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1 = $gen->create_chapter(['bookid' => $book->id]);
        $gen->create_chapter(['bookid' => $book->id]);
        $gen->create_chapter(['bookid' => $book->id]);

        $this->insert_userview($ch1->id, $user->id);

        $this->assertFalse(helper::is_book_read_completed($book->id, $user->id));
    }

    /**
     * User has read exactly the threshold percentage.
     *
     * @covers \mod_book\helper::is_book_read_completed
     */
    public function test_is_book_read_completed_at_threshold(): void {
        $course = $this->getDataGenerator()->create_course();
        [$book, $gen, $user] = $this->create_book_test_data();
        $ch1    = $gen->create_chapter(['bookid' => $book->id]);
        $ch2    = $gen->create_chapter(['bookid' => $book->id]);

        $this->insert_userview($ch1->id, $user->id);
        $this->insert_userview($ch2->id, $user->id);

        $this->assertTrue(helper::is_book_read_completed($book->id, $user->id));
    }

    /**
     * User with no views and read percent set should not be completed.
     *
     * @covers \mod_book\helper::is_book_read_completed
     */
    public function test_is_book_read_completed_no_views(): void {
        [$book, $gen, $user] = $this->create_book_test_data();
        $gen->create_chapter(['bookid' => $book->id]);

        $this->assertFalse(helper::is_book_read_completed($book->id, $user->id));
    }

    /**
     * Hiding chapters changes the read percentage, so the stored completion state must be recalculated.
     *
     * @covers \mod_book\helper::reset_completion_state
     */
    public function test_reset_completion_state_after_chapters_hidden(): void {
        global $DB, $CFG;

        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module(
            'book',
            ['course' => $course->id, 'completionreadpercent' => 50],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC]
        );
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter1 = $gen->create_chapter(['bookid' => $book->id]);
        $chapter2 = $gen->create_chapter(['bookid' => $book->id]);
        $chapter3 = $gen->create_chapter(['bookid' => $book->id]);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $cm = get_coursemodule_from_instance('book', $book->id);
        $context = \context_module::instance($book->cmid);

        // Reading 1 of 3 chapters is 33%, which does not meet the 50% requirement.
        book_view($book, $chapter1, false, $course, $cm, $context);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        // Hide the two unread chapters. The student has now read every visible chapter.
        $DB->set_field('book_chapters', 'hidden', 1, ['id' => $chapter2->id]);
        $DB->set_field('book_chapters', 'hidden', 1, ['id' => $chapter3->id]);
        helper::reset_completion_state($book, $course);

        // The student must not be left stranded as incomplete with nothing else to read.
        rebuild_course_cache($course->id, true);
        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_instance('book', $book->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    /**
     * Nothing is recalculated when the read percent condition is not in use.
     *
     * @covers \mod_book\helper::reset_completion_state
     */
    public function test_reset_completion_state_without_readpercent(): void {
        global $CFG;

        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module(
            'book',
            ['course' => $course->id],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]
        );
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $cm = get_coursemodule_from_instance('book', $book->id);
        $context = \context_module::instance($book->cmid);

        // Viewing the only chapter completes the activity through the view condition.
        book_view($book, $chapter, true, $course, $cm, $context);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        // The view based completion must survive a call for a book without the read percent condition.
        helper::reset_completion_state($book, $course);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }
}
