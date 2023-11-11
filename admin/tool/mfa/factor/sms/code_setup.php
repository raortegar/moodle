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
 * Configure code user factor page
 *
 * @package     tool_mfa
 * @copyright   2023 Raquel Ortega <raquel.ortega@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '../../../../../../config.php');

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('error:isguestuser', 'tool_mfa');
}

$factor = optional_param('factor', '', PARAM_ALPHANUMEXT);
$params = ['factor' => $factor];
$currenturl = new moodle_url('/admin/tool/mfa/factor/sms/code_setup.php', $params);
$returnurl = new moodle_url('/admin/tool/mfa/user_preferences.php');

if (empty($factor)) {
    throw new moodle_exception('error:directaccess', 'tool_mfa', $returnurl);
}

if (!\tool_mfa\plugininfo\factor::factor_exists('sms')) {
    throw new moodle_exception('error:factornotfound', 'tool_mfa', $returnurl, 'sms');
}

$context = context_user::instance($USER->id);
$PAGE->set_context($context);
$PAGE->set_url('/admin/tool/mfa/factor/sms/code_setup.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('setupfactor', 'tool_mfa'));
$PAGE->set_cacheable(false);

if ($node = $PAGE->settingsnav->find('usercurrentsettings', null)) {
    $PAGE->navbar->add($node->get_content(), $node->action());
}
$PAGE->navbar->add(get_string('preferences:header', 'tool_mfa'), new \moodle_url('/admin/tool/mfa/user_preferences.php'));

$factorobject = \tool_mfa\plugininfo\factor::get_factor($factor);
if (!$factorobject || !$factorobject->has_setup()) {
    redirect($returnurl);
}

$PAGE->navbar->add(get_string('setupfactor', 'factor_'.$factor));
$OUTPUT = $PAGE->get_renderer('tool_mfa');

// Remove the session number after send it to the form. 
$phonenumber = !empty($SESSION->tool_mfa_sms_number) ? $SESSION->tool_mfa_sms_number : '';

$form = new factor_sms\form\code_setup_form($currenturl, ['factorname' => $factor, 'phonenumber' => $phonenumber]);

if ($form->is_submitted()) {
    $form->is_validated();

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/admin/tool/mfa/user_preferences.php'));

    } else if ($form->no_submit_button_pressed()) {
        redirect(new \moodle_url('/admin/tool/mfa/action.php', [
            'action' => 'setup',
            'factor' => 'sms',
        ]));

    } else if ($data = $form->get_data()) {
        $record = $factorobject->code_setup_user_factor($data);
        if (!empty($record)) {
            $factorobject->set_state(\tool_mfa\plugininfo\factor::STATE_PASS);
            $finalurl = new moodle_url($returnurl, ['action' => 'setup', 'factorid' => $record->id]);
            redirect($finalurl);
        }

        throw new moodle_exception('error:setupfactor', 'tool_mfa', $returnurl);
    }
}

echo $OUTPUT->header();
$form->display();


echo $OUTPUT->footer();
