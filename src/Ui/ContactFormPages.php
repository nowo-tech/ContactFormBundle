<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Ui;

use LogicException;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use Twig\Environment;

use function is_string;
use function sprintf;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layouts the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null =
 * `nowo_ui_kit.layouts.guest`) and the per-page template overrides (`web_ui.templates.<page>`). A configured admin
 * layout must render the UiKit canonical blocks: 2.0 removed the 1.x layout bridge (deprecated since 1.6), so a layout
 * without them fails with a clear error instead of rendering an empty page. Configuration only: no request state, safe
 * in worker mode.
 */
final readonly class ContactFormPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoContactFormBundle`). */
    public const BUNDLE = 'NowoContactFormBundle';

    /** Translation domain of the bundle labels. */
    public const DOMAIN = 'NowoContactFormBundle';

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'form_index'       => '@NowoContactFormBundle/admin/form/index.html.twig',
        'form_form'        => '@NowoContactFormBundle/admin/form/form.html.twig',
        'field_index'      => '@NowoContactFormBundle/admin/field/index.html.twig',
        'field_form'       => '@NowoContactFormBundle/admin/field/form.html.twig',
        'submission_index' => '@NowoContactFormBundle/admin/submission/index.html.twig',
        'submission_show'  => '@NowoContactFormBundle/admin/submission/show.html.twig',
        'public_form'      => '@NowoContactFormBundle/public/contact_form.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /**
     * @param array<string, string> $templates `web_ui.templates` (page id => template)
     */
    public function __construct(
        #[Autowire('%nowo_contact_form.web_ui.layout_template%')]
        private ?string $layoutTemplate = null,
        #[Autowire('%nowo_contact_form.web_ui.public_layout_template%')]
        private ?string $publicLayoutTemplate = null,
        #[Autowire('%nowo_contact_form.web_ui.templates%')]
        private array $templates = [],
        // Autowired by type: `#[Autowire('@?twig')]` is not an optional reference (the id becomes "?twig").
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$page] ?? $page;
    }

    /**
     * Layout the admin scaffolds extend (`page.layout`): `web_ui.layout_template`, else null (UiKit
     * `nowo_ui_layout.admin`).
     *
     * @throws LogicException when the configured layout does not render the UiKit canonical blocks (a 1.x layout)
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }

        $this->assertCanonical($this->layoutTemplate);

        return $this->layoutTemplate;
    }

    /**
     * Layout of the public contact form page: `web_ui.public_layout_template`, else null (UiKit
     * `nowo_ui_layout.guest`).
     */
    public function publicLayout(): ?string
    {
        return $this->publicLayoutTemplate === '' ? null : $this->publicLayoutTemplate;
    }

    /**
     * Shared variables of the admin pages, added to the controller variables. Since 2.0 there are none (the 1.x layout
     * bridge variables were removed): the page variables are returned unchanged.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars;
    }

    /**
     * Fails on a configured layout that loads but does not define the UiKit canonical blocks. A layout that cannot be
     * loaded is left to Twig, which reports it when the page extends it.
     */
    private function assertCanonical(string $layout): void
    {
        if (!$this->twig instanceof Environment) {
            return;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return;
        }

        if (!$canonical) {
            throw new LogicException(sprintf('The layout "%s" (nowo_contact_form.web_ui.layout_template) does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...). The 1.x layout bridge (deprecated since 1.6) was removed in 2.0: render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig), or set web_ui.layout_template to null to use nowo_ui_kit.layouts.admin. See TEMPLATES.md "Admin layout" and UPGRADING.md "To 2.0.0".', $layout));
        }
    }
}
