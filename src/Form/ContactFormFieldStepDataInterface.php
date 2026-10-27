<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Symfony\Component\Form\FormInterface;

/**
 * A field wizard step that fills its unmapped / dynamic children from the field (machine names, phone options, option
 * labels per locale). Symfony does not dispatch PRE / POST_SET_DATA on `inherit_data` children, so
 * {@see ContactFormFieldFlowType} calls this for every step child once the flow data is set.
 *
 * @internal
 */
interface ContactFormFieldStepDataInterface
{
    /**
     * @param FormInterface<mixed> $stepForm
     */
    public function mapFieldToStep(FormInterface $stepForm, ContactFormField $field): void;
}
