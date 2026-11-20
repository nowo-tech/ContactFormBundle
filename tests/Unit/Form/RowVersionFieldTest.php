<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Form\RowVersionField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

#[CoversClass(RowVersionField::class)]
final class RowVersionFieldTest extends TestCase
{
    public function testTheTokenIsFilledFromTheEditedEntityAndRoundTrips(): void
    {
        $contactForm = (new ContactForm())->setRowVersion(7);
        $form        = $this->form($contactForm);

        self::assertSame('7', $form->get(RowVersionField::NAME)->getData());
        self::assertSame('7', $form->createView()->children[RowVersionField::NAME]->vars['value']);

        $form->submit([RowVersionField::NAME => '7']);
        self::assertSame(7, RowVersionField::read($form));
        self::assertSame(7, $contactForm->getRowVersion(), 'the token is unmapped: it never writes to the entity');
    }

    public function testAddingTheFieldTwiceKeepsOneField(): void
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, new ContactForm());
        RowVersionField::add($builder);
        RowVersionField::add($builder);

        self::assertCount(1, $builder->all());
        self::assertSame(HiddenType::class, $builder->get(RowVersionField::NAME)->getType()->getInnerType()::class);
    }

    public function testAFormWithoutAnEntityKeepsAnEmptyToken(): void
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, null, ['data_class' => null]);
        RowVersionField::add($builder);
        $form = $builder->getForm();

        self::assertNull($form->get(RowVersionField::NAME)->getData());
        $form->submit([]);
        self::assertSame(RowVersionField::MISSING, RowVersionField::read($form));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function submittedTokens(): iterable
    {
        yield 'whole number' => ['12', 12];
        yield 'missing' => [null, RowVersionField::MISSING];
        yield 'empty' => ['', RowVersionField::MISSING];
        yield 'negative' => ['-3', RowVersionField::MISSING];
        yield 'decimal' => ['1.5', RowVersionField::MISSING];
        yield 'text' => ['abc', RowVersionField::MISSING];
    }

    #[DataProvider('submittedTokens')]
    public function testReadOnlyAcceptsAWholeNumber(mixed $submitted, int $expected): void
    {
        $form = $this->form(new ContactForm());
        $form->submit($submitted === null ? [] : [RowVersionField::NAME => $submitted]);

        self::assertSame($expected, RowVersionField::read($form));
    }

    public function testAFormWithoutTheFieldReadsAsMissing(): void
    {
        $form = Forms::createFormFactory()->createBuilder(FormType::class, null, ['data_class' => null])->getForm();
        $form->submit([]);

        self::assertSame(RowVersionField::MISSING, RowVersionField::read($form));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(ContactForm $contactForm): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $contactForm, ['data_class' => ContactForm::class]);
        RowVersionField::add($builder);

        return $builder->getForm();
    }
}
