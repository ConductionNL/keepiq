// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "keepiq-mobile"

// The Android parts build only where an Android SDK is installed (CI, a
// developer machine with Android Studio). Without one, `shared` still builds
// and tests on its jvm() target. -Pkeepiq.android=true forces them on.
val localSdkDir = file("local.properties").takeIf { it.isFile }?.readLines()
    ?.firstOrNull { it.startsWith("sdk.dir=") }?.substringAfter("=")
val sdkDir = localSdkDir ?: System.getenv("ANDROID_HOME") ?: System.getenv("ANDROID_SDK_ROOT")
val withAndroid = providers.gradleProperty("keepiq.android").orNull?.toBoolean()
    ?: (sdkDir != null && file(sdkDir).isDirectory)
gradle.extensions.extraProperties["keepiq.android"] = withAndroid

include(":shared")
if (withAndroid) {
    include(":android:app")
}
