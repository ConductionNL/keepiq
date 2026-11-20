<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The eye button that shows or hides a masked value. The host handles the
  click; attributes such as a title pass on to the button.

  @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
-->
<template>
	<NcButton
		variant="tertiary"
		:aria-label="revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')">
		<template #icon>
			<EyeOff v-if="revealed" :size="20" />
			<Eye v-else :size="20" />
		</template>
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'RevealToggle',

	components: { Eye, EyeOff, NcButton },

	props: {
		/** Whether the value is shown now. */
		revealed: {
			type: Boolean,
			required: true,
		},
	},

	methods: {
		t,
	},
}
</script>
