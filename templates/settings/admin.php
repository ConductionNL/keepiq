<?php

use OCP\Util;

$appId = OCA\Keepiq\AppInfo\Application::APP_ID;
// Shared chunks must load before the entry — settings expects the same
// shared Vue / @nextcloud/vue / @conduction/nextcloud-vue baseline as
// the main entry. See webpack.config.js `splitChunks.cacheGroups`.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="keepiq-settings"></div>
