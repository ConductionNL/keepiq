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
	// Offline app-shell service worker (offline-readonly-cache §3). Emitted
	// as a standalone script (no runtime chunk imports) so it can be
	// registered at a stable URL and precache the shell.
	serviceWorker: {
		import: path.join(__dirname, 'src', 'offline', 'service-worker.js'),
		filename: appId + '-service-worker.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
// `USE_LOCAL_LIB=false` forces the published package even when a sibling checkout
// is present — without it a local build can never reproduce what CI and production
// build (they have no sibling, so they always resolve the npm dist).
//
// ⚠️ USE_LOCAL_LIB is opt-IN (ADR-090). Building against a developer's working
// checkout is the wrong default for a build that can ship.
//
// The sibling is validated against THIS app's own declared range. The previous
// test was `major < 2`, on the premise that a bad sibling would be 1.x. The
// sibling today is 2.0.5 while this app declares 2.2.0-vue3.16 — both major 2 —
// so the test waved through a version the app never asked for.
//
// The failure that skew produces is not obvious from the version alone. Building
// against the sibling also pulls packages out of the SIBLING's node_modules, and
// a stale vue-demi shim there (its postinstall picks v2/v2.7/v3 and does not
// re-run on `npm install`) yields errors of the form
//   export 'default' (imported as 'Vue') was not found in 'vue'
// — a Vue-2-shaped failure from a library that is itself Vue 3.
//
// Fail CLOSED: if the check cannot run, the sibling is refused. A guard that
// degrades to "allow" is not a guard.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[doriath] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		//
		// `vue` and `pinia` still publish `main`/`module`, so a directory
		// alias resolves for those two.
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// @nextcloud/vue@9, @nextcloud/dialogs@7 and vue-router@5 are ESM-only:
		// their package.json has NO `main` and NO `module`, only an `exports`
		// map. A Vue-2-era alias to the package DIRECTORY bypasses `exports`
		// entirely (webpack applies an exports map to a PACKAGE REQUEST, never
		// to an already-absolutised path) and then looks for a main/index.js
		// that does not exist — every import fails with
		// "Can't resolve '@nextcloud/vue'". Alias to the absolute FILE. The
		// exact-match (`$`) form keeps deep imports going through the map.
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/vue@9 hard-depends on vue-router ^5.1.0 while this app is
		// on vue-router 4, so npm installs a SECOND nested copy under
		// node_modules/@nextcloud/vue/node_modules/vue-router. Two router
		// instances mean two different injection keys: NcAppNavigationItem's
		// RouterLink would look up a router this app never provided and
		// navigation dies with no console error. Force every `vue-router`
		// specifier onto this app's single copy.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
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
const otherPlugins = (webpackConfig.plugins || []).filter(
	(p) => p.constructor.name !== 'VueLoaderPlugin',
)
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	...otherPlugins,
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules, preventing
// a nested copy from leaking in. Register the exact-match style.css alias
// BEFORE the package alias: enhanced-resolve applies the first matching entry.
// dialogs v7 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
// v7 is exports-map-only (no main, no module) — the bare DIRECTORY alias that
// worked against v6 resolves to nothing. Alias the absolute file, exact-match.
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// The dialogs FilePicker chunk imports node's `path`, and webpack 5 no longer
// auto-polyfills node core modules. `path-browserify` is also an undeclared
// requirement of @nextcloud/webpack-vue-config@7, so it is a real dependency
// either way — provide it rather than stubbing `path` to false.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
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
		// The service worker must stay a self-contained script — exclude it
		// from shared-chunk extraction so it never references a chunk it
		// cannot import at the SW scope (offline-readonly-cache §3).
		chunks: (chunk) => chunk.name !== 'serviceWorker',
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
