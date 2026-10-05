// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import AuthenticationServices
import SwiftUI
import UIKit

/// Keepiq as the iOS AutoFill provider (mobile-system-autofill, task 4.4).
///
/// - prepareCredentialList: the logins for the asked sites, read from their
///   per-site files only, after an unlock in the sheet.
/// - provideCredentialWithoutUserInteraction: fills at once when this
///   extension opened the vault within the idle time; otherwise iOS shows
///   prepareInterfaceToProvideCredential, which unlocks and then fills.
/// - After a password fill, the site's one-time code goes on the clipboard
///   for 60 seconds (task 4.5, iOS 17 path).
final class CredentialProviderViewController: ASCredentialProviderViewController {
    private var host: UIHostingController<AutofillRootView>?

    override func prepareCredentialList(for serviceIdentifiers: [ASCredentialServiceIdentifier]) {
        show(AutofillModel(serviceIdentifiers: serviceIdentifiers.map(\.identifier), onFill: complete))
    }

    override func prepareInterfaceToProvideCredential(for credentialIdentity: ASPasswordCredentialIdentity) {
        show(AutofillModel(serviceIdentifiers: [credentialIdentity.serviceIdentifier.identifier], record: credentialIdentity.recordIdentifier, onFill: complete))
    }

    override func provideCredentialWithoutUserInteraction(for credentialIdentity: ASPasswordCredentialIdentity) {
        let model = AutofillModel(serviceIdentifiers: [credentialIdentity.serviceIdentifier.identifier], onFill: { _ in })
        guard model.phase == .list, let record = credentialIdentity.recordIdentifier, let filled = model.fill(record: record) else {
            extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.userInteractionRequired.rawValue))
            return
        }
        complete(filled)
    }

    override func prepareInterfaceForExtensionConfiguration() {
        show(AutofillModel(serviceIdentifiers: [], configuration: true, onFill: { _ in }))
    }

    private func complete(_ filled: FilledLogin) {
        extensionContext.completeRequest(withSelectedCredential: ASPasswordCredential(user: filled.user, password: filled.password), completionHandler: nil)
    }

    private func cancel() {
        extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.userCanceled.rawValue))
    }

    private func show(_ model: AutofillModel) {
        host?.willMove(toParent: nil)
        host?.view.removeFromSuperview()
        host?.removeFromParent()
        let controller = UIHostingController(rootView: AutofillRootView(model: model, onCancel: { [weak self] in self?.cancel() }))
        addChild(controller)
        controller.view.frame = view.bounds
        controller.view.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        view.addSubview(controller.view)
        controller.didMove(toParent: self)
        host = controller
    }
}
