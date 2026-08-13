<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Vault-admin takeover of a secret owned by somebody else.

  This is the second of the two delegation paths the user-sharing spec names,
  and until now it had no way in at all: `DelegationService::createAdminHandover()`
  was implemented, unit-tested and spec'd with no production caller, no route
  and no UI. That is not cosmetic. `AccountDeletionService::transferDelegatedSecrets()`
  reassigns a departing user's secrets to their DELEGATE and hard-deletes
  everything else they own — so a delegation is what makes a secret survive its
  owner leaving. The owner-initiated path requires the owner to act; this one
  does not, and it is the only answer when an owner is gone, unresponsive or
  hostile. Without it those secrets are destroyed on account deletion.

  The panel renders nothing at all unless the signed-in user is in the
  vault_admin group. Group membership is the only thing checked here; whether
  THIS admin may take over THIS secret (they must already hold a share of it,
  and must not already be its owner) is decided server-side on the write, so a
  visible button is an offer, never a permission.

  @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
-->
<template>
	<div
		v-if="store.isVaultAdmin === true"
		class="admin-handover"
		data-testid="admin-handover-panel">
		<NcButton
			variant="secondary"
			:disabled="store.loading || done"
			data-testid="admin-handover-button"
			@click="onHandover">
			{{ t('doriath', 'Take over as vault administrator') }}
		</NcButton>

		<p
			v-if="done"
			class="admin-handover__done"
			data-testid="admin-handover-done">
			{{ t('doriath', 'Permanent') }}
		</p>

		<p
			v-if="failure"
			class="admin-handover__error"
			role="alert"
			data-testid="admin-handover-error">
			{{ failure }}
		</p>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useDelegationStore } from '../../store/modules/delegation.js'

export default {
	name: 'AdminHandoverPanel',

	components: {
		NcButton,
	},

	props: {
		/** The secret being taken over. */
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['taken-over'],

	/**
	 * Expose the delegation store to the options API.
	 *
	 * @return {{store: object}} The delegation store.
	 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
	 */
	setup() {
		const store = useDelegationStore()
		return { store }
	},

	data() {
		return {
			done: false,
			/** @type {string} The server's refusal, shown verbatim. */
			failure: '',
		}
	},

	/**
	 * Ask whether this user is a vault admin at all. The panel renders
	 * nothing until the answer arrives, and nothing if it is false.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
	 */
	created() {
		if (this.store.isVaultAdmin === null) {
			this.store.fetchCapabilities()
		}
	},

	methods: {
		/**
		 * Perform the takeover.
		 *
		 * The server's refusal is surfaced verbatim because it is the useful
		 * part: it distinguishes "you are not a vault admin" from "this
		 * secret is not shared with you", and an admin needs to know which.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
		 */
		async onHandover() {
			this.failure = ''
			try {
				const row = await this.store.adminHandover(this.secretId)
				this.done = true
				this.$emit('taken-over', row)
			} catch (e) {
				this.failure = e?.response?.data?.message || this.store.error || ''
			}
		},
	},
}
</script>

<style scoped>
.admin-handover {
	margin: 16px 0;
}

.admin-handover__error {
	color: var(--color-error, #c00);
	margin: 8px 0 0;
}

.admin-handover__done {
	color: var(--color-success, #46ba61);
	margin: 8px 0 0;
}
</style>
