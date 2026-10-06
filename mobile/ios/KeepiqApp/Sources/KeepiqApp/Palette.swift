// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import SwiftUI
import UIKit

/// Keepiq's own colours, chosen for WCAG AA contrast (task 3.1). The
/// accessibility audit failed the system blue (4.0:1 on white) and the
/// system secondary text (about 3.5:1 on a grouped list) at body sizes.
enum KeepiqPalette {
    /// The tint: 7.1:1 on white in light mode; the system blue in dark mode,
    /// 5.8:1 on black, and 3.6:1 under the white label of a prominent button,
    /// which is large text (17 pt semibold).
    static let accent = Color(UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 0x0A / 255, green: 0x84 / 255, blue: 0xFF / 255, alpha: 1)
            : UIColor(red: 0x00 / 255, green: 0x55 / 255, blue: 0xB3 / 255, alpha: 1)
    })

    /// Secondary text: 6.6:1 on white and 7.5:1 on the dark grouped cells.
    static let secondaryText = Color(UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 0xAE / 255, green: 0xAE / 255, blue: 0xB2 / 255, alpha: 1)
            : UIColor(red: 0x5C / 255, green: 0x5C / 255, blue: 0x61 / 255, alpha: 1)
    })
}

/// A section header in Keepiq's secondary text colour.
struct SectionTitle: View {
    let title: String

    var body: some View {
        Text(title).foregroundStyle(KeepiqPalette.secondaryText)
    }
}

extension Section where Parent == SectionTitle, Footer == EmptyView, Content: View {
    /// A section with a header in Keepiq's secondary text colour.
    init(titled title: String, @ViewBuilder content: () -> Content) {
        self.init(content: content, header: { SectionTitle(title: title) })
    }
}
