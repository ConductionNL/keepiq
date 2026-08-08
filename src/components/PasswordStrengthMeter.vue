<template>
	<div class="password-strength-meter">
		<div class="password-strength-meter__bar">
			<div
				class="password-strength-meter__fill"
				:class="`password-strength-meter__fill--${scoreClass}`"
				:style="{ width: `${(score / 4) * 100}%` }" />
		</div>
		<p class="password-strength-meter__feedback" :class="`password-strength-meter__feedback--${scoreClass}`">
			{{ feedbackText }}
		</p>
	</div>
</template>

<script>
import zxcvbn from 'zxcvbn'

export default {
	name: 'PasswordStrengthMeter',

	props: {
		password: {
			type: String,
			required: true,
		},
		minScore: {
			type: Number,
			default: 3,
		},
		minLength: {
			type: Number,
			default: 12,
		},
	},

	emits: ['strength-change'],

	data() {
		return {
			score: 0,
			feedback: null,
			debounceTimer: null,
		}
	},

	computed: {
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
			if (this.password.length < this.minLength) {
				return t('doriath', 'At least {length} characters required', { length: this.minLength })
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
			return this.password.length >= this.minLength && this.score >= this.minScore
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

	created() {
		this.evaluate()
	},

	methods: {
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
			this.$emit('strength-change', { isValid: this.isValid, score: this.score })
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
	transition: width 0.3s ease, background 0.3s ease;
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

.password-strength-meter__fill--danger { background: var(--color-error); }

.password-strength-meter__fill--warning { background: var(--color-warning); }

.password-strength-meter__fill--success { background: var(--color-success); }

.password-strength-meter__feedback {
	font-size: 0.85rem;
	margin: 0.25rem 0 0;
}

.password-strength-meter__feedback--danger { color: var(--color-error); }

.password-strength-meter__feedback--warning { color: var(--color-warning); }

.password-strength-meter__feedback--success { color: var(--color-success); }
</style>
