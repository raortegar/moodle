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

namespace mod_book\completion;

use core_completion\activity_custom_completion;

/**
 * Activity custom completion subclass for the book activity.
 *
 * Class for defining mod_book's custom completion rules and fetching the completion statuses
 * of the custom completion rules for a given book instance and a user.
 *
 * @package   mod_book
 * @copyright 2025 Willian Mano <willianmanoaraujo@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        $this->validate_rule($rule);

        // Read the requirement from the course module cache rather than the database. This method is called
        // once per user when building completion reports, so an instance lookup here would be a query per user.
        $requiredpercent = (int) ($this->cm->customdata['customcompletionrules']['completionreadpercent'] ?? 0);
        if (!$requiredpercent) {
            return COMPLETION_INCOMPLETE;
        }

        $viewedpercent = \mod_book\helper::get_book_userview_progress((int) $this->cm->instance, (int) $this->userid);

        return $viewedpercent >= $requiredpercent ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionreadpercent',
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $completionreadpercent = $this->cm->customdata['customcompletionrules']['completionreadpercent'] ?? 0;
        if ($completionreadpercent == 100) {
            return [
                'completionreadpercent' => get_string('completionreadallstatus', 'mod_book'),
            ];
        }
        return [
            'completionreadpercent' => get_string('completionreadpercentstatus', 'mod_book', $completionreadpercent),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionreadpercent',
        ];
    }
}
