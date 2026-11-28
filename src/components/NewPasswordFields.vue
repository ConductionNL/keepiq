<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The new-password part of a master password change: the new password, its
  strength meter once something is typed, and the confirmation.
-->
<template>
	<NcPasswordField
		:modelValue="newPassword"
		:label="t('keepiq', 'New password')"
		:disabled="disabled"
		@update:modelValue="$emit('update:newPassword', $event)" />

	<PasswordStrengthMeter
		v-if="newPassword"
		:password="newPassword"
		@strengthChange="$emit('strengthChange', $event)" />

	<NcPasswordField
		:modelValue="confirmPassword"
		:label="t('keepiq', 'Confirm new password')"
		:disabled="disabled"
		@update:modelValue="$emit('update:confirmPassword', $event)" />
</template>

<script>
import { NcPasswordField } from '@nextcloud/vue'
import PasswordStrengthMeter from './PasswordStrengthMeter.vue'

/**
 * Enter and confirm a new master password, with its strength shown.
 */
export default {
	name: 'NewPasswordFields',

	components: { NcPasswordField, PasswordStrengthMeter },

	props: {
		/** The new password being entered. */
		newPassword: {
			type: String,
			required: true,
		},

		/** Its confirmation. */
		confirmPassword: {
			type: String,
			required: true,
		},

		/** Whether the fields are disabled (a change is running). */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:newPassword', 'update:confirmPassword', 'strengthChange'],
}
</script>
