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
 * @package auth_oidc
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace auth_oidc;

/**
 * General purpose utility class.
 */
class utils {

    /**
     * Process an OIDC JSON response.
     *
     * @param string $response The received JSON.
     * @return array The parsed JSON.
     */
    public static function process_json_response($response, array $expectedstructure = array()) {
        $backtrace = debug_backtrace(0);
        $callingclass = (isset($backtrace[1]['class'])) ? $backtrace[1]['class'] : '?';
        $callingfunc = (isset($backtrace[1]['function'])) ? $backtrace[1]['function'] : '?';
        $callingline = (isset($backtrace[0]['line'])) ? $backtrace[0]['line'] : '?';
        $caller = $callingclass.'::'.$callingfunc.':'.$callingline;

        $result = @json_decode($response, true);
        if (empty($result) || !is_array($result)) {
            \auth_oidc\utils::debug('Bad response received', $caller, $response);
            throw new \moodle_exception('erroroidccall', 'auth_oidc');
        }

        if (isset($result['error'])) {
            $errmsg = 'Error response received.';
            \auth_oidc\utils::debug($errmsg, $caller, $result);
            if (isset($result['error_description'])) {
                throw new \moodle_exception('erroroidccall_message', 'auth_oidc', '', $result['error_description']);
            } else {
                throw new \moodle_exception('erroroidccall', 'auth_oidc');
            }
        }

        foreach ($expectedstructure as $key => $val) {
            if (!isset($result[$key])) {
                $errmsg = 'Invalid structure received. No "'.$key.'"';
                \auth_oidc\utils::debug($errmsg, $caller, $result);
                throw new \moodle_exception('erroroidccall', 'auth_oidc');
            }

            if ($val !== null && $result[$key] !== $val) {
                $strreceivedval = \auth_oidc\utils::tostring($result[$key]);
                $strval = \auth_oidc\utils::tostring($val);
                $errmsg = 'Invalid structure received. Invalid "'.$key.'". Received "'.$strreceivedval.'", expected "'.$strval.'"';
                \auth_oidc\utils::debug($errmsg, $caller, $result);
                throw new \moodle_exception('erroroidccall', 'auth_oidc');
            }
        }
        return $result;
    }

    /**
     * Convert any value into a debuggable string.
     *
     * @param mixed $val The variable to convert.
     * @return string A string representation.
     */
    public static function tostring($val) {
        if (is_scalar($val)) {
            if (is_bool($val)) {
                return '(bool)'.(string)(int)$val;
            } else {
                return '('.gettype($val).')'.(string)$val;
            }
        } else if (is_null($val)) {
            return '(null)';
        } else {
            return print_r($val, true);
        }
    }

    /**
     * Record a debug message.
     *
     * @param string $message The debug message to log.
     */
    public static function debug($message, $where = '', $debugdata = null) {
        $debugmode = (bool)get_config('auth_oidc', 'debugmode');
        if ($debugmode === true) {
            $fullmessage = (!empty($where)) ? $where : 'Unknown function';
            $fullmessage .= ': '.$message;
            $fullmessage .= ' Data: '.static::tostring($debugdata);
            $event = \auth_oidc\event\action_failed::create(['other' => $fullmessage]);
            $event->trigger();
        }
    }
}