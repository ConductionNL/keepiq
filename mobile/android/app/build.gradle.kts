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
    buildTypes {
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
    implementation(libs.androidx.browser)
    implementation(libs.androidx.lifecycle.process)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.material3)

    androidTestImplementation(platform(libs.compose.bom))
    androidTestImplementation(libs.compose.ui.test.junit4)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.rules)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.espresso.intents)
    androidTestImplementation(libs.androidx.uiautomator)
    androidTestImplementation(libs.junit)
    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.serialization.json)
    testImplementation(libs.robolectric)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(platform(libs.compose.bom))
    debugImplementation(libs.compose.ui.test.manifest)
}
