<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;

#[CoversClass(ContactPhoneType::class)]
final class ContactPhoneTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new ContactPhoneType()),
        ];
    }

    public function testWidgetNamesTheGroupAndBothControls(): void
    {
        $form = $this->factory->createNamed('phone', ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
            'label'    => 'Phone',
        ]);

        $views  = dirname(__DIR__, 3) . '/src/Resources/views';
        $loader = new FilesystemLoader();
        $loader->addPath($views, 'NowoContactFormBundle');
        $loader->addPath(dirname((string) (new ReflectionClass(FormExtension::class))->getFileName(), 2) . '/Resources/views/Form');
        $twig       = new Environment($loader);
        $translator = new Translator('es');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3) . '/src/Resources/translations/NowoContactFormBundle.es.yaml', 'es', 'NowoContactFormBundle');
        $twig->addExtension(new TranslationExtension($translator));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig', '@NowoContactFormBundle/form/contact_phone.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());

        $html = $twig->createTemplate('{{ form_widget(form) }}')->render(['form' => $form->createView()]);

        // WCAG 1.3.1 / 4.1.2: the rows render no <label> for the two controls, so each carries its own name.
        self::assertStringContainsString('role="group" aria-label="Phone"', $html);
        self::assertMatchesRegularExpression('/<select id="phone_prefix"[^>]*aria-label="Prefijo del país"/', $html);
        self::assertMatchesRegularExpression('/<input type="tel" id="phone_number"[^>]*aria-label="Número de teléfono"/', $html);
        self::assertStringContainsString('autocomplete="tel-national"', $html, 'the type attributes are kept');
    }

    public function testSubmitCombinesPrefixAndNumber(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)', '+1' => 'US (+1)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => '600 111 222',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame('+34600111222', $form->getData());
        self::assertSame('contact_phone', $form->getConfig()->getType()->getBlockPrefix());
    }

    public function testSubmitEmptyReturnsNull(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => '',
        ]);

        self::assertNull($form->getData());
    }

    public function testViewDataSplitsCombinedNumber(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, '+34600111222', [
            'prefixes' => ['+34' => 'ES (+34)', '+1' => 'US (+1)'],
        ]);

        self::assertSame('+34', $form->get('prefix')->getData());
        self::assertSame('600111222', $form->get('number')->getData());
    }

    public function testViewDataUsesDefaultPrefixForEmptyValue(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        self::assertSame('+34', $form->get('prefix')->getData());
        self::assertSame('', $form->get('number')->getData());
    }

    public function testSubmitNumberWithoutPrefixUsesCombine(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '',
            'number' => '600111222',
        ]);

        self::assertSame('600111222', $form->getData());
    }

    public function testSubmitInvalidNumberFailsValidation(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => 'abc',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testConfigureOptionsRequiresPrefixes(): void
    {
        $resolver = new OptionsResolver();
        (new ContactPhoneType())->configureOptions($resolver);

        self::assertSame([], $resolver->resolve(['prefixes' => []])['prefixes']);
    }

    public function testReverseTransformOfANonArrayIsNull(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, ['prefixes' => ['+34' => 'ES (+34)']]);

        self::assertNull($form->getConfig()->getModelTransformers()[0]->reverseTransform(null));
    }
}
