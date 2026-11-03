/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for shareSaveErrorText in src/services/shareSave.js: every
 * error code it knows and a grid of response shapes around them, each
 * recorded as the text it returns (or null), compared with
 * golden/share-save-error-text.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/secrets-write-ui/spec.md#requirement-a-failed-share-save-is-shown-and-nothing-is-saved
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params = {}) =>
		`[${app}] ${text.replace(/\{(\w+)\}/g, (_, key) => `<${params[key]}>`)}`,
	translatePlural: (app, one) => `[${app}] ${one}`,
}))

const { ShareSaveError, shareSaveErrorText } =
	await import('../../src/services/shareSave.js')

const GOLDEN = path.join(__dirname, 'golden', 'share-save-error-text.json')

const CODES = [
	'stale',
	'stale_source',
	'version_refused',
	'incomplete',
	'partner_lookup_limit',
	'view_only',
	'partner_unreachable',
	'partner_upgrade_required',
	'forbidden',
	'not_allowed',
	'other',
	'constructor',
	'toString',
]
const PARTNERS = [
	{ shareId: 's1', recipientCloudId: 'bob@cloud.example', reason: 'down' },
	{ shareId: 's2', recipientCloudId: 'eve@other.example', reason: 'down' },
]

/**
 * An axios-shaped error with a response.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The response body.
 * @param {object} extra Properties set on the error itself.
 * @return {Error} The error.
 */
function httpError(status, data, extra = {}) {
	return Object.assign(new Error(`HTTP ${status}`), {
		response: { status, data },
		...extra,
	})
}

const CASES = {
	null: () => null,
	undefined: () => undefined,
	'plain error': () => new Error('boom'),
	'409 with current': () => httpError(409, { current: { id: 1 } }),
	'409 without current': () => httpError(409, {}),
	'412 with current': () => httpError(412, { current: { id: 1 } }),
	'partner upgrade, version and partners': () =>
		httpError(
			409,
			{ error: 'partner_upgrade_required', requiredVersion: '9.9.9' },
			{ partners: PARTNERS },
		),
	'partner upgrade, no partners': () =>
		httpError(409, {
			error: 'partner_upgrade_required',
			requiredVersion: '2.0.0',
		}),
	'refusal with a policy code': () =>
		httpError(
			403,
			{ error: 'forbidden', code: 'policy' },
			{ fromShareSave: true },
		),
	'refusal not from share save': () => httpError(403, { error: 'forbidden' }),
	'share save forbidden, 400': () =>
		httpError(400, { error: 'forbidden' }, { fromShareSave: true }),
	'partner unreachable without partners': () =>
		httpError(502, { error: 'partner_unreachable' }),
	'share save forbidden, fromShareSave "true"': () =>
		httpError(403, { error: 'forbidden' }, { fromShareSave: 'true' }),
}
const UPGRADE = {
	response: {
		status: 409,
		data: { error: 'partner_upgrade_required', requiredVersion: '9.9.9' },
	},
}
for (const code of ['incomplete', 'partner_unreachable', 'other']) {
	CASES[`ShareSaveError ${code} with partner upgrade data`] = () =>
		Object.assign(new ShareSaveError(code, PARTNERS), UPGRADE)
}
CASES['ShareSaveError partner_upgrade_required, no response'] = () =>
	new ShareSaveError('partner_upgrade_required', PARTNERS)
for (const code of CODES) {
	CASES[`ShareSaveError ${code}`] = () => new ShareSaveError(code, PARTNERS)
	for (const status of [403, 409, 500]) {
		CASES[`response ${status} ${code}`] = () =>
			httpError(status, { error: code }, { partners: PARTNERS })
		CASES[`share save ${status} ${code}`] = () =>
			httpError(
				status,
				{ error: code },
				{ fromShareSave: true, partners: PARTNERS },
			)
	}
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * The text for one case, or the error it throws.
 *
 * @param {Function} make Builds the error.
 * @return {object} The outcome.
 */
function outcome(make) {
	try {
		return { text: shareSaveErrorText(make()) }
	} catch (error) {
		return { threw: `${error.name}: ${error.message}` }
	}
}

describe('shareSaveErrorText (golden)', () => {
	it.each(Object.keys(CASES))('%s', (name) => {
		const actual = outcome(CASES[name])
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
