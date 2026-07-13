<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(ContactFormType::class)]
final class ContactFormTypeTest extends TypeTestCase
{
    public function testSubmitValidData(): void
    {
        $form = (new ContactForm())
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Title'));

        $formView = $this->factory->create(ContactFormType::class, $form);

        $formView->submit([
            'name'              => 'Support',
            'slug'              => 'support',
            'enabled'           => true,
            'privacyPolicyUrl'  => 'https://example.com/privacy',
            'retentionDays'     => '30',
            'notificationEmail' => 'admin@example.com',
            'requireConsent'    => true,
            'translations'      => [
                ['title' => 'Contact', 'locale' => 'en'],
            ],
        ]);

        self::assertTrue($formView->isSynchronized());
        self::assertSame('Support', $form->getName());
        self::assertSame('support', $form->getSlug());
        self::assertTrue($form->isRequireConsent());
    }
}
