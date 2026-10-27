<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

/**
 * Detects whether nowo-tech/phone-input-bundle is installed.
 */
final readonly class ContactPhoneInputAvailability
{
    /**
     * @param bool|null $available null (default) = detect the PhoneInputBundle form type; a bool forces the answer
     *                             (tests, hosts that ship their own phone type)
     */
    public function __construct(
        private ?bool $available = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available ?? class_exists('Nowo\PhoneInputBundle\Form\Type\PhoneType');
    }
}
