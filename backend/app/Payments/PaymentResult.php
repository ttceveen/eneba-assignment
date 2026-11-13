<?php

namespace App\Payments;

use InvalidArgumentException;

final readonly class PaymentResult
{
    public function __construct(
        public string $status,
        public ?string $externalId = null,
        public ?string $redirectUrl = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
        if (! in_array($status, ['pending', 'succeeded', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid payment result status.');
        }
        if (($externalId !== null && mb_strlen($externalId) > 255)
            || ($errorCode !== null && mb_strlen($errorCode) > 100)
            || ($errorMessage !== null && mb_strlen($errorMessage) > 255)
            || ($redirectUrl !== null && (! filter_var($redirectUrl, FILTER_VALIDATE_URL) || parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https'))) {
            throw new InvalidArgumentException('Invalid payment result metadata.');
        }
    }
}
