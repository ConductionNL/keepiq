<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin settings section for the instance-wide breach-check master toggle. When
  enabled, users may opt in to checking their passwords against the Have I Been
  Pwned corpus via the k-anonymity range protocol. Off by default so
  municipal / air-gapped instances never make a surprise external call. The
  external-call disclosure is shown explicitly.

  @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Breach checking')"
		:description="
			t(
				'doriath',
				'Allow users to check their passwords against the Have I Been Pwned breach corpus.',
			)
		">
		<div class="breach-check">
			<label class="breach-check__toggle" for="breach-check-enabled">
				<input
					id="breach-check-enabled"
					v-model="enabled"
					type="checkbox"
					data-testid="breach-check-enabled"
					@change="save" />
				{{ t('doriath', 'Enable breach checking for this instance') }}
			</label>
			<p class="breach-check__disclosure">
				{{
					t(
						'doriath',
						'When a user opts in, Doriath sends 5-character SHA-1 hash prefixes of their passwords to Have I Been Pwned (api.pwnedpasswords.com). The full hash and the password never leave the server. Leave this off for air-gapped instances.',
					)
				}}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BreachCheckSection',
	components: { CnSettingsSection },

	data() {
		return {
			enabled: false,
		}
	},

	/**
	 * Load the current instance-wide breach-check gate.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/settings/admin'),
			)
			this.enabled =
				response.data?.breach_check_enabled === true
				|| response.data?.breach_check_enabled === '1'
		} catch (e) {
			console.warn('Doriath: failed to load breach-check gate', e)
		}
	},

	methods: {
		/**
		 * Persist the instance-wide breach-check gate.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
		 */
		async save() {
			await axios.put(generateUrl('/apps/doriath/api/settings/admin'), {
				breach_check_enabled: this.enabled,
			})
		},
	},
}
</script>

<style scoped>
.breach-check__toggle {
	display: block;
	margin-bottom: 0.5rem;
}

.breach-check__disclosure {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
