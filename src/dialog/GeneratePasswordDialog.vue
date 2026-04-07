<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Generate password')"
		size="small"
		@update:open="$emit('update:open', $event)">
		<div class="generate-password">
			<p class="generate-password__subtitle">
				{{ t('doriath', 'Configure your password criteria and generate a cryptographically secure random password.') }}
			</p>

			<div class="generate-password__section">
				<h4 class="generate-password__section-label">
					{{ t('doriath', 'Criteria') }}
				</h4>

				<div class="generate-password__option">
					<label class="generate-password__option-label" for="gen-length">
						{{ t('doriath', 'Length') }}
					</label>
					<div class="generate-password__length-control">
						<input
							id="gen-length"
							v-model.number="length"
							type="range"
							:min="minLength"
							:max="128"
							class="generate-password__slider">
						<NcInputField
							:model-value="String(length)"
							type="number"
							:label="t('doriath', 'Length')"
							:label-visible="false"
							:min="minLength"
							:max="128"
							class="generate-password__length-input"
							@update:model-value="onLengthInput"
							@blur="clampLength" />
					</div>
				</div>

				<div class="generate-password__toggles">
					<NcCheckboxRadioSwitch
						:checked="lowercase"
						type="switch"
						@update:checked="lowercase = $event">
						{{ t('doriath', 'Lowercase (a-z)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="uppercase"
						type="switch"
						@update:checked="uppercase = $event">
						{{ t('doriath', 'Uppercase (A-Z)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="numbers"
						type="switch"
						@update:checked="numbers = $event">
						{{ t('doriath', 'Numbers (0-9)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="symbols"
						type="switch"
						@update:checked="symbols = $event">
						{{ t('doriath', 'Symbols (!@#$%...)') }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcNoteCard v-if="!hasAnyCriteria" type="warning">
					{{ t('doriath', 'Select at least one character type.') }}
				</NcNoteCard>
			</div>

			<div class="generate-password__section">
				<h4 class="generate-password__section-label">
					{{ t('doriath', 'Preview') }}
				</h4>
				<div class="generate-password__preview-row">
					<code class="generate-password__preview">{{ generated }}</code>
					<NcButton
						type="tertiary"
						:aria-label="t('doriath', 'Regenerate')"
						@click="generate">
						<template #icon>
							<RefreshIcon :size="20" />
						</template>
					</NcButton>
				</div>
				<PasswordStrengthMeter
					v-if="generated"
					:password="generated"
					:min-length="minLength" />
			</div>

			<div class="generate-password__actions">
				<NcButton type="tertiary" @click="$emit('update:open', false)">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="generated.length < minLength || !generated"
					@click="accept">
					{{ t('doriath', 'Use password') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcInputField, NcNoteCard } from '@nextcloud/vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'

const CHARSETS = {
	uppercase: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
	lowercase: 'abcdefghijklmnopqrstuvwxyz',
	numbers: '0123456789',
	symbols: '!@#$%^&*()-_=+[]{}|;:,.<>?/',
}

/**
 * Generate cryptographically secure random bytes using crypto.subtle.
 *
 * Generates AES-256 keys via the WebCrypto API and exports them as raw
 * bytes. Each key yields 32 bytes of hardware-sourced entropy.
 *
 * @param {number} count - Number of random bytes needed
 * @return {Promise<Uint8Array>} The random bytes
 */
async function secureRandomBytes(count) {
	const keysNeeded = Math.ceil(count / 32)
	const allBytes = new Uint8Array(keysNeeded * 32)

	for (let i = 0; i < keysNeeded; i++) {
		const key = await crypto.subtle.generateKey(
			{ name: 'AES-GCM', length: 256 },
			true,
			['encrypt'],
		)
		const raw = await crypto.subtle.exportKey('raw', key)
		allBytes.set(new Uint8Array(raw), i * 32)
	}

	return allBytes.subarray(0, count)
}

/**
 * Generate a cryptographically secure random password.
 *
 * Uses crypto.subtle.generateKey() (WebCrypto CSPRNG) with rejection
 * sampling to avoid modulo bias.
 *
 * @param {number} len - Desired password length
 * @param {string} charset - String of allowed characters
 * @return {Promise<string>} The generated password
 */
async function secureRandomPassword(len, charset) {
	const result = new Array(len)
	const maxValid = 256 - (256 % charset.length)

	// Over-provision bytes to account for rejection sampling
	let pool = await secureRandomBytes(len * 2)
	let poolIdx = 0

	for (let i = 0; i < len; i++) {
		let byte
		do {
			if (poolIdx >= pool.length) {
				pool = await secureRandomBytes(len)
				poolIdx = 0
			}
			byte = pool[poolIdx++]
		} while (byte >= maxValid)
		result[i] = charset[byte % charset.length]
	}

	return result.join('')
}

/**
 * Shuffle an array in-place using Fisher-Yates with WebCrypto CSPRNG.
 *
 * @param {Array} arr - The array to shuffle
 * @return {Promise<void>}
 */
async function secureShuffle(arr) {
	const bytes = await secureRandomBytes(arr.length * 2)
	let byteIdx = 0

	for (let i = arr.length - 1; i > 0; i--) {
		const maxValid = 256 - (256 % (i + 1))
		let byte
		do {
			if (byteIdx >= bytes.length) {
				// Extremely unlikely but handle gracefully
				const extra = await secureRandomBytes(arr.length)
				bytes.set(extra)
				byteIdx = 0
			}
			byte = bytes[byteIdx++]
		} while (byte >= maxValid)
		const j = byte % (i + 1);
		[arr[i], arr[j]] = [arr[j], arr[i]]
	}
}

export default {
	name: 'GeneratePasswordDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcInputField,
		NcNoteCard,
		PasswordStrengthMeter,
		RefreshIcon,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		minLength: {
			type: Number,
			default: 12,
		},
	},
	emits: ['update:open', 'accept'],
	data() {
		return {
			length: 24,
			uppercase: true,
			lowercase: true,
			numbers: true,
			symbols: true,
			generated: '',
		}
	},
	computed: {
		hasAnyCriteria() {
			return this.uppercase || this.lowercase || this.numbers || this.symbols
		},
		charset() {
			let cs = ''
			if (this.uppercase) cs += CHARSETS.uppercase
			if (this.lowercase) cs += CHARSETS.lowercase
			if (this.numbers) cs += CHARSETS.numbers
			if (this.symbols) cs += CHARSETS.symbols
			return cs
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.generate()
			}
		},
		length() {
			this.generate()
		},
		charset() {
			this.generate()
		},
	},
	methods: {
		onLengthInput(val) {
			const n = parseInt(val, 10)
			if (!isNaN(n)) {
				this.length = Math.min(128, n)
			}
		},
		clampLength() {
			this.length = Math.max(this.minLength, Math.min(128, this.length))
		},
		async generate() {
			if (!this.hasAnyCriteria) {
				this.generated = ''
				return
			}

			const chars = (await secureRandomPassword(this.length, this.charset)).split('')

			const guarantees = []
			if (this.uppercase) guarantees.push(CHARSETS.uppercase)
			if (this.lowercase) guarantees.push(CHARSETS.lowercase)
			if (this.numbers) guarantees.push(CHARSETS.numbers)
			if (this.symbols) guarantees.push(CHARSETS.symbols)

			// Place one guaranteed character from each enabled set
			// at the first N positions, then shuffle
			for (let i = 0; i < guarantees.length && i < chars.length; i++) {
				chars[i] = await secureRandomPassword(1, guarantees[i])
			}

			await secureShuffle(chars)
			this.generated = chars.join('')
		},
		accept() {
			this.$emit('accept', this.generated)
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.generate-password {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0 0 4px;
}

.generate-password__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 0 0 8px;
}

.generate-password__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.generate-password__section + .generate-password__section {
	border-top: 1px solid var(--color-border);
}

.generate-password__section-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0;
}

.generate-password__option {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.generate-password__option-label {
	font-size: 0.85rem;
	font-weight: 600;
}

.generate-password__length-control {
	display: flex;
	align-items: center;
	gap: 12px;
}

.generate-password__slider {
	flex: 1;
	accent-color: var(--color-primary-element);
}

.generate-password__length-input {
	width: 72px;
	flex-shrink: 0;
}

.generate-password__toggles {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 4px 16px;
}

.generate-password__preview-row {
	display: flex;
	align-items: center;
	gap: 4px;
}

.generate-password__preview {
	flex: 1;
	padding: 10px 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	font-size: 0.95rem;
	word-break: break-all;
	line-height: 1.4;
	min-height: 44px;
	display: flex;
	align-items: center;
}

.generate-password__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 4px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.generate-password__actions:last-child {
	margin-bottom: 0.5rem;
}
</style>
