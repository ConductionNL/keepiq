/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for scripts/build-l10n-js.js: every case runs the script in a
 * throwaway repository and records its exit code, stdout, stderr and the files
 * present afterwards, compared byte for byte with
 * fixtures/buildL10nJs.golden.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 */

import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/build-l10n-js.js')
const PLURAL_RULES = path.resolve(__dirname, '../../scripts/l10n-plural-rules.js')
const GOLDEN = path.resolve(__dirname, 'fixtures/buildL10nJs.golden.json')

/**
 * Pretty JSON text.
 *
 * @param {unknown} value - the value
 * @return {string} the JSON
 */
function json(value) {
	return JSON.stringify(value, null, 2)
}

const L10N = {
	'appinfo/info.xml': '<info>\n\t<id> keepiq </id>\n</info>\n',
	'l10n/en.json': json({
		translations: {
			Hello: 'Hello',
			'Say "hi"': 'Say "hi"',
			'Ünï\ncode': 'é\t',
		},
	}),
	'l10n/nl.json': json({
		translations: { Hello: 'Hallo' },
		pluralForm: 'nplurals=2; plural=(n > 1);',
	}),
	'l10n/de.json': json({ translations: { Hello: 'Hallo' }, pluralForm: '' }),
	'l10n/.schema-l10n-baseline.json': json({ uncovered: 3 }),
	'l10n/README.md': 'not a catalogue',
}

const NL_JS =
	'OC.L10N.register(\n    "keepiq",\n    {\n        "Hello": "Hallo"\n    },\n    "nplurals=2; plural=(n > 1);"\n)\n'

const CASES = {
	'empty id element': { files: { ...L10N, 'appinfo/info.xml': '<id></id>' } },
	'--check with only one stale': {
		args: ['--check'],
		files: {
			'appinfo/info.xml': '<id>keepiq</id>',
			'l10n/fr.json': json({ translations: {} }),
		},
	},
	'many locales out of order': {
		files: Object.fromEntries(
			['zz', 'b', 'yy', 'a', 'x', 'c', 'w', 'mm', 'd', 'v']
				.map((l) => [`l10n/${l}.json`, json({ translations: { [l]: l } })])
				.concat([['appinfo/info.xml', '<id>keepiq</id>']]),
		),
	},
	'writes every catalogue': { files: L10N },
	'--check when nothing is built': { args: ['--check'], files: L10N },
	'--check with one stale': {
		args: ['--check'],
		files: { ...L10N, 'l10n/nl.js': NL_JS, 'l10n/en.js': 'old\n' },
	},
	'rewrites only the stale one': {
		files: { ...L10N, 'l10n/nl.js': NL_JS, 'l10n/en.js': 'old\n' },
	},
	'empty translations': {
		files: {
			'appinfo/info.xml': '<id>keepiq</id>',
			'l10n/fr.json': json({ translations: {} }),
		},
	},
	'no catalogue': {
		files: {
			'appinfo/info.xml': '<id>keepiq</id>',
			'l10n/.schema-l10n-baseline.json': '{}',
		},
	},
	'catalogue does not parse': {
		files: { ...L10N, 'l10n/nl.json': '{ nope' },
	},
	'catalogue without translations': {
		files: { ...L10N, 'l10n/nl.json': json({ pluralForm: 'x' }) },
	},
	'info.xml without an id': {
		files: { ...L10N, 'appinfo/info.xml': '<info></info>' },
	},
	'two ids, the first wins': {
		files: { ...L10N, 'appinfo/info.xml': '<id>first</id><id>second</id>' },
	},
	'locales without a pluralForm take their own rule': {
		files: {
			'appinfo/info.xml': '<id>keepiq</id>',
			'l10n/pl.json': json({ translations: { One: 'Jeden' } }),
			'l10n/tr.json': json({ translations: { One: 'Bir' } }),
			'l10n/xx.json': json({ translations: { One: 'One' } }),
		},
	},
	'L10N_REPO_ROOT points at another app': {
		root: 'other',
		files: {
			...L10N,
			'other/appinfo/info.xml': '<id>other</id>',
			'other/l10n/nl.json': json({ translations: { Hello: 'Hoi' } }),
		},
	},
	'--check under L10N_REPO_ROOT': {
		root: 'other',
		args: ['--check'],
		files: {
			'other/appinfo/info.xml': '<id>other</id>',
			'other/l10n/nl.json': json({ translations: { Hello: 'Hoi' } }),
		},
	},
}

/**
 * Every file under a directory, as repo-relative path -> content.
 *
 * @param {string} root - the throwaway repository
 * @return {object} path -> content
 */
function readTree(root) {
	const tree = {}
	const walk = (dir) => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			const abs = path.join(dir, entry.name)
			if (entry.isDirectory()) {
				walk(abs)
			} else if (!abs.includes(`${path.sep}scripts${path.sep}`)) {
				tree[path.relative(root, abs).split(path.sep).join('/')] =
					fs.readFileSync(abs, 'utf8')
			}
		}
	}
	walk(root)
	return Object.fromEntries(
		Object.entries(tree).sort(([a], [b]) => a.localeCompare(b)),
	)
}

/**
 * Run the script in a fresh repository built from one case.
 *
 * @param {{args?: string[], root?: string, files: object}} spec - the case
 * @return {object} exit code, output and the files afterwards
 */
function runCase(spec) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'keepiq-golden-'))
	try {
		const files = {
			'scripts/build-l10n-js.js': fs.readFileSync(SCRIPT, 'utf8'),
			'scripts/l10n-plural-rules.js': fs.readFileSync(PLURAL_RULES, 'utf8'),
			...spec.files,
		}
		for (const [rel, content] of Object.entries(files)) {
			fs.mkdirSync(path.dirname(path.join(root, rel)), {
				recursive: true,
			})
			fs.writeFileSync(path.join(root, rel), content)
		}
		const run = spawnSync(
			process.execPath,
			[path.join(root, 'scripts/build-l10n-js.js'), ...(spec.args || [])],
			{
				cwd: root,
				encoding: 'utf8',
				env: spec.root
					? { ...process.env, L10N_REPO_ROOT: path.join(root, spec.root) }
					: { ...process.env, L10N_REPO_ROOT: '' },
			},
		)
		return {
			status: run.status,
			stdout: run.stdout,
			stderr: run.stderr.replace(
				/does not parse: .*/g,
				'does not parse: <message>',
			),
			files: readTree(root),
		}
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('scripts/build-l10n-js.js (golden)', () => {
	it.each(Object.keys(CASES))('%s', (name) => {
		const actual = runCase(CASES[name])
		if (RECORD) {
			recorded[name] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
