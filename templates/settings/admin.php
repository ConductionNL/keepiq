<?php

use OCP\Util;

$appId = OCA\Keepiq\AppInfo\Application::APP_ID;
// Shared chunks must load before the entry — settings expects the same
// shared Vue / @nextcloud/vue / @conduction/nextcloud-vue baseline as
// the main entry. See webpack.config.js `splitChunks.cacheGroups`.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
// One mount element per admin area (admin-scoped-roles D3): Nextcloud renders
// this template once for every area the viewer holds, so each area mounts its
// own sections. The bundle is added once however often it is requested.
?>
<div id="keepiq-settings-<?php p($_['area'] ?? 'general'); ?>"></div>
