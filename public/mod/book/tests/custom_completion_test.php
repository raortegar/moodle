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

declare(strict_types=1);

namespace mod_book;

use advanced_testcase;
use cm_info;
use coding_exception;
use mod_book\completion\custom_completion;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Class for unit testing mod_book/custom_completion.
 *
 * @package    mod_book
 * @copyright  2026 Moodle Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_book\completion\custom_completion
 */
final class custom_completion_test extends advanced_testcase {
    /**
     * Builds a mocked cm_info returning the given custom completion rules.
     *
     * The rule metadata methods only read customdata, so a mock keeps those tests free of database setup.
     *
     * @param array $rules The customcompletionrules to expose, as rule name => value.
     * @return cm_info
     */
    private function mock_cm_info(array $rules): cm_info {
        $mockcminfo = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_custom_data'])
            ->getMock();

        $mockcminfo->expects($this->any())
            ->method('get_custom_data')
            ->willReturn(['customcompletionrules' => $rules]);

        return $mockcminfo;
    }

    /**
     * Data provider for test_get_state().
     *
     * @return array[]
     */
    public static function get_state_provider(): array {
        return [
            'Nothing read' =>
                ['requiredpercent' => 50, 'chapters' => 3, 'hidden' => 0, 'read' => 0, 'expected' => COMPLETION_INCOMPLETE],
            'One of three read, below requirement' =>
                ['requiredpercent' => 50, 'chapters' => 3, 'hidden' => 0, 'read' => 1, 'expected' => COMPLETION_INCOMPLETE],
            'Two of three read, above requirement' =>
                ['requiredpercent' => 50, 'chapters' => 3, 'hidden' => 0, 'read' => 2, 'expected' => COMPLETION_COMPLETE],
            'Whole book read' =>
                ['requiredpercent' => 100, 'chapters' => 2, 'hidden' => 0, 'read' => 2, 'expected' => COMPLETION_COMPLETE],
            'Progress is truncated, not rounded' =>
                ['requiredpercent' => 67, 'chapters' => 3, 'hidden' => 0, 'read' => 2, 'expected' => COMPLETION_INCOMPLETE],
            'Hidden chapters are excluded from the total' =>
                ['requiredpercent' => 50, 'chapters' => 3, 'hidden' => 1, 'read' => 1, 'expected' => COMPLETION_COMPLETE],
            'Every visible chapter read while one is hidden' =>
                ['requiredpercent' => 100, 'chapters' => 3, 'hidden' => 1, 'read' => 2, 'expected' => COMPLETION_COMPLETE],
        ];
    }

    /**
     * Test for get_state().
     *
     * @dataProvider get_state_provider
     * @param int $requiredpercent The percentage of the book that must be read.
     * @param int $chapters The number of chapters to create.
     * @param int $hidden The number of chapters, counting from the last, to hide.
     * @param int $read The number of visible chapters the user reads.
     * @param int $expected The expected completion state.
     */
    public function test_get_state(int $requiredpercent, int $chapters, int $hidden, int $read, int $expected): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module(
            'book',
            ['course' => $course->id, 'completionreadpercent' => $requiredpercent],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC]
        );
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $created = [];
        for ($i = 0; $i < $chapters; $i++) {
            $created[] = $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => $i + 1]);
        }

        // Hide the trailing chapters so that the read ones stay visible.
        for ($i = $chapters - $hidden; $i < $chapters; $i++) {
            $DB->set_field('book_chapters', 'hidden', 1, ['id' => $created[$i]->id]);
        }

        $now = time();
        for ($i = 0; $i < $read; $i++) {
            $DB->insert_record('book_chapters_userviews', (object) [
                'chapterid' => $created[$i]->id,
                'userid' => $student->id,
                'timecreated' => $now,
                'timeviewed' => $now,
            ]);
        }

        $cminfo = get_fast_modinfo($course)->get_cm($book->cmid);
        $customcompletion = new custom_completion($cminfo, (int) $student->id);

        $this->assertEquals($expected, $customcompletion->get_state('completionreadpercent'));
    }

    /**
     * A rule this module does not define must be rejected.
     */
    public function test_get_state_undefined_rule(): void {
        $customcompletion = new custom_completion($this->mock_cm_info(['completionreadpercent' => 50]), 1);

        $this->expectException(coding_exception::class);
        $customcompletion->get_state('somenonexistentrule');
    }

    /**
     * A defined rule that the instance does not use must be rejected.
     *
     * validate_rule() checks is_available(), which treats a zero read percent as not in use, so this throws
     * before the read percent is ever compared.
     */
    public function test_get_state_rule_not_available(): void {
        $customcompletion = new custom_completion($this->mock_cm_info(['completionreadpercent' => 0]), 1);

        $this->expectException(moodle_exception::class);
        $customcompletion->get_state('completionreadpercent');
    }

    /**
     * Test for get_defined_custom_rules().
     */
    public function test_get_defined_custom_rules(): void {
        $rules = custom_completion::get_defined_custom_rules();

        $this->assertCount(1, $rules);
        $this->assertEquals('completionreadpercent', reset($rules));
    }

    /**
     * Test for get_custom_rule_descriptions().
     */
    public function test_get_custom_rule_descriptions(): void {
        $customcompletion = new custom_completion($this->mock_cm_info(['completionreadpercent' => 85]), 1);
        $descriptions = $customcompletion->get_custom_rule_descriptions();

        // Every defined rule must be described.
        $rules = custom_completion::get_defined_custom_rules();
        $this->assertCount(count($rules), $descriptions);
        foreach ($rules as $rule) {
            $this->assertArrayHasKey($rule, $descriptions);
        }

        $this->assertEquals(
            get_string('completionreadpercentstatus', 'mod_book', 85),
            $descriptions['completionreadpercent']
        );
    }

    /**
     * Requiring the whole book to be read is described differently to a partial requirement.
     */
    public function test_get_custom_rule_descriptions_whole_book(): void {
        $customcompletion = new custom_completion($this->mock_cm_info(['completionreadpercent' => 100]), 1);
        $descriptions = $customcompletion->get_custom_rule_descriptions();

        $this->assertEquals(
            get_string('completionreadallstatus', 'mod_book'),
            $descriptions['completionreadpercent']
        );
    }

    /**
     * Test for get_sort_order().
     */
    public function test_get_sort_order(): void {
        $customcompletion = new custom_completion($this->mock_cm_info(['completionreadpercent' => 50]), 1);

        $this->assertEquals(
            ['completionview', 'completionreadpercent'],
            $customcompletion->get_sort_order()
        );
    }
}
