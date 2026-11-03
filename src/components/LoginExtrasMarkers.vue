<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Small markers on a vault row whose login carries a one-time code or
  passkeys. A row holds those fields as ciphertext, so only their presence
  is read; nothing is decrypted. Each icon carries its own text alternative.

  @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
-->
<template>
	<span v-if="hasCode || hasPasskeys" class="login-extras">
		<ClockOutline
			v-if="hasCode"
			:size="16"
			:title="t('keepiq', 'Has a one-time code')"
			data-testid="login-marker-totp" />
		<Fingerprint
			v-if="hasPasskeys"
			:size="16"
			:title="t('keepiq', 'Has passkeys')"
			data-testid="login-marker-passkeys" />
	</span>
</template>

<script>
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Fingerprint from 'vue-material-design-icons/Fingerprint.vue'
import { rowHasCode, rowHasPasskeys } from '../utils/valueFieldMarkers.js'

export default {
	name: 'LoginExtrasMarkers',

	components: {
		ClockOutline,
		Fingerprint,
	},

	props: {
		/** The vault row or import preview row; only whether its `totp` and `passkeys` are set is read (null / '' for none). */
		secret: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		hasCode() {
			return rowHasCode(this.secret)
		},

		/**
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		hasPasskeys() {
			return rowHasPasskeys(this.secret)
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.login-extras {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	margin-inline-start: 4px;
	vertical-align: middle;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
</style>
