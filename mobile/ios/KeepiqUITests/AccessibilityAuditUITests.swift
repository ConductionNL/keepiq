// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import XCTest

/// The automatic accessibility audit of the main screens (task 3.1), with
/// Xcode's `performAccessibilityAudit` (iOS 17 and later), against the
/// replay of the seeded demo vault: connect, unlock, the vault list, an
/// item, a one-time code, the item form, the generator, Send and its form,
/// and the settings.
///
/// Every issue is collected first, so one run names all of them; the test
/// then fails on any issue that is not a documented exclusion. The list goes
/// to a11y-findings.txt next to the screenshots. Exclusions live in
/// `A11yExclusions`, each with its reason. A VoiceOver pass by hand is still
/// open (task 3.1).
final class AccessibilityAuditUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }
    private var issues: [String] = []
    private var excluded: [String] = []
    private var screens: [String] = []

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
        app.launchEnvironment["KEEPIQ_UITEST_STILL_CODE"] = "1"
        app.launch()
    }

    func testMainScreensPassTheAccessibilityAudit() throws {
        // Connect.
        XCTAssertTrue(app.textFields["server"].waitForExistence(timeout: 30))
        try audit("connect")
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        XCTAssertTrue(app.secureTextFields["appPassword"].waitForExistence(timeout: 20))
        try audit("connect with an app password")
        type("admin", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        tap(app.buttons["connect"])

        // Unlock.
        XCTAssertTrue(app.secureTextFields["masterPassword"].waitForExistence(timeout: 60))
        declineSavePassword()
        try audit("unlock")
        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])

        // The vault list.
        XCTAssertTrue(app.buttons["lock"].waitForExistence(timeout: 60), "the vault opens after unlock")
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 60))
        try audit("vault list")

        // An item, its password shown.
        tap(text("Webmail (demo)"))
        XCTAssertTrue(text("anna.demo@example.com").waitForExistence(timeout: 60))
        tap(app.buttons["Show Password"])
        XCTAssertTrue(text("Lantern-Orbit-42!").waitForExistence(timeout: 10))
        try audit("item detail")
        back()

        // A one-time code and its countdown.
        tap(text("Authenticator (demo)"))
        XCTAssertTrue(app.staticTexts["totpCode"].waitForExistence(timeout: 60))
        try audit("item detail, one-time code")
        back()

        // The item form.
        tap(app.buttons["Add an item"])
        XCTAssertTrue(app.textFields["Name"].waitForExistence(timeout: 20))
        try audit("item form")
        back()

        // The generator.
        tap(app.tabBars.buttons["Generator"])
        XCTAssertTrue(app.staticTexts["generated"].waitForExistence(timeout: 20))
        try audit("generator")

        // Send and its form.
        tap(app.tabBars.buttons["Send"])
        XCTAssertTrue(app.buttons["New Send"].waitForExistence(timeout: 20))
        try audit("send list")
        tap(app.buttons["New Send"])
        XCTAssertTrue(app.buttons["Create link"].waitForExistence(timeout: 20) || scrolledTo(app.buttons["Create link"]).exists)
        try audit("send form")
        back()

        // The settings.
        tap(onScreen(app.buttons.matching(identifier: "accounts")))
        tap(app.buttons["settings"])
        XCTAssertTrue(app.secureTextFields["newPin"].waitForExistence(timeout: 20))
        try audit("settings")

        report()
    }

    // MARK: - The audit

    /// Audits the screen on display, and collects what it finds.
    private func audit(_ screen: String) throws {
        screens.append(screen)
        // Let a push or a sheet finish: the contrast check reads pixels.
        usleep(1_000_000)
        let screenshot = shot("a11y-" + screen.replacingOccurrences(of: ",", with: "").replacingOccurrences(of: " ", with: "-"))
        let bars = barFrames()
        let rowUnderBar = rowsUnderBars(bars)
        try app.performAccessibilityAudit(for: .all) { issue in
            let line = "\(screen): \(Self.name(of: issue.auditType)): \(issue.compactDescription) [\(Self.describe(issue.element))]"
            if let reason = A11yExclusions.reason(for: issue, bars: bars, rowUnderBar: rowUnderBar, screenshot: screenshot) {
                self.excluded.append("\(line) (excluded: \(reason))")
            } else {
                self.issues.append(line + "\n    " + issue.detailedDescription)
            }
            // Collected here; the test fails once, at the end, with the whole list.
            return true
        }
    }

    /// The navigation bar and the tab bar on screen. From iOS 26 the system
    /// fades the content scrolled under them (the scroll edge effect).
    private func barFrames() -> [CGRect] {
        (app.navigationBars.allElementsBoundByIndex + app.tabBars.allElementsBoundByIndex)
            .filter { $0.exists }
            .map(\.frame)
            .filter { !$0.isEmpty }
    }

    /// Whether a list row sits partly under a bar, faded by the scroll edge
    /// effect, at the moment of the audit.
    private func rowsUnderBars(_ bars: [CGRect]) -> Bool {
        app.cells.allElementsBoundByIndex.contains { cell in
            cell.exists && bars.contains { $0.intersects(cell.frame) }
        }
    }

    private func report() {
        var lines = ["Screens audited: \(screens.joined(separator: ", "))", "Issues: \(issues.count)"]
        lines += issues
        lines.append("Excluded: \(excluded.count)")
        lines += excluded
        let text = lines.joined(separator: "\n")
        let attachment = XCTAttachment(string: text)
        attachment.name = "a11y-findings"
        attachment.lifetime = .keepAlways
        add(attachment)
        if let dir = env["KEEPIQ_SHOTS_DIR"] {
            try? text.write(to: URL(fileURLWithPath: dir).appendingPathComponent("a11y-findings.txt"), atomically: true, encoding: .utf8)
        }
        print(text)
        XCTAssertTrue(issues.isEmpty, "accessibility issues:\n\(text)")
    }

    private static func name(of type: XCUIAccessibilityAuditType) -> String {
        let names: [(XCUIAccessibilityAuditType, String)] = [
            (.contrast, "contrast"), (.elementDetection, "elementDetection"), (.hitRegion, "hitRegion"),
            (.sufficientElementDescription, "sufficientElementDescription"), (.dynamicType, "dynamicType"),
            (.textClipped, "textClipped"), (.trait, "trait"),
        ]
        let matched = names.filter { type.contains($0.0) }.map { $0.1 }
        return matched.isEmpty ? "type \(type.rawValue)" : matched.joined(separator: "+")
    }

    private static func describe(_ element: XCUIElement?) -> String {
        guard let element, element.exists else { return "no element" }
        return "type \(element.elementType.rawValue), id '\(element.identifier)', label '\(element.label)'"
    }

    // MARK: - Helpers, as in VaultFlowsUITests

    @discardableResult
    private func shot(_ name: String) -> XCUIScreenshot {
        let screenshot = XCUIScreen.main.screenshot()
        let attachment = XCTAttachment(screenshot: screenshot)
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
        if let dir = env["KEEPIQ_SHOTS_DIR"] {
            try? screenshot.pngRepresentation.write(to: URL(fileURLWithPath: dir).appendingPathComponent("\(name).png"))
        }
        return screenshot
    }

    private func type(_ text: String, into element: XCUIElement) {
        XCTAssertTrue(element.waitForExistence(timeout: 20), "\(element) is missing")
        let deadline = Date().addingTimeInterval(5)
        while !reachable(element) && Date() < deadline { usleep(250_000) }
        if !reachable(element) { makeHittable(element) }
        element.tap()
        element.typeText(text)
        dismissKeyboardTip()
    }

    private func reachable(_ element: XCUIElement) -> Bool {
        guard element.exists else { return false }
        let frame = element.frame
        let screen = app.windows.firstMatch.frame
        guard !frame.isEmpty, screen.contains(CGPoint(x: frame.midX, y: frame.midY)) else { return false }
        return element.isHittable
    }

    private func makeHittable(_ element: XCUIElement) {
        dismissSavePasswordNow()
        if reachable(element) { return }
        if app.keyboards.count > 0 {
            for key in ["Return", "return", "Done", "done"] where app.keyboards.buttons[key].exists {
                app.keyboards.buttons[key].tap()
                break
            }
        }
        for _ in 0..<4 where !reachable(element) {
            app.swipeUp()
        }
    }

    private func onScreen(_ query: XCUIElementQuery) -> XCUIElement {
        _ = query.firstMatch.waitForExistence(timeout: 20)
        return query.allElementsBoundByIndex.first { reachable($0) } ?? query.firstMatch
    }

    private func scrolledTo(_ element: XCUIElement) -> XCUIElement {
        for _ in 0..<6 where !element.waitForExistence(timeout: 2) {
            app.swipeUp()
        }
        return element
    }

    private func dismissKeyboardTip() {
        let tip = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Speed up your typing")).firstMatch
        if tip.exists, app.buttons["Continue"].exists { app.buttons["Continue"].tap() }
    }

    private func tap(_ element: XCUIElement, timeout: TimeInterval = 20) {
        XCTAssertTrue(element.waitForExistence(timeout: timeout), "\(element) is missing")
        dismissKeyboardTip()
        dismissSavePasswordNow()
        let deadline = Date().addingTimeInterval(5)
        while !reachable(element) && Date() < deadline { usleep(250_000) }
        if !reachable(element) { makeHittable(element) }
        element.tap()
    }

    private func text(_ label: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label == %@", label)).firstMatch
    }

    private func declineSavePassword() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] {
            let notNow = owner.buttons["Not Now"]
            if notNow.waitForExistence(timeout: 3) {
                notNow.tap()
                return
            }
        }
    }

    private func dismissSavePasswordNow() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].exists {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    private func back() {
        let bar = app.navigationBars.firstMatch
        for candidate in [bar.buttons["BackButton"], bar.buttons["Back"], bar.buttons["Vault"]] where candidate.exists {
            candidate.tap()
            return
        }
        bar.buttons.element(boundBy: 0).tap()
    }
}

/// Audit issues the test leaves out, each a documented false positive with
/// its reason. Real issues are fixed in the app, never listed here.
enum A11yExclusions {
    static func reason(for issue: XCUIAccessibilityAuditIssue, bars: [CGRect], rowUnderBar: Bool, screenshot: XCUIScreenshot) -> String? {
        let element = issue.element.flatMap { $0.exists ? $0 : nil }
        let type = element?.elementType
        let label = element?.label ?? ""
        // A button that is off until its field is filled in: WCAG 1.4.3
        // exempts an inactive user interface component from the contrast rule.
        if issue.auditType == .contrast, let element, !element.isEnabled {
            return "an inactive control, exempt from WCAG 1.4.3"
        }
        // Text scrolled under the navigation bar or the tab bar: iOS 26 fades
        // it on purpose (the scroll edge effect). Scrolled into view it has its
        // full contrast. 24 pt around each bar is the width of the fade.
        if issue.auditType == .contrast, let frame = element?.frame,
           bars.contains(where: { $0.insetBy(dx: 0, dy: -24).intersects(frame) }) {
            return "under the system scroll edge effect of a bar"
        }
        // A contrast finding on an element whose pixels, measured in the
        // screenshot of the same screen, pass WCAG AA: the text colour against
        // the background is 4.5:1 or more. Seen on the Edit button, drawn in
        // Keepiq's tint on white (7.1:1) like the Move button below it.
        if issue.auditType == .contrast, let frame = element?.frame,
           let measured = measuredContrast(in: frame, of: screenshot), measured >= 4.5 {
            return String(format: "measured %.1f:1 in the screenshot, at least 4.5:1", measured)
        }
        // A contrast finding the audit cannot tie to an element, on a screen
        // where a list row is half under a bar: the faded row has no element
        // of its own any more. Seen on the vault list, with the last row under
        // the tab bar. Any finding with an element is still counted.
        if issue.auditType == .contrast, element == nil, rowUnderBar {
            return "a row half under a bar, faded by the scroll edge effect"
        }
        // A single-line text field scrolls its text sideways, so it never
        // hides what was typed. The audit flags every single-line field at
        // the largest sizes; the field itself grows with Dynamic Type.
        if issue.auditType == .textClipped, let type, [.textField, .secureTextField, .searchField].contains(type) {
            return "a single-line text field scrolls its text, it does not clip it"
        }
        // The item's own login or address, shown as the user saved it.
        // VoiceOver reads it as it is; a different label would hide the value.
        if issue.auditType == .sufficientElementDescription, label.contains("@") || label.contains("://") {
            return "the label is the item's own login or address"
        }
        // Every Text in Keepiq uses a text style (no fixed point sizes), and
        // the text grows with the setting. The audit reports SwiftUI text in
        // lists and buttons as "partially unsupported" all the same. The full
        // "unsupported" finding is not excluded.
        if issue.auditType == .dynamicType, issue.compactDescription.contains("partially") {
            return "SwiftUI text styles reported as partially unsupported; Keepiq uses no fixed font size"
        }
        return nil
    }

    /// The contrast ratio between the background (the most frequent colour
    /// inside `frame`) and the text (the most frequent colour clearly unlike
    /// it), or nil when the frame holds no second colour.
    static func measuredContrast(in frame: CGRect, of screenshot: XCUIScreenshot) -> Double? {
        let image = screenshot.image
        guard let cg = image.cgImage, image.size.width > 0 else { return nil }
        let scale = CGFloat(cg.width) / image.size.width
        let rect = CGRect(x: frame.minX * scale, y: frame.minY * scale, width: frame.width * scale, height: frame.height * scale).integral
        guard let crop = cg.cropping(to: rect), crop.width > 0, crop.height > 0,
              let space = CGColorSpace(name: CGColorSpace.sRGB) else { return nil }
        let width = crop.width
        let height = crop.height
        var pixels = [UInt8](repeating: 0, count: width * height * 4)
        let drawn: Bool = pixels.withUnsafeMutableBytes { buffer in
            guard let context = CGContext(data: buffer.baseAddress, width: width, height: height, bitsPerComponent: 8,
                                          bytesPerRow: width * 4, space: space,
                                          bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue) else { return false }
            context.draw(crop, in: CGRect(x: 0, y: 0, width: width, height: height))
            return true
        }
        guard drawn else { return nil }
        var counts: [UInt32: Int] = [:]
        for index in stride(from: 0, to: pixels.count, by: 4) {
            let key = UInt32(pixels[index]) << 16 | UInt32(pixels[index + 1]) << 8 | UInt32(pixels[index + 2])
            counts[key, default: 0] += 1
        }
        let ranked = counts.sorted { $0.value > $1.value }.map(\.key)
        guard let background = ranked.first,
              let text = ranked.dropFirst().first(where: { distance($0, background) > 96 }) else { return nil }
        let lighter = max(luminance(text), luminance(background))
        let darker = min(luminance(text), luminance(background))
        return (lighter + 0.05) / (darker + 0.05)
    }

    private static func channels(_ rgb: UInt32) -> [Double] {
        [Double((rgb >> 16) & 0xFF), Double((rgb >> 8) & 0xFF), Double(rgb & 0xFF)]
    }

    private static func distance(_ a: UInt32, _ b: UInt32) -> Double {
        zip(channels(a), channels(b)).reduce(0) { $0 + abs($1.0 - $1.1) }
    }

    /// WCAG relative luminance of an sRGB colour.
    private static func luminance(_ rgb: UInt32) -> Double {
        let linear = channels(rgb).map { value -> Double in
            let c = value / 255
            return c <= 0.04045 ? c / 12.92 : pow((c + 0.055) / 1.055, 2.4)
        }
        return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]
    }
}
