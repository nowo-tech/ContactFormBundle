<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;

use function array_keys;

/**
 * Returns the EntityManager that maps the bundle entities and replaces it when it has been closed.
 *
 * Without a kernel reset between requests (worker mode), a failed flush would otherwise leave the
 * manager closed for every later request served by the same worker. The identity map is never cleared.
 */
final readonly class ContactFormEntityManagerResolver
{
    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public function get(): EntityManagerInterface
    {
        $entityManager = $this->managerForBundleEntities();

        if ($entityManager->isOpen()) {
            return $entityManager;
        }

        return $this->reset($entityManager) ?? $entityManager;
    }

    public function resetClosed(): void
    {
        $entityManager = $this->managerForBundleEntities();

        if (!$entityManager->isOpen()) {
            $this->reset($entityManager);
        }
    }

    private function managerForBundleEntities(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass(ContactForm::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager maps ' . ContactForm::class . '.');
        }

        return $entityManager;
    }

    private function reset(EntityManagerInterface $entityManager): ?EntityManagerInterface
    {
        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) !== $entityManager) {
                continue;
            }

            $reset = $this->registry->resetManager($name);

            return $reset instanceof EntityManagerInterface ? $reset : null;
        }

        return null;
    }
}
