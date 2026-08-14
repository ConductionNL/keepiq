<template>
	<div class="password-strength-meter">
		<div class="password-strength-meter__bar">
			<div
				class="password-strength-meter__fill"
				:class="`password-strength-meter__fill--${scoreClass}`"
				:style="{ width: `${(score / 4) * 100}%` }" />
		</div>
		<p
			class="password-strength-meter__feedback"
			:class="`password-strength-meter__feedback--${scoreClass}`">
			{{ feedbackText }}
		</p>
	</div>
</template>

<script>
import zxcvbn from 'zxcvbn'
import { fetchPolicy } from '../policy/policy.js'

/** Floors used when neither the caller nor the server supplies one. */
const FALLBACK_MIN_LENGTH = 12
const FALLBACK_MIN_SCORE = 3

export default {
	name: 'PasswordStrengthMeter',

	props: {
		password: {
			type: String,
			required: true,
		},
		/**
		 * Explicit score floor. `null` (the default) means "use the
		 * administrator's master-password policy"; no caller in the app
		 * passes one, which is why the admin setting has to reach this
		 * component through the policy endpoint (#192).
		 */
		minScore: {
			type: Number,
			default: null,
		},
		/** Explicit length floor; `null` defers to the admin policy. */
		minLength: {
			type: Number,
			default: null,
		},
	},

	emits: ['strength-change'],

	data() {
		return {
			score: 0,
			feedback: null,
			debounceTimer: null,
			policyMinLength: null,
			policyMinScore: null,
		}
	},

	computed: {
		/**
		 * The length floor actually enforced: an explicit prop wins, then
		 * the administrator's stored policy, then the app fallback.
		 *
		 * @return {number} Minimum master-password length.
		 * @spec openspec/specs/admin-settings/spec.md#requirement-master-password-policy-mvp
		 */
		effectiveMinLength() {
			return this.minLength ?? this.policyMinLength ?? FALLBACK_MIN_LENGTH
		},

		/**
		 * The zxcvbn score floor actually enforced.
		 *
		 * @return {number} Minimum zxcvbn score.
		 * @spec openspec/specs/admin-settings/spec.md#requirement-master-password-policy-mvp
		 */
		effectiveMinScore() {
			return this.minScore ?? this.policyMinScore ?? FALLBACK_MIN_SCORE
		},

		/**
		 * Map the zxcvbn score (0-4) to a severity colour class for the bar.
		 *
		 * @return {string} Severity token.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		scoreClass() {
			const classes = ['danger', 'danger', 'warning', 'success', 'success']
			return classes[this.score] || 'danger'
		},

		/**
		 * Human-readable strength feedback: minimum-length hint, zxcvbn
		 * warning, or a strength label keyed by score.
		 *
		 * @return {string} Feedback text.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		feedbackText() {
			if (!this.password) return ''
			if (this.password.length < this.effectiveMinLength) {
				return t('doriath', 'At least {length} characters required', {
					length: this.effectiveMinLength,
				})
			}
			if (this.feedback?.warning) return this.feedback.warning
			const labels = [
				t('doriath', 'Very weak'),
				t('doriath', 'Weak'),
				t('doriath', 'Fair'),
				t('doriath', 'Strong'),
				t('doriath', 'Very strong'),
			]
			return labels[this.score]
		},

		isValid() {
			return (
				this.password.length >= this.effectiveMinLength
				&& this.score >= this.effectiveMinScore
			)
		},
	},

	watch: {
		/**
		 * Debounce strength re-evaluation as the password changes (300ms).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		password() {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.evaluate(), 300)
		},
	},

	/**
	 * Score the initial value, then pull the administrator's floors.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-master-password-policy-mvp
	 */
	created() {
		this.evaluate()
		this.loadPolicyFloors()
	},

	methods: {
		/**
		 * Pull the administrator's master-password floors from the read-only
		 * policy endpoint and re-evaluate, so a form that passed under the
		 * fallback floor is re-judged against the floor the admin actually
		 * configured. Failures leave the fallbacks in place — the meter must
		 * never become permissive because a request failed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md#requirement-master-password-policy-mvp
		 */
		async loadPolicyFloors() {
			try {
				const policy = await fetchPolicy()
				const length = Number(policy?.master_password_min_length)
				const score = Number(policy?.master_password_min_score)
				if (Number.isFinite(length) && length > 0) {
					this.policyMinLength = length
				}
				if (Number.isFinite(score) && score > 0) {
					this.policyMinScore = score
				}
			} catch (e) {
				// Keep the fallback floors. Swallowing here is deliberate: the
				// only alternative outcome is a more permissive meter, and a
				// failed policy read must never lower the bar.
			}
			this.evaluate()
		},

		/**
		 * Run zxcvbn on the current password, store the score + feedback,
		 * and emit a strength-change event with validity to the parent form.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		evaluate() {
			if (!this.password) {
				this.score = 0
				this.feedback = null
				this.$emit('strength-change', { isValid: false, score: 0 })
				return
			}
			const result = zxcvbn(this.password)
			this.score = result.score
			this.feedback = result.feedback
			this.$emit('strength-change', {
				isValid: this.isValid,
				score: this.score,
			})
		},
	},
}
</script>

<style scoped>
.password-strength-meter__bar {
	height: 4px;
	background: var(--color-background-dark);
	border-radius: 2px;
	overflow: hidden;
}

.password-strength-meter__fill {
	height: 100%;
	transition:
		width 0.3s ease,
		background 0.3s ease;
}

/*
 * WCAG 2.2 AA 2.3.3 Animation from Interactions. The bar still moves to its
 * new width and colour — only the tween is dropped — so the strength reading
 * is never lost, just delivered without motion.
 */
@media (prefers-reduced-motion: reduce) {
	.password-strength-meter__fill {
		transition: none;
	}
}

/*
 * The fill sits on a --color-background-dark track, so it needs the CONTRASTY
 * member of each colour family rather than the background tint. The tints are
 * near-invisible here: --color-success is #11321A in dark mode, which on a dark
 * grey track at 4px high reads as an empty bar. The *-text values are the ones
 * the theme keeps legible against the main background in both themes.
 */
.password-strength-meter__fill--danger {
	background: var(--color-error-text);
}

.password-strength-meter__fill--warning {
	background: var(--color-warning-text);
}

.password-strength-meter__fill--success {
	background: var(--color-success-text);
}

.password-strength-meter__feedback {
	font-size: 0.85rem;
	margin: 0.25rem 0 0;
}

.password-strength-meter__feedback--danger {
	color: var(--color-error-text);
}

.password-strength-meter__feedback--warning {
	color: var(--color-warning-text);
}

.password-strength-meter__feedback--success {
	color: var(--color-success-text);
}
</style>
