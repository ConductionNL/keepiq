<template>
	<div class="totp-display" data-testid="totp-display">
		<div v-if="invalid" class="totp-display__invalid" data-testid="totp-invalid">
			<AlertCircleOutline :size="20" />
			<span>{{ t('doriath', 'Not a valid authenticator secret') }}</span>
		</div>

		<template v-else-if="code">
			<div v-if="account || issuer" class="totp-display__label">
				<span v-if="issuer" class="totp-display__issuer">{{ issuer }}</span>
				<span v-if="account" class="totp-display__account">{{ account }}</span>
			</div>

			<div class="totp-display__row">
				<span class="totp-display__code" data-testid="totp-code">{{ spacedCode }}</span>

				<svg class="totp-display__ring" viewBox="0 0 36 36" aria-hidden="true">
					<circle class="totp-display__ring-track"
						cx="18"
						cy="18"
						r="16" />
					<circle class="totp-display__ring-progress"
						cx="18"
						cy="18"
						r="16"
						:stroke-dasharray="ringCircumference"
						:stroke-dashoffset="ringOffset" />
				</svg>
				<span class="totp-display__countdown"
					data-testid="totp-countdown"
					:aria-label="countdownLabel">{{ remaining }}s</span>

				<CopyButton :value="code"
					:label="t('doriath', 'Copy one-time code')"
					data-testid="totp-copy" />
			</div>
		</template>

		<div v-else class="totp-display__loading">
			<NcLoadingIcon :size="20" />
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CopyButton from './CopyButton.vue'
import { parseOtpauth, generateTotp, secondsRemaining } from '../totp/totp.js'
import { useSessionStore, onVaultLock } from '../store/modules/session.js'

/**
 * Renders the live RFC 6238 one-time code for a `totp` secret, computed
 * entirely in the browser from the decrypted seed. The seed, derived HMAC key,
 * and generated code live only in this component's memory; they are discarded
 * on vault lock and on component destroy — matching the password-health no-leak
 * contract. Nothing here is transmitted to the server or written to any Web
 * Storage API. An unparseable seed shows an explicit invalid state and never a
 * fabricated code.
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */
export default {
	name: 'TotpDisplay',

	components: {
		NcLoadingIcon,
		AlertCircleOutline,
		CopyButton,
	},

	props: {
		/**
		 * The decrypted TOTP seed (otpauth:// URI or bare base32 secret).
		 *
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		seed: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** @type {object|null} Parsed TOTP params (memory only). */
			params: null,
			/** @type {string} The current one-time code (memory only). */
			code: '',
			/** @type {number} Seconds left in the current window. */
			remaining: 0,
			/** @type {boolean} Whether the seed failed to parse. */
			invalid: false,
			/** @type {?number} The recompute interval handle. */
			timer: null,
		}
	},

	computed: {
		/**
		 * The code grouped for readability (e.g. "123 456").
		 *
		 * @return {string} The spaced code.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		spacedCode() {
			if (!this.code) {
				return ''
			}
			const mid = Math.ceil(this.code.length / 2)
			return `${this.code.slice(0, mid)} ${this.code.slice(mid)}`
		},
		/**
		 * The issuer for display.
		 *
		 * @return {(string|null)} The issuer.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		issuer() {
			return this.params ? this.params.issuer : null
		},
		/**
		 * The account name for display.
		 *
		 * @return {(string|null)} The account.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		account() {
			return this.params ? this.params.account : null
		},
		/**
		 * The SVG ring circumference (r=16).
		 *
		 * @return {number} The circumference.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		ringCircumference() {
			return 2 * Math.PI * 16
		},
		/**
		 * The stroke offset representing time elapsed in the current window.
		 *
		 * @return {number} The stroke-dashoffset.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		ringOffset() {
			if (!this.params) {
				return 0
			}
			const fraction = this.remaining / this.params.period
			return this.ringCircumference * (1 - fraction)
		},
		/**
		 * The accessible countdown label.
		 *
		 * @return {string} The label text.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		countdownLabel() {
			return t('doriath', '{seconds} seconds until the code refreshes', { seconds: this.remaining })
		},
	},

	watch: {
		/**
		 * Restart the generator when the decrypted seed changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		seed() {
			this.start()
		},
	},

	/**
	 * Register the vault-lock discard hook and start the generator.
	 *
	 * @return {void}
	 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
	 */
	mounted() {
		// Discard all TOTP state when the vault locks (no seed/key/code survives
		// a locked vault — password-health no-leak contract parity).
		onVaultLock(this.discard)
		this.start()
	},

	/**
	 * Discard all TOTP state when the component is torn down.
	 *
	 * @return {void}
	 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
	 */
	beforeUnmount() {
		this.discard()
	},

	methods: {
		t,

		/**
		 * Parse the seed and begin the per-second recompute loop. Refuses when
		 * the vault is locked or the seed is unparseable.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		async start() {
			this.discard()
			const session = useSessionStore()
			if (session.isLocked || !this.seed) {
				return
			}
			try {
				this.params = parseOtpauth(this.seed)
			} catch {
				this.invalid = true
				this.params = null
				return
			}
			this.invalid = false
			await this.tick()
			this.timer = setInterval(() => {
				this.tick()
			}, 1000)
		},

		/**
		 * Recompute the code + countdown for the current instant. Aborts and
		 * discards if the vault locked between ticks.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		async tick() {
			const session = useSessionStore()
			if (session.isLocked || !this.params) {
				this.discard()
				return
			}
			this.remaining = secondsRemaining(this.params.period)
			this.code = await generateTotp(this.params)
		},

		/**
		 * Drop every byte of TOTP state (seed-derived params, code, timer). Called
		 * on vault lock, seed change, and destroy so nothing survives a lock.
		 *
		 * @return {void}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		discard() {
			if (this.timer !== null) {
				clearInterval(this.timer)
				this.timer = null
			}
			this.params = null
			this.code = ''
			this.remaining = 0
			this.invalid = false
		},
	},
}
</script>

<style scoped>
.totp-display {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.totp-display__label {
	display: flex;
	gap: 8px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.totp-display__issuer {
	font-weight: 600;
}

.totp-display__row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.totp-display__code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 1.4rem;
	letter-spacing: 0.12em;
	color: var(--color-main-text);
}

.totp-display__ring {
	width: 24px;
	height: 24px;
	transform: rotate(-90deg);
}

.totp-display__ring-track {
	fill: none;
	stroke: var(--color-background-darker);
	stroke-width: 3;
}

.totp-display__ring-progress {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 3;
	stroke-linecap: round;
	transition: stroke-dashoffset 1s linear;
}

/*
 * WCAG 2.2 AA 2.3.3. The ring still steps to each new offset once per second,
 * so the time-remaining cue survives; only the continuous sweep is removed.
 * The numeric countdown in .totp-display__countdown carries the same
 * information without any motion at all.
 */
@media (prefers-reduced-motion: reduce) {
	.totp-display__ring-progress {
		transition: none;
	}
}

.totp-display__countdown {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	min-width: 2.5em;
}

.totp-display__invalid {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-warning-text, var(--color-warning));
}
</style>
