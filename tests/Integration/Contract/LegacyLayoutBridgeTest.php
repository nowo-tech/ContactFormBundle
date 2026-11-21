<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\ContactFormBundle\Tests\Support\PageFixtures;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * 2.0 removed the 1.x admin layout bridge (deprecated since 1.6): a configured layout without the UiKit canonical
 * blocks (a 1.x layout, or a copy of the removed 1.x bundle layout) fails with a clear error naming the layout and the
 * block to add. A layout with the canonical blocks is used as is.
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyLayouts(): iterable
    {
        yield 'plain 1.x layout' => ['legacy/plain.html.twig'];
        yield 'copy of the removed 1.x bundle layout' => ['legacy/bundle-copy.html.twig'];
    }

    #[Test]
    #[DataProvider('legacyLayouts')]
    public function aLayoutWithoutTheCanonicalBlocksFailsClearly(string $layout): void
    {
        $pages = new ContactFormPages($layout, null, [], $this->twig());

        try {
            $pages->layout();
            self::fail('A 1.x layout must be refused since 2.0.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($layout, $exception->getMessage());
            self::assertStringContainsString('nowo_ui_page_header', $exception->getMessage());
        }
        self::assertSame(['a' => 1], $pages->context(['a' => 1]), 'no bridge variables any more');
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
        self::assertSame([], $pages->context([]));

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
        self::assertSame([], $noTwig->context([]));

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
