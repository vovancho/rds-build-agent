<?php

$includePaths = explode(PATH_SEPARATOR, get_include_path());
// vdm: exclude system's pear from include path
if (($idx = array_search('/usr/share/pear', $includePaths)) !== false) {
    unset($includePaths[$idx]);
}

set_include_path(join(PATH_SEPARATOR, array_merge($includePaths, array(
	dirname(__FILE__) . '/../misc/cron',
	// local
	dirname(__FILE__) . '/',
	dirname(__FILE__) . '/../main/',
	// developer's
	dirname(__FILE__) . '/../../../lib/',
	dirname(__FILE__) . '/../../../lib/pear',
	dirname(__FILE__) . '/../../../lib/libcore/',
))));

require_once 'Autoload.php';
