<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The item's "ask for my master password" switch (crypto-item-reprompt),
  shared by the create and edit dialogs.
-->
<template>
	<NcCheckboxRadioSwitch
		:modelValue="modelValue"
		:disabled="disabled"
		data-testid="secret-reprompt"
		@update:modelValue="$emit('update:modelValue', $event)">
		{{
			t(
				'keepiq',
				'Ask for my master password before showing or filling this item',
			)
		}}
	</NcCheckboxRadioSwitch>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

/**
 * Whether the item asks for the master password before it is shown or filled.
 */
export default {
	name: 'RepromptSwitch',

	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		/** Whether the item asks for the master password. */
		modelValue: {
			type: Boolean,
			default: false,
		},

		/** Whether the switch is disabled while the dialog saves or loads. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	methods: {
		t,
	},
}
</script>
