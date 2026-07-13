<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

/**
 * Detects whether nowo-tech/phone-input-bundle is installed.
 */
final class ContactPhoneInputAvailability
{
    public function isAvailable(): bool
    {
        return class_exists('Nowo\PhoneInputBundle\Form\Type\PhoneType');
    }
}
