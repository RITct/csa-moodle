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
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365\feature\usersync;

class main {
    protected $clientdata = null;
    protected $httpclient = null;

    public function __construct(\local_o365\oauth2\clientdata $clientdata = null, \local_o365\httpclient $httpclient = null) {
        $this->clientdata = (!empty($clientdata)) ? $clientdata : \local_o365\oauth2\clientdata::instance_from_oidc();
        $this->httpclient = (!empty($httpclient)) ? $httpclient : new \local_o365\httpclient();
    }

    /**
     * Construct a user API client, accounting for unified api presence, and fall back to system api user if desired.
     *
     * @param int $muserid The userid to get the outlook token for. If you want to force a system API user client, use an empty
     *                     value here and set $systemfallback to true.
     * @return \local_o365\rest\o365api|bool A constructed user API client (unified or legacy), or false if error.
     */
    public function construct_user_api($muserid = null, $systemfallback = true, $forcelegacy = false) {
        $unifiedconfigured = \local_o365\rest\unified::is_configured();
        if ($unifiedconfigured === true && !$forcelegacy) {
            $resource = \local_o365\rest\unified::get_resource();
        } else {
            $resource = \local_o365\rest\azuread::get_resource();
        }

        $token = null;
        if (!empty($muserid)) {
            $token = \local_o365\oauth2\token::instance($muserid, $resource, $this->clientdata, $this->httpclient);
        }
        if (empty($token) && $systemfallback === true) {
            $token = \local_o365\oauth2\systemtoken::instance(null, $resource, $this->clientdata, $this->httpclient);
        }
        if (empty($token)) {
            throw new \Exception('No token available for user #'.$muserid);
        }

        if ($unifiedconfigured === true && !$forcelegacy) {
            $apiclient = new \local_o365\rest\unified($token, $this->httpclient);
        } else {
            $apiclient = new \local_o365\rest\azuread($token, $this->httpclient);
        }
        return $apiclient;
    }

    /**
     * Construct a outlook API client using the system API user.
     *
     * @param int $muserid The userid to get the outlook token for. Call with null to retrieve system token.
     * @param boolean $systemfallback Set to true to use system token as fall back.
     * @return \local_o365\rest\o365api|bool A constructed calendar API client (unified or legacy), or false if error.
     */
    public function construct_outlook_api($muserid, $systemfallback = true) {
        $unifiedconfigured = \local_o365\rest\unified::is_configured();
        if ($unifiedconfigured === true) {
            $resource = \local_o365\rest\unified::get_resource();
        } else {
            $resource = \local_o365\rest\outlook::get_resource();
        }

        $token = \local_o365\oauth2\token::instance($muserid, $resource, $this->clientdata, $this->httpclient);
        if (empty($token) && $systemfallback === true) {
            $token = \local_o365\oauth2\systemtoken::instance(null, $resource, $this->clientdata, $this->httpclient);
        }
        if (empty($token)) {
            throw new \Exception('No token available for user #'.$muserid);
        }

        if ($unifiedconfigured === true) {
            $apiclient = new \local_o365\rest\unified($token, $this->httpclient);
        } else {
            $apiclient = new \local_o365\rest\outlook($token, $this->httpclient);
        }
        return $apiclient;
    }

    /**
     * Get information on the app.
     *
     * @return array|null Array of app service information, or null if failure.
     */
    public function get_application_serviceprincipal_info() {
        $apiclient = $this->construct_user_api(0, true);
        return $apiclient->get_application_serviceprincipal_info();
    }

    /**
     * Assign user to application.
     *
     * @param string|array $params Requested user parameters.
     * @param string $userid Object id of user.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     * @return array|null Array of user information, or null if failure.
     */
    public function assign_user($muserid, $userid, $appobjectid) {
        global $DB;
        // Force using legacy api.
        $apiclient = $this->construct_user_api(0, true, true);
        $result = $apiclient->assign_user($muserid, $userid, $appobjectid);
        if (!empty($result['odata.error'])) {
            $error = '';
            $code = '';
            if (!empty($result['odata.error']['code'])) {
                $code = $result['odata.error']['code'];
            }
            if (!empty($result['odata.error']['message']['value'])) {
                $error = $result['odata.error']['message']['value'];
            }
            $user = $DB->get_record('user', array('id' => $muserid));
            mtrace('Error assigning users "'.$user->username.'" Reason: '.$code.' '.$error);
        } else {
            mtrace('User assigned to application.');
        }
        return $result;
    }

    /**
     * Assign photo to Moodle user account.
     *
     * @param string|array $params Requested user parameters.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     * @return boolean True on photo updated.
     */
    public function assign_photo($muserid, $user) {
        global $DB, $CFG, $PAGE;
        require_once("$CFG->libdir/gdlib.php");
        $record = $DB->get_record('local_o365_appassign', array('muserid' => $muserid));
        $photoid = '';
        if (!empty($record->photoid)) {
            $photoid = $record->photoid;
        }
        $result = false;
        $apiclient = $this->construct_outlook_api($muserid, true);
        $size = $apiclient->get_photo_metadata($user);
        $muser = $DB->get_record('user', array('id' => $muserid), 'id, picture', MUST_EXIST);
        // If there is no meta data, there is no photo.
        if (empty($size)) {
            // Profile photo has been deleted.
            if (!empty($muser->picture)) {
                // User has no photo. Deleting previous profile photo.
                $fs = \get_file_storage();
                $fs->delete_area_files($context->id, 'user', 'icon');
                $DB->set_field('user', 'picture', 0, array('id' => $muser->id));
            }
            $result = false;
        } else if ($size['@odata.mediaEtag'] !== $photoid) {
            if (!empty($size['height']) && !empty($size['width'])) {
                $image = $apiclient->get_photo($user, $size['height'], $size['width']);
            } else {
                $image = $apiclient->get_photo($user);
            }
            // Check if json error message was returned.
            if (!preg_match('/^{/', $image)) {
                // Update profile picture.
                $tempfile = tempnam($CFG->tempdir.'/', 'profileimage').'.jpg';
                if (!$fp = fopen($tempfile, 'w+b')) {
                    @unlink($tempfile);
                    return false;
                }
                fwrite($fp, $image);
                fclose($fp);
                $context = \context_user::instance($muserid, MUST_EXIST);
                $newpicture = process_new_icon($context, 'user', 'icon', 0, $tempfile);
                $photoid = $size['@odata.mediaEtag'];
                if ($newpicture != $muser->picture) {
                    $DB->set_field('user', 'picture', $newpicture, array('id' => $muser->id));
                    $result = true;
                }
                @unlink($tempfile);
            }
        }
        if (empty($record)) {
            $record = new \stdClass();
            $record->muserid = $muserid;
            $record->assigned = 0;
        }
        $record->photoid = $photoid;
        $record->photoupdated = time();
        if (empty($record->id)) {
            $DB->insert_record('local_o365_appassign', $record);
        } else {
            $DB->update_record('local_o365_appassign', $record);
        }
        return $result;
    }

    /**
     * Get all users in the configured directory.
     *
     * @param string|array $params Requested user parameters.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     * @return array|null Array of user information, or null if failure.
     */
    public function get_users($params = 'default', $skiptoken = '') {
        $apiclient = $this->construct_user_api(0, true);
        return $apiclient->get_users($params, $skiptoken);
    }

    /**
     * Apply the configured field map.
     *
     * @param array $aaddata User data from Azure AD.
     * @param array $user Moodle user data.
     * @param string $eventtype 'login', or 'create'
     * @return array Modified Moodle user data.
     */
    public static function apply_configured_fieldmap(array $aaddata, \stdClass $user, $eventtype) {
        $fieldmaps = get_config('local_o365', 'fieldmap');
        $fieldmaps = (!empty($fieldmaps)) ? @unserialize($fieldmaps) : [];
        if (empty($fieldmaps) || !is_array($fieldmaps)) {
            return $user;
        }

        foreach ($fieldmaps as $fieldmap) {
            $fieldmap = explode('/', $fieldmap);
            if (count($fieldmap) !== 3) {
                continue;
            }
            list($remotefield, $localfield, $behavior) = $fieldmap;
            if ($behavior !== 'on'.$eventtype && $behavior !== 'always') {
                // Field mapping doesn't apply to this event type.
                continue;
            }
            if (isset($aaddata[$remotefield])) {
                $user->$localfield = $aaddata[$remotefield];
            }
        }

        // Lang cannot be longer than 2 chars.
        if (!empty($user->lang) && strlen($user->lang) > 2) {
            $user->lang = substr($user->lang, 0, 2);
        }

        return $user;
    }

    /**
     * Check the configured user creation restriction and determine whether a user can be created.
     *
     * @param array $aaddata Array of user data from Azure AD.
     * @return bool Whether the user can be created.
     */
    protected function check_usercreationrestriction($aaddata) {
        $restriction = get_config('local_o365', 'usersynccreationrestriction');
        if (empty($restriction)) {
            return true;
        }
        $restriction = @unserialize($restriction);
        if (empty($restriction) || !is_array($restriction)) {
            return true;
        }
        if (empty($restriction['remotefield']) || empty($restriction['value'])) {
            return true;
        }

        if (!isset($aaddata[$restriction['remotefield']])) {
            return false;
        }

        if ($aaddata[$restriction['remotefield']] === $restriction['value']) {
            return true;
        }
        return false;
    }

    /**
     * Create a Moodle user from Azure AD user data.
     *
     * @param array $aaddata Array of Azure AD user data.
     * @return \stdClass An object representing the created Moodle user.
     */
    public function create_user_from_aaddata($aaddata) {
        global $CFG;

        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');

        $creationallowed = $this->check_usercreationrestriction($aaddata);
        if ($creationallowed !== true) {
            mtrace('Cannot create user because they do not meet the configured user creation restrictions.');
            return false;
        }

        // Locate country code.
        if (isset($aaddata['country'])) {
            $countries = get_string_manager()->get_list_of_countries();
            foreach ($countries as $code => $name) {
                if ($aaddata['country'] == $name) {
                    $aaddata['country'] = $code;
                }
            }
            if (strlen($aaddata['country']) > 2) {
                // Limit string to 2 chars to prevent sql error.
                $aaddata['country'] = substr($aaddata['country'], 0, 2);
            }
        }

        $newuser = (object)[
            'auth' => 'oidc',
            'username' => trim(\core_text::strtolower($aaddata['userPrincipalName'])),
            'lang' => 'en',
            'confirmed' => 1,
            'timecreated' => time(),
            'mnethostid' => $CFG->mnet_localhost_id,
        ];

        $newuser = static::apply_configured_fieldmap($aaddata, $newuser, 'create');

        $password = null;
        $newuser->idnumber = $newuser->username;

        if (!empty($newuser->email)) {
            if (email_is_not_allowed($newuser->email)) {
                unset($newuser->email);
            }
        }

        if (empty($newuser->lang) || !get_string_manager()->translation_exists($newuser->lang)) {
            $newuser->lang = $CFG->lang;
        }

        $newuser->timemodified = $newuser->timecreated;
        $newuser->id = user_create_user($newuser, false, false);

        // Save user profile data.
        profile_save_data($newuser);

        $user = get_complete_user_data('id', $newuser->id);
        if (!empty($CFG->{'auth_'.$newuser->auth.'_forcechangepassword'})) {
            set_user_preference('auth_forcepasswordchange', 1, $user);
        }
        // Set the password.
        update_internal_user_password($user, $password);

        // Trigger event.
        \core\event\user_created::create_from_userid($newuser->id)->trigger();

        return $user;
    }

    /**
     * Selectively run mtrace.
     *
     * @param string $msg The message.
     */
    public static function mtrace($msg) {
        if (!PHPUNIT_TEST) {
            mtrace($msg);
        }
    }

    /**
     * Sync Azure AD Moodle users with the configured Azure AD directory.
     *
     * @param array $aadusers Array of Azure AD users from $this->get_users().
     * @return bool Success/Failure
     */
    public function sync_users(array $aadusers = array()) {
        global $DB, $CFG;

        $aadsync = get_config('local_o365', 'aadsync');
        $photoexpire = get_config('local_o365', 'photoexpire');
        $aadsync = array_flip(explode(',', $aadsync));

        $usernames = [];
        foreach ($aadusers as $i => $user) {
            $upnlower = \core_text::strtolower($user['userPrincipalName']);
            $aadusers[$i]['upnlower'] = $upnlower;

            $usernames[] = $upnlower;

            $upnsplit = explode('@', $upnlower);
            if (!empty($upnsplit[0])) {
                $aadusers[$i]['upnsplit0'] = $upnsplit[0];
                $usernames[] = $upnsplit[0];
            }
        }

        // Retrieve object id for app.
        if (!PHPUNIT_TEST) {
            $appinfo = $this->get_application_serviceprincipal_info();
        }

        $objectid = null;
        if (!empty($appinfo)) {
            if (\local_o365\rest\unified::is_configured()) {
                $objectid = $appinfo['value'][0]['id'];
            } else {
                $objectid = $appinfo['value'][0]['objectId'];
            }
        }

        list($usernamesql, $usernameparams) = $DB->get_in_or_equal($usernames);
        $sql = 'SELECT u.username,
                       u.id as muserid,
                       u.auth,
                       tok.id as tokid,
                       conn.id as existingconnectionid,
                       assign.assigned assigned,
                       assign.photoid photoid,
                       assign.photoupdated photoupdated
                  FROM {user} u
             LEFT JOIN {auth_oidc_token} tok ON tok.username = u.username
             LEFT JOIN {local_o365_connections} conn ON conn.muserid = u.id
             LEFT JOIN {local_o365_appassign} assign ON assign.muserid = u.id
                 WHERE u.username '.$usernamesql.' AND u.mnethostid = ? AND u.deleted = ? ';
        $params = array_merge($usernameparams, [$CFG->mnet_localhost_id, '0']);
        $existingusers = $DB->get_records_sql($sql, $params);

        foreach ($aadusers as $user) {
            $this->mtrace(' ');
            $this->mtrace('Syncing user '.$user['upnlower']);
            if (isset($user['aad.isDeleted']) && $user['aad.isDeleted'] == '1') {
                $this->mtrace('User is deleted. Skipping.');
                continue;
            }
            if (\local_o365\rest\unified::is_configured()) {
                $userobjectid = $user['id'];
            } else {
                $userobjectid = $user['objectId'];
            }
            if (!isset($existingusers[$user['upnlower']]) && !isset($existingusers[$user['upnsplit0']])) {
                $this->mtrace('User doesn\'t exist in Moodle');
                if (!isset($aadsync['create'])) {
                    $this->mtrace('Not creating a Moodle user because that sync option is disabled.');
                    continue;
                }

                try {
                    // Create moodle account, if enabled.
                    $newmuser = $this->create_user_from_aaddata($user);
                    if (!empty($newmuser)) {
                        $this->mtrace('Created user #'.$newmuser->id);
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not create user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                }
                try {
                    if (!PHPUNIT_TEST) {
                        if (!empty($newmuser) && !empty($userobjectid) && !empty($objectid) && isset($aadsync['appassign'])) {
                            $this->assign_user($newmuser->id, $userobjectid, $objectid);
                        }
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not assign user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                }
                try {
                    if (!PHPUNIT_TEST) {
                        if (!empty($newmuser) && isset($aadsync['photosync'])) {
                            $this->assign_photo($newmuser->id, $user['upnlower']);
                        }
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not assign photo to user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                }
            } else {
                $existinguser = null;
                if (isset($existingusers[$user['upnlower']])) {
                    $existinguser = $existingusers[$user['upnlower']];
                } else if (isset($existingusers[$user['upnsplit0']])) {
                    $existinguser = $existingusers[$user['upnsplit0']];
                }
                // Assign user to app if not already assigned.
                if (empty($existinguser->assigned)) {
                    try {
                        if (!PHPUNIT_TEST) {
                            if (!empty($existinguser->muserid) && !empty($userobjectid) && !empty($objectid) && isset($aadsync['appassign'])) {
                                $this->assign_user($existinguser->muserid, $userobjectid, $objectid);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->mtrace('Could not assign user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                    }
                }
                if (isset($aadsync['photosync']) && (empty($existinguser->photoupdated) || ($existinguser->photoupdated + $photoexpire*3600) < time())) {
                    try {
                        if (!PHPUNIT_TEST) {
                            $this->assign_photo($existinguser->muserid, $user['upnlower']);
                        }
                    } catch (\Exception $e) {
                        $this->mtrace('Could not assign profile photo to user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                    }
                }

                if ($existinguser->auth !== 'oidc' && empty($existinguser->tok)) {
                    $this->mtrace('Found a user in Azure AD that seems to match a user in Moodle');
                    $this->mtrace(sprintf('moodle username: %s, aad upn: %s', $existinguser->username, $user['upnlower']));

                    if (!isset($aadsync['match'])) {
                        $this->mtrace('Not matching user because that sync option is disabled.');
                        continue;
                    }

                    if (!empty($existinguser->existingconnectionid)) {
                        $this->mtrace('User is already matched.');
                        continue;
                    }

                    // Match to o365 account, if enabled.
                    $matchrec = [
                        'muserid' => $existinguser->muserid,
                        'aadupn' => $user['upnlower'],
                        'uselogin' => (isset($aadsync['matchswitchauth'])) ? 1 : 0,
                    ];
                    $DB->insert_record('local_o365_connections', $matchrec);
                    $this->mtrace('Matched user.');
                } else {
                    // User already connected.
                    $this->mtrace('User is already synced.');
                }
            }
        }
        return true;
    }
}
