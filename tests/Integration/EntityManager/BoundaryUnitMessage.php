<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

/**
 * Message of the kept-alive boundary test: unit of work number $index.
 */
final readonly class BoundaryUnitMessage
{
    public function __construct(
        public int $index,
    ) {
    }
}
