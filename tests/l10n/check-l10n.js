#!/usr/bin/env node
/**
 * l10n extraction / drift check.
 *
 * Scans the frontend source for translation calls — t('<app>', '...'),
 * n('<app>', '...', '...', n) and the $t/$n template variants — and asserts
 * every literal source string is present as a key in l10n/en.json.
 *
 * PLURALS. `n(app, singular, plural, count)` looks up ONE key,
 * `_<singular>_::_<plural>_`, whose value is an array with one form per
 * plural category of the language (Nextcloud's format; @nextcloud/l10n
 * `translatePlural`). This script used to record the singular and the plural
 * as two ordinary keys, so the browser fell back to "singular when n is 1,
 * plural otherwise" in every language, and Polish read wrong for 2-4 (and
 * Slovenian, Czech, Russian ... for their own counts). Now:
 *   - en.json must carry the `_s_::_p_` entry as `[singular, plural]`;
 *   - every l10n/*.json plural entry must have exactly as many forms as the
 *     locale's rule (its declared pluralForm, else scripts/l10n-plural-rules.js,
 *     which mirrors the browser's table), and a declared pluralForm must not
 *     disagree with that table on the number of forms;
 *   - --write adds a missing entry to en.json AND seeds it in every other
 *     locale the browser has a plural rule for (not `rm`; see
 *     scripts/l10n-plural-rules.js) from that locale's existing singular/plural translations, the
 *     extra forms copying the plural. That reproduces exactly what the browser
 *     showed before for every count; a translator then corrects the forms
 *     that differ (pl n=2-4, ...).
 *
 * This is the i18n equivalent of the Nextcloud `l10n` extraction step: it
 * guarantees l10n/en.json can never silently drift from the t() calls a
 * component actually makes, so a translatable string can't ship with no
 * English source entry (and therefore no entry for translators to pick up).
 *
 * It is intentionally dependency-free (pure Node, no build, no npm install)
 * so CI can run it in a bare node container, and devs can run it with
 * `node tests/l10n/check-l10n.js` from the app root.
 *
 * Modes:
 *   (default)  check only — exit non-zero if any used key is missing.
 *   --write    extraction — merge every missing used key into l10n/en.json
 *              as `"<source>": "<source>"` (English source === key, the
 *              Nextcloud convention) and re-sort. This is the reproducible
 *              "run extraction" step; run it, review the diff, commit.
 *
 * Exit codes:
 *   0  every used key is present in en.json (or --write made it so)
 *   1  one or more used keys are missing from en.json (hard failure)
 *
 * Env:
 *   L10N_APP_ID   override the app id (default: package.json "name")
 *   L10N_SRC_DIR  override the source dir to scan (default: src)
 *   L10N_FILE     override the en.json path (default: l10n/en.json)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { browserKnows, isPluralKey, pluralFormFor, npluralsOf, pluralKey } = require('../../scripts/l10n-plural-rules.js')

const ROOT = process.cwd()
const WRITE = process.argv.includes('--write')

function readJson (p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

if (!appId) {
	console.error('l10n-check: cannot determine app id (no L10N_APP_ID and no package.json "name")')
	process.exit(2)
}

const srcDir = path.join(ROOT, process.env.L10N_SRC_DIR || 'src')
const enFile = path.join(ROOT, process.env.L10N_FILE || 'l10n/en.json')

if (!fs.existsSync(srcDir)) {
	console.error(`l10n-check: source dir not found: ${srcDir}`)
	process.exit(2)
}
if (!fs.existsSync(enFile)) {
	console.error(`l10n-check: en.json not found: ${enFile} — every t() call would be a miss`)
	process.exit(1)
}

const translations = readJson(enFile).translations || {}

// Collect all .vue/.js/.ts/.mjs files under the source dir.
const exts = new Set(['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx'])
const files = []
;(function walk (dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
			continue
		}
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			walk(full)
		} else if (exts.has(path.extname(entry.name))) {
			files.push(full)
		}
	}
})(srcDir)

/**
 * Match t('<app>', '<key>') and n('<app>', '<singular>', '<plural>', ...),
 * plus the $t/$n template variants. Only LITERAL string arguments are
 * checkable — dynamic args (variables, concatenation, template literals
 * with ${}) are skipped, since their key isn't statically knowable.
 *
 * The app id and quote style (single, double, or back-tick without ${})
 * are matched explicitly so we don't pick up unrelated t() helpers.
 */
const esc = appId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// t('app', 'key'  — key in group 2 (any of the three quote styles).
const tRe = new RegExp(
	'[\\$.]?\\bt\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)
// n('app', 'singular', 'plural'  — singular in 2, plural in 3.
const nRe = new RegExp(
	'[\\$.]?\\bn\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)

function unescape (s) {
	// Mirror JS string unescaping for the escapes that appear in source keys.
	return s
		.replace(/\\n/g, '\n')
		.replace(/\\t/g, '\t')
		.replace(/\\'/g, "'")
		.replace(/\\"/g, '"')
		.replace(/\\`/g, '`')
		.replace(/\\\\/g, '\\')
}

// usedKey -> Set of "file:line" where it appears (for actionable output).
const used = new Map()

function record (key, file, idx, content) {
	if ((key === null || key === undefined)) {
		return
	}
	const k = unescape(key)
	const line = content.slice(0, idx).split('\n').length
	const where = `${path.relative(ROOT, file)}:${line}`
	if (!used.has(k)) {
		used.set(k, new Set())
	}
	used.get(k).add(where)
}

// pluralKey -> { singular, plural } for every literal n() call.
const plurals = new Map()

for (const file of files) {
	const content = fs.readFileSync(file, 'utf8')
	let m
	while ((m = tRe.exec(content)) !== null) {
		record(m[1] ?? m[2] ?? m[3], file, m.index, content)
	}
	while ((m = nRe.exec(content)) !== null) {
		const singular = m[1] ?? m[2] ?? m[3]
		const plural = m[4] ?? m[5] ?? m[6]
		if (singular === undefined || plural === undefined) {
			continue
		}
		const pair = { singular: unescape(singular), plural: unescape(plural) }
		const key = pluralKey(pair.singular, pair.plural)
		plurals.set(key, pair)
		record(key, file, m.index, content)
	}
}

const EN_FORMS = npluralsOf(readJson(enFile).pluralForm || pluralFormFor('en'))

const missing = []
for (const [key, locations] of used) {
	if (!Object.hasOwn(translations, key)) {
		missing.push({ key, locations: [...locations] })
		continue
	}
	// A plural entry that exists but is a plain string, or has the wrong
	// number of forms, is as broken as a missing one: translatePlural only
	// reads an ARRAY.
	if (plurals.has(key)) {
		const v = translations[key]
		if (!Array.isArray(v) || v.length !== EN_FORMS) {
			missing.push({ key, locations: [...locations], malformed: true })
		}
	}
}

/**
 * Every plural entry in every l10n/*.json must carry exactly the number of
 * forms its locale's rule declares.
 *
 * @return {string[]} one line per problem
 */
function pluralShapeProblems () {
	const dir = path.dirname(enFile)
	const problems = []
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json') && !x.startsWith('.')).sort()) {
		const locale = f.slice(0, -5)
		const doc = readJson(path.join(dir, f))
		const tableForms = npluralsOf(pluralFormFor(locale))
		const declared = doc.pluralForm ? npluralsOf(doc.pluralForm) : null
		if (declared !== null && declared !== tableForms) {
			problems.push(`l10n/${f}: pluralForm declares ${declared} form(s), but the browser `
				+ `(@nextcloud/l10n) uses ${tableForms} for "${locale}"`)
		}
		const forms = declared ?? tableForms
		for (const [k, v] of Object.entries(doc.translations || {})) {
			if (!isPluralKey(k)) {
				continue
			}
			if (!Array.isArray(v) || v.length !== forms) {
				problems.push(`l10n/${f}: ${JSON.stringify(k)} has `
					+ `${Array.isArray(v) ? v.length + ' form(s)' : 'a non-array value'}, needs ${forms}`)
			}
		}
	}
	return problems
}

/**
 * --write for plurals: give every other locale the entries en.json just got,
 * seeded from that locale's own singular/plural translations.
 *
 * @param {string[]} keys - plural keys added to en.json
 * @return {number} entries written
 */
function seedLocalePlurals (keys) {
	const dir = path.dirname(enFile)
	let written = 0
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json') && !x.startsWith('.')).sort()) {
		const file = path.join(dir, f)
		if (file === enFile) {
			continue
		}
		const locale = f.slice(0, -5)
		if (!browserKnows(locale)) {
			// The browser would show form 0 for every count; without an entry
			// it falls back to the plain singular/plural keys, which is right.
			continue
		}
		const doc = readJson(file)
		const tr = doc.translations || {}
		const forms = npluralsOf(doc.pluralForm || pluralFormFor(locale))
		let changed = false
		for (const key of keys) {
			if (Object.hasOwn(tr, key)) {
				continue
			}
			const { singular, plural } = plurals.get(key)
			const one = typeof tr[singular] === 'string' ? tr[singular] : singular
			const other = typeof tr[plural] === 'string' ? tr[plural] : plural
			tr[key] = Array.from({ length: forms }, (_, i) => (i === 0 ? one : other))
			changed = true
			written++
		}
		if (changed) {
			doc.translations = tr
			fs.writeFileSync(file, JSON.stringify(doc, null, 4) + '\n')
		}
	}
	return written
}

console.log(`l10n-check [${appId}]: scanned ${files.length} files, `
	+ `${used.size} distinct literal keys used, `
	+ `${Object.keys(translations).length} keys in en.json`)

if (missing.length === 0) {
	console.log('l10n-check: OK — every used translation key is present in l10n/en.json')
	const shape = pluralShapeProblems()
	if (shape.length > 0) {
		console.error(`\nl10n-check: FAIL — ${shape.length} plural entr(y/ies) with the wrong number of forms:`)
		for (const line of shape) {
			console.error(`  • ${line}`)
		}
		console.error('\nEach `_<singular>_::_<plural>_` value is an array with one form per plural '
			+ 'category of its locale (pl 3, sl 4, tr 1, ...). See scripts/l10n-plural-rules.js.')
		process.exit(1)
	}
	console.log(`l10n-check: OK — ${plurals.size} plural call(s), every plural entry has its locale's number of forms`)
	// The English source is complete — now enforce translation PARITY for the
	// other locales. Without this require the parity gate has no caller at all
	// and `test:l10n` reports green over every missing translation; that was
	// this repo's state until keepiq#180. The parity script exits the process
	// itself, so its verdict is this script's verdict.
	require('./check-l10n-parity.js')
	process.exit(0)
}

if (WRITE) {
	// Extraction mode: APPEND missing keys (source === English value) after
	// the existing entries, preserving the original key order so the diff is
	// purely additive (no whole-file re-sort churn). New keys are sorted
	// among themselves for a stable, reviewable block.
	const full = readJson(enFile)
	const appended = { ...full.translations }
	const pluralKeys = []
	for (const { key } of missing.slice().sort((a, b) => a.key.localeCompare(b.key))) {
		if (plurals.has(key)) {
			const { singular, plural } = plurals.get(key)
			appended[key] = [singular, plural]
			pluralKeys.push(key)
		} else {
			appended[key] = key
		}
	}
	full.translations = appended
	fs.writeFileSync(enFile, JSON.stringify(full, null, 4) + '\n')
	if (pluralKeys.length > 0) {
		const seeded = seedLocalePlurals(pluralKeys)
		console.log(`l10n-check: seeded ${seeded} plural entr(y/ies) across the other locales `
			+ 'from their existing singular/plural translations; review the extra forms.')
	}
	console.log(`l10n-check: WROTE ${missing.length} missing key(s) into `
		+ `${path.relative(ROOT, enFile)} (source === English value). `
		+ 'Review the diff and translate the nl.json side as needed.')
	process.exit(0)
}

console.error(`\nl10n-check: FAIL — ${missing.length} translation key(s) used in source `
	+ 'but MISSING from l10n/en.json:')
for (const { key, locations, malformed } of missing.sort((a, b) => a.key.localeCompare(b.key))) {
	console.error(`  • ${JSON.stringify(key)}${malformed ? ` (present, but not an array of ${EN_FORMS} forms)` : ''}`)
	for (const loc of locations.slice(0, 5)) {
		console.error(`      ${loc}`)
	}
	if (locations.length > 5) {
		console.error(`      … +${locations.length - 5} more`)
	}
}
console.error('\nAdd the missing source strings to l10n/en.json (key === English source), '
	+ 'or run `node tests/l10n/check-l10n.js --write` to extract them automatically.')
process.exit(1)
