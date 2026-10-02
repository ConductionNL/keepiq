/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Plural strings end to end: `n('app', singular, plural, count)` must be
 * extracted as Nextcloud's `"_<singular>_::_<plural>_": [forms]` entry with
 * one form per plural category of each locale (pl 3, nl 2), the build must
 * emit it to l10n/<locale>.js with the matching rule, and @nextcloud/l10n
 * must then pick the right form for each count.
 *
 * Before this, the extraction stored the singular and plural as two plain
 * keys, so Polish showed the 5+ form for 2-4 (`grep -c "_::_" l10n/pl.json`
 * was 0).
 *
 * The REAL scripts run against a fixture app in a temp directory:
 * tests/l10n/check-l10n.js (cwd = the fixture) and scripts/build-l10n-js.js
 * (L10N_REPO_ROOT = the fixture).
 *
 * @spec exclude build tooling for translation catalogues; no product spec covers it
 */

import { getPlural, translatePlural } from '@nextcloud/l10n'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import vm from 'node:vm'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../..')
const CHECK = join(ROOT, 'tests/l10n/check-l10n.js')
const BUILD = join(ROOT, 'scripts/build-l10n-js.js')
const KEY = '_%n file_::_%n files_'

let app

/**
 * Write a catalogue.
 *
 * @param {string} locale - catalogue name
 * @param {object} translations - key -> value
 * @param {object} extra - other top-level fields
 */
function catalogue(locale, translations, extra = {}) {
	writeFileSync(
		join(app, 'l10n', `${locale}.json`),
		JSON.stringify({ translations, ...extra }, null, 4) + '\n',
	)
}

/**
 * @param {string} locale - catalogue name
 * @return {object} its translations
 */
function read(locale) {
	return JSON.parse(readFileSync(join(app, 'l10n', `${locale}.json`), 'utf8'))
		.translations
}

/**
 * @param {string} script - absolute script path
 * @param {string[]} args - arguments
 * @return {{status: number, out: string}} exit status and combined output
 */
function run(script, args = []) {
	const r = spawnSync(process.execPath, [script, ...args], {
		cwd: app,
		env: {
			...process.env,
			L10N_REPO_ROOT: app,
			L10N_REQUIRED_LOCALES: 'nl,pl,rm',
		},
		encoding: 'utf8',
	})
	return { status: r.status, out: (r.stdout || '') + (r.stderr || '') }
}

/**
 * Load a built l10n/<locale>.js the way the browser does.
 *
 * @param {string} locale - catalogue name
 * @return {{translations: object, pluralForm: string}} what it registers
 */
function loadJs(locale) {
	let captured = null
	const sandbox = {
		OC: {
			L10N: {
				register: (id, translations, pluralForm) => {
					captured = { id, translations, pluralForm }
				},
			},
		},
	}
	vm.runInNewContext(
		readFileSync(join(app, 'l10n', `${locale}.js`), 'utf8'),
		sandbox,
	)
	return captured
}

/**
 * Render n() through the real @nextcloud/l10n with a built bundle.
 *
 * @param {string} locale - catalogue name
 * @param {number} count - the n
 * @return {string} what the user sees
 */
function render(locale, count) {
	const bundle = {
		translations: loadJs(locale).translations,
		pluralFunction: (x) => getPlural(x, locale),
	}
	return translatePlural('fx', '%n file', '%n files', count, undefined, { bundle })
}

beforeEach(() => {
	app = mkdtempSync(join(tmpdir(), 'l10n-plurals-'))
	mkdirSync(join(app, 'src'))
	mkdirSync(join(app, 'l10n'))
	mkdirSync(join(app, 'appinfo'))
	writeFileSync(join(app, 'package.json'), JSON.stringify({ name: 'fx' }))
	writeFileSync(join(app, 'appinfo', 'info.xml'), '<info><id>fx</id></info>\n')
	writeFileSync(
		join(app, 'src', 'Files.vue'),
		[
			'<template>',
			"\t<p>{{ t('fx', 'Files') }} {{",
			'\t\tn(',
			"\t\t\t'fx',",
			"\t\t\t'%n file',",
			"\t\t\t'%n files',",
			'\t\t\tcount,',
			'\t\t)',
			'\t}}</p>',
			'</template>',
			'',
		].join('\n'),
	)
	// The shape keepiq has today: singular and plural as two plain keys.
	const plain = (one, many, files) => ({
		Files: files,
		'%n file': one,
		'%n files': many,
	})
	catalogue('en', plain('%n file', '%n files', 'Files'), {
		pluralForm: 'nplurals=2; plural=(n != 1);',
	})
	catalogue('nl', plain('%n bestand', '%n bestanden', 'Bestanden'))
	catalogue('pl', plain('%n plik', '%n plików', 'Pliki'))
	catalogue('rm', plain('%n datoteca', '%n datotecas', 'Datotecas'))
})

afterEach(() => {
	rmSync(app, { recursive: true, force: true })
})

describe('l10n plural extraction (n())', () => {
	it('fails while n() is stored as two plain keys (red before)', () => {
		const r = run(CHECK)
		expect(r.status).toBe(1)
		expect(r.out).toContain(JSON.stringify(KEY))
	})

	it("--write adds the _::_ entry with each locale's number of forms", () => {
		expect(run(CHECK, ['--write']).status).toBe(0)
		expect(read('en')[KEY]).toEqual(['%n file', '%n files'])
		expect(read('nl')[KEY]).toEqual(['%n bestand', '%n bestanden'])
		// Seeded from today's two keys: identical output for every count until
		// a translator writes form 1 (2-4).
		expect(read('pl')[KEY]).toEqual(['%n plik', '%n plików', '%n plików'])
		// The browser has no rule for Romansh and would show form 0 for every
		// count, so no entry: it keeps the plain-key fallback.
		expect(Object.hasOwn(read('rm'), KEY)).toBe(false)
	})

	it('the build emits the arrays and the per-locale rule, and the browser picks the right form', () => {
		run(CHECK, ['--write'])
		catalogue('pl', {
			...read('pl'),
			[KEY]: ['%n plik', '%n pliki', '%n plików'],
		})
		expect(run(BUILD).status).toBe(0)

		expect(loadJs('pl').translations[KEY]).toEqual([
			'%n plik',
			'%n pliki',
			'%n plików',
		])
		expect(loadJs('pl').pluralForm).toMatch(/^nplurals=3;/)
		expect(loadJs('nl').pluralForm).toMatch(/^nplurals=2;/)

		expect([1, 2, 4, 5, 12, 22, 25].map((c) => render('pl', c))).toEqual([
			'1 plik',
			'2 pliki',
			'4 pliki',
			'5 plików',
			'12 plików',
			'22 pliki',
			'25 plików',
		])
		expect([1, 2, 0].map((c) => render('nl', c))).toEqual([
			'1 bestand',
			'2 bestanden',
			'0 bestanden',
		])
		// rm: no entry, so translatePlural falls back to the plain keys.
		expect(render('rm', 1)).toBe('1 datoteca')
		expect(render('rm', 3)).toBe('3 datotecas')

		const r = run(CHECK)
		expect(r.status, r.out).toBe(0)
		expect(r.out).toContain('l10n-parity')
	})

	it('rejects a plural entry with the wrong number of forms for its locale', () => {
		run(CHECK, ['--write'])
		catalogue('pl', { ...read('pl'), [KEY]: ['%n plik', '%n plików'] })
		run(BUILD)
		const r = run(CHECK)
		expect(r.status).toBe(1)
		expect(r.out).toContain('l10n/pl.json')
		expect(r.out).toContain('needs 3')
	})

	it('rejects a declared pluralForm that disagrees with the browser on the number of forms', () => {
		run(CHECK, ['--write'])
		catalogue('pl', read('pl'), { pluralForm: 'nplurals=2; plural=(n != 1);' })
		run(BUILD)
		const r = run(CHECK)
		expect(r.status).toBe(1)
		expect(r.out).toContain('pluralForm declares 2 form(s)')
	})
})
