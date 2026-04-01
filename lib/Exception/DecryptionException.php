<?php

declare(strict_types=1);

namespace OCA\Doriath\Exception;

/**
 * Thrown when decryption fails due to GCM auth failure, invalid format, or wrong key.
 */
class DecryptionException extends \RuntimeException
{
}//end class
