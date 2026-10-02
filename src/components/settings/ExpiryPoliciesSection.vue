<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Personal expiry rules (#77): for an item type or a folder, how many days a
  password may live and how many days ahead to be reminded. The API and the
  rotation store existed; this is the screen for them. Mounted in the
  user-settings dialog in App.vue. Rules an administrator set apply too and
  are listed read-only.

  @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
  @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
-->
<template>
	<div class="expiry-policies" data-testid="expiry-policies-section">
		<p class="expiry-policies__hint">
			{{ t('keepiq', 'Set how long passwords of one item type or in one folder may live, and when to be reminded. When several dates apply, the earliest one counts.') }}
		</p>

		<ul v-if="rules.length > 0" class="expiry-policies__list">
			<li
				v-for="rule in rules"
				:key="rule.id"
				class="expiry-policies__rule"
				data-testid="expiry-policy-row">
				<span class="expiry-policies__scope">{{ scopeLabel(rule) }}</span>
				<span class="expiry-policies__detail">{{ ruleDetail(rule) }}</span>
				<NcButton
					v-if="isOwn(rule)"
					variant="tertiary"
					:aria-label="t('keepiq', 'Delete rule')"
					data-testid="expiry-policy-delete"
					@click="onDelete(rule)">
					{{ t('keepiq', 'Delete') }}
				</NcButton>
				<span v-else class="expiry-policies__admin">{{ t('keepiq', 'Set by your administrator') }}</span>
			</li>
		</ul>
		<p v-else class="expiry-policies__empty" data-testid="expiry-policies-empty">
			{{ t('keepiq', 'No expiry rules yet.') }}
		</p>

		<div class="expiry-policies__form">
			<NcSelect
				v-model="draft.scope"
				:options="scopeOptions"
				:inputLabel="t('keepiq', 'Applies to')"
				label="label"
				:reduce="(opt) => opt.value"
				:clearable="false"
				data-testid="expiry-policy-scope" />
			<NcSelect
				v-model="draft.scopeId"
				:options="targetOptions"
				:inputLabel="draft.scope === 'folder' ? t('keepiq', 'Folder') : t('keepiq', 'Item type')"
				label="label"
				:reduce="(opt) => opt.value"
				data-testid="expiry-policy-target" />
			<NcTextField
				v-model="draft.maxAgeDays"
				type="number"
				min="1"
				:label="t('keepiq', 'Maximum age in days (empty for reminders only)')"
				data-testid="expiry-policy-max-age" />
			<NcTextField
				v-model="draft.reminderDays"
				:label="t('keepiq', 'Remind me this many days before, comma separated')"
				data-testid="expiry-policy-reminders" />
			<NcButton
				variant="primary"
				:disabled="!canSave || saving"
				data-testid="expiry-policy-save"
				@click="onSave">
				{{ t('keepiq', 'Save rule') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { useFolderStore } from '../../store/modules/folder.js'
import { useRotationStore } from '../../store/modules/rotation.js'
import { useSecretTypeStore } from '../../store/modules/secretType.js'
import { secretTypeLabel } from '../../utils/secretTypes.js'

/**
 * Parse "30, 7,1" into [30, 7, 1]: positive whole days, largest first.
 *
 * @param {string} text The typed thresholds.
 * @return {Array<number>} The thresholds.
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 */
export function parseReminderDays(text) {
	const days = String(text ?? '')
		.split(/[\s,;]+/)
		.map((part) => Number(part))
		.filter((day) => Number.isInteger(day) && day > 0)
	return [...new Set(days)].sort((a, b) => b - a)
}

/**
 * The typed maximum age as a whole number of days, or null for none.
 *
 * @param {string|number} value The typed value.
 * @return {number|null} The days, or null.
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 */
export function parseMaxAge(value) {
	if (value === '' || value === null || value === undefined) {
		return null
	}
	const days = Number(value)
	return Number.isInteger(days) && days > 0 ? days : NaN
}

export default {
	name: 'ExpiryPoliciesSection',
	components: { NcButton, NcSelect, NcTextField },

	data() {
		return {
			saving: false,
			draft: { scope: 'type', scopeId: null, maxAgeDays: '', reminderDays: '' },
		}
	},

	computed: {
		/**
		 * The rotation store.
		 *
		 * @return {object}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		rotation() {
			return useRotationStore()
		},

		/**
		 * The rules that apply to the user: their own and the administrator's.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		rules() {
			return Array.isArray(this.rotation.policies) ? this.rotation.policies : []
		},

		/**
		 * What a rule can apply to.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		scopeOptions() {
			return [
				{ value: 'type', label: t('keepiq', 'An item type') },
				{ value: 'folder', label: t('keepiq', 'A folder') },
			]
		},

		/**
		 * The item types or folders a rule can apply to.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		targetOptions() {
			if (this.draft.scope === 'folder') {
				return useFolderStore().folders.map((folder) => ({ value: folder.id, label: folder.name }))
			}
			return useSecretTypeStore().types.map((type) => ({ value: type.id, label: secretTypeLabel(type) }))
		},

		/**
		 * A rule needs a target, a valid maximum age or none, and at least one
		 * of the two limits, or it would do nothing.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		canSave() {
			const maxAge = parseMaxAge(this.draft.maxAgeDays)
			if (!this.draft.scopeId || Number.isNaN(maxAge)) {
				return false
			}
			return maxAge !== null || parseReminderDays(this.draft.reminderDays).length > 0
		},
	},

	watch: {
		'draft.scope': function() {
			this.draft.scopeId = null
		},
	},

	/**
	 * Load the rules, the item types and the folders.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
	 */
	async mounted() {
		const types = useSecretTypeStore()
		const folders = useFolderStore()
		await Promise.allSettled([
			this.rotation.fetchPolicies(),
			types.types.length === 0 ? types.fetchTypes() : null,
			folders.folders.length === 0 ? folders.fetchFolders() : null,
		])
	},

	methods: {
		t,

		/**
		 * Whether the user may delete a rule: only their own.
		 *
		 * @param {object} rule The policy.
		 * @return {boolean}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		isOwn(rule) {
			return rule.ownerId === getCurrentUser()?.uid
		},

		/**
		 * Name the type or folder a rule applies to.
		 *
		 * @param {object} rule The policy.
		 * @return {string}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		scopeLabel(rule) {
			const target = rule.scope === 'folder'
				? useFolderStore().folders.find((folder) => folder.id === rule.scopeId)
				: useSecretTypeStore().types.find((type) => type.id === rule.scopeId)
			const name = target ? (rule.scope === 'folder' ? target.name : secretTypeLabel(target)) : rule.scopeId
			return rule.scope === 'folder'
				? t('keepiq', 'Folder {name}', { name })
				: t('keepiq', 'Type {name}', { name })
		},

		/**
		 * Say what a rule does.
		 *
		 * @param {object} rule The policy.
		 * @return {string}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		ruleDetail(rule) {
			const parts = []
			if (rule.maxAgeDays) {
				parts.push(t('keepiq', 'Expires after {days} days', { days: rule.maxAgeDays }))
			}
			if (Array.isArray(rule.reminderDays) && rule.reminderDays.length > 0) {
				parts.push(t('keepiq', 'Reminders {days} days before', { days: rule.reminderDays.join(', ') }))
			}
			return parts.join('. ')
		},

		/**
		 * Create or update the drafted rule.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		async onSave() {
			this.saving = true
			try {
				await this.rotation.upsertPolicy({
					scope: this.draft.scope,
					scopeId: this.draft.scopeId,
					maxAgeDays: parseMaxAge(this.draft.maxAgeDays),
					reminderDays: parseReminderDays(this.draft.reminderDays),
				})
				this.draft = { scope: this.draft.scope, scopeId: null, maxAgeDays: '', reminderDays: '' }
			} catch {
				showError(t('keepiq', 'Could not save the expiry rule.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Delete one of the user's own rules.
		 *
		 * @param {object} rule The policy.
		 * @return {Promise<void>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 */
		async onDelete(rule) {
			try {
				await this.rotation.deletePolicy(rule.id)
			} catch {
				showError(t('keepiq', 'Could not delete the expiry rule.'))
			}
		},
	},
}
</script>

<style scoped>
.expiry-policies__hint,
.expiry-policies__empty,
.expiry-policies__admin {
	color: var(--color-text-maxcontrast);
}

.expiry-policies__list {
	list-style: none;
	margin: 8px 0;
	padding: 0;
}

.expiry-policies__rule {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.expiry-policies__scope {
	font-weight: bold;
}

.expiry-policies__detail {
	flex: 1;
}

.expiry-policies__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
	max-width: 420px;
}
</style>
