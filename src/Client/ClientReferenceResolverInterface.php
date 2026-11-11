<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Client;

use Nowo\ContactFormBundle\Service\ClientResolverInterface;

/**
 * Host contract (`nowo_contact_form.client_reference_resolver`, 1.7): the client of the current visitor as an id
 * reference, or null for an anonymous submission. The default adapts {@see ClientResolverInterface}
 * (security user / `client_entity_class`) and the label property. Worker mode: resolve per call, keep nothing.
 */
interface ClientReferenceResolverInterface
{
    public function resolve(): ?ClientReference;
}
