<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Delete confirmation for one passkey on a login. The prompt names the site
  and account, and says the passkey is gone for everyone who holds the login:
  the change saves like any other edit of its value.

  Lives in its own file per ADR-004.

  @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Delete passkey')"
		:open="passkey !== null"
		size="small"
		data-testid="passkey-delete-dialog"
		@update:open="$emit('close')">
		<p class="passkey-delete__prompt">
			{{
				t(
					'keepiq',
					'Delete the passkey for {site} ({account})? You can no longer sign in with it, here or for anyone this login is shared with.',
					{ site: siteLabel, account: accountLabel },
				)
			}}
		</p>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="passkey-delete-confirm"
				@click="$emit('confirm')">
				{{ t('keepiq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PasskeyDeleteDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The passkey credential awaiting confirmation, or `null` when closed.
		 * Only its relying party and user name are read.
		 */
		passkey: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'confirm'],

	computed: {
		/**
		 * @spec exclude Presentation-only label for the prompt.
		 */
		siteLabel() {
			return this.passkey?.rpName || this.passkey?.rpId || ''
		},

		/**
		 * @spec exclude Presentation-only label for the prompt.
		 */
		accountLabel() {
			return (
				this.passkey?.userName
				|| this.passkey?.userDisplayName
				|| t('keepiq', 'no user name')
			)
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.passkey-delete__prompt {
	padding: 0 12px 12px;
}
</style>
