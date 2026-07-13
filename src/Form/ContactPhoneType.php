<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Service\ContactPhoneValue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

use function is_array;

/**
 * Phone input with an international dialing prefix selector.
 */
class ContactPhoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $prefixes */
        $prefixes      = $options['prefixes'];
        $prefixCodes   = array_keys($prefixes);
        $choices       = [];
        $defaultPrefix = $prefixCodes[0] ?? '+34';

        foreach ($prefixes as $code => $label) {
            $choices[$label] = $code;
        }

        $builder
            ->add('prefix', ChoiceType::class, [
                'label'    => false,
                'choices'  => $choices,
                'data'     => $defaultPrefix,
                'required' => true,
                'attr'     => [
                    'class' => 'form-select',
                ],
            ])
            ->add('number', TelType::class, [
                'label'    => false,
                'required' => false,
                'attr'     => [
                    'class'        => 'form-control',
                    'inputmode'    => 'tel',
                    'autocomplete' => 'tel-national',
                ],
                'constraints' => [
                    new Regex(
                        pattern: '/^[\d\s().-]*$/',
                        message: 'nowo_contact_form.public.phone_number_invalid',
                    ),
                ],
            ]);

        $builder->addModelTransformer(new CallbackTransformer(
            static function (?string $combined) use ($prefixCodes): ?array {
                if ($combined === null || $combined === '') {
                    return ContactPhoneValue::split(null, $prefixCodes);
                }

                return ContactPhoneValue::split($combined, $prefixCodes);
            },
            static function (?array $parts): ?string {
                if (!is_array($parts)) {
                    return null;
                }

                $prefix = (string) ($parts['prefix'] ?? '');
                $number = (string) ($parts['number'] ?? '');

                if ($prefix === '' || $number === '') {
                    return $number === '' ? null : ContactPhoneValue::combine($prefix, $number);
                }

                return ContactPhoneValue::combine($prefix, $number);
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound'           => true,
            'translation_domain' => 'NowoContactFormBundle',
            'prefixes'           => [],
            'error_bubbling'     => false,
        ]);

        $resolver->setAllowedTypes('prefixes', 'array');
        $resolver->setRequired(['prefixes']);
    }

    public function getBlockPrefix(): string
    {
        return 'contact_phone';
    }
}
