<template>
	<div class="additional-fields-editor">
		<div
			v-for="(row, index) in rows"
			:key="row.uid"
			:class="['additional-fields-editor__row-wrapper', { 'additional-fields-editor__row-wrapper--modified': rowModifiedState[row.uid] }]">
			<div class="additional-fields-editor__row">
				<div class="additional-fields-editor__row-controls">
					<NcInputField
						v-model="row.label"
						:label="t('doriath', 'Field name')"
						:placeholder="t('doriath', 'Field name')"
						class="additional-fields-editor__label-input"
						@update:model-value="emitChange" />
					<NcSelect
						v-model="row.type"
						:options="typeOptions"
						label="label"
						:reduce="opt => opt.value"
						:clearable="false"
						:placeholder="t('doriath', 'Type')"
						class="additional-fields-editor__type-select"
						@update:model-value="emitChange" />
					<NcButton
						type="tertiary"
						:aria-label="t('doriath', 'Remove field')"
						:title="t('doriath', 'Remove field')"
						@click="removeRow(index)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
				<NcPasswordField
					v-if="row.type === 'hidden'"
					v-model="row.value"
					:label="t('doriath', 'Field value')"
					class="additional-fields-editor__value-input"
					@update:model-value="emitChange" />
				<NcTextArea
					v-else-if="row.type === 'textarea'"
					v-model="row.value"
					:label="t('doriath', 'Field value')"
					resize="vertical"
					class="additional-fields-editor__value-input"
					@update:model-value="emitChange" />
				<NcInputField
					v-else
					v-model="row.value"
					:label="t('doriath', 'Field value')"
					class="additional-fields-editor__value-input"
					@update:model-value="emitChange" />
				<span
					v-if="duplicateLabels.has(row.label) && row.label"
					class="additional-fields-editor__warning">
					{{ t('doriath', 'Duplicate field name — only the last entry will be saved.') }}
				</span>
			</div>
		</div>

		<NcButton
			type="secondary"
			class="additional-fields-editor__add"
			@click="addRow">
			<template #icon>
				<PlusIcon :size="20" />
			</template>
			{{ t('doriath', 'Add field') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcInputField, NcPasswordField, NcSelect, NcTextArea } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

let nextUid = 0
const uid = () => `af-${++nextUid}`

const FIELD_TYPES = ['text', 'hidden', 'textarea']

function normaliseValue(val) {
	if (val == null) return { type: 'text', value: '' }
	if (typeof val === 'string') return { type: 'hidden', value: val }
	const type = FIELD_TYPES.includes(val.type) ? val.type : 'text'
	return { type, value: typeof val.value === 'string' ? val.value : '' }
}

export default {
	name: 'AdditionalFieldsEditor',
	components: {
		NcButton,
		NcInputField,
		NcPasswordField,
		NcSelect,
		NcTextArea,
		DeleteIcon,
		PlusIcon,
	},
	model: {
		prop: 'value',
		event: 'input',
	},
	props: {
		value: {
			type: Object,
			default: () => ({}),
		},
		originalValue: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			rows: this.rowsFromModel(this.value),
		}
	},
	computed: {
		typeOptions() {
			return [
				{ value: 'text', label: this.t('doriath', 'Text') },
				{ value: 'hidden', label: this.t('doriath', 'Hidden') },
				{ value: 'textarea', label: this.t('doriath', 'Long text') },
			]
		},
		duplicateLabels() {
			const seen = new Map()
			for (const row of this.rows) {
				if (!row.label) continue
				seen.set(row.label, (seen.get(row.label) ?? 0) + 1)
			}
			return new Set([...seen.entries()].filter(([, n]) => n > 1).map(([k]) => k))
		},
		rowModifiedState() {
			if (!this.originalValue) return {}
			const result = {}
			for (const row of this.rows) {
				const label = (row.label ?? '').trim()
				if (!label) {
					result[row.uid] = false
					continue
				}
				if (!(label in this.originalValue)) {
					result[row.uid] = true
					continue
				}
				const orig = normaliseValue(this.originalValue[label])
				result[row.uid] = orig.type !== row.type || orig.value !== row.value
			}
			return result
		},
	},
	watch: {
		value(next) {
			if (this.objectsEqual(next, this.rowsToObject(this.rows))) return
			this.rows = this.rowsFromModel(next)
		},
	},
	methods: {
		rowsFromModel(obj) {
			if (!obj || typeof obj !== 'object') return []
			return Object.entries(obj).map(([label, val]) => {
				const { type, value } = normaliseValue(val)
				return { uid: uid(), label, type, value }
			})
		},
		rowsToObject(rows) {
			const out = {}
			for (const row of rows) {
				const label = (row.label ?? '').trim()
				if (!label) continue
				out[label] = { type: row.type, value: row.value ?? '' }
			}
			return out
		},
		objectsEqual(a, b) {
			return JSON.stringify(a ?? {}) === JSON.stringify(b ?? {})
		},
		addRow() {
			this.rows.push({ uid: uid(), label: '', type: 'text', value: '' })
			this.emitChange()
		},
		removeRow(index) {
			this.rows.splice(index, 1)
			this.emitChange()
		},
		emitChange() {
			this.$emit('input', this.rowsToObject(this.rows))
		},
	},
}
</script>

<style scoped>
.additional-fields-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.additional-fields-editor__row-wrapper {
	border-left: 3px solid transparent;
	padding-left: 8px;
	transition: border-color 0.15s ease;
}

.additional-fields-editor__row-wrapper--modified {
	border-left-color: var(--color-primary-element);
}

.additional-fields-editor__row {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.additional-fields-editor__row-controls {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.additional-fields-editor__label-input {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
}

.additional-fields-editor__type-select {
	flex: 0 0 160px;
	min-width: 120px;
	margin: 0;
}

.additional-fields-editor__value-input {
	width: 100%;
}

.additional-fields-editor__warning {
	font-size: 0.8125rem;
	color: var(--color-warning, var(--color-error));
}

.additional-fields-editor__add {
	align-self: flex-start;
}
</style>
