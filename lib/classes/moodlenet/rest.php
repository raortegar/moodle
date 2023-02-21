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

namespace core\moodlenet;

defined('MOODLE_INTERNAL') || die();

/**
 * MoodleNet API Rest Interface.
 *
 * @package    core
 * @copyright  2023 Michael Hawkins <michaelh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest extends \core\oauth2\rest {
//TODO - possibly not use this because of apparent lack of multipart support
    /**
     * Define the functions of the rest API.
     *
     * @return array Example:
     *  [ 'listFiles' => [ 'method' => 'post', 'endpoint' => 'https://...', 'args' => [] ] ]
     */
    public function get_api_functions() {
        return [
            'create_permission' => [
                'endpoint' => '{baseurl}/.pkg/@moodlenet/resource/basic/v1/create',
                'method' => 'post',
                'args' => [
                    'fileid' => PARAM_RAW,
                ],
                'response' => 'json'
            ],
        ];
    }
}
