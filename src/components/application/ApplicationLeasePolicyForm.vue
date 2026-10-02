<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Per-application lease policy on the application detail view (keepiq#753).
  Shows the policy in force and the stored override; an empty field
  inherits the instance value. Only an administrator may change it (the
  PUT is admin-only), so the registrant sees it read-only. Anyone else
  gets a 404 from the read and the form stays hidden.

  @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
-->
<template>
	<section
		v-if="view"
		class="lease-policy-form"
		data-testid="application-lease-policy">
		<h4>{{ t('keepiq', 'Lease policy for this application') }}</h4>

		<p data-testid="lease-policy-effective">
			{{
				t(
					'keepiq',
					'In force now: {default} seconds by default, {max} seconds at most.',
					{ default: view.effective.defaultTtl, max: view.effective.maxTtl },
				)
			}}
			{{
				view.effective.renewable
					? t('keepiq', 'Leases are renewable')
					: t('keepiq', 'Leases are not renewable')
			}}
		</p>

		<NcNoteCard v-if="error" type="error" data-testid="lease-policy-error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success" data-testid="lease-policy-saved">
			{{ t('keepiq', 'Lease policy saved.') }}
		</NcNoteCard>

		<form v-if="view.canEdit" class="lease-policy-form__fields" @submit.prevent="save">
			<p class="lease-policy-form__hint">
				{{ t('keepiq', 'Leave a field empty to use the instance value.') }}
			</p>
			<label class="lease-policy-form__field">
				<span>{{ t('keepiq', 'Default lease TTL (seconds)') }}</span>
				<input
					v-model="defaultTtl"
					type="number"
					min="60"
					:placeholder="t('keepiq', 'Instance value: {value}', { value: view.instance.defaultTtl })"
					data-testid="lease-policy-default-ttl">
			</label>
			<label class="lease-policy-form__field">
				<span>{{ t('keepiq', 'Maximum lease TTL (seconds)') }}</span>
				<input
					v-model="maxTtl"
					type="number"
					min="60"
					:placeholder="t('keepiq', 'Instance value: {value}', { value: view.instance.maxTtl })"
					data-testid="lease-policy-max-ttl">
			</label>
			<label class="lease-policy-form__field">
				<span>{{ t('keepiq', 'Renewal') }}</span>
				<select v-model="renewable" data-testid="lease-policy-renewable">
					<option value="inherit">
						{{
							t('keepiq', 'Use the instance value ({value})', {
								value: view.instance.renewable
									? t('keepiq', 'Allowed')
									: t('keepiq', 'Not allowed'),
							})
						}}
					</option>
					<option value="yes">
						{{ t('keepiq', 'Allowed') }}
					</option>
					<option value="no">
						{{ t('keepiq', 'Not allowed') }}
					</option>
				</select>
			</label>
			<NcButton
				type="submit"
				variant="primary"
				:disabled="saving"
				data-testid="lease-policy-save">
				{{ t('keepiq', 'Save lease policy') }}
			</NcButton>
		</form>
		<p v-else class="lease-policy-form__hint" data-testid="lease-policy-readonly">
			{{ t('keepiq', 'Only an administrator can change this policy.') }}
		</p>
	</section>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useLeaseStore } from '../../store/modules/lease.js'

/**
 * A form field value as an override TTL: empty means inherit (null).
 *
 * @param {string|number|null} value The field value.
 * @return {number|null} Whole seconds, or null.
 */
function ttlOrNull(value) {
	if (value === null || value === undefined || String(value).trim() === '') {
		return null
	}
	return Math.round(Number(value))
}

export default {
	name: 'ApplicationLeasePolicyForm',
	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		applicationId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			view: null,
			defaultTtl: '',
			maxTtl: '',
			renewable: 'inherit',
			error: null,
			saved: false,
			saving: false,
		}
	},

	/**
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
	 */
	async mounted() {
		try {
			this.applyView(await useLeaseStore().fetchPolicy(this.applicationId))
		} catch (e) {
			// A 404 means the viewer may not see this application's policy.
			if (e?.response?.status !== 404) {
				this.error = e?.response?.data?.message || e?.message || null
			}
		}
	},

	methods: {
		/**
		 * Load a policy view into the form fields.
		 *
		 * @param {object} view The policy view from the server.
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		applyView(view) {
			this.view = view
			this.defaultTtl = view.override.defaultTtl ?? ''
			this.maxTtl = view.override.maxTtl ?? ''
			if (view.override.renewable === null) {
				this.renewable = 'inherit'
			} else {
				this.renewable = view.override.renewable ? 'yes' : 'no'
			}
		},

		/**
		 * Store the override, then reload the view so the form shows what is
		 * in force now rather than what was typed.
		 *
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		async save() {
			this.saving = true
			this.saved = false
			this.error = null
			const renewableMap = { inherit: null, yes: true, no: false }
			try {
				const store = useLeaseStore()
				await store.savePolicy(this.applicationId, {
					defaultTtl: ttlOrNull(this.defaultTtl),
					maxTtl: ttlOrNull(this.maxTtl),
					renewable: renewableMap[this.renewable],
				})
				this.applyView(await store.fetchPolicy(this.applicationId))
				this.saved = true
			} catch (e) {
				this.error = e?.response?.data?.message
					|| this.t('keepiq', 'Could not save the lease policy.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.lease-policy-form {
	margin-top: 16px;
}

.lease-policy-form__fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;
}

.lease-policy-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.lease-policy-form__hint {
	color: var(--color-text-maxcontrast);
}
</style>
