const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'doriath'

// Lazy-loaded chunks (the argon2-browser WASM loader for link-share encryption)
// must resolve relative to the script that loaded them. Nextcloud serves the
// entry bundle from `/custom_apps/<app>/js/`, but the default publicPath points
// lazy chunks at `/apps/<app>/js/` which 401s. `publicPath: 'auto'` makes
// webpack derive the chunk base from the executing script's own URL, so chunks
// load from the same `/custom_apps/doriath/js/` directory as the main bundle.
webpackConfig.output = {
	...(webpackConfig.output || {}),
	publicPath: 'auto',
}

webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
// `USE_LOCAL_LIB=false` forces the published package even when a sibling checkout
// is present — without it a local build can never reproduce what CI and production
// build (they have no sibling, so they always resolve the npm dist).
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by the aliased @conduction/nextcloud-vue components
			// (CnCard, CnDataTable, CnAppRoot internals, …) when building
			// against the monorepo-dev source tree.
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
		{
			// Image assets referenced by library components (e.g. Leaflet
			// marker icons pulled in transitively).
			test: /\.(png|jpe?g|gif|svg)$/,
			type: 'asset/resource',
			generator: {
				filename: 'img/[name][ext]',
			},
		},
	],
}

// Replace VueLoaderPlugin (don't push — duplicates break templates when using local package)
const otherPlugins = (webpackConfig.plugins || []).filter((p) => p.constructor.name !== 'VueLoaderPlugin')
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	...otherPlugins,
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
// Register the exact-match style.css alias BEFORE the bare package alias below:
// enhanced-resolve applies the first matching entry, and the bare alias maps the
// package to its DIRECTORY, so '@nextcloud/dialogs/style.css' (imported by
// nextcloud-vue's useAppInstaller) would resolve to a non-existent root style.css.
// dialogs v6 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css')
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// dialogs v6 drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
// longer auto-polyfills node core modules — without this the bundle fails to emit with
// "Can't resolve 'path'". This app only uses the toast APIs (showError/showSuccess), so
// the FilePicker code path never runs and an empty module is safe.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: false,
}

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across the main / settings entry-points so each bundle no longer
// inlines its own ~3 MB framework copy. Stable filenames mean each
// entry's `Util::addScript` PHP call can reference the chunk directly
// without a manifest. The shared chunks are loaded once per page and
// cached across navigations between doriath's own pages.
//
// CRITICAL: templates/index.php + templates/settings/admin.php MUST
// addScript these shared chunks BEFORE the entry script — without
// them the webpack runtime sits forever in `chunkOnLoad` and the app
// renders blank with no console error (same gotcha that bit
// docudesk#242).
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

// Nextcloud apps with @conduction/nextcloud-vue and zxcvbn exceed the default
// 244 KiB asset size hint. Raise the limit to suppress warnings.
webpackConfig.performance = {
	maxAssetSize: 5 * 1024 * 1024,
	maxEntrypointSize: 5 * 1024 * 1024,
}

// The crypto module lazy-loads the `argon2-browser` WASM library via a dynamic
// import (src/crypto/argon2.js). argon2-browser's own loader contains a
// `require('../dist/argon2.wasm')` that Webpack 5 cannot statically resolve
// (it parses the binary as a module). Emit the .wasm as a static resource so
// the bundle builds; argon2-browser falls back to fetching it from the
// configured `argon2WasmPath` at runtime.
webpackConfig.module.rules.push({
	test: /argon2\.wasm$/,
	type: 'asset/resource',
	generator: { filename: '[name][ext]' },
})

module.exports = webpackConfig
