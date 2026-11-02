/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test of the audit event labels: every known event type, a few
 * unknown ones, the filter options and the actor labels, compared with
 * golden/audit-event-labels.json. KEEPIQ_RECORD_GOLDEN=1 rewrites it.
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `[${app}] ${text}`,
}))

const { auditActorLabel, auditEventLabel, auditEventOptions } =
	await import('../../src/utils/auditEventLabels.js')

const GOLDEN = path.join(__dirname, 'golden', 'audit-event-labels.json')

function describeValue(value) {
	return typeof value === 'function' ? `function ${value.name}` : value
}

describe('audit event labels', () => {
	it('match the golden record', () => {
		const options = auditEventOptions()
		const source = fs.readFileSync(
			path.join(__dirname, '../../src/utils/auditEventLabels.js'),
			'utf8',
		)
		const keyed = [...source.matchAll(/'([a-z_]+\.[a-z_]+)'/g)]
			.map((m) => m[1])
			.sort()
		const extra = [
			...keyed,
			'',
			'unknown.event',
			'secret',
			'constructor',
			'toString',
			'__proto__',
			'SECRET.CREATED',
		]
		const got = {
			options,
			labels: Object.fromEntries(
				[...options.map((o) => o.id), ...extra]
					.sort()
					.map((id) => [
						id || '(empty)',
						describeValue(auditEventLabel(id)),
					]),
			),
			undefinedLabel: describeValue(auditEventLabel(undefined)),
			freshCopies: auditEventOptions() !== options,
			actors: [
				{ actorType: 'link_visitor', actorId: 'x' },
				{ actorType: 'system', actorId: 'x' },
				{ actorType: 'user', actorId: 'deleted-account' },
				{ actorType: 'user', actorId: null },
				{ actorType: 'user', actorId: 'alice' },
				{ actorType: 'user' },
			].map((entry) => describeValue(auditActorLabel(entry)) ?? '(undefined)'),
		}
		const json = JSON.stringify(got, null, 1) + '\n'
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, json)
		}
		expect(json).toBe(fs.readFileSync(GOLDEN, 'utf8'))
	})
})
