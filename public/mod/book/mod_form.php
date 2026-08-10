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
 * Instance add/edit form
 *
 * @package    mod_book
 * @copyright  2004-2011 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__.'/locallib.php');
require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_book_mod_form extends moodleform_mod {

    function definition() {
        global $CFG;

        $mform = $this->_form;

        $config = get_config('book');

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 1333), 'maxlength', 1333, 'client');
        $this->standard_intro_elements(get_string('moduleintro'));

        // Appearance.
        $mform->addElement('header', 'appearancehdr', get_string('appearance'));

        $alloptions = book_get_numbering_types();
        $allowed = explode(',', $config->numberingoptions);
        $options = array();
        foreach ($allowed as $type) {
            if (isset($alloptions[$type])) {
                $options[$type] = $alloptions[$type];
            }
        }
        if ($this->current->instance) {
            if (!isset($options[$this->current->numbering])) {
                if (isset($alloptions[$this->current->numbering])) {
                    $options[$this->current->numbering] = $alloptions[$this->current->numbering];
                }
            }
        }
        $mform->addElement('select', 'numbering', get_string('numbering', 'book'), $options);
        $mform->addHelpButton('numbering', 'numbering', 'mod_book');
        $mform->setDefault('numbering', $config->numbering);

        $mform->addElement('static', 'customtitlestext', get_string('customtitles', 'mod_book'));
        $mform->addElement('checkbox', 'customtitles', get_string('customtitles', 'book'));
        $mform->addHelpButton('customtitles', 'customtitles', 'mod_book');
        $mform->setDefault('customtitles', 0);

        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Process the data before load the form
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        $suffix = $this->get_suffix();
        $completionreadpercentel = 'completionreadpercent' . $suffix;
        $completionreadpercentactiveel = 'completionreadpercentactive' . $suffix;
        $defaultvalues[$completionreadpercentactiveel] = !empty($defaultvalues[$completionreadpercentel]) ? 1 : 0;
    }

    #[\Override]
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $completionreadpercentoptions = [0 => get_string('choose')];
        for ($i = 5; $i <= 100; $i += 5) {
            $completionreadpercentoptions[$i] = $i . '%';
        }

        $completionreadpercentactiveel = 'completionreadpercentactive' . $suffix;
        $completionreadpercentel = 'completionreadpercent' . $suffix;
        $completionreadpercentgroupel = 'completionreadpercentgroup' . $suffix;

        $group = [
            $mform->createElement(
                'checkbox',
                $completionreadpercentactiveel,
                '',
                get_string('requiredcompletionreadpercent', 'mod_book')
            ),
            $mform->createElement(
                'select',
                $completionreadpercentel,
                get_string('completionreadpercentselect', 'mod_book'),
                $completionreadpercentoptions
            ),
        ];

        $mform->addGroup($group, $completionreadpercentgroupel, '', '', false);
        $mform->disabledIf($completionreadpercentel, $completionreadpercentactiveel, 'notchecked');

        return [$completionreadpercentgroupel];
    }

    /**
     * Validates the form.
     *
     * @param array $data
     * @param array $files
     *
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $suffix = $this->get_suffix();
        $completionreadpercentactiveel = 'completionreadpercentactive' . $suffix;
        $completionreadpercentel = 'completionreadpercent' . $suffix;
        $completionreadpercentgroupel = 'completionreadpercentgroup' . $suffix;

        // The select is disabled, and therefore not submitted, while the checkbox is unticked.
        if (!empty($data[$completionreadpercentactiveel]) && empty($data[$completionreadpercentel])) {
            $errors[$completionreadpercentgroupel] = get_string('completionreadpercentvalidation', 'mod_book');
        }

        return $errors;
    }

    #[\Override]
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return (!empty($data['completionreadpercentactive' . $suffix]) && $data['completionreadpercent' . $suffix] > 0);
    }

    /**
     * Allows module to modify the data returned by form get_data().
     *
     * @param stdClass $data the form data to be modified.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        // Turn off the readpercent completion setting if the checkbox is unticked.
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completionel = 'completion' . $suffix;
            $completionreadpercentactiveel = 'completionreadpercentactive' . $suffix;
            $completionreadpercentel = 'completionreadpercent' . $suffix;

            $completion = $data->{$completionel} ?? COMPLETION_TRACKING_NONE;
            $autocompletion = !empty($completion) && $completion == COMPLETION_TRACKING_AUTOMATIC;

            $ruleenabled = !empty($data->{$completionreadpercentactiveel}) && !empty($data->{$completionreadpercentel});
            if (!$ruleenabled || !$autocompletion) {
                $data->{$completionreadpercentel} = 0;
            }
        }
    }
}
