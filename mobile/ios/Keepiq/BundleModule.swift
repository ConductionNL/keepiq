// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import Foundation

/// The vault, Send and generator screens are the KeepiqApp Swift package,
/// which reads its strings from `Bundle.module`. The app target compiles
/// those sources itself (project.yml), and their Localizable.strings land in
/// the main bundle.
extension Bundle {
    static var module: Bundle { .main }
}
