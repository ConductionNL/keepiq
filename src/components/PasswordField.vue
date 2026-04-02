<template>
	<div class="password-field">
		<NcInputField
			:value="value"
			:label="label"
			:type="showPassword ? 'text' : 'password'"
			v-bind="$attrs"
			@input="$emit('input', $event)" />
		<NcButton
			class="password-field__toggle"
			:title="showPassword ? t('doriath', 'Hide password') : t('doriath', 'Show password')"
			type="tertiary"
			@click="showPassword = !showPassword">
			<template #icon>
				<EyeOffIcon v-if="showPassword" :size="16" />
				<EyeIcon v-else :size="16" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcInputField } from '@nextcloud/vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'PasswordField',
	components: {
		NcButton,
		NcInputField,
		EyeIcon,
		EyeOffIcon,
	},
	inheritAttrs: false,
	props: {
		value: {
			type: String,
			default: '',
		},
		label: {
			type: String,
			default: '',
		},
	},
	emits: ['input'],
	data() {
		return {
			showPassword: false,
		}
	},
}
</script>

<style scoped>
.password-field {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.password-field :deep(.input-field) {
	flex: 1;
}

.password-field__toggle {
	flex-shrink: 0;
}
</style>
