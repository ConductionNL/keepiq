// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

// A stand-alone login form for the end-to-end tests, and nothing else
// (PackageVisibilityTest in :android:app). It is a separate APK on purpose:
// Android makes an instrumentation APK and the app it tests visible to each
// other, so the test APK's own forms cannot show whether Keepiq can read the
// identity of an ordinary app that asks for autofill. Never released.
plugins {
    alias(libs.plugins.android.application)
}

android {
    namespace = "nl.conduction.keepiq.otherapp"
    compileSdk = libs.versions.android.compileSdk.get().toInt()
    defaultConfig {
        applicationId = "nl.conduction.keepiq.e2e.otherapp"
        minSdk = libs.versions.android.minSdk.get().toInt()
        targetSdk = libs.versions.android.targetSdk.get().toInt()
        versionCode = 1
        versionName = "1"
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    dependenciesInfo {
        includeInApk = false
        includeInBundle = false
    }
}
