<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Symfony\Component\Form\FormTypeInterface;

use function is_subclass_of;

/**
 * Detects whether nowo-tech/phone-input-bundle is installed (its PhoneType form type exists).
 */
final readonly class ContactPhoneInputAvailability
{
    /** Form type of nowo-tech/phone-input-bundle. */
    public const FORM_TYPE = 'Nowo\PhoneInputBundle\Form\Type\PhoneType';

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
        return $this->available ?? is_subclass_of(self::FORM_TYPE, FormTypeInterface::class);
    }
}
