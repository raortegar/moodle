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

namespace mod_book\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\tests\provider_testcase;

/**
 * Unit tests for mod_book privacy provider.
 *
 * @package    mod_book
 * @copyright  2026 Anupama Sarjoshi <anupama.sarjoshi@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_book\privacy\provider
 */
final class provider_test extends provider_testcase {
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
     * Provider stores no data when user has not viewed any chapter.
     */
    public function test_get_contexts_for_userid_no_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(0, $contextlist);
    }

    /**
     * Context is returned when a user has viewed a chapter.
     */
    public function test_get_contexts_for_userid_with_data(): void {
        $course  = $this->getDataGenerator()->create_course();
        $book    = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $gen     = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);
        $user    = $this->getDataGenerator()->create_user();

        $this->insert_userview($chapter->id, $user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(1, $contextlist);

        $context = \context_module::instance($book->cmid);
        $this->assertContainsEquals($context->id, $contextlist->get_contextids());
    }

    /**
     * Tests that get_users_in_context() returns users with views in the given context.
     */
    public function test_get_users_in_context(): void {
        $course  = $this->getDataGenerator()->create_course();
        $book    = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $gen     = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);
        $user1   = $this->getDataGenerator()->create_user();
        $user2   = $this->getDataGenerator()->create_user();

        $this->insert_userview($chapter->id, $user1->id);

        $context  = \context_module::instance($book->cmid);
        $userlist = new userlist($context, 'mod_book');
        provider::get_users_in_context($userlist);

        $this->assertCount(1, $userlist);
        $this->assertContainsEquals($user1->id, $userlist->get_userids());
        $this->assertNotContainsEquals($user2->id, $userlist->get_userids());
    }

    /**
     * Export data writes chapter view records for approved contexts.
     */
    public function test_export_user_data(): void {
        $course  = $this->getDataGenerator()->create_course();
        $book    = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $gen     = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);
        $user    = $this->getDataGenerator()->create_user();

        $this->insert_userview($chapter->id, $user->id);

        $context     = \context_module::instance($book->cmid);
        $contextlist = new approved_contextlist($user, 'mod_book', [$context->id]);
        provider::export_user_data($contextlist);

        $writer = \core_privacy\local\request\writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Tests deletion of chapter user views using all privacy deletion methods.
     */
    public function test_delete_user_view_data(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $book    = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $gen     = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $context = \context_module::instance($book->cmid);

        $this->insert_userview($chapter->id, $user1->id);
        $this->insert_userview($chapter->id, $user2->id);

        $this->assertEquals(2, $DB->count_records('book_chapters_userviews'));

        // Delete data for the specified user in the given context.
        provider::delete_data_for_user(
            new approved_contextlist($user1, 'mod_book', [$context->id])
        );

        $this->assertEquals(0, $DB->count_records('book_chapters_userviews', ['userid' => $user1->id]));
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews', ['userid' => $user2->id]));

        // Delete data for the specified users in the given context.
        $this->insert_userview($chapter->id, $user1->id);
        $this->insert_userview($chapter->id, $user3->id);

        $this->assertEquals(3, $DB->count_records('book_chapters_userviews'));

        provider::delete_data_for_users(
            new approved_userlist($context, 'mod_book', [$user1->id, $user2->id])
        );

        $this->assertEquals(0, $DB->count_records('book_chapters_userviews', ['userid' => $user1->id]));
        $this->assertEquals(0, $DB->count_records('book_chapters_userviews', ['userid' => $user2->id]));
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews', ['userid' => $user3->id]));

        // Delete data for all users in the given context.
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews'));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertEquals(0, $DB->count_records('book_chapters_userviews'));
    }

    /**
     * Contexts belonging to another module type must never delete book data.
     *
     * Core hands every module context to every component's provider, and module instance ids come from
     * independent sequences, so a page instance id can be the same number as a book instance id.
     */
    public function test_delete_ignores_contexts_of_other_modules(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $book    = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $page    = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $gen     = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $gen->create_chapter(['bookid' => $book->id]);
        $user    = $this->getDataGenerator()->create_user();

        // Simulate the id collision between the two module types.
        $DB->set_field('course_modules', 'instance', $book->id, ['id' => $page->cmid]);

        $this->insert_userview($chapter->id, $user->id);
        $pagecontext = \context_module::instance($page->cmid);

        provider::delete_data_for_all_users_in_context($pagecontext);
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews'));

        provider::delete_data_for_user(new approved_contextlist($user, 'mod_book', [$pagecontext->id]));
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews'));

        provider::delete_data_for_users(new approved_userlist($pagecontext, 'mod_book', [$user->id]));
        $this->assertEquals(1, $DB->count_records('book_chapters_userviews'));
    }
}
