<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The user's opt-in to receiving secrets from partner organisations
  (sharing-federated-recipients D6). Off by default: until it is on, a
  partner's certificate lookup treats this user as unknown and a share sent
  to them is refused.

  @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-users-opt-in-to-receiving
-->
<template>
	<div class="federation-receive" data-testid="federation-receive-section">
		<label for="federation-receive">
			<input
				id="federation-receive"
				v-model="receive"
				type="checkbox"
				data-testid="federation-receive-toggle"
				@change="save" />
			{{ t('keepiq', 'Receive secrets from other organisations') }}
		</label>
		<p class="federation-receive__hint">
			{{
				t(
					'keepiq',
					'People in partner organisations can then find your account and share secrets with you. You accept each one yourself.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'FederationReceiveSection',

	data() {
		return {
			receive: false,
		}
	},

	/**
	 * Load the current preference.
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-users-opt-in-to-receiving
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/user'),
			)
			const value = response.data?.federation_receive
			this.receive = value === '1' || value === true
		} catch {
			this.receive = false
		}
	},

	methods: {
		/**
		 * Store the preference.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-users-opt-in-to-receiving
		 */
		async save() {
			await axios.put(generateUrl('/apps/keepiq/api/settings/user'), {
				federation_receive: this.receive ? '1' : '0',
			})
		},
	},
}
</script>

<style scoped>
.federation-receive__hint {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
