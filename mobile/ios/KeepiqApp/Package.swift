// swift-tools-version:5.9
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Placeholder Swift package that links the shared Kotlin core. Build the
// framework first: `cd mobile && ./gradlew :shared:assembleKeepiqSharedXCFramework`.
// The SwiftUI app, its AutoFill extension and the Xcode project come in later
// groups of clients-mobile-apps.
import PackageDescription

let package = Package(
    name: "KeepiqApp",
    platforms: [.iOS(.v17)],
    products: [
        .library(name: "KeepiqApp", targets: ["KeepiqApp"]),
    ],
    targets: [
        .binaryTarget(
            name: "KeepiqShared",
            path: "../../shared/build/XCFrameworks/release/KeepiqShared.xcframework"
        ),
        .target(name: "KeepiqApp", dependencies: ["KeepiqShared"]),
    ]
)
