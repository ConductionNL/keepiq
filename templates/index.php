<?php

use OCP\Util;

$appId = OCA\Doriath\AppInfo\Application::APP_ID;
// Shared chunks must load before the entry — main expects Vue / @nextcloud/vue
// / @conduction/nextcloud-vue / pinia / vue-material-design-icons to be
// resolved by the time its chunkOnLoad callback runs. See webpack.config.js
// `splitChunks.cacheGroups`.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');

// PWA / mobile home-screen meta (mobile-pwa §2.1). The Doriath web app
// manifest is served by WebManifestController at /manifest.webmanifest; iOS
// honours the apple-* meta per-page even where the browser installs the
// NC-scoped PWA. This change registers NO service worker — installability's
// SW criterion is satisfied by Nextcloud's instance service worker (§D2).
$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
$manifestUrl  = $urlGenerator->linkToRoute($appId . '.webManifest.manifest');
$touchIcon    = $urlGenerator->imagePath($appId, 'pwa-icon.svg');
Util::addHeader('link', ['rel' => 'manifest', 'href' => $manifestUrl]);
Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#21468B']);
Util::addHeader('meta', ['name' => 'apple-mobile-web-app-capable', 'content' => 'yes']);
Util::addHeader('meta', ['name' => 'apple-mobile-web-app-status-bar-style', 'content' => 'black-translucent']);
Util::addHeader('meta', ['name' => 'apple-mobile-web-app-title', 'content' => 'Doriath']);
Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $touchIcon]);
?>
<div id="content"></div>
