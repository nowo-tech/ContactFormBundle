<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Contract;

use Nowo\ContactFormBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

/**
 * REQ-UI-003 page contract: the admin pages extend UiKit scaffolds (list, form, wizard, show), the public contact form
 * is a unique page declared in docs/TEMPLATES.md on the guest layout; no <html>, CDN, inline handler or confirm();
 * framework classes only through theme slots; the stub theme is reflected; no raw or missing translation keys (strict
 * rule); the CSRF forms of the delete actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    use ContactFormContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'forms' => new PageFixture('@NowoContactFormBundle/admin/form/index.html.twig', PageFixtures::formIndex());
        yield 'forms (empty)' => new PageFixture('@NowoContactFormBundle/admin/form/index.html.twig', PageFixtures::formIndex(true));
        yield 'form new' => new PageFixture('@NowoContactFormBundle/admin/form/form.html.twig', PageFixtures::formForm());
        yield 'form edit' => new PageFixture('@NowoContactFormBundle/admin/form/form.html.twig', PageFixtures::formForm(true));
        yield 'fields' => new PageFixture('@NowoContactFormBundle/admin/field/index.html.twig', PageFixtures::fieldIndex());
        yield 'fields (empty)' => new PageFixture('@NowoContactFormBundle/admin/field/index.html.twig', PageFixtures::fieldIndex(true));
        yield 'field new (definition)' => new PageFixture('@NowoContactFormBundle/admin/field/form.html.twig', PageFixtures::fieldWizard('definition'));
        yield 'field edit (select options)' => new PageFixture('@NowoContactFormBundle/admin/field/form.html.twig', PageFixtures::fieldWizard('select_options', true));
        yield 'field edit (content)' => new PageFixture('@NowoContactFormBundle/admin/field/form.html.twig', PageFixtures::fieldWizard('content', true));
        yield 'submissions' => new PageFixture('@NowoContactFormBundle/admin/submission/index.html.twig', PageFixtures::submissionIndex());
        yield 'submissions (empty)' => new PageFixture('@NowoContactFormBundle/admin/submission/index.html.twig', PageFixtures::submissionIndex(true));
        yield 'submission' => new PageFixture('@NowoContactFormBundle/admin/submission/show.html.twig', PageFixtures::submissionShow());
        yield 'submission (anonymous)' => new PageFixture('@NowoContactFormBundle/admin/submission/show.html.twig', PageFixtures::submissionShow(true));
        yield 'public form' => new PageFixture('@NowoContactFormBundle/public/contact_form.html.twig', PageFixtures::publicForm());
    }
}
