/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the admin settings sections that load one settings object and
 * save a payload back to it: per case, the requests sent, the component
 * state after load and save, and the rendered markup. Set GOLDEN_RECORD=1 to
 * rewrite the fixture, then run again without it.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest'
import AttachmentLimitsSection from '../../src/components/settings/AttachmentLimitsSection.vue'
import ExtensionSection from '../../src/components/settings/ExtensionSection.vue'
import RetentionPolicySection from '../../src/components/settings/RetentionPolicySection.vue'
import RotationPolicySection from '../../src/components/settings/RotationPolicySection.vue'

const FIXTURE = join(
	process.cwd(),
	'tests/components/fixtures/adminSettingsForms.golden.json',
)
const RECORD = process.env.GOLDEN_RECORD === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

const global = {
	stubs: {
		CnSettingsSection: {
			props: ['name', 'description', 'docUrl'],
			template:
				'<section :data-name="name" :data-description="description"><slot /></section>',
		},
		NcNoteCard: {
			props: ['type'],
			template: '<div class="note" :data-type="type"><slot /></div>',
		},
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const SECTIONS = {
	AttachmentLimitsSection: {
		component: AttachmentLimitsSection,
		fields: ['maxMib', 'quotaMib'],
		stored: {
			attachment_max_bytes: 10 * 1048576,
			attachment_user_quota_bytes: 3 * 1048576 + 600000,
		},
		edits: [
			{ maxMib: 0, quotaMib: 7 },
			{ maxMib: 12, quotaMib: -3 },
		],
	},
	RetentionPolicySection: {
		component: RetentionPolicySection,
		fields: ['retentionCount', 'retentionDays', 'trashDays'],
		stored: {
			version_retention_count: 5,
			version_retention_days: 0,
			trash_retention_days: 400,
		},
		edits: [
			{ retentionCount: 0, retentionDays: -1, trashDays: 500 },
			{ retentionCount: 9, retentionDays: 30, trashDays: 0 },
		],
	},
	RotationPolicySection: {
		component: RotationPolicySection,
		fields: ['maxAgeDays', 'reminderCsv'],
		stored: { expiry_default_max_age_days: 90, expiry_reminder_days: [14, 2] },
		edits: [
			{ maxAgeDays: -5, reminderCsv: ' 10, x, 0, 3 ' },
			{ maxAgeDays: '', reminderCsv: 'none, -2' },
			{ maxAgeDays: 7, reminderCsv: '5' },
		],
	},
	ExtensionSection: {
		component: ExtensionSection,
		fields: ['maxIdleMinutes'],
		stored: { extension_max_idle_minutes: 15 },
		edits: [{ maxIdleMinutes: 60 }],
	},
}

/**
 * @param {object} section The case definition.
 * @param {object} wrapper The mounted section.
 * @return {object} The section's fields and error.
 */
function state(section, wrapper) {
	const out = { error: wrapper.vm.error }
	for (const f of section.fields) {
		out[f] = wrapper.vm[f]
	}
	return out
}

/**
 * @param {string} key The case name.
 * @param {object} value The observed value.
 */
function check(key, value) {
	const plain = JSON.parse(JSON.stringify(value))
	recorded[key] = plain
	if (!RECORD) {
		expect(plain).toEqual(expected[key])
	}
}

/**
 * @param {object} getAnswer What the GET resolves or rejects with.
 * @param {object} putAnswer What the PUT resolves or rejects with.
 * @return {Array<Array>} The calls log, filled as requests are made.
 */
function stubAxios(getAnswer, putAnswer) {
	const calls = []
	vi.spyOn(axios, 'get').mockImplementation(async (...args) => {
		calls.push(['get', ...args])
		if (getAnswer.reject) {
			throw getAnswer.reject
		}
		return { data: getAnswer.data }
	})
	vi.spyOn(axios, 'put').mockImplementation(async (...args) => {
		calls.push(['put', ...args])
		if (putAnswer.reject) {
			throw putAnswer.reject
		}
		return { data: {} }
	})
	return calls
}

const serverError = { response: { data: { message: 'Server says no' } } }
const networkError = { message: 'Network Error' }

describe('admin settings forms (golden)', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	afterAll(() => {
		if (RECORD) {
			writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, section] of Object.entries(SECTIONS)) {
		it(`${name}: loads the stored settings, then saves each edit`, async () => {
			const calls = stubAxios({ data: section.stored }, {})
			const wrapper = mount(section.component, { global })
			await flush()
			const loaded = state(section, wrapper)
			const html = wrapper.html()
			const saves = []
			for (const edit of section.edits) {
				Object.assign(wrapper.vm.$data, edit)
				await wrapper.vm.save()
				saves.push(state(section, wrapper))
			}
			check(`${name} load+save`, { loaded, html, saves, calls })
		})

		it(`${name}: defaults when the stored object is empty`, async () => {
			const calls = stubAxios({ data: {} }, {})
			const wrapper = mount(section.component, { global })
			await flush()
			check(`${name} empty`, { state: state(section, wrapper), calls })
		})

		it(`${name}: a load failure shows the server message`, async () => {
			const calls = stubAxios({ reject: serverError }, {})
			const wrapper = mount(section.component, { global })
			await flush()
			check(`${name} load fails`, {
				state: state(section, wrapper),
				html: wrapper.html(),
				calls,
			})
		})

		it(`${name}: a save failure shows the error, a later save clears it`, async () => {
			const calls = stubAxios(
				{ data: section.stored },
				{ reject: networkError },
			)
			const wrapper = mount(section.component, { global })
			await flush()
			await wrapper.vm.save()
			const failed = state(section, wrapper)
			axios.put.mockImplementation(async (...args) => {
				calls.push(['put', ...args])
				return { data: {} }
			})
			await wrapper.vm.save()
			check(`${name} save fails`, {
				failed,
				recovered: state(section, wrapper),
				calls,
			})
		})

		it(`${name}: a refused save shows the server message`, async () => {
			const calls = stubAxios(
				{ data: section.stored },
				{ reject: serverError },
			)
			const wrapper = mount(section.component, { global })
			await flush()
			await wrapper.vm.save()
			check(`${name} save refused`, {
				state: state(section, wrapper),
				calls,
			})
		})
	}
})
