<?php

use OCP\Util;

$appId = OCA\Doriath\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="doriath-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
