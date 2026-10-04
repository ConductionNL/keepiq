// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import org.jetbrains.kotlin.gradle.ExperimentalKotlinGradlePluginApi
import org.jetbrains.kotlin.gradle.dsl.JvmTarget
import java.util.Base64

plugins {
    alias(libs.plugins.kotlin.multiplatform)
    alias(libs.plugins.kotlin.serialization)
}

val withAndroid = gradle.extensions.extraProperties["keepiq.android"] as Boolean
if (withAndroid) {
    apply(plugin = "com.android.library")
    extensions.configure<com.android.build.gradle.LibraryExtension>("android") {
        namespace = "nl.conduction.keepiq.shared"
        compileSdk = libs.versions.android.compileSdk.get().toInt()
        defaultConfig {
            minSdk = libs.versions.android.minSdk.get().toInt()
        }
        compileOptions {
            sourceCompatibility = JavaVersion.VERSION_17
            targetCompatibility = JavaVersion.VERSION_17
        }
    }
}

kotlin {
    jvm {
        compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
    }
    if (withAndroid) {
        androidTarget {
            compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
        }
    }
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach { target ->
        target.binaries.framework {
            baseName = "KeepiqShared"
            isStatic = true
        }
    }

    // jvmShared holds the javax.crypto / java.security actuals that the jvm()
    // test target and Android share (design D2: Android uses the platform JCA).
    @OptIn(ExperimentalKotlinGradlePluginApi::class)
    applyDefaultHierarchyTemplate {
        common {
            group("jvmShared") {
                withJvm()
                withAndroidTarget()
            }
        }
    }

    sourceSets {
        commonMain.dependencies {
            implementation(libs.kotlinx.serialization.json)
        }
        commonTest.dependencies {
            implementation(kotlin("test"))
        }
        getByName("jvmSharedMain").dependencies {
            implementation(libs.bouncycastle.prov)
        }
        iosMain.dependencies {
            implementation(libs.cryptography.core)
            implementation(libs.cryptography.provider.apple)
            implementation(libs.cryptography.provider.cryptokit)
        }
    }
}

// The shared vectors in tests/vectors/crypto/ are compiled into commonTest as
// base64 constants, so every target (jvm, Android unit tests, the iOS
// simulator) reads the same bytes without file access.
val vectorsDir = layout.projectDirectory.dir("../../tests/vectors/crypto")
val generatedVectors = layout.buildDirectory.dir("generated/vectors/kotlin")
val generateCryptoVectors by tasks.registering {
    inputs.dir(vectorsDir).withPathSensitivity(PathSensitivity.RELATIVE)
    outputs.dir(generatedVectors)
    val sourceDir = vectorsDir.asFile
    val outDir = generatedVectors.get().asFile
    doLast {
        val names = listOf("envelope", "fields", "send", "totp", "passkey")
        val body = StringBuilder()
        body.append("// Generated from tests/vectors/crypto by :shared:generateCryptoVectors. Do not edit.\n")
        body.append("package nl.conduction.keepiq.shared.vectors\n\n")
        body.append("internal object GeneratedVectors {\n")
        for (name in names) {
            val encoded = Base64.getEncoder().encodeToString(File(sourceDir, "$name.json").readBytes())
            val parts = encoded.chunked(16_000).joinToString(",\n        ") { "\"$it\"" }
            body.append("    val $name: List<String> = listOf(\n        $parts,\n    )\n")
        }
        body.append("}\n")
        val target = File(outDir, "nl/conduction/keepiq/shared/vectors/GeneratedVectors.kt")
        target.parentFile.mkdirs()
        target.writeText(body.toString())
    }
}
kotlin.sourceSets.commonTest {
    kotlin.srcDir(generateCryptoVectors)
}

// The jvm tests write ciphertext produced by this core for the vector inputs.
// tests/vitest/crypto-vectors-kotlin.spec.js opens it with the web modules.
// -Pkeepiq.writeKotlinVectors=true writes the committed copy instead.
val kotlinVectorsOut = if (providers.gradleProperty("keepiq.writeKotlinVectors").orNull == "true") {
    vectorsDir.file("kotlin-output.json").asFile
} else {
    layout.buildDirectory.file("vectors/kotlin-output.json").get().asFile
}
tasks.named<Test>("jvmTest") {
    systemProperty("keepiq.kotlinVectorsOut", kotlinVectorsOut.absolutePath)
    outputs.upToDateWhen { false }
}
