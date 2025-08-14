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

namespace repository_googledocs\form;


defined('MOODLE_INTERNAL') || die();

use repository_googledocs;
require_once($CFG->dirroot . '/repository/googledocs/lib.php');

/**
 * Class upload_file
 *
 * @package    repository_googledocs
 * @copyright  2025 Raquel Ortega <raquel.ortega@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_file extends \core_form\dynamic_form {
    /**
     * Add elements to this form.
     */
    public function definition() {
        global $CFG;
        $mform = $this->_form;
        $mform->addElement('hidden', 'repo_id', $this->_customdata['repo_id']);
        $mform->setType('repo_id', PARAM_INT);

        $mform->addElement('hidden', 'contextid', $this->_customdata['contextid']);
        $mform->setType('contextid', PARAM_INT);

        $options = [
            'maxfiles' => 1,
            'maxbytes' => $CFG->userquota,
            'maxareabytes' => $CFG->userquota,
            'accepted_types' => '*',
        ];
        $filepicker = $mform->addElement('filepicker', 'userfile', '', null, $options);
        $filepicker->setAttributes($filepicker->getAttributes() + ['class' => 'nofilebutton']);
        $mform->addRule('userfile', null, 'required', null, 'client');
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): \context {
        $contextid = $this->optional_param('contextid', null, PARAM_INT);
        return \context::instance_by_id($contextid, MUST_EXIST);
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        $repoid = $this->_ajaxformdata['repo_id'];
        $contextid = $this->_ajaxformdata["contextid"];
        $context = \context::instance_by_id($contextid);
        $repo = new repository_googledocs($repoid, $context);

        $userauth = $repo->get_user_oauth_client();
        // Check if the user is logged in.
        if (!$userauth->is_logged_in()) {
            throw new \moodle_exception('invalidlogin');
        }
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;

        // Get repository and context details.
        $repoid = $this->get_data()->repo_id;
        $contextid = $this->get_data()->contextid;
        $usercontext = \context_user::instance($USER->id);
        $context = \context::instance_by_id($contextid);

        // Retrieve file from draft area and prepare file for transfer.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $this->get_data()->userfile,
            'itemid,
            filepath,
            filename',
            false,
        );

        if (empty($files)) {
            throw new \moodle_exception('refreshnonjsfilepicker', 'repository');
        }
        $file = reset($files);
        $filename = $file->get_filename();
        $tmp = make_request_directory();
        $tempfile = $tmp . '/' . random_string(10);

        // Uploads a file to Google Docs.
        $ha = new repository_googledocs($repoid, $context);
        $userauth = $ha->get_user_oauth_client();
        $userservice = new repository_googledocs\rest($userauth);
        $ha->upload_file($userservice, $tempfile, $filename, 'download', "root");

        return null;
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $data = (object)[
            'contextid' => $this->optional_param('contextid', null, PARAM_INT),
            'repo_id' => $this->optional_param('repo_id', null, PARAM_INT),
        ];
        $this->set_data($data);
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/repository/googledocs/upload', [
            'repo_id' => $this->_ajaxformdata['repo_id'] ?? 0,
            'contextid' => $this->get_context_for_dynamic_submission()->id,
        ]);
    }
}
