<template>
	<div class="field-card">
		<div class="field-card__header">
			<label class="field-card__label">{{ label }}</label>
			<div class="field-card__actions">
				<NcButton
					v-if="sensitive"
					type="tertiary-no-background"
					:aria-label="revealed ? t('doriath', 'Hide value') : t('doriath', 'Show value')"
					@click="revealed = !revealed">
					<template #icon>
						<EyeOffIcon v-if="revealed" :size="20" />
						<EyeIcon v-else :size="20" />
					</template>
				</NcButton>
				<CopyButton :value="value" />
			</div>
		</div>
		<span :class="['field-card__value', { 'field-card__value--multiline': multiline }]">{{ displayValue }}</span>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'
import CopyButton from './CopyButton.vue'

export default {
	name: 'SecretFieldCard',
	components: {
		NcButton,
		EyeIcon,
		EyeOffIcon,
		CopyButton,
	},
	props: {
		label: {
			type: String,
			required: true,
		},
		value: {
			type: String,
			default: '',
		},
		sensitive: {
			type: Boolean,
			default: false,
		},
		multiline: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			revealed: false,
		}
	},
	computed: {
		displayValue() {
			if (this.sensitive && !this.revealed) {
				return '\u2022'.repeat(16)
			}
			return this.value
		},
	},
}
</script>

<style scoped>
.field-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	background: var(--color-background-hover);
}

.field-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.field-card__label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.field-card__actions {
	display: flex;
	gap: 0;
	margin: -8px -8px -8px 0;
}

.field-card__value {
	display: block;
	font-size: 0.9375rem;
	word-break: break-all;
	color: var(--color-main-text);
	user-select: all;
}

.field-card__value--multiline {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-monospace, monospace);
	font-size: 0.8125rem;
}
</style>
