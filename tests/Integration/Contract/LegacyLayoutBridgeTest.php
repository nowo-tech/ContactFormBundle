<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Contract;

use Nowo\ContactFormBundle\Tests\Support\PageFixtures;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function substr_count;

/**
 * Deprecated 1.x admin layouts (no UiKit canonical blocks) keep working through the bridge: the page renders inside
 * the host `content` block, once, with title, assets and flashes mapped; canonical blocks the 1.x layout renders itself
 * (a copy of the removed bundle layout) are not printed twice. A layout with the canonical blocks is used as is.
 */
#[CoversClass(ContactFormPages::class)]
final class LegacyLayoutBridgeTest extends TestCase
{
    public const LAYOUTS = [
        // A host layout written for 1.x: title, stylesheets, content, javascripts
        'legacy/plain.html.twig' => '<header data-host="plain"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block content %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        // A copy of the removed 1.x bundle layout: renders flashes, nowo_ui_content (wrapping content) and modals itself
        'legacy/bundle-copy.html.twig' => '<header data-host="copy"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}</header>'
            . '<div data-host-flashes>{% block nowo_ui_flashes %}{% endblock %}</div>'
            . '<div data-host-content>{% block nowo_ui_content %}{% block content %}{% endblock %}{% endblock %}</div>'
            . '{% block nowo_ui_modals %}{% endblock %}{% block javascripts %}{% endblock %}',
        // A host layout that already renders the UiKit canonical blocks
        'canonical.html.twig' => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
    ];

    #[Test]
    #[IgnoreDeprecations]
    public function aPlainLegacyLayoutRendersThePageInItsContentBlock(): void
    {
        $twig    = $this->twig();
        $pages   = new ContactFormPages('legacy/plain.html.twig', null, [], $twig);
        $factory = PageFixtures::factory($pages);

        $this->expectUserDeprecationMessageMatches('/legacy\/plain\.html\.twig/');
        self::assertSame(ContactFormPages::LEGACY_ADMIN_BRIDGE, $pages->layout());
        $context = $factory->context(PageFixtures::formIndex(false, $factory));
        self::assertSame('legacy/plain.html.twig', $context['nowo_contact_form_legacy_layout']);
        self::assertSame([], $context['nowo_contact_form_legacy_blocks']);

        $html = $twig->render('@NowoContactFormBundle/admin/form/index.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-host="plain"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="contact-form-forms"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="contact-form-forms"'));
        self::assertStringContainsString('<title>Contact forms</title>', $html);
        self::assertStringContainsString('data-host-css', $html);
        self::assertStringContainsString('nowo-ui.css', $html);
        self::assertStringContainsString('data-host-js', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function aCopyOfTheRemovedBundleLayoutDoesNotPrintBlocksTwice(): void
    {
        $twig    = $this->twig();
        $pages   = new ContactFormPages('legacy/bundle-copy.html.twig', null, [], $twig);
        $factory = PageFixtures::factory($pages);

        $this->expectUserDeprecationMessageMatches('/legacy\/bundle-copy\.html\.twig/');
        $context = $factory->context(PageFixtures::submissionShow(false, $factory));
        self::assertSame(['nowo_ui_flashes', 'nowo_ui_content', 'nowo_ui_modals'], $context['nowo_contact_form_legacy_blocks']);

        $html = $twig->render('@NowoContactFormBundle/admin/submission/show.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-testid="contact-form-submission"'));
        self::assertMatchesRegularExpression('/<div data-host-content>\s*<div class="nowo-ui-show-page"/', $html);
        self::assertMatchesRegularExpression('/<div data-host-flashes>.*data-nowo-ui-contract-partial="page_header"/s', $html, 'page header before the content');
        self::assertSame(1, substr_count($html, 'data-nowo-ui-contract-partial="page_header"'));
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig();
        $pages = new ContactFormPages('canonical.html.twig', 'guest.html.twig', ['form_index' => 'host/forms.html.twig'], $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame('guest.html.twig', $pages->publicLayout());
        self::assertSame('host/forms.html.twig', $pages->template('form_index'));
        self::assertSame(ContactFormPages::TEMPLATES['form_form'], $pages->template('form_form'));
        self::assertSame(['nowo_contact_form_legacy_layout' => null, 'nowo_contact_form_legacy_blocks' => []], $pages->context([]));

        $factory = PageFixtures::factory($pages);
        $html    = $twig->render('@NowoContactFormBundle/admin/form/index.html.twig', $factory->context(PageFixtures::formIndex(false, $factory)));
        self::assertStringContainsString('<div data-host-canonical><title>Contact forms</title>', $html);
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigFallBackToTheConfiguredValue(): void
    {
        $missing = new ContactFormPages('missing/layout.html.twig', '', [], $this->twig());
        self::assertSame('missing/layout.html.twig', $missing->layout());
        self::assertNull($missing->publicLayout());

        $noTwig = new ContactFormPages('legacy/plain.html.twig');
        self::assertSame('legacy/plain.html.twig', $noTwig->layout());
        self::assertSame(['nowo_contact_form_legacy_layout' => null, 'nowo_contact_form_legacy_blocks' => []], $noTwig->context([]));

        $default = new ContactFormPages();
        self::assertNull($default->layout());
        self::assertNull($default->publicLayout());
        self::assertSame('unknown_page', $default->template('unknown_page'));
        self::assertSame(['key' => 'k', 'domain' => ContactFormPages::DOMAIN, 'params' => ['%n%' => 1]], (array) ContactFormPages::label('k', ['%n%' => 1]));
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
            use ContactFormContractEnvironment;

            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function inlineTemplates(): array
            {
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
