const base = require('@conduction/nextcloud-vue/stylelint')
const path = require('path')

// One app rule on top of the shared preset: a colour may appear only as the
// fallback of a var(), so the Nextcloud / thematiq theme always wins
// (openspec/changes/platform-properties, task 1.3).
module.exports = {
	...base,
	plugins: [...(base.plugins || []), path.join(__dirname, 'scripts/stylelint/no-bare-hex-color.mjs')],
	rules: {
		...base.rules,
		'keepiq/no-bare-hex-color': true,
	},
}
