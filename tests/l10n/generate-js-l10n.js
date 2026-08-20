/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Generate the frontend l10n files (l10n/<locale>.js, OC.L10N.register)
 * from the backend ones (l10n/<locale>.json).
 *
 * Nextcloud loads JS-side translations ONLY from the .js files — the
 * .json files feed PHP's IL10N. This repo shipped .json only, so
 * `t('doriath', …)` fell back to English in the browser for every
 * language regardless of the user's setting. Run this after adding or
 * changing strings in the .json files:
 *
 *   node tests/l10n/generate-js-l10n.js
 *
 * The plural header is the default 2-form rule: the corpus contains no
 * plural (array) entries and the source no n('doriath', …) calls, so
 * per-language plural rules would be dead weight until plurals appear.
 * If plurals are ever added, extend this generator with per-language
 * rules BEFORE relying on them.
 */
const fs = require('fs')
const path = require('path')

const APP_ID = 'doriath'
const PLURAL_FORM = 'nplurals=2; plural=(n != 1);'
const L10N_DIR = path.join(__dirname, '..', '..', 'l10n')

let written = 0
for (const file of fs.readdirSync(L10N_DIR)) {
	if (!file.endsWith('.json')) {
		continue
	}
	const locale = file.slice(0, -'.json'.length)
	const { translations } = JSON.parse(
		fs.readFileSync(path.join(L10N_DIR, file), 'utf8'),
	)
	if (!translations || Object.keys(translations).length === 0) {
		console.warn(`generate-js-l10n: ${file} has no translations, skipped`)
		continue
	}
	const body = Object.entries(translations)
		.filter(([, value]) => value !== '' && value !== null && value !== undefined)
		.map(([key, value]) => `    ${JSON.stringify(key)} : ${JSON.stringify(value)}`)
		.join(',\n')
	const js = 'OC.L10N.register(\n'
		+ `    ${JSON.stringify(APP_ID)},\n`
		+ '    {\n'
		+ body + '\n'
		+ '},\n'
		+ `${JSON.stringify(PLURAL_FORM)});\n`
	fs.writeFileSync(path.join(L10N_DIR, locale + '.js'), js)
	written++
}
console.log(`generate-js-l10n: wrote ${written} l10n/<locale>.js file(s)`)
