<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Upload;

/**
 * The contact form file field a {@see UploadedContactFile} was submitted to (1.8, REQ-ARCH-001 rule 1): ids and names
 * only, no entity.
 *
 * `fieldOptions` are the field's admin-defined options (for example `max_size` / `mime_types`, or keys of your own such
 * as an upload directory); they never hold submitted data.
 */
final readonly class ContactFormFileTarget
{
    /**
     * @param int|null $formId id of the contact form (null only for a form that was never persisted)
     * @param string $formSlug slug of the contact form
     * @param int|null $fieldId id of the file field (null only for a field that was never persisted)
     * @param string $fieldName name of the file field (the submission key)
     * @param string $locale locale of the submission
     * @param array<array-key, mixed> $fieldOptions the field's admin-defined options
     */
    public function __construct(
        public ?int $formId,
        public string $formSlug,
        public ?int $fieldId,
        public string $fieldName,
        public string $locale = '',
        public array $fieldOptions = [],
    ) {
    }
}
