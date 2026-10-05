// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

// The app: pairing and unlock (group 2) and the vault, Send and
// generator screens (group 3) over :shared. Autofill and the passkey
// providers come in later groups of clients-mobile-apps.
plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

android {
    namespace = "nl.conduction.keepiq.android"
    compileSdk = libs.versions.android.compileSdk.get().toInt()
    defaultConfig {
        applicationId = "nl.conduction.keepiq"
        minSdk = libs.versions.android.minSdk.get().toInt()
        targetSdk = libs.versions.android.targetSdk.get().toInt()
        versionCode = 1
        versionName = "0.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    // Release signing comes from the environment only, so no key or
    // password lives in the repository. mobile-preview.yml sets these four
    // variables from GitHub secrets (or from a throwaway key in its pull
    // request dry run). Without them the release build stays unsigned.
    val releaseStoreFile = providers.environmentVariable("KEEPIQ_SIGNING_STORE_FILE").orNull
    signingConfigs {
        if (releaseStoreFile != null) {
            create("release") {
                storeFile = file(releaseStoreFile)
                storePassword = providers.environmentVariable("KEEPIQ_SIGNING_STORE_PASSWORD").get()
                keyAlias = providers.environmentVariable("KEEPIQ_SIGNING_KEY_ALIAS").get()
                keyPassword = providers.environmentVariable("KEEPIQ_SIGNING_KEY_PASSWORD").get()
            }
        }
    }
    buildTypes {
        getByName("release") {
            signingConfig = signingConfigs.findByName("release")
            // R8: drop unused code and resources (keep rules in
            // proguard-rules.pro). Only the release build; debug and e2e stay
            // as they are, so the emulator tests run unshrunk code.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
        // The end-to-end build (.github/workflows/mobile-e2e.yml). It is the
        // debug build plus one thing: it trusts the self-signed certificate
        // of the test server, which the workflow writes to
        // src/e2e/res/raw/keepiq_e2e_ca.pem before it builds. Release and
        // debug never trust it.
        create("e2e") {
            initWith(getByName("debug"))
            matchingFallbacks += "debug"
        }
    }
    testBuildType = "e2e"
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    buildFeatures { compose = true }
    // Compose screen tests on the JVM with Robolectric (free software); the
    // emulator tests come with the e2e harness of task group 2.
    testOptions {
        unitTests.isIncludeAndroidResources = true
    }
    // Lint on release builds resolves lint artifacts that are not in
    // gradle/verification-metadata.xml, so the release build would fail
    // dependency verification. The release workflow builds without it.
    lint {
        checkReleaseBuilds = false
    }
    // One APK per processor type next to the universal one, when asked for
    // (-Pkeepiq.abiSplits=true, mobile-preview.yml): the SQLCipher native
    // library is most of the APK, and a phone needs only its own. Off by
    // default, so debug and e2e builds keep their single APK.
    if (providers.gradleProperty("keepiq.abiSplits").orNull?.toBoolean() == true) {
        splits {
            abi {
                isEnable = true
                reset()
                include("arm64-v8a", "armeabi-v7a", "x86", "x86_64")
                isUniversalApk = true
            }
        }
    }
    // F-Droid: no Google dependency metadata blob in the APK.
    dependenciesInfo {
        includeInApk = false
        includeInBundle = false
    }
}

kotlin {
    compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) }
}

// The e2e build without the test server's certificate would build an app
// that trusts nothing extra and fails every request; say so instead.
val e2eCa = layout.projectDirectory.file("src/e2e/res/raw/keepiq_e2e_ca.pem")
val checkE2eCa by tasks.registering {
    val caFile = e2eCa.asFile
    doLast {
        if (!caFile.isFile) {
            throw GradleException("The e2e build needs the test server's certificate in ${caFile.path}; see .github/workflows/mobile-e2e.yml.")
        }
    }
}
tasks.matching { it.name == "preE2eBuild" }.configureEach { dependsOn(checkE2eCa) }

dependencies {
    implementation(project(":shared"))
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.fragment)
    implementation(libs.androidx.biometric)
    implementation(libs.androidx.autofill)
    implementation(libs.androidx.credentials)
    implementation(libs.androidx.browser)
    implementation(libs.androidx.lifecycle.process)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.material3)

    androidTestImplementation(platform(libs.compose.bom))
    androidTestImplementation(libs.compose.ui.test.junit4)
    androidTestImplementation(libs.compose.ui.test.junit4.accessibility)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.rules)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.espresso.intents)
    androidTestImplementation(libs.androidx.uiautomator)
    androidTestImplementation(libs.kotlinx.serialization.json)
    androidTestImplementation(libs.junit)
    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.serialization.json)
    androidTestImplementation(libs.kotlinx.serialization.json)
    testImplementation(libs.robolectric)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(platform(libs.compose.bom))
    debugImplementation(libs.compose.ui.test.manifest)
}
