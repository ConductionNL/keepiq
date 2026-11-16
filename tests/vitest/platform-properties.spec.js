/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keepiq takes its look and its language from Nextcloud.
 *
 *  - `keepiq/no-bare-hex-color` (scripts/stylelint) rejects a hex colour in a
 *    stylesheet unless it is the fallback of a var(), so the Nextcloud or
 *    thematiq theme always wins.
 *  - tests/l10n/check-l10n-nl.js fails, naming the key, when a
 *    t('keepiq', ...) key used in src/ has no entry in l10n/nl.json.
 *
 * @spec openspec/changes/platform-properties/tasks.md#1.3
 * @spec openspec/changes/platform-properties/tasks.md#2.1
 */

import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import stylelint from 'stylelint'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import plugin, { bareHexColors, ruleName } from '../../scripts/stylelint/no-bare-hex-color.mjs'

const ROOT = path.resolve(__dirname, '../..')

describe('bareHexColors', () => {
	it('reports a plain hex colour with its position', () => {
		expect(bareHexColors('#ff0000')).toEqual([{ hex: '#ff0000', index: 0 }])
		expect(bareHexColors('1px solid #abc')).toEqual([{ hex: '#abc', index: 10 }])
	})

	it('accepts a hex colour that is the fallback of a var()', () => {
		expect(bareHexColors('var(--color-primary-element, #00679e)')).toEqual([])
		expect(bareHexColors('var(--a, var(--b, #fff))')).toEqual([])
		expect(bareHexColors('0 0 0 1px var(--color-border, #dbdbdb)')).toEqual([])
	})

	it('rejects a hex colour inside var() but not in its fallback', () => {
		expect(bareHexColors('var(#123456)')).toEqual([{ hex: '#123456', index: 4 }])
	})

	it('rejects a hex colour in another function, even next to a var()', () => {
		expect(bareHexColors('linear-gradient(var(--x, #111), #222)'))
			.toEqual([{ hex: '#222', index: 32 }])
		expect(bareHexColors('rgba(0, 0, 0, .5), inset 0 0 #000'))
			.toEqual([{ hex: '#000', index: 29 }])
	})

	it('ignores strings, ids and non-colour hashes', () => {
		expect(bareHexColors('"#ff0000"')).toEqual([])
		expect(bareHexColors('url(#gradient)')).toEqual([])
		expect(bareHexColors('#12345')).toEqual([])
	})
})

describe('keepiq/no-bare-hex-color', () => {
	const lint = (code) => stylelint.lint({
		code,
		config: { plugins: [plugin], rules: { [ruleName]: true } },
	})

	it('reports a hard-coded colour in a stylesheet', async () => {
		const { results } = await lint('.a { color: #ff0000; border: 1px solid var(--color-border, #ddd); }')
		const warnings = results[0].warnings
		expect(warnings).toHaveLength(1)
		expect(warnings[0].rule).toBe(ruleName)
		expect(warnings[0].text).toContain('#ff0000')
		expect(warnings[0].column).toBe(13)
	})

	it('passes theme variables with fallbacks', async () => {
		const { results } = await lint('.a { color: var(--color-primary-element-text, #fff); background: var(--color-primary-element); }')
		expect(results[0].warnings).toEqual([])
	})

	it('is switched on in the app stylelint config', () => {
		const config = require(path.join(ROOT, 'stylelint.config.js'))
		expect(config.rules[ruleName]).toBe(true)
		expect(config.extends).toBe('@nextcloud/stylelint-config')
	})
})

describe('Dutch coverage gate (check-l10n-nl.js)', () => {
	const gate = path.join(ROOT, 'tests/l10n/check-l10n-nl.js')
	let fixture

	beforeAll(() => {
		fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'keepiq-l10n-nl-'))
		fs.mkdirSync(path.join(fixture, 'src'))
		fs.mkdirSync(path.join(fixture, 'l10n'))
		fs.writeFileSync(path.join(fixture, 'package.json'), JSON.stringify({ name: 'keepiq' }))
		fs.writeFileSync(path.join(fixture, 'src', 'Probe.vue'),
			"<template><p>{{ t('keepiq', 'Vault') }} {{ t('keepiq', 'Deliberately untranslated') }}</p></template>\n")
		fs.writeFileSync(path.join(fixture, 'l10n', 'nl.json'), JSON.stringify({
			translations: { Vault: 'Kluis' },
			pluralForm: 'nplurals=2; plural=(n != 1);',
		}))
	})

	afterAll(() => {
		fs.rmSync(fixture, { recursive: true, force: true })
	})

	it('fails and names a key that has no Dutch entry', () => {
		const run = spawnSync(process.execPath, [gate], { cwd: fixture, encoding: 'utf8' })
		expect(run.status).toBe(1)
		expect(run.stderr).toContain('MISSING from l10n/nl.json')
		expect(run.stderr).toContain('"Deliberately untranslated"')
		expect(run.stderr).not.toContain('"Vault"')
	})

	it('refuses --write, which would seed English as Dutch', () => {
		const run = spawnSync(process.execPath, [gate, '--write'], { cwd: fixture, encoding: 'utf8' })
		expect(run.status).toBe(2)
		expect(JSON.parse(fs.readFileSync(path.join(fixture, 'l10n', 'nl.json'), 'utf8')).translations)
			.toEqual({ Vault: 'Kluis' })
	})

	it('passes on the app: every key used in src/ has a Dutch entry', () => {
		const run = spawnSync(process.execPath, [gate], { cwd: ROOT, encoding: 'utf8' })
		expect(run.stderr).toBe('')
		expect(run.status).toBe(0)
		expect(run.stdout).toContain('every used translation key is present in l10n/nl.json')
	})
})
