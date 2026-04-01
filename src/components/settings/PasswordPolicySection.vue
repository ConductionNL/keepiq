<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<CnSettingsSection :name="t('app-template', 'Master Password Policy')"
		:description="t('app-template', 'Set minimum length and strength requirements for master passwords.')">
		<div class="password-policy">
			<!-- Minimum length slider (12–20) -->
			<div class="password-policy__field">
				<label class="password-policy__label" for="pw-min-length">
					{{ t('app-template', 'Minimum length') }}:
					<strong>{{ form.passwordMinLength }}</strong>
				</label>
				<input id="pw-min-length"
					v-model.number="form.passwordMinLength"
					type="range"
					min="12"
					max="20"
					step="1"
					class="password-policy__slider"
					@change="save">
				<div class="password-policy__range-hints">
					<span>12</span><span>20</span>
				</div>
			</div>

			<!-- Minimum score selector (3 or 4) -->
			<div class="password-policy__field">
				<label class="password-policy__label" for="pw-min-score">
					{{ t('app-template', 'Minimum strength score') }}
				</label>
				<NcSelect v-model="form.passwordMinScore"
					input-id="pw-min-score"
					:options="scoreOptions"
					:reduce="(o) => o.value"
					label="label"
					:clearable="false"
					@update:model-value="save" />
			</div>

			<div v-if="saved" class="password-policy__saved">
				{{ t('app-template', 'Saved') }}
			</div>
		</div>
	</CnSettingsSection>
</template>

<script setup>
/**
 * PasswordPolicySection
 *
 * Admin section that allows configuring master-password policy:
 * minimum length (12–20) via a range slider and minimum strength
 * score (3–4) via a select.
 */
import { ref, watch } from 'vue'
import { NcSelect } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useAdminSettingsStore } from '../../store/modules/adminSettings.js'

const adminSettingsStore = useAdminSettingsStore()

const form = ref({
	passwordMinLength: adminSettingsStore.settings.passwordMinLength ?? 12,
	passwordMinScore: adminSettingsStore.settings.passwordMinScore ?? 3,
})

const saved = ref(false)

watch(() => adminSettingsStore.settings, (s) => {
	form.value.passwordMinLength = s.passwordMinLength ?? 12
	form.value.passwordMinScore = s.passwordMinScore ?? 3
}, { deep: true })

const scoreOptions = [
	{ value: 3, label: t('app-template', '3 — Strong') },
	{ value: 4, label: t('app-template', '4 — Very strong') },
]

/**
 *
 */
async function save() {
	await adminSettingsStore.updateSettings({
		passwordMinLength: form.value.passwordMinLength,
		passwordMinScore: form.value.passwordMinScore,
	})
	saved.value = true
	setTimeout(() => { saved.value = false }, 2000)
}
</script>

<style scoped>
.password-policy {
	display: flex;
	flex-direction: column;
	gap: 20px;
	max-width: 400px;
}

.password-policy__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.password-policy__label {
	font-weight: 600;
	font-size: 0.9rem;
}

.password-policy__slider {
	width: 100%;
	cursor: pointer;
}

.password-policy__range-hints {
	display: flex;
	justify-content: space-between;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.password-policy__saved {
	color: var(--color-success);
	font-size: 0.85rem;
}
</style>
