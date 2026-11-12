<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Domain;

/**
 * A DDD host's own domain port: plain PHP, no framework type.
 */
interface AttachmentVault
{
    /**
     * @param resource $content
     */
    public function keep(string $owner, string $safeName, $content): string;
}
