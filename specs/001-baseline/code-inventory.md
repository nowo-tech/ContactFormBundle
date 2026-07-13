# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/contact-form-bundle`  
**Last audited**: 2026-07-07

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/routes.yaml` | Route imports | FR-DI-001 |
| `Resources/config/packages/nowo_contact_form.yaml` | Default package | FR-DI-001 |

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoContactFormBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoContactFormExtension.php` | DI extension | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig paths | FR-TWIG-001 |

## Entities

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/ContactForm.php` | Form entity | FR-ORM-001 |
| `Entity/ContactFormTranslation.php` | Form i18n | FR-ORM-001 |
| `Entity/ContactFormField.php` | Field entity | FR-ORM-001 |
| `Entity/ContactFormFieldTranslation.php` | Field i18n | FR-ORM-001 |
| `Entity/ContactSubmission.php` | Submission | FR-ORM-001 |
| `Entity/ContactSubmissionValue.php` | Field values | FR-ORM-001 |

## Repositories

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/ContactFormRepository.php` | Form repo | FR-ORM-002 |
| `Repository/ContactFormTranslationRepository.php` | Form i18n repo | FR-ORM-002 |
| `Repository/ContactFormFieldRepository.php` | Field repo | FR-ORM-002 |
| `Repository/ContactFormFieldTranslationRepository.php` | Field i18n repo | FR-ORM-002 |
| `Repository/ContactSubmissionRepository.php` | Submission repo | FR-ORM-002 |
| `Repository/ContactSubmissionValueRepository.php` | Values repo | FR-ORM-002 |

## Enums & events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/ContactFieldType.php` | Field types | FR-ENUM-001 |
| `Enum/ContactPhoneWidget.php` | Phone widgets | FR-ENUM-001 |
| `Event/ContactSubmissionCreatedEvent.php` | Submission event | FR-EVT-001 |
| `EventSubscriber/ContactFormAdminLocaleSubscriber.php` | Admin locale | FR-EVT-001 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/ContactFormAdminController.php` | Form admin | FR-CTRL-001 |
| `Controller/ContactFormFieldAdminController.php` | Field admin | FR-CTRL-001 |
| `Controller/ContactSubmissionAdminController.php` | Submission admin | FR-CTRL-001 |
| `Controller/ContactFormPublicController.php` | Public form | FR-CTRL-001 |
| `Controller/ContactFormPublicLegacyController.php` | Legacy public | FR-CTRL-001 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ContactFormType.php` | Form definition | FR-FORM-001 |
| `Form/ContactFormTranslationType.php` | Form translation | FR-FORM-001 |
| `Form/ContactFormFieldFlowType.php` | Field wizard | FR-FORM-001 |
| `Form/ContactFormFieldDefinitionStepType.php` | Definition step | FR-FORM-001 |
| `Form/ContactFormFieldContentStepType.php` | Content step | FR-FORM-001 |
| `Form/ContactFormFieldSelectOptionsStepType.php` | Select step | FR-FORM-001 |
| `Form/ContactFormFieldPhonePrefixesStepType.php` | Phone step | FR-FORM-001 |
| `Form/ContactFormFieldTranslationType.php` | Field translation | FR-FORM-001 |
| `Form/ContactFormFieldWizardStepTrait.php` | Wizard shared | FR-FORM-002 |
| `Form/ContactPhoneType.php` | Phone field | FR-FORM-001 |

## Services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/ContactSubmissionProcessor.php` | Submit pipeline | FR-SVC-001 |
| `Service/DynamicContactFormBuilder.php` | Runtime form | FR-SVC-001 |
| `Service/ContactFormSubmissionValueNormalizer.php` | Value normalize | FR-SVC-001 |
| `Service/ContactFormSubmissionRateLimiter.php` | Rate limit | FR-SVC-001 |
| `Service/ContactFormRichTextSanitizer.php` | HTML sanitize | FR-SVC-001 |
| `Service/ClientResolverInterface.php` | Client contract | FR-SVC-002 |
| `Service/SecurityClientResolver.php` | Security client | FR-SVC-002 |
| `Service/ClientLabelResolver.php` | Client labels | FR-SVC-002 |
| `Service/ContactPhonePrefixResolver.php` | Phone prefix | FR-SVC-003 |
| `Service/ContactPhoneInputOptionsResolver.php` | Phone options | FR-SVC-003 |
| `Service/ContactPhoneInputAvailability.php` | PhoneInput check | FR-SVC-003 |
| `Service/ContactPhoneValue.php` | Phone value VO | FR-SVC-003 |
| `Service/ContactFormFieldSelectOptionsSynchronizer.php` | Select sync | FR-SVC-004 |
| `Service/ContactFormFileUploadHandlerInterface.php` | Upload contract | FR-SVC-004 |
| `Service/NullContactFormFileUploadHandler.php` | Null upload | FR-SVC-004 |
| `Service/IpAnonymizer.php` | GDPR IP mask | FR-SVC-005 |
| `Service/SubmissionRetentionCleanupService.php` | Retention purge | FR-SVC-005 |

## Notifications

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Notification/ContactSubmissionNotifierInterface.php` | Notify contract | FR-NOT-001 |
| `Notification/ContactSubmissionNotification.php` | Notify DTO | FR-NOT-001 |
| `Notification/MailerContactSubmissionNotifier.php` | Mailer impl | FR-NOT-001 |
| `Notification/NullContactSubmissionNotifier.php` | Null impl | FR-NOT-001 |

## Phone

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Phone/ContactFormFieldPhoneOptions.php` | Phone options VO | FR-PHONE-001 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/CleanupExpiredSubmissionsCommand.php` | Retention CLI | FR-CLI-001 |

## Twig PHP

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/ContactFormAdminTwigExtension.php` | Admin helpers | FR-TWIG-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoContactFormBundle.en.yaml` | English | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.es.yaml` | Spanish | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.de.yaml` | German | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.fr.yaml` | French | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.it.yaml` | Italian | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.nl.yaml` | Dutch | FR-I18N-001 |
| `Resources/translations/NowoContactFormBundle.pt.yaml` | Portuguese | FR-I18N-001 |

## Twig views — admin

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/admin/layout.html.twig` | Admin layout | FR-TWIG-002 |
| `Resources/views/admin/_locale_switch.html.twig` | Locale switch | FR-TWIG-002 |
| `Resources/views/admin/form/index.html.twig` | Form list | FR-TWIG-002 |
| `Resources/views/admin/form/form.html.twig` | Form edit | FR-TWIG-002 |
| `Resources/views/admin/form/_translation_tabs.html.twig` | Form i18n tabs | FR-TWIG-002 |
| `Resources/views/admin/field/index.html.twig` | Field list | FR-TWIG-002 |
| `Resources/views/admin/field/form.html.twig` | Field edit | FR-TWIG-002 |
| `Resources/views/admin/field/_translation_tabs.html.twig` | Field i18n tabs | FR-TWIG-002 |
| `Resources/views/admin/submission/index.html.twig` | Submissions list | FR-TWIG-002 |
| `Resources/views/admin/submission/show.html.twig` | Submission detail | FR-TWIG-002 |

## Twig views — public

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/public/contact_form.html.twig` | Public page | FR-TWIG-002 |
| `Resources/views/public/_form_fields.html.twig` | Field partial | FR-TWIG-002 |
| `Resources/views/form/contact_phone.html.twig` | Phone widget | FR-TWIG-002 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config | 3 | 3 |
| Bundle & DI | 4 | 4 |
| Entities | 6 | 6 |
| Repositories | 6 | 6 |
| Enums & events | 4 | 4 |
| Controllers | 5 | 5 |
| Forms | 10 | 10 |
| Services | 17 | 17 |
| Notifications | 4 | 4 |
| Phone | 1 | 1 |
| CLI | 1 | 1 |
| Twig PHP | 1 | 1 |
| Translations | 7 | 7 |
| Twig admin | 10 | 10 |
| Twig public | 3 | 3 |
| **Total production sources** | **82** | **82** |
