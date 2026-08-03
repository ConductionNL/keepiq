<?php

/**
 * Doriath SuppressesDiagnostics
 *
 * A single, named home for the "call a PHP function that reports invalid input
 * as a diagnostic warning AND as a false/null return value" pattern.
 *
 * Several extension functions used by Doriath (openssl_csr_get_public_key,
 * openssl_csr_new, openssl_x509_parse, stream_socket_client) raise an E_WARNING
 * on input that is merely user-supplied-and-malformed — an entirely expected
 * condition here, because the input arrives over HTTP and is validated by
 * checking the return value. Those functions expose no "quiet" flag, so the
 * warning has to be discarded somewhere.
 *
 * Historically that was the error-control operator (`@expr`). This trait
 * replaces it with an explicit, greppable, strictly-scoped error handler so
 * that the suppression is a visible decision at each call site rather than a
 * one-character mark, and so the reason lives in exactly one place instead of
 * being restated at every site.
 *
 * @category Support
 * @package  OCA\Doriath\Support
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Support;

/**
 * Runs a callable with PHP diagnostics (warnings/notices) discarded.
 */
trait SuppressesDiagnostics
{
    /**
     * Run $call with the default error handler replaced by a no-op.
     *
     * The handler is installed immediately before the call and restored in a
     * `finally`, so it cannot leak into unrelated code even if $call throws.
     * Errors that PHP does not route through the user handler (parse/fatal)
     * are unaffected — only recoverable diagnostics are discarded.
     *
     * @param callable $call The operation whose diagnostics are discarded.
     *
     * @return mixed Whatever $call returns — callers MUST still inspect the
     *               return value, because discarding the warning does not make
     *               the failure go away.
     *
     * @template      T
     * @psalm-param   callable():T $call
     * @psalm-return  T
     * @phpstan-param callable():T $call
     */
    private function withoutDiagnostics(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }

    }//end withoutDiagnostics()
}//end trait
