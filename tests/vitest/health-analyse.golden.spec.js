/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test of analyse(): findings, summary and score for a fixed set of
 * rows under several option sets, compared with golden/health-analyse.json.
 * KEEPIQ_RECORD_GOLDEN=1 rewrites it.
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { analyse } from '../../src/health/engine.js'

const GOLDEN = path.join(__dirname, 'golden', 'health-analyse.json')
const NOW = Date.parse('2026-11-01T00:00:00Z')

const ROWS = [
	{
		id: 'a',
		name: 'weak',
		url: null,
		folderPath: 'f',
		value: 'password',
		keyUpdatedAt: '2026-10-30T00:00:00Z',
	},
	{
		id: 'b',
		name: 'strong',
		value: 'c0rrect-H0rse-battery-staple-91!',
		keyUpdatedAt: '2020-01-01T00:00:00Z',
	},
	{
		id: 'c',
		name: 'reuse 1',
		value: 'shared-Secret-value-77',
		keyUpdatedAt: null,
	},
	{
		id: 'd',
		name: 'reuse 2',
		value: 'shared-Secret-value-77',
		keyUpdatedAt: 'not a date',
		possiblyCompromisedAt: '2026-01-01',
	},
	{
		id: 'e',
		name: 'key material',
		value: '-----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY-----',
		keyUpdatedAt: '2025-10-31T00:00:00Z',
	},
	{ id: 'f', name: 'empty', value: '', keyUpdatedAt: '2019-01-01T00:00:00Z' },
	{ id: 'g', name: 'not a string', value: 12345, possiblyCompromisedAt: '' },
	{
		id: 'h',
		name: 'missing value',
		keyUpdatedAt: '2026-11-01T00:00:00Z',
		possiblyCompromisedAt: 'yes',
	},
	{
		id: 'i',
		name: 'triple 1',
		value: 'abc',
		keyUpdatedAt: '2018-01-01T00:00:00Z',
	},
	{ id: 'j', name: 'triple 2', value: 'abc' },
	{ id: 'k', name: 'triple 3', value: 'abc', folderPath: null },
	{
		id: 'l',
		name: 'breached',
		value: 'Summer2024!',
		keyUpdatedAt: '2025-11-01T00:00:00Z',
	},
	{
		id: 'm',
		name: 'safe verdict',
		value: 'Tr0ub4dor&3-zebra-lamp',
		keyUpdatedAt: '2025-11-02T00:00:00Z',
	},
]

const digest = async (value) => `d:${value.length}:${value.slice(0, 4)}`

const BREACH = new Map([
	['d:11:Summ', { status: 'breached', count: 42 }],
	['d:22:Tr0u', { status: 'safe', count: 0 }],
	['d:3:abc', { status: 'unknown', count: 0 }],
])

const OPTIONS = {
	defaults: {},
	'digest and now': { digest, now: NOW },
	'threshold 30': { digest, now: NOW, stalenessThreshold: '30' },
	'threshold number': { digest, now: NOW, stalenessThreshold: 730 },
	'threshold never': { digest, now: NOW, stalenessThreshold: 'never' },
	'threshold junk': { digest, now: NOW, stalenessThreshold: 'junk' },
	breaches: { digest, now: NOW, breachResults: BREACH },
	'breaches empty': { digest, now: NOW, breachResults: new Map() },
	'constant digest': {
		digest: async () => 'same',
		now: NOW,
		breachResults: new Map([['same', { status: 'breached', count: 1 }]]),
	},
	'empty digest': { digest: async () => '', now: NOW },
}

describe('analyse', () => {
	it('matches the golden record', async () => {
		const got = {}
		for (const [name, options] of Object.entries(OPTIONS)) {
			const result = await analyse(ROWS, options)
			got[name] =
				name === 'defaults'
					? {
							summaryKeys: Object.keys(result.summary),
							count: result.findings.length,
						}
					: result
		}
		got['no rows'] = await analyse([], { digest, now: NOW })
		try {
			await analyse([{ id: 'x', value: 'abc' }], {
				digest,
				now: NOW,
				breachResults: new Map([['d:3:abc', null]]),
			})
			got['null verdict'] = 'no error'
		} catch (e) {
			got['null verdict'] = `throws ${e.constructor.name}`
		}
		const json = JSON.stringify(got, null, 1) + '\n'
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, json)
		}
		expect(json).toBe(fs.readFileSync(GOLDEN, 'utf8'))
	})
})
