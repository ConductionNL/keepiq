// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import UIKit
import XCTest

/// Task group 3 on the iOS simulator, over the demo vault recorded from the
/// seeded test server (mobile/e2e/server.mjs seed, then record). The replay
/// keeps the vault in memory, so what the test creates, edits and trashes
/// behaves as on the real server: after unlock the vault list, search, an
/// item with its password revealed and copied, the authenticator code, a new
/// login with a generated password, its edit and trash, the generator, a Send
/// with its link, the settings route, and the lock.
final class VaultFlowsUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
        app.launch()
    }

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
        element.tap()
        element.typeText(text)
        dismissKeyboardTip()
    }

    /// On screen and not covered. Asking an off-screen element whether it is
    /// hittable fails the test ("activation point invalid"), so the frame comes first.
    private func reachable(_ element: XCUIElement) -> Bool {
        guard element.exists else { return false }
        let frame = element.frame
        let screen = app.windows.firstMatch.frame
        guard !frame.isEmpty, screen.contains(CGPoint(x: frame.midX, y: frame.midY)) else { return false }
        return element.isHittable
    }

    /// Brings a button into reach: answer a save-password prompt, lower the
    /// keyboard with its Return key, then scroll until it can be tapped.
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

    /// The first element of [query] that is on screen, or its first match.
    private func onScreen(_ query: XCUIElementQuery) -> XCUIElement {
        _ = query.firstMatch.waitForExistence(timeout: 20)
        return query.allElementsBoundByIndex.first { reachable($0) } ?? query.firstMatch
    }

    /// A Form builds its rows lazily: a button below the fold is not in the
    /// tree until the form scrolls to it.
    private func scrolledTo(_ element: XCUIElement) -> XCUIElement {
        for _ in 0..<6 where !element.waitForExistence(timeout: 2) {
            app.swipeUp()
        }
        return element
    }

    /// The first keyboard use shows a slide-to-type tip that covers the lower half.
    private func dismissKeyboardTip() {
        let tip = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Speed up your typing")).firstMatch
        if tip.exists, app.buttons["Continue"].exists { app.buttons["Continue"].tap() }
    }

    private func tap(_ element: XCUIElement, timeout: TimeInterval = 20) {
        XCTAssertTrue(element.waitForExistence(timeout: timeout), "\(element) is missing")
        dismissKeyboardTip()
        dismissSavePasswordNow()
        // A screen still sliding in, or a keyboard on its way out, covers it for a moment.
        let deadline = Date().addingTimeInterval(5)
        while !reachable(element) && Date() < deadline { usleep(250_000) }
        if !reachable(element) { makeHittable(element) }
        element.tap()
    }

    /// Any element whose label is [label] (a list row, a title or a value).
    private func text(_ label: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label == %@", label)).firstMatch
    }

    private func waitGone(_ element: XCUIElement, timeout: TimeInterval = 30) {
        expectation(for: NSPredicate(format: "exists == false"), evaluatedWith: element)
        waitForExpectations(timeout: timeout)
    }

    /// iOS offers to save the app password the user just typed; the test declines.
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

    /// The save-password prompt, if it is up right now, without waiting for it.
    private func dismissSavePasswordNow() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].exists {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    /// Leaves the search: "Cancel" up to iOS 18, a close button from iOS 26 on.
    private func endSearch(_ search: XCUIElement) {
        for label in ["Cancel", "Close"] where app.buttons[label].exists {
            app.buttons[label].firstMatch.tap()
            return
        }
        let clear = search.buttons["Clear text"]
        if clear.exists { clear.tap() }
    }

    private func back() {
        let bar = app.navigationBars.firstMatch
        for candidate in [bar.buttons["BackButton"], bar.buttons["Back"], bar.buttons["Vault"]] where candidate.exists {
            candidate.tap()
            return
        }
        bar.buttons.element(boundBy: 0).tap()
    }

    func testVaultSearchRevealCopyTotpCreateEditTrashGeneratorSendAndLock() {
        // Pair with an app password and unlock: the vault list opens.
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        type("admin", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        tap(app.buttons["connect"])
        XCTAssertTrue(app.secureTextFields["masterPassword"].waitForExistence(timeout: 60))
        declineSavePassword()
        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.buttons["lock"].waitForExistence(timeout: 60), "the vault opens after unlock")
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 60))
        XCTAssertTrue(text("Authenticator (demo)").exists)
        shot("30-vault-list")

        // Search runs on the device, over every folder.
        let search = app.searchFields.firstMatch
        if !search.waitForExistence(timeout: 5) { app.swipeDown() }
        type("bank", into: search)
        XCTAssertTrue(text("Bank (demo)").waitForExistence(timeout: 10))
        waitGone(text("Webmail (demo)"))
        shot("31-search")
        endSearch(search)
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 10))

        // A folder holds its own items.
        tap(app.buttons["Open folder Personal"])
        XCTAssertTrue(text("Bank (demo)").waitForExistence(timeout: 20))
        shot("31-folder")
        back()
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 10))

        // An item: the password hidden until shown, then copied.
        tap(text("Webmail (demo)"))
        XCTAssertTrue(text("anna.demo@example.com").waitForExistence(timeout: 60))
        XCTAssertFalse(text("Lantern-Orbit-42!").exists)
        tap(app.buttons["Show Password"])
        XCTAssertTrue(text("Lantern-Orbit-42!").waitForExistence(timeout: 10))
        shot("32-item-revealed")
        tap(app.buttons["Copy Password"])
        let copied = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Copied.")).firstMatch
        XCTAssertTrue(copied.waitForExistence(timeout: 10))
        // Reading the pasteboard from the test would raise the paste prompt;
        // that something text-like is on it can be asked without one.
        XCTAssertTrue(UIPasteboard.general.hasStrings)
        shot("33-copied")
        back()

        // The authenticator code and its seconds left.
        tap(text("Authenticator (demo)"))
        let code = app.staticTexts["totpCode"]
        XCTAssertTrue(code.waitForExistence(timeout: 60))
        XCTAssertNotNil(code.label.range(of: "^[0-9]{6}$", options: .regularExpression), "code: \(code.label)")
        let countdown = app.descendants(matching: .any).matching(NSPredicate(format: "label ENDSWITH %@", "seconds left")).firstMatch
        XCTAssertTrue(countdown.waitForExistence(timeout: 10))
        shot("34-totp")
        back()

        // A new login with a generated password.
        tap(app.buttons["Add an item"])
        type("Shop (demo)", into: app.textFields["Name"])
        type("https://shop.example.com", into: app.textFields["Website address"])
        type("anna.demo@example.com", into: app.textFields["User name"])
        tap(scrolledTo(app.buttons["Generate"]))
        XCTAssertTrue(app.staticTexts["generated"].waitForExistence(timeout: 20))
        let generated = app.staticTexts["generated"].label
        XCTAssertGreaterThanOrEqual(generated.count, 12)
        shot("35-generate-in-form")
        tap(app.buttons["Use this"])
        tap(scrolledTo(app.buttons["Save"]))
        // Saving a login with a password makes iOS offer to keep it.
        declineSavePassword()
        XCTAssertTrue(text("Shop (demo)").waitForExistence(timeout: 60))
        tap(text("Shop (demo)"))
        tap(app.buttons["Show Password"], timeout: 60)
        XCTAssertTrue(text(generated).waitForExistence(timeout: 10), "the generated password was saved")
        shot("36-created")

        // Edit it.
        tap(app.buttons["Edit"])
        let name = app.textFields["Name"]
        XCTAssertTrue(name.waitForExistence(timeout: 20))
        // The cursor after the last character, then the old name out.
        name.coordinate(withNormalizedOffset: CGVector(dx: 0.95, dy: 0.5)).tap()
        name.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: 20))
        name.typeText("Shop account (demo)")
        tap(scrolledTo(app.buttons["Save"]))
        // Saving a login with a password makes iOS offer to keep it.
        declineSavePassword()
        XCTAssertTrue(text("Shop account (demo)").waitForExistence(timeout: 60))
        shot("37-edited")

        // Trash it: gone from the list.
        tap(scrolledTo(app.buttons["Move to trash"]))
        // The confirmation is an action sheet up to iOS 18 and a popover from
        // iOS 26 on; either way it is the "Move to trash" button the
        // dialog does not cover.
        let trashButtons = app.buttons.matching(identifier: "Move to trash")
        expectation(for: NSPredicate(format: "count >= 2"), evaluatedWith: trashButtons)
        waitForExpectations(timeout: 10)
        let confirmTrash = trashButtons.allElementsBoundByIndex.first { reachable($0) }
        XCTAssertNotNil(confirmTrash, "no confirmation to tap")
        confirmTrash?.tap()
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 60))
        waitGone(text("Shop account (demo)"), timeout: 60)
        shot("38-trashed")

        // The generator.
        tap(app.tabBars.buttons["Generator"])
        let value = app.staticTexts["generated"]
        XCTAssertTrue(value.waitForExistence(timeout: 20))
        let first = value.label
        tap(app.buttons["Generate a new value"])
        expectation(for: NSPredicate(format: "label != %@", first), evaluatedWith: value)
        waitForExpectations(timeout: 10)
        shot("39-generator")

        // A Send and its link.
        tap(app.tabBars.buttons["Send"])
        tap(app.buttons["New Send"])
        // A vertical TextField reads as a text view on some iOS versions.
        let sendText = app.descendants(matching: .any).matching(
            NSPredicate(format: "(elementType == %d OR elementType == %d) AND label == %@",
                        XCUIElement.ElementType.textField.rawValue, XCUIElement.ElementType.textView.rawValue, "Text to send")
        ).firstMatch
        type("The demo door code is 2468.", into: sendText)
        tap(scrolledTo(app.buttons["Create link"]))
        let link = app.staticTexts["sendLink"]
        XCTAssertTrue(link.waitForExistence(timeout: 60))
        XCTAssertTrue(link.label.hasPrefix(server) && link.label.contains("#"), "link: \(link.label)")
        shot("40-send-link")
        tap(app.buttons["Close"])
        shot("41-send-list")

        // The settings route from inside the vault, and back. Every tab has
        // the account and lock buttons; the one on screen is the one to tap.
        tap(onScreen(app.buttons.matching(identifier: "accounts")))
        tap(app.buttons["settings"])
        XCTAssertTrue(app.secureTextFields["newPin"].waitForExistence(timeout: 20))
        shot("42-settings")
        tap(app.buttons["back"])

        // Lock: the unlock screen again.
        tap(onScreen(app.buttons.matching(identifier: "lock")), timeout: 30)
        XCTAssertTrue(app.secureTextFields["masterPassword"].waitForExistence(timeout: 30))
        XCTAssertFalse(app.buttons["lock"].exists)
        shot("43-locked")
    }
}
