<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

/**
 * Anonymizes IP addresses for GDPR-compliant storage (SHA-256 hash with salt).
 */
final class IpAnonymizer
{
    public function __construct(
        private readonly string $salt = '',
    ) {
    }

    public function anonymize(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $this->salt . $ip);
    }
}
