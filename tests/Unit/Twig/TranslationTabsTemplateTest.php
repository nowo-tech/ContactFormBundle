<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Twig;

use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormTranslationType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use Nowo\FormKitBundle\Form\Extension\LocaleTabsExtension;
use Nowo\FormKitBundle\Twig\LocaleTabsTwigExtension;
use Nowo\UiKitBundle\Theme\Theme;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;
use function substr_count;

/**
 * Both admin translation partials render FormKit locale tabs (mode entries) through the UiKit partial:
 * default locale first, required / invalid markers, hidden locale input, no Bootstrap tabs.
 */
final class TranslationTabsTemplateTest extends TestCase
{
    public function testFormTranslationTabsListTheDefaultLocaleFirst(): void
    {
        $form = $this->form(ContactFormTranslationType::class, [
            (new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'),
            (new ContactFormTranslation())->setLocale('es')->setTitle('Contacto'),
        ]);

        $html = $this->render('@NowoContactFormBundle/admin/form/_translation_tabs.html.twig', $form);
        $c    = new Crawler($html);

        self::assertSame(['es', 'en'], $c->filter('[role="tab"]')->each(static fn (Crawler $t): string => (string) $t->attr('data-locale')), 'default locale (es) first');
        self::assertSame('form-translation', $c->filter('[data-testid="form-translation-tabs"] [data-nowo-ui-locale-tabs]')->attr('id'));
        self::assertNotNull($c->filter('#tab-form-translation-es')->attr('data-default'));
        self::assertNotNull($c->filter('#tab-form-translation-es')->attr('data-required'));
        self::assertNotNull($c->filter('#tab-form-translation-en')->attr('data-required'));
        self::assertNull($c->filter('#tab-form-translation-en')->attr('data-default'));
        self::assertSame('es', $c->filter('#tab-form-translation-es-panel input[type="hidden"][name$="[locale]"]')->attr('value'));
        self::assertCount(1, $c->filter('#tab-form-translation-en-panel input[name$="[title]"]'));
        self::assertSame(1, substr_count($html, '[translations][0][locale]"'));
        self::assertStringNotContainsString('data-bs-toggle', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testFieldTranslationTabsMarkAndOpenTheInvalidLocale(): void
    {
        $form = $this->form(ContactFormFieldTranslationType::class, [
            (new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email'),
            (new ContactFormFieldTranslation())->setLocale('es')->setLabel('Correo'),
        ]);
        $form->submit(['translations' => [['locale' => 'en', 'label' => 'Email'], ['locale' => 'es', 'label' => 'Correo']]], false);
        $form->get('translations')->get('0')->get('label')->addError(new FormError('Label missing.'));

        $c = new Crawler($this->render('@NowoContactFormBundle/admin/field/_translation_tabs.html.twig', $form));

        self::assertSame(['es', 'en'], $c->filter('[role="tab"]')->each(static fn (Crawler $t): string => (string) $t->attr('data-locale')));
        self::assertSame('field-translation-en', $c->filter('#field-translation')->attr('data-nowo-ui-tabs-active'), 'the invalid tab opens');
        self::assertNotNull($c->filter('#tab-field-translation-en')->attr('data-invalid'));
        self::assertNull($c->filter('#tab-field-translation-es')->attr('data-invalid'));
        self::assertSame('true', $c->filter('#tab-field-translation-en-panel input[name$="[label]"]')->attr('aria-invalid'));
        self::assertStringContainsString('Label missing.', $c->filter('#tab-field-translation-en-panel')->text());
    }

    public function testEmptyTranslationsShowTheEmptyMessage(): void
    {
        $html = $this->render('@NowoContactFormBundle/admin/form/_translation_tabs.html.twig', $this->form(ContactFormTranslationType::class, []));

        self::assertStringContainsString('nowo_contact_form.admin.form.fields.translations_empty', $html);
        self::assertStringNotContainsString('role="tab"', $html);
    }

    /**
     * @param class-string $entryType
     * @param list<object> $translations
     *
     * @return FormInterface<mixed>
     */
    private function form(string $entryType, array $translations): FormInterface
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new LocaleTabsExtension())
            ->addTypes([
                FormKitTestSupport::withMerger(new ContactFormTranslationType()),
                FormKitTestSupport::withMerger(new ContactFormFieldTranslationType()),
            ])
            ->getFormFactory();

        return $factory->createNamedBuilder('contact', FormType::class, ['translations' => $translations])
            ->add('translations', CollectionType::class, [
                'entry_type'    => $entryType,
                'entry_options' => ['label' => false, 'hide_locale' => true, 'error_bubbling' => false],
            ])
            ->getForm();
    }

    /** @param FormInterface<mixed> $form */
    private function render(string $template, FormInterface $form): string
    {
        $root  = dirname(__DIR__, 3);
        $views = new FilesystemLoader([$root . '/vendor/symfony/twig-bridge/Resources/views/Form']);
        $views->addPath($root . '/src/Resources/views', 'NowoContactFormBundle');
        $views->addPath($root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/views', 'NowoFormKitBundle');
        $views->addPath($root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');
        $views->addPath($root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKit');

        $twig = new Environment($views, ['strict_variables' => true, 'cache' => false]);
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new LocaleTabsTwigExtension());
        $twig->addExtension(new ThemeExtension(new Theme()));
        $twig->addExtension(new ContactFormAdminTwigExtension(new ParameterBag([
            'kernel.enabled_locales' => ['en', 'es'],
            'kernel.default_locale'  => 'es',
        ])));

        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));

        return $twig->render($template, ['form' => $form->createView()]);
    }
}
