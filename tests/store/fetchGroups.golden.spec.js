/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for fetchGroups in the group store: OCS answers of every shape,
 * failures with and without a message, and a search overtaken by a newer
 * one. Each case records the request, what the call returns or throws and
 * the store's groups, error and loading flag, compared with
 * golden/fetch-groups.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const requests = []
const answers = []

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url, config) => {
			requests.push({ url, config })
			const answer = answers.shift()
			return new Promise((resolve, reject) => {
				const settle = () =>
					answer.fail
						? reject(answer.fail)
						: resolve({ data: answer.data })
				if (answer.hold) {
					answer.hold.release = settle
				} else {
					settle()
				}
			})
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/${url}` }))

const { useGroupStore } = await import('../../src/store/modules/group.js')

const GOLDEN = path.join(__dirname, 'golden', 'fetch-groups.json')

/**
 * An OCS answer listing groups.
 *
 * @param {*} groups The groups value.
 * @return {object}
 */
function ok(groups) {
	return { data: { ocs: { data: { groups } } } }
}

/**
 * A failed request, with an OCS message when one is given.
 *
 * @param {string} message The error message.
 * @param {string} [ocs] The OCS meta message.
 * @return {Error}
 */
function failure(message, ocs) {
	return Object.assign(
		new Error(message),
		ocs === undefined
			? {}
			: { response: { data: { ocs: { meta: { message: ocs } } } } },
	)
}

const CASES = {
	'groups out of order': [ok(['b', 'a', 'c'])],
	'no groups key': [{ data: { ocs: { data: {} } } }],
	'groups not a list': [ok('admin')],
	'empty data': [{ data: null }],
	'failure with an OCS message': [{ fail: failure('boom', 'Not allowed') }],
	'failure with only a message': [{ fail: failure('network down') }],
	'failure with nothing': [{ fail: Object.assign(new Error(''), {}) }],
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * The store state worth recording.
 *
 * @param {object} store The group store.
 * @return {object}
 */
function snapshot(store) {
	return {
		groups: store.groups,
		error: store.candidatesError,
		loading: store.loading,
	}
}

/**
 * The value or error of one call.
 *
 * @param {Promise} call The pending call.
 * @return {Promise<object>}
 */
async function outcome(call) {
	try {
		return { returned: await call }
	} catch (error) {
		return { threw: error.message }
	}
}

describe('fetchGroups (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		requests.length = 0
		answers.length = 0
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		answers.push(...CASES[name])
		const store = useGroupStore()
		const result = await outcome(
			store.fetchGroups(name === 'groups out of order' ? 'ad' : undefined),
		)
		const actual = {
			result,
			store: snapshot(store),
			requests: JSON.parse(JSON.stringify(requests)),
		}
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})

	it.each([
		['older answer arrives last', ['old'], ['new'], 'first'],
		['older failure arrives last', null, ['new'], 'first'],
		['newer failure, older answer last', ['old'], null, 'first'],
		['in order', ['old'], ['new'], 'second'],
	])('%s', async (name, first, second, lastToSettle) => {
		const holdA = {}
		const holdB = {}
		answers.push(
			first
				? { ...ok(first), hold: holdA }
				: { fail: failure('old failed', 'Old'), hold: holdA },
			second
				? { ...ok(second), hold: holdB }
				: { fail: failure('new failed', 'New'), hold: holdB },
		)
		const store = useGroupStore()
		const a = outcome(store.fetchGroups('a'))
		const b = outcome(store.fetchGroups('b'))
		const steps = [snapshot(store)]
		const order = lastToSettle === 'first' ? [holdB, holdA] : [holdA, holdB]
		for (const hold of order) {
			hold.release()
			await new Promise((resolve) => setTimeout(resolve, 0))
			steps.push(snapshot(store))
		}
		const actual = {
			a: await a,
			b: await b,
			steps: JSON.parse(JSON.stringify(steps)),
		}
		const key = `race: ${name}`
		if (RECORD) {
			recorded[key] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[key])
	})
})
