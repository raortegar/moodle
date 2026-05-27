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

namespace core\learning_outcomes;

/**
 * Service class for the Learning Outcomes subsystem.
 *
 * Handles tagging relationships between course activities and outcomes.
 * An "outcome tag" is a deliberate alignment link: a teacher asserts that
 * a given activity addresses a specific learning outcome.
 *
 * @package   core
 * @copyright 2026 Moodle Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /**
     * Tags a course activity with a learning outcome.
     *
     * Safe to call when the tag already exists — duplicate rows are silently
     * ignored via the unique index on (cmid, outcomeid).
     *
     * @param int $cmid      Course-module id of the activity to tag.
     * @param int $outcomeid Id of the outcome from grade_outcomes.
     * @param int $courseid  Id of the course, used to populate the denormalised courseid column.
     */
    public function tag_outcome(int $cmid, int $outcomeid, int $courseid): void {
        global $DB, $USER;

        if ($DB->record_exists('course_outcome_tags', ['cmid' => $cmid, 'outcomeid' => $outcomeid])) {
            return;
        }

        $now = time();
        $DB->insert_record('course_outcome_tags', (object) [
            'courseid'     => $courseid,
            'cmid'         => $cmid,
            'outcomeid'    => $outcomeid,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Removes the tag linking a course activity to an outcome.
     *
     * Silent no-op when the tag does not exist.
     *
     * @param int $cmid      Course-module id.
     * @param int $outcomeid Outcome id.
     */
    public function untag_outcome(int $cmid, int $outcomeid): void {
        global $DB;
        $DB->delete_records('course_outcome_tags', ['cmid' => $cmid, 'outcomeid' => $outcomeid]);
    }

    /**
     * Returns all outcomes tagged to a given course activity.
     *
     * Each element is a record from grade_outcomes joined with the tag row,
     * so callers get the full outcome data alongside the tag metadata.
     *
     * @param int $cmid Course-module id to query.
     * @return \stdClass[] Rows with all grade_outcomes fields plus tag timecreated/timemodified.
     *                     Empty array when nothing is tagged.
     */
    public function get_tagged_outcomes(int $cmid): array {
        global $DB;

        // Read from core grade_items, which is where Moodle stores activity-outcome
        // associations when teachers use the standard "Learning outcomes" checkboxes
        // on the activity edit form.
        $sql = 'SELECT go.*
                  FROM {grade_items} gi
                  JOIN {grade_outcomes} go ON go.id = gi.outcomeid
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course  = gi.courseid
                  JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
                 WHERE cm.id = :cmid
                   AND gi.itemtype = :itemtype
                   AND gi.outcomeid IS NOT NULL
              ORDER BY go.shortname';

        return array_values($DB->get_records_sql($sql, ['cmid' => $cmid, 'itemtype' => 'mod']));
    }

    /**
     * Returns all course modules that have been tagged with a given outcome.
     *
     * Results are scoped to a single course to prevent leaking cross-course data.
     *
     * @param int $outcomeid Outcome id to query.
     * @param int $courseid  Course to scope the query to.
     * @return \stdClass[] Rows with all course_modules fields plus tag timecreated/timemodified.
     *                     Empty array when no activities are tagged.
     */
    public function get_activities_for_outcome(int $outcomeid, int $courseid): array {
        global $DB;

        $sql = 'SELECT cm.*
                  FROM {grade_items} gi
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course  = gi.courseid
                  JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
                 WHERE gi.outcomeid = :outcomeid
                   AND gi.courseid  = :courseid
                   AND gi.itemtype  = :itemtype
              ORDER BY cm.id';

        return array_values($DB->get_records_sql($sql, [
            'outcomeid' => $outcomeid,
            'courseid'  => $courseid,
            'itemtype'  => 'mod',
        ]));
    }

    // =========================================================================
    // Layer 2 — Outcome definition
    // =========================================================================

    /**
     * Returns true when learning outcomes are enabled for the given course.
     *
     * Falls back to the site-wide default when no per-course record exists.
     *
     * @param int $courseid
     * @return bool
     */
    public function is_enabled_for_course(int $courseid): bool {
        global $DB;
        $record = $DB->get_record('course_learning_outcomes_config', ['courseid' => $courseid]);
        if ($record !== false) {
            return (bool) $record->enabled;
        }
        return (bool) get_config('core', 'learningoutcomes_coursedefault');
    }

    /**
     * Enables or disables learning outcomes for a specific course.
     *
     * Upserts the per-course config row.
     *
     * @param int  $courseid
     * @param bool $enabled
     */
    public function set_enabled_for_course(int $courseid, bool $enabled): void {
        global $DB, $USER;
        $existing = $DB->get_record('course_learning_outcomes_config', ['courseid' => $courseid]);
        $now = time();
        if ($existing) {
            $existing->enabled      = (int) $enabled;
            $existing->timemodified = $now;
            $existing->usermodified = $USER->id;
            $DB->update_record('course_learning_outcomes_config', $existing);
        } else {
            $DB->insert_record('course_learning_outcomes_config', (object) [
                'courseid'     => $courseid,
                'enabled'      => (int) $enabled,
                'timemodified' => $now,
                'usermodified' => $USER->id,
            ]);
        }
    }

    /**
     * Returns all outcomes defined for the given course, ordered by short name.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public function get_course_outcomes(int $courseid): array {
        global $DB;
        // Include both course-specific outcomes (courseid=N) and site-wide outcomes
        // (courseid=0) that have been explicitly linked to this course via grade_outcomes_courses.
        $sql = 'SELECT DISTINCT go.*
                  FROM {grade_outcomes} go
                 WHERE go.courseid = :cid1
                    OR go.id IN (
                        SELECT outcomeid FROM {grade_outcomes_courses} WHERE courseid = :cid2
                    )
                 ORDER BY go.shortname';
        return array_values($DB->get_records_sql($sql, ['cid1' => $courseid, 'cid2' => $courseid]));
    }

    /**
     * Creates a single learning outcome scoped to a course.
     *
     * Also writes the linking row to grade_outcomes_courses so the outcome
     * appears in the course gradebook context.
     *
     * @param int    $courseid
     * @param string $shortname Unique within the course; used in reports and exports.
     * @param string $fullname  Human-readable description shown to teachers and students.
     * @param string $description Optional longer description (plain text or HTML).
     * @return \stdClass The newly created grade_outcomes record.
     * @throws \dml_exception When the shortname already exists in this course.
     */
    public function create_course_outcome(
        int $courseid,
        string $shortname,
        string $fullname,
        string $description = '',
    ): \stdClass {
        global $DB, $USER;
        $now = time();
        $id = $DB->insert_record('grade_outcomes', (object) [
            'courseid'          => $courseid,
            'shortname'         => $shortname,
            'fullname'          => $fullname,
            'description'       => $description,
            'descriptionformat' => FORMAT_PLAIN,
            'timecreated'       => $now,
            'timemodified'      => $now,
            'usermodified'      => $USER->id,
        ]);
        $DB->insert_record('grade_outcomes_courses', (object) [
            'courseid'  => $courseid,
            'outcomeid' => $id,
        ]);
        return $DB->get_record('grade_outcomes', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Creates multiple outcomes from pasted text.
     *
     * Each non-blank line must be in the format "Shortname | Full description".
     * Lines that do not match the format are skipped and reported in the return value.
     *
     * @param int    $courseid
     * @param string $text Multi-line string, one outcome per line.
     * @return array{created: \stdClass[], errors: array<int, string>}
     *         'created' contains the saved records; 'errors' maps 1-based line numbers to messages.
     */
    public function bulk_create_outcomes(int $courseid, string $text): array {
        $lines   = explode("\n", str_replace("\r\n", "\n", trim($text)));
        $created = [];
        $errors  = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                $errors[$index + 1] = get_string('learningoutcomes_bulkcreate_error', 'grades',
                    (object) ['line' => $index + 1]);
                continue;
            }
            $created[] = $this->create_course_outcome($courseid, $parts[0], $parts[1]);
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Updates the editable fields of a course-scoped outcome.
     *
     * The outcome must belong to $courseid; passing the wrong course id is a
     * coding error and throws immediately.
     *
     * @param int    $outcomeid
     * @param int    $courseid   Used to verify ownership.
     * @param string $shortname
     * @param string $fullname
     * @param string $description
     * @throws \coding_exception When the outcome does not belong to the given course.
     */
    public function update_course_outcome(
        int $outcomeid,
        int $courseid,
        string $shortname,
        string $fullname,
        string $description,
    ): void {
        global $DB, $USER;
        $outcome = $DB->get_record('grade_outcomes', ['id' => $outcomeid, 'courseid' => $courseid]);
        if (!$outcome) {
            throw new \coding_exception("Outcome {$outcomeid} does not belong to course {$courseid}.");
        }
        $outcome->shortname    = $shortname;
        $outcome->fullname     = $fullname;
        $outcome->description  = $description;
        $outcome->timemodified = time();
        $outcome->usermodified = $USER->id;
        $DB->update_record('grade_outcomes', $outcome);
    }

    /**
     * Deletes a course-scoped outcome and all associated tag rows.
     *
     * Refuses to delete if any grade item is currently referencing the outcome,
     * because that would orphan student grade records.
     *
     * @param int $outcomeid
     * @param int $courseid  Used to verify ownership.
     * @throws \coding_exception   When the outcome does not belong to the given course.
     * @throws \moodle_exception   When grade items are still linked to this outcome.
     */
    public function delete_course_outcome(int $outcomeid, int $courseid): void {
        global $DB;
        $outcome = $DB->get_record('grade_outcomes', ['id' => $outcomeid, 'courseid' => $courseid]);
        if (!$outcome) {
            throw new \coding_exception("Outcome {$outcomeid} does not belong to course {$courseid}.");
        }
        if ($DB->record_exists('grade_items', ['outcomeid' => $outcomeid])) {
            throw new \moodle_exception('outcomehasitems', 'grades');
        }
        $DB->delete_records('course_outcome_tags',    ['outcomeid' => $outcomeid]);
        $DB->delete_records('grade_outcomes_courses', ['outcomeid' => $outcomeid]);
        $DB->delete_records('grade_outcomes',         ['id' => $outcomeid]);
    }

    // =========================================================================
    // Layer 2 — Minimum outcomes nudge
    // =========================================================================

    /**
     * Returns the site-configured minimum number of outcomes per course.
     *
     * @return int  0 means the minimum check is disabled.
     */
    public function get_min_outcomes(): int {
        return (int) get_config('core', 'learningoutcomes_minoutcomes');
    }

    /**
     * Returns the site-configured enforcement mode: 'soft' or 'hard'.
     *
     * @return string
     */
    public function get_enforcement_mode(): string {
        $mode = get_config('core', 'learningoutcomes_enforcement');
        return ($mode === 'hard') ? 'hard' : 'soft';
    }

    /**
     * Returns nudge data when a course is below the minimum outcomes threshold, or null when all is well.
     *
     * Returns null when:
     * - learning outcomes are not enabled for the course, or
     * - the minimum is set to 0 (check disabled), or
     * - the course already meets or exceeds the minimum.
     *
     * @param int $courseid
     * @return array{mode: string, min: int, count: int}|null
     */
    public function get_nudge(int $courseid): ?array {
        if (!$this->is_enabled_for_course($courseid)) {
            return null;
        }
        $min = $this->get_min_outcomes();
        if ($min === 0) {
            return null;
        }
        global $DB;
        $count = $DB->count_records('grade_outcomes', ['courseid' => $courseid]);
        if ($count >= $min) {
            return null;
        }
        return [
            'mode'  => $this->get_enforcement_mode(),
            'min'   => $min,
            'count' => $count,
        ];
    }

    /**
     * Returns the nudge message string for display, or null when the course is on track.
     *
     * Uses the 'hard' string variant when enforcement mode is hard, otherwise the soft variant.
     *
     * @param int $courseid
     * @return string|null
     */
    public function get_nudge_message(int $courseid): ?string {
        $nudge = $this->get_nudge($courseid);
        if ($nudge === null) {
            return null;
        }
        $strkey = ($nudge['mode'] === 'hard')
            ? 'learningoutcomes_belowminimum_hard'
            : 'learningoutcomes_belowminimum';
        return get_string($strkey, 'grades', (object) ['min' => $nudge['min'], 'count' => $nudge['count']]);
    }

    // =========================================================================
    // Layer 3 — Alignment tools
    // =========================================================================

    /**
     * Returns the list of module names that are considered decorative (non-assessable).
     *
     * Decorative activities do not contribute to learning outcomes and are
     * excluded from "untagged activities" warnings in the alignment report.
     *
     * @return string[]
     */
    public function get_decorative_modules(): array {
        return ['label', 'page', 'url', 'folder', 'resource', 'book', 'imscp', 'subsection'];
    }

    /**
     * Returns true when the given module type is considered decorative (non-assessable).
     *
     * @param string $modname Module name, e.g. 'assign', 'label'.
     * @return bool
     */
    public function is_decorative(string $modname): bool {
        return in_array($modname, $this->get_decorative_modules(), true);
    }

    /**
     * Returns alignment data for a course: which outcomes have no activities and which
     * assessable activities have no outcomes.
     *
     * @param int $courseid
     * @return array{untagged_outcomes: \stdClass[], untagged_activities: \stdClass[]}
     */
    public function get_alignment_report(int $courseid): array {
        global $DB;

        // Same broad scope as get_course_outcomes — include both course-specific and linked site-wide outcomes.
        $sql = 'SELECT DISTINCT go.id, go.shortname, go.fullname
                  FROM {grade_outcomes} go
                 WHERE (go.courseid = :cid1
                    OR go.id IN (
                        SELECT outcomeid FROM {grade_outcomes_courses} WHERE courseid = :cid2
                    ))
                   AND NOT EXISTS (
                       SELECT 1 FROM {course_outcome_tags} cot WHERE cot.outcomeid = go.id
                   )
                 ORDER BY go.shortname';
        $untaggedoutcomes = array_values($DB->get_records_sql($sql, ['cid1' => $courseid, 'cid2' => $courseid]));

        $decorative = $this->get_decorative_modules();
        [$notinsql, $notinparams] = $DB->get_in_or_equal($decorative, SQL_PARAMS_NAMED, 'dec', false);
        $sql = "SELECT cm.id, cm.module, cm.instance, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name $notinsql
                   AND NOT EXISTS (
                       SELECT 1 FROM {course_outcome_tags} cot WHERE cot.cmid = cm.id
                   )
                 ORDER BY cm.id";
        $params = array_merge(['courseid' => $courseid], $notinparams);
        $untaggedactivities = array_values($DB->get_records_sql($sql, $params));

        return [
            'untagged_outcomes'   => $untaggedoutcomes,
            'untagged_activities' => $untaggedactivities,
        ];
    }
}
