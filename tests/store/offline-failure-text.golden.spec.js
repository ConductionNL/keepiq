/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test of failureText(): the text the failed list shows for every
 * stored failure shape, compared with golden/offline-failure-text.json.
 * KEEPIQ_RECORD_GOLDEN=1 rewrites it.
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars) =>
		`[${app}] ${text}${vars ? ' ' + JSON.stringify(vars) : ''}`,
	translatePlural: (app, one, many, n, vars) =>
		`[${app}] ${n === 1 ? one : many} ${JSON.stringify(vars ?? {})}`,
}))
vi.mock('../../src/offline/cache.js', () => ({
	isCacheAvailable: () => true,
	readQueue: async () => [],
	putQueueEntry: async () => {},
	deleteQueueEntry: async () => {},
	clearQueue: async () => {},
	readSnapshot: async () => null,
	writeSnapshot: async () => true,
	purge: async () => {},
}))

const { failureText } = await import('../../src/store/modules/offline.js')

const GOLDEN = path.join(__dirname, 'golden', 'offline-failure-text.json')

const FAILURES = {
	undefined,
	null: null,
	empty: {},
	'message only': { message: 'Server says no' },
	'message null': { message: null },
	'unknown code': { code: 'mystery', message: 'kept' },
	'unknown code no message': { code: 'mystery' },
	'partner unreachable one': { code: 'partner_unreachable', partners: ['a@x'] },
	'partner unreachable two': {
		code: 'partner_unreachable',
		partners: ['a@x', 'b@y'],
	},
	'partner unreachable none': { code: 'partner_unreachable' },
	'partner lookup limit': { code: 'partner_lookup_limit', message: 'ignored' },
	'view only': { code: 'view_only' },
	'upgrade required': {
		code: 'partner_upgrade_required',
		partners: ['a@x'],
		requiredVersion: '3.1',
	},
	'upgrade required no partners': { code: 'partner_upgrade_required' },
	'refused known': { code: 'refused', reason: 'not_allowed' },
	'refused unknown': { code: 'refused', reason: 'some_server_code' },
	'refused no reason': { code: 'refused' },
	'not allowed': { code: 'not_allowed', message: 'ignored' },
	server: { code: 'server', message: 'De server weigert' },
	'server null': { code: 'server', message: null },
}

describe('failureText', () => {
	it('matches the golden record', () => {
		const got = {}
		for (const [name, failure] of Object.entries(FAILURES)) {
			try {
				got[name] = failureText(failure) ?? '(null)'
			} catch (e) {
				got[name] = `throws ${e.constructor.name}: ${e.message}`
			}
		}
		const json = JSON.stringify(got, null, 1) + '\n'
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, json)
		}
		expect(json).toBe(fs.readFileSync(GOLDEN, 'utf8'))
	})
})
