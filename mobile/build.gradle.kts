// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

plugins {
    alias(libs.plugins.kotlin.multiplatform) apply false
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.android.library) apply false
    alias(libs.plugins.android.application) apply false
}

// Resolves every configuration of every project, so the dependency
// verification metadata covers the Android and iOS artifacts too, also on a
// machine without an Android SDK or macOS. Regenerate the metadata with:
//   ./gradlew --write-verification-metadata sha256 -Pkeepiq.android=true resolveAllDependencies
// then add the macOS Kotlin/Native toolchain by hand (see the comment in
// gradle/verification-metadata.xml).
fun touch(configuration: Configuration) =
    configuration.resolvedConfiguration.lenientConfiguration.allModuleDependencies
        .flatMap { it.moduleArtifacts }
        .forEach { it.file }

tasks.register("resolveAllDependencies") {
    notCompatibleWithConfigurationCache("walks every configuration of every project")
    doLast {
        allprojects.forEach { project ->
            project.configurations.filter { it.isCanBeResolved }.forEach { configuration ->
                // Project dependencies are left out: their own configurations
                // are resolved in turn, and Android variants of a sibling
                // project are ambiguous without the variant attributes.
                val resolved = runCatching { touch(configuration) }.recoverCatching {
                    touch(configuration.copyRecursive { it !is ProjectDependency })
                }
                if (resolved.isFailure) logger.lifecycle("skipped ${project.path}:${configuration.name}")
            }
        }
        // AGP fetches aapt2 for the build host through a configuration of its
        // own; CI builds on Linux.
        val aapt2 = libs.aapt2.linux.get()
        touch(configurations.detachedConfiguration(dependencies.create("${aapt2.module}:${aapt2.version}:linux")))
    }
}
