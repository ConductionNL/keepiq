// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// Placeholder screen: proves the app links the shared core.
public struct ContentView: View {
    public init() {}

    public var body: some View {
        Text(KeepiqShared.shared.APP_NAME)
            .font(.largeTitle)
    }
}
