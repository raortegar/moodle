<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Learning outcomes block — shows the course's learning outcomes to all
 * enrolled users, including shortname badges and full descriptions.
 *
 * The block renders no content (and is invisible) when:
 *  - The global outcomes feature is disabled, or
 *  - Learning outcomes are not enabled for this specific course, or
 *  - The course has no outcomes defined yet.
 *
 * @package   block_learningoutcomes
 * @copyright 2026 Moodle HQ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_learningoutcomes extends block_base {

    /**
     * Initialises the block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_learningoutcomes');
    }

    /**
     * The block is only relevant on course pages.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return ['course' => true];
    }

    /**
     * Only show the "add this block" option when outcomes are globally enabled.
     *
     * @param moodle_page $page
     * @return bool
     */
    public function can_block_be_added(moodle_page $page): bool {
        global $CFG;
        return !empty($CFG->enableoutcomes);
    }

    /**
     * Builds and returns the block content.
     *
     * Returns an empty content object (invisible block) when prerequisites
     * are not met, so the block does not clutter pages unnecessarily.
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        global $CFG, $OUTPUT;

        if (isset($this->content)) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        if (empty($CFG->enableoutcomes)) {
            return $this->content;
        }

        $courseid = (int) $this->page->course->id;
        if ($courseid <= SITEID) {
            return $this->content;
        }

        $manager = new \core\learning_outcomes\manager();

        if (!$manager->is_enabled_for_course($courseid)) {
            return $this->content;
        }

        $outcomes = $manager->get_course_outcomes($courseid);
        if (empty($outcomes)) {
            return $this->content;
        }

        // Load module info once for activity names, URLs and icons.
        $modinfo = get_fast_modinfo($this->page->course);
        $allcms  = $modinfo->get_cms();

        // Intro sentence — plain <p>, theme styles it.
        $intro = html_writer::tag(
            'p',
            format_string(get_string('courseoutcomes_intro', 'report_learningoutcomes'))
        );

        // One <li> per outcome, with a nested <ul> of aligned activities.
        $outcomeitems = '';
        foreach ($outcomes as $outcome) {

            $badge = html_writer::tag(
                'span',
                format_string($outcome->shortname),
                [
                    'class' => 'badge',
                    'style' => 'background-color:#cce6ea;border:1px solid #99cdd5;color:#00343c;',
                ]
            );
            // Short name badge on first line, full name on a separate line below.
            $header = html_writer::tag('div', $badge, ['class' => 'mb-1 mt-5'])
                . html_writer::tag('div', format_string($outcome->fullname), ['class' => 'small']);

            // Aligned activities.
            $taggedcms    = $manager->get_activities_for_outcome((int) $outcome->id, $courseid);
            $activityrows = '';
            foreach ($taggedcms as $cmrow) {
                if (!isset($allcms[$cmrow->id])) {
                    continue; // CM deleted or not visible.
                }
                $cminfo = $allcms[$cmrow->id];
                $icon   = $OUTPUT->pix_icon(
                    'monologo',
                    '',
                    $cminfo->modname,
                    ['class' => 'activityicon me-1', 'width' => '16', 'height' => '16', 'aria-hidden' => 'true']
                );
                $url    = $cminfo->url
                    ?? new moodle_url('/mod/' . $cminfo->modname . '/view.php', ['id' => $cmrow->id]);

                $activityrows .= html_writer::tag(
                    'li',
                    html_writer::link($url, $icon . format_string($cminfo->get_formatted_name()))
                );
            }

            if ($activityrows !== '') {
                $sublist = html_writer::tag('ul', $activityrows, ['class' => 'list-unstyled ps-0 mt-1 mb-0']);
            } else {
                $sublist = html_writer::tag(
                    'p',
                    get_string('noactivitiesaligned', 'block_learningoutcomes'),
                    ['class' => 'text-muted fst-italic small mb-0']
                );
            }

            $outcomeitems .= html_writer::tag('li', $header . $sublist, ['class' => 'mb-3']);
        }

        $this->content->text = $intro
            . html_writer::tag('div',
                html_writer::tag('ul', $outcomeitems, ['class' => 'list-unstyled']),
                ['class' => 'sub-content']
            );

        // Footer link for teachers — quick access to the manage page.
        if (has_capability('report/learningoutcomes:manage', context_course::instance($courseid))) {
            $manageurl = new moodle_url('/report/learningoutcomes/manage.php', ['id' => $courseid]);
            $this->content->footer = html_writer::link(
                $manageurl,
                get_string('manage_linkinnav', 'report_learningoutcomes'),
                ['class' => 'small']
            );
        }

        return $this->content;
    }
}
