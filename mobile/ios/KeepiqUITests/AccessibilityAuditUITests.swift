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
        shot("a11y-" + screen.replacingOccurrences(of: ",", with: "").replacingOccurrences(of: " ", with: "-"))
        try app.performAccessibilityAudit(for: .all) { issue in
            let line = "\(screen): \(Self.name(of: issue.auditType)): \(issue.compactDescription) [\(Self.describe(issue.element))]"
            if let reason = A11yExclusions.reason(for: issue) {
                self.excluded.append("\(line) (excluded: \(reason))")
            } else {
                self.issues.append(line + "\n    " + issue.detailedDescription)
            }
            // Collected here; the test fails once, at the end, with the whole list.
            return true
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

    private func shot(_ name: String) {
        let screenshot = XCUIScreen.main.screenshot()
        let attachment = XCTAttachment(screenshot: screenshot)
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
        if let dir = env["KEEPIQ_SHOTS_DIR"] {
            try? screenshot.pngRepresentation.write(to: URL(fileURLWithPath: dir).appendingPathComponent("\(name).png"))
        }
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
    static func reason(for issue: XCUIAccessibilityAuditIssue) -> String? {
        nil
    }
}
