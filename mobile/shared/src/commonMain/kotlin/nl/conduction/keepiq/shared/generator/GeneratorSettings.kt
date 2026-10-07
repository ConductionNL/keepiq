// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.generator

/** Password or passphrase: the generator screen's two modes. */
enum class GeneratorMode { PASSWORD, PASSPHRASE }

/** What the generator screen edits and the app remembers per account. */
data class GeneratorSettings(
    val mode: GeneratorMode = GeneratorMode.PASSWORD,
    val password: PasswordOptions = DEFAULT_PASSWORD,
    val passphrase: PassphraseOptions = PassphraseOptions(),
) {
    /**
     * The settings made safe (browser-extension/src/lib/generator-state.js
     * sanitizeOptions): numbers clamped to the supported ranges and raised to
     * the policy, the classes the policy requires switched on, a passphrase
     * mode the organisation switched off turned back to password.
     */
    fun sanitized(policy: GeneratorPolicy?): GeneratorSettings {
        var p = password.copy(
            length = password.length.coerceIn(Generator.MIN_LENGTH, Generator.MAX_LENGTH),
            minDigits = password.minDigits.coerceIn(0, 9),
            minSpecial = password.minSpecial.coerceIn(0, 9),
            regex = null,
        )
        if (policy != null) {
            p = p.copy(
                length = maxOf(p.length, policy.minLength),
                includeUppercase = p.includeUppercase || policy.requireUpper,
                includeLowercase = p.includeLowercase || policy.requireLower,
                includeDigits = p.includeDigits || policy.requireDigit,
                includeSpecialCharacters = p.includeSpecialCharacters || policy.requireSymbol,
                minDigits = if (policy.requireDigit) maxOf(p.minDigits, 1) else p.minDigits,
                minSpecial = if (policy.requireSymbol) maxOf(p.minSpecial, 1) else p.minSpecial,
            )
        }
        if (!p.includeUppercase && !p.includeLowercase && !p.includeDigits && !p.includeSpecialCharacters) {
            p = p.copy(includeLowercase = true)
        }
        val w = passphrase.copy(
            words = passphrase.words.coerceIn(Generator.MIN_WORDS, Generator.MAX_WORDS),
            separator = passphrase.separator.take(3),
        )
        val m = if (mode == GeneratorMode.PASSPHRASE && policy != null && !policy.allowPassphrase) GeneratorMode.PASSWORD else mode
        return GeneratorSettings(m, p, w)
    }

    /** Generates one value with these settings under [policy]. */
    fun generate(policy: GeneratorPolicy?, rand: (Int, Int) -> Int = Generator.secureRandomInt): String {
        val safe = sanitized(policy)
        return when (safe.mode) {
            GeneratorMode.PASSWORD -> Generator.generateKey(safe.password, policy, rand)
            GeneratorMode.PASSPHRASE -> Generator.generatePassphrase(safe.passphrase, policy, rand)
        }
    }

    /** [generate] without throwing: the value, or why it was refused. For Swift. */
    fun tryGenerate(policy: GeneratorPolicy?): GeneratorOutcome = try {
        GeneratorOutcome(generate(policy), null)
    } catch (e: GeneratorException) {
        GeneratorOutcome(null, e.code)
    }

    /** The shortest password length the screen may offer under [policy]. */
    fun minimumLength(policy: GeneratorPolicy?): Int = maxOf(Generator.MIN_LENGTH, policy?.minLength ?: 0)

    companion object {
        /** DEFAULT_OPTIONS.password of the extension, close to Bitwarden's defaults. */
        val DEFAULT_PASSWORD = PasswordOptions(
            length = 14,
            includeUppercase = true,
            includeLowercase = true,
            includeDigits = true,
            includeSpecialCharacters = false,
            minDigits = 1,
            minSpecial = 1,
            avoidAmbiguous = true,
        )
    }
}

/** A generated value, or the code of the refusal. */
data class GeneratorOutcome(val value: String?, val error: GeneratorErrorCode?) {
    override fun toString(): String = "GeneratorOutcome(error=$error)"
}
