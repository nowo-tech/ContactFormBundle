<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\VersionedRowInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

use function ctype_digit;
use function is_string;

/**
 * The hidden base token of an admin edit form (REQ-RUNTIME-003 rule 4): the row version of the page, unmapped.
 *
 * `add()` puts it on the form and fills it from the edited entity when the form is built; `read()` returns the token
 * a submitted form carries. The admin controllers pass it to the save, which refuses the edit when another admin saved
 * the row in between ({@see RowVersionGuard}). Stateless helpers.
 */
final class RowVersionField
{
    /** Name of the hidden field; render it with `form_rest()` (custom templates that list the fields must render it). */
    public const NAME = 'rowVersion';

    /** Token read from a form that has no valid token field: never equal to a stored version (they start at 1). */
    public const MISSING = 0;

    /**
     * Adds the unmapped hidden token field (once) and fills it with the version of the edited entity.
     *
     * @param FormBuilderInterface<mixed> $builder The form builder of an edit form whose data is a versioned entity
     */
    public static function add(FormBuilderInterface $builder): void
    {
        if ($builder->has(self::NAME)) {
            return;
        }

        $builder->add(self::NAME, HiddenType::class, ['mapped' => false, 'required' => false, 'label' => false]);
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $row = $event->getData();
            if ($row instanceof VersionedRowInterface) {
                $event->getForm()->get(self::NAME)->setData($row->getRowVersion());
            }
        });
    }

    /**
     * The token a submitted form carries, or {@see self::MISSING} when the field is absent or not a whole number.
     *
     * @param FormInterface<mixed> $form The submitted form
     */
    public static function read(FormInterface $form): int
    {
        $token = $form->has(self::NAME) ? $form->get(self::NAME)->getData() : null;

        return is_string($token) && $token !== '' && ctype_digit($token) ? (int) $token : self::MISSING;
    }
}
