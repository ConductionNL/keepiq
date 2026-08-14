<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for the org password policy (org-password-policies §5.1):
  the honest-client save-flow floor (zxcvbn score, HIBP block) and the
  server-authoritative generator floor (length + required classes), with
  the exempt-type picker.

  @spec openspec/specs/org-password-policies/spec.md#requirement-configurable-org-password-policy
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Org password policy')"
		:description="
			t(
				'doriath',
				'Quality floor for secret values: the generator floor is enforced server-side; save-flow checks run in the browser before encryption (the server never sees a value).',
			)
		">
		<div class="org-policy" data-testid="org-policy-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="org-policy__check">
				<input
					v-model="policy.policy_enabled"
					type="checkbox"
					data-testid="policy-enabled"
					@change="save" />
				<span>{{ t('doriath', 'Enable the org password policy') }}</span>
			</label>
			<label class="org-policy__field">
				<span>{{
					t('doriath', 'Generator minimum length (at least 8)')
				}}</span>
				<input
					v-model.number="policy.generator_min_length"
					type="number"
					min="8"
					data-testid="generator-min-length"
					@change="save" />
			</label>
			<div class="org-policy__group">
				<span>{{ t('doriath', 'Generated values must contain') }}</span>
				<label
					v-for="cls in classes"
					:key="cls.key"
					class="org-policy__check">
					<input
						v-model="policy[cls.key]"
						type="checkbox"
						:data-testid="cls.key"
						@change="save" />
					<span>{{ cls.label }}</span>
				</label>
			</div>
			<label class="org-policy__field">
				<span>{{
					t('doriath', 'Minimum strength score for manual values (0–4)')
				}}</span>
				<input
					v-model.number="policy.min_zxcvbn_score"
					type="number"
					min="0"
					max="4"
					data-testid="min-zxcvbn-score"
					@change="save" />
			</label>
			<label class="org-policy__check">
				<input
					v-model="policy.block_on_hibp_hit"
					type="checkbox"
					data-testid="block-on-hibp"
					@change="save" />
				<span>{{
					t(
						'doriath',
						'Block values found in known breaches (requires the breach check gate)',
					)
				}}</span>
			</label>
			<NcSelect
				v-model="exemptTypes"
				:options="typeOptions"
				:inputLabel="t('doriath', 'Exempt secret types')"
				multiple
				data-testid="policy-exempt-types"
				@update:modelValue="save" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useSecretTypeStore } from '../../store/modules/secretType.js'

export default {
	name: 'OrgPasswordPolicySection',
	components: { CnSettingsSection, NcNoteCard, NcSelect },

	data() {
		return {
			policy: {
				policy_enabled: false,
				generator_min_length: 12,
				generator_require_upper: false,
				generator_require_lower: false,
				generator_require_digit: false,
				generator_require_symbol: false,
				min_zxcvbn_score: 0,
				block_on_hibp_hit: false,
			},

			exemptTypes: [],
			error: null,
		}
	},

	computed: {
		classes() {
			return [
				{
					key: 'generator_require_upper',
					label: this.t('doriath', 'an uppercase letter'),
				},
				{
					key: 'generator_require_lower',
					label: this.t('doriath', 'a lowercase letter'),
				},
				{
					key: 'generator_require_digit',
					label: this.t('doriath', 'a digit'),
				},
				{
					key: 'generator_require_symbol',
					label: this.t('doriath', 'a symbol'),
				},
			]
		},

		typeOptions() {
			return useSecretTypeStore().types.map((type) => type.name)
		},
	},

	/**
	 * Load the current policy + the type list for the exempt picker.
	 */
	async created() {
		try {
			const typeStore = useSecretTypeStore()
			if (typeStore.types.length === 0) {
				await typeStore.fetchTypes()
			}
			const response = await axios.get(
				generateUrl('/apps/doriath/api/settings/admin'),
			)
			for (const key of Object.keys(this.policy)) {
				if (response.data[key] !== undefined) {
					this.policy[key] = response.data[key]
				}
			}
			this.exemptTypes = Array.isArray(response.data.policy_exempt_types)
				? response.data.policy_exempt_types
				: []
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Persist the policy (server validates floors + the HIBP gate).
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.error = null
			try {
				await axios.put(generateUrl('/apps/doriath/api/settings/admin'), {
					...this.policy,
					policy_exempt_types: this.exemptTypes,
				})
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
}
</script>

<style scoped>
.org-policy {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 520px;
}

.org-policy__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.org-policy__check {
	display: flex;
	align-items: center;
	gap: 8px;
}

.org-policy__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
</style>
