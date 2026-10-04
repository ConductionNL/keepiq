// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import org.jetbrains.kotlin.gradle.ExperimentalKotlinGradlePluginApi
import org.jetbrains.kotlin.gradle.dsl.JvmTarget
import org.jetbrains.kotlin.gradle.plugin.mpp.apple.XCFramework
import java.util.Base64

plugins {
    alias(libs.plugins.kotlin.multiplatform)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.sqldelight)
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
    compilerOptions {
        // expect/actual objects (Primitives) are Beta in Kotlin 2.2.
        freeCompilerArgs.add("-Xexpect-actual-classes")
    }
    jvm {
        compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
    }
    if (withAndroid) {
        androidTarget {
            compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
        }
    }
    // mobile/ios links this as KeepiqShared.xcframework.
    val xcframework = XCFramework("KeepiqShared")
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach { target ->
        target.binaries.framework {
            baseName = "KeepiqShared"
            isStatic = true
            xcframework.add(this)
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
            implementation(libs.kotlinx.coroutines.core)
            implementation(libs.ktor.client.core)
        }
        commonTest.dependencies {
            implementation(kotlin("test"))
            implementation(libs.kotlinx.coroutines.test)
            implementation(libs.ktor.client.mock)
        }
        getByName("jvmSharedMain").dependencies {
            implementation(libs.bouncycastle.prov)
            implementation(libs.ktor.client.okhttp)
        }
        jvmTest.dependencies {
            implementation(libs.sqldelight.sqlite.driver)
        }
        if (withAndroid) {
            androidMain.dependencies {
                implementation(libs.sqldelight.android.driver)
                implementation(libs.sqlcipher.android)
                implementation(libs.androidx.sqlite)
            }
        }
        iosMain.dependencies {
            implementation(libs.ktor.client.darwin)
            implementation(libs.cryptography.core)
            implementation(libs.cryptography.provider.apple)
            implementation(libs.cryptography.provider.cryptokit)
        }
    }
}

// The offline store (design D5). SQLCipher on Android; see openEncryptedDriver.
sqldelight {
    databases {
        create("KeepiqDatabase") {
            packageName.set("nl.conduction.keepiq.shared.store.db")
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

// The passphrase word list is the web generator's own (src/generator/
// eff-large-wordlist.js), compiled into commonMain, so the two generators
// can never draw from different lists.
val wordlistSource = layout.projectDirectory.file("../../src/generator/eff-large-wordlist.js")
val generatedWordlist = layout.buildDirectory.dir("generated/wordlist/kotlin")
val generateWordlist by tasks.registering {
    inputs.file(wordlistSource).withPathSensitivity(PathSensitivity.RELATIVE)
    outputs.dir(generatedWordlist)
    val sourceFile = wordlistSource.asFile
    val outDir = generatedWordlist.get().asFile
    doLast {
        val js = sourceFile.readText()
        val list = js.substringAfter("Object.freeze(").substringBefore(".split(' ')")
        val words = Regex("'([^']*)'").findAll(list).joinToString("") { it.groupValues[1] }.split(' ')
        check(words.size == 7776 && words.toSet().size == 7776) { "The EFF word list has ${words.size} words, expected 7776" }
        val parts = words.joinToString(" ").chunked(8_000).joinToString(",\n        ") { "\"$it\"" }
        val body = StringBuilder()
        body.append("// Generated from src/generator/eff-large-wordlist.js by :shared:generateWordlist. Do not edit.\n")
        body.append("// The EFF large word list, by the Electronic Frontier Foundation, CC BY 3.0 US.\n")
        body.append("package nl.conduction.keepiq.shared.generator\n\n")
        body.append("internal object EffWordlist {\n")
        body.append("    val words: List<String> by lazy {\n        listOf(\n        $parts,\n        ).joinToString(\"\").split(' ')\n    }\n")
        body.append("}\n")
        val target = File(outDir, "nl/conduction/keepiq/shared/generator/EffWordlist.kt")
        target.parentFile.mkdirs()
        target.writeText(body.toString())
    }
}
kotlin.sourceSets.commonMain {
    kotlin.srcDir(generateWordlist)
}

// The generator cases in tests/vectors/generator/ are compiled into
// commonTest the same way as the crypto vectors.
val generatorVectorsFile = layout.projectDirectory.file("../../tests/vectors/generator/cases.json")
val generatedGeneratorVectors = layout.buildDirectory.dir("generated/generator-vectors/kotlin")
val generateGeneratorVectors by tasks.registering {
    inputs.file(generatorVectorsFile).withPathSensitivity(PathSensitivity.RELATIVE)
    outputs.dir(generatedGeneratorVectors)
    val sourceFile = generatorVectorsFile.asFile
    val outDir = generatedGeneratorVectors.get().asFile
    doLast {
        val encoded = Base64.getEncoder().encodeToString(sourceFile.readBytes())
        val parts = encoded.chunked(16_000).joinToString(",\n        ") { "\"$it\"" }
        val target = File(outDir, "nl/conduction/keepiq/shared/vectors/GeneratedGeneratorVectors.kt")
        target.parentFile.mkdirs()
        target.writeText(
            "// Generated from tests/vectors/generator by :shared:generateGeneratorVectors. Do not edit.\n" +
                "package nl.conduction.keepiq.shared.vectors\n\n" +
                "internal object GeneratedGeneratorVectors {\n    val cases: List<String> = listOf(\n        $parts,\n    )\n}\n",
        )
    }
}
kotlin.sourceSets.commonTest {
    kotlin.srcDir(generateGeneratorVectors)
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
