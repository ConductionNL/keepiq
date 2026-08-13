<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Rotation & expiry panel on the secret detail view
  (rotation-expiry-policies §7.1/§7.3): stored + effective expiry with an
  owner-only date editor, the secret's open rotation flag with
  mark-rotated (server-proven key advance) and dismiss actions, and a
  manual flag-for-rotation action. Metadata only — nothing here touches
  ciphertext.

  @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry
-->
<template>
	<div class="rotation-panel" data-testid="rotation-panel">
		<NcNoteCard v-if="error" type="error" data-testid="rotation-error">
			{{ error }}
		</NcNoteCard>

		<!-- Open rotation flag. -->
		<div
			v-if="openFlag"
			class="rotation-panel__flag"
			data-testid="rotation-flag">
			<span class="rotation-panel__chip rotation-panel__chip--due">
				{{ flagLabel }}
			</span>
			<NcButton
				v-if="canManage"
				variant="secondary"
				data-testid="rotation-mark-rotated"
				@click="onMarkRotated">
				{{ t('doriath', 'Mark rotated') }}
			</NcButton>
			<NcButton
				v-if="canManage"
				variant="tertiary"
				data-testid="rotation-dismiss"
				@click="onDismiss">
				{{ t('doriath', 'Dismiss') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-if="rotationRequired"
			type="warning"
			data-testid="rotation-required-note">
			{{
				t(
					'doriath',
					'The credential has not changed since it was flagged — update the secret value first, then mark it rotated.',
				)
			}}
		</NcNoteCard>

		<!-- Expiry line. -->
		<div class="rotation-panel__expiry">
			<span
				v-if="effectiveExpiry"
				:class="['rotation-panel__chip', expiryChipClass]"
				data-testid="expiry-chip">
				{{ expiryLabel }}
			</span>
			<span v-else class="rotation-panel__none">
				{{ t('doriath', 'No expiry') }}
			</span>

			<template v-if="canManage">
				<input
					v-model="editValue"
					type="date"
					class="rotation-panel__date"
					:aria-label="t('doriath', 'Expiry date')"
					data-testid="expiry-input" />
				<NcButton
					variant="secondary"
					data-testid="expiry-save"
					@click="onSave">
					{{ t('doriath', 'Set expiry') }}
				</NcButton>
				<NcButton
					v-if="expiresAt"
					variant="tertiary"
					data-testid="expiry-clear"
					@click="onClear">
					{{ t('doriath', 'Clear') }}
				</NcButton>
				<NcButton
					v-if="!openFlag"
					variant="tertiary"
					data-testid="rotation-flag-now"
					@click="onFlag">
					{{ t('doriath', 'Flag for rotation') }}
				</NcButton>
			</template>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useRotationStore } from '../store/modules/rotation.js'

export default {
	name: 'RotationPanel',
	components: {
		NcButton,
		NcNoteCard,
	},
	props: {
		secretId: {
			type: String,
			required: true,
		},
		/** Whether the viewer may edit expiry / resolve flags (the owner). */
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			expiresAt: null,
			effectiveExpiry: null,
			editValue: '',
			rotationRequired: false,
			error: null,
		}
	},
	computed: {
		store() {
			return useRotationStore()
		},
		openFlag() {
			return this.store.flagsBySecretId[this.secretId] || null
		},
		flagLabel() {
			const reasons = {
				user_flagged: this.t('doriath', 'Rotation requested'),
				policy_expiry: this.t('doriath', 'Rotation due — expired'),
				suite_compromise: this.t(
					'doriath',
					'Rotation due — possible compromise',
				),
			}
			return (
				reasons[this.openFlag?.reason] || this.t('doriath', 'Rotation due')
			)
		},
		daysLeft() {
			if (!this.effectiveExpiry) {
				return null
			}
			return Math.floor(
				(Date.parse(this.effectiveExpiry) - Date.now()) / 86400000,
			)
		},
		expiryChipClass() {
			if (this.daysLeft === null) {
				return ''
			}
			if (this.daysLeft < 0) {
				return 'rotation-panel__chip--due'
			}
			if (this.daysLeft <= 30) {
				return 'rotation-panel__chip--soon'
			}
			return 'rotation-panel__chip--ok'
		},
		expiryLabel() {
			const date = new Date(
				Date.parse(this.effectiveExpiry),
			).toLocaleDateString()
			if (this.daysLeft !== null && this.daysLeft < 0) {
				return this.t('doriath', 'Expired {date}', { date })
			}
			return this.t('doriath', 'Expires {date}', { date })
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load the expiry pair and the caller's open flags.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const data = await this.store.getExpiry(this.secretId)
				this.expiresAt = data.expiresAt
				this.effectiveExpiry = data.effectiveExpiry
				this.editValue = this.expiresAt ? this.expiresAt.slice(0, 10) : ''
				await this.store.fetchFlags()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load expiry'
			}
		},

		/**
		 * Save the edited expiry date.
		 *
		 * @return {Promise<void>}
		 */
		async onSave() {
			if (!this.editValue) {
				return
			}
			try {
				const data = await this.store.setExpiry(
					this.secretId,
					`${this.editValue}T00:00:00Z`,
				)
				this.expiresAt =
					data.secret?.expiresAt ?? `${this.editValue}T00:00:00Z`
				this.effectiveExpiry = data.effectiveExpiry
				this.error = null
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to set expiry'
			}
		},

		/**
		 * Clear the per-secret expiry (policies may still apply).
		 *
		 * @return {Promise<void>}
		 */
		async onClear() {
			try {
				const data = await this.store.setExpiry(this.secretId, null)
				this.expiresAt = null
				this.effectiveExpiry = data.effectiveExpiry
				this.editValue = ''
				this.error = null
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to clear expiry'
			}
		},

		/**
		 * Manually flag this secret for rotation (IDs only).
		 *
		 * @return {Promise<void>}
		 */
		async onFlag() {
			try {
				await this.store.flagSecrets([this.secretId])
				this.error = null
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to flag'
			}
		},

		/**
		 * Mark rotated — only resolves on a server-proven key advance.
		 *
		 * @return {Promise<void>}
		 */
		async onMarkRotated() {
			try {
				const result = await this.store.markRotated(this.openFlag.id)
				this.rotationRequired = result?.requiresRotation === true
				this.error = null
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to mark rotated'
			}
		},

		/**
		 * Dismiss the open flag without rotation.
		 *
		 * @return {Promise<void>}
		 */
		async onDismiss() {
			try {
				await this.store.dismissFlag(this.openFlag.id)
				this.rotationRequired = false
				this.error = null
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to dismiss'
			}
		},
	},
}
</script>

<style scoped>
.rotation-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.rotation-panel__flag,
.rotation-panel__expiry {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.rotation-panel__chip {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 13px;
	font-weight: 600;
}

.rotation-panel__chip--due {
	background-color: var(--color-error, #d91f2d);
	color: var(--color-error-text, #fff);
}

.rotation-panel__chip--soon {
	background-color: var(--color-warning, #eca700);
	color: var(--color-warning-text, #222);
}

.rotation-panel__chip--ok {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}

.rotation-panel__none {
	color: var(--color-text-maxcontrast, #777);
}

.rotation-panel__date {
	max-width: 170px;
}
</style>
