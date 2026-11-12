<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure;

use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Domain\AttachmentVault;

use function count;
use function stream_get_contents;

final class InMemoryAttachmentVault implements AttachmentVault
{
    /** @var array<string, array{owner: string, name: string, content: string}> */
    public array $kept = [];

    public function keep(string $owner, string $safeName, $content): string
    {
        $id              = 'att-' . (count($this->kept) + 1);
        $this->kept[$id] = ['owner' => $owner, 'name' => $safeName, 'content' => (string) stream_get_contents($content)];

        return $id;
    }
}
