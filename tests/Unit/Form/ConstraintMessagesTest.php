<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

use function dirname;

/**
 * Symfony translates constraint messages in the `validators` domain, never in the bundle's own domain. A dotted key
 * that lives only in `NowoContactFormBundle.<locale>.yaml` would reach the end user as raw text.
 */
#[CoversNothing]
final class ConstraintMessagesTest extends TypeTestCase
{
    private const TRANSLATIONS = '/src/Resources/translations';

    /**
     * @return list<string>
     */
    private static function shippedLocales(): array
    {
        $locales = [];
        foreach (glob(dirname(__DIR__, 3) . self::TRANSLATIONS . '/NowoContactFormBundle.*.yaml') ?: [] as $file) {
            $locales[] = explode('.', basename($file))[1];
        }
        sort($locales);

        return $locales;
    }

    /**
     * @return list<string>
     */
    private static function dottedConstraintMessages(): array
    {
        $found = [];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src')),
            '/\.php$/',
        );
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            // Literal constraint messages: `message: 'a.b'` or `new X('...', message: ...)`-style named args, plus any
            // `*Message: 'a.b'` option; and the own-domain prefix used as a literal on a line that builds a constraint.
            if (preg_match_all('/[mM]essage\w*\s*(?::|=>)\s*\'([a-z_]+\.[a-z_.]+)\'/', $source, $m) > 0) {
                array_push($found, ...$m[1]);
            }
        }

        return array_values(array_unique($found));
    }

    public function testEveryDottedConstraintMessageExistsInValidatorsForEveryShippedLocale(): void
    {
        $messages = self::dottedConstraintMessages();
        self::assertContains('nowo_contact_form.public.phone_number_invalid', $messages);

        foreach (self::shippedLocales() as $locale) {
            $file = dirname(__DIR__, 3) . self::TRANSLATIONS . '/validators.' . $locale . '.yaml';
            self::assertFileExists($file, $locale);
            $flat = self::flatten(Yaml::parseFile($file));

            foreach ($messages as $key) {
                self::assertArrayHasKey($key, $flat, sprintf('%s missing in validators.%s.yaml', $key, $locale));
                self::assertNotSame('', trim((string) $flat[$key]), $key . ' ' . $locale);
            }
        }
    }

    public function testInvalidPhoneNumberRendersTranslatedTextInEveryShippedLocale(): void
    {
        foreach (self::shippedLocales() as $locale) {
            $translator = new Translator($locale);
            $translator->addLoader('yaml', new YamlFileLoader());
            $translator->addResource('yaml', dirname(__DIR__, 3) . self::TRANSLATIONS . '/validators.' . $locale . '.yaml', $locale, 'validators');
            $validator = Validation::createValidatorBuilder()->setTranslator($translator)->setTranslationDomain('validators')->getValidator();

            $form = $this->createFormFactoryFor($validator)->createNamed('phone', ContactPhoneType::class, null, [
                'prefixes' => ['+34' => 'ES (+34)'],
            ]);
            $form->submit(['prefix' => '+34', 'number' => 'abc']);

            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }
            self::assertNotSame([], $errors, $locale);
            foreach ($errors as $message) {
                self::assertStringNotContainsString('nowo_contact_form.', $message, $locale);
            }
        }
    }

    private function createFormFactoryFor(\Symfony\Component\Validator\Validator\ValidatorInterface $validator): \Symfony\Component\Form\FormFactoryInterface
    {
        return \Symfony\Component\Form\Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->addType(FormKitTestSupport::withMerger(new ContactPhoneType()))
            ->getFormFactory();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            is_array($v) ? $out += self::flatten($v, $prefix . $k . '.') : $out[$prefix . $k] = $v;
        }

        return $out;
    }
}
