<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'bitnami_moodle';
$CFG->dbuser    = '';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '/opt/bitnami/mysql/tmp/mysql.sock',
);

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = '127.0.0.1:80';
};

if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
    $CFG->wwwroot   = 'https://' . $_SERVER['HTTP_HOST'];
} else {
    $CFG->wwwroot   = 'http://' . $_SERVER['HTTP_HOST'];
};
$CFG->dataroot  = '/opt/bitnami/apps/moodle/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 02775;

//$CFG->passwordsaltalt1 = '85cfac5e6f25321cbe51d21c4fc3d6a66ed1a48a1e18219d0bb5378e38fdca0c';
//$CFG->passwordsaltmain = '1bb69c598a02eb28069229495714ae81101950682a62f248a3ba01bf65d8838d';
require_once(dirname(__FILE__) . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
