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
    // Argon2id on iOS: the reference C code, compiled per target into a
    // static library that cinterop bundles into the klib (task 1.3.1).
    val argon2Dir = layout.projectDirectory.dir("src/nativeInterop/argon2")
    val argon2Sources = listOf("argon2.c", "core.c", "encoding.c", "ref.c", "thread.c", "blake2/blake2b.c")
    val iosTargets = mapOf(
        iosX64() to ("iphonesimulator" to "x86_64-apple-ios17.0-simulator"),
        iosArm64() to ("iphoneos" to "arm64-apple-ios17.0"),
        iosSimulatorArm64() to ("iphonesimulator" to "arm64-apple-ios17.0-simulator"),
    )
    iosTargets.forEach { (target, toolchain) ->
        val (sdk, triple) = toolchain
        val libDir = layout.buildDirectory.dir("argon2/${target.name}").get().asFile
        val buildArgon2 = tasks.register<Exec>("buildArgon2${target.name.replaceFirstChar { it.uppercase() }}") {
            inputs.dir(argon2Dir)
            outputs.dir(libDir)
            val src = argon2Dir.dir("src").asFile
            val include = argon2Dir.dir("include").asFile
            val compile = argon2Sources.joinToString("; ") { source ->
                "xcrun --sdk $sdk clang -target $triple -O2 -DARGON2_NO_THREADS -I'$include' -I'$src' " +
                    "-c '$src/$source' -o '${source.substringAfterLast('/').removeSuffix(".c")}.o'"
            }
            commandLine("bash", "-c", "set -e; rm -rf '$libDir'; mkdir -p '$libDir'; cd '$libDir'; $compile; xcrun --sdk $sdk ar rcs libargon2.a *.o")
        }
        target.compilations.getByName("main").cinterops.create("argon2") {
            definitionFile.set(project.file("src/nativeInterop/cinterop/argon2.def"))
            includeDirs(argon2Dir.dir("include"))
            extraOpts("-libraryPath", libDir.absolutePath)
        }
        tasks.matching { it.name == "cinteropArgon2${target.name.replaceFirstChar { c -> c.uppercase() }}" }
            .configureEach { dependsOn(buildArgon2) }
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
    // LiveServerTest: the mobile e2e workflow points it at the test server
    // and its self-signed certificate. Without these it is skipped.
    for (name in listOf("keepiq.liveServer", "keepiq.liveMasterPassword", "javax.net.ssl.trustStore", "javax.net.ssl.trustStorePassword")) {
        providers.gradleProperty(name).orNull?.let { systemProperty(name, it) }
    }
    outputs.upToDateWhen { false }
}
