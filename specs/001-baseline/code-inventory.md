# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/contact-form-bundle`  
**Last audited**: 2026-11-16

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
| `DependencyInjection/Compiler/ContactFormSecurityPass.php` | Security wiring | FR-SEC-001 |
| `DependencyInjection/Compiler/ContactFormOptionalServicesPass.php` | Optional clock / mailer wiring on the merged container | FR-CFG-003 |
| `DependencyInjection/Compiler/ContactFormMappingDriverPass.php` | Attribute (default) or XML mapping of the entities (1.9, `doctrine.mapping_type`) | FR-ORM-001 |
| `DependencyInjection/TablePrefixListener.php` | Doctrine `table_prefix` for bundle entity tables | FR-CFG-004 |
| `DependencyInjection/Compiler/ContactFormCsrfProtectionPass.php` | Fails closed when the admin UI is enabled without CSRF protection | FR-SEC-001 |
| `DependencyInjection/Compiler/ContactFormEntityManagerPass.php` | Keeps the bundle entities on `doctrine.entity_manager` only (1.7) | FR-ORM-001, FR-WORKER-001 |
| `DependencyInjection/Compiler/ContactFormCommitTrackingPass.php` | Scopes the commit-tracking middleware to the bundle connection, or reuses transaction-doctrine's ledger (REQ-RUNTIME-003) | FR-CONC-001 |
| `DependencyInjection/Compiler/ContactFormFileUploaderPass.php` | Wires the file ports (1.8) and the deprecated handler bridge | FR-SVC-004 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ContactFormAccessCheckerInterface.php` | Admin access contract | FR-SEC-001 |
| `Security/ConfigurableContactFormAccessChecker.php` | Role-based checker | FR-SEC-001 |
| `Security/AllowAllContactFormAccessChecker.php` | Demo allow-all checker | FR-SEC-001 |
| `Security/SafeRelativePath.php` | Same-origin relative paths for redirects and links (CWE-601) | FR-SEC-002 |
| `Collation/ContactFormExactKeyColumns.php` | Declares `client_reference` as an identity key (REQ-SEC-006) | FR-SEC-003 |
| `Doctrine/ClientReferenceCollationListener.php` | Exact-match collation for `client_reference` on MySQL / MariaDB | FR-SEC-003 |

## Entities

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/ContactForm.php` | Form entity | FR-ORM-001 |
| `Entity/ContactFormTranslation.php` | Form i18n | FR-ORM-001 |
| `Entity/ContactFormField.php` | Field entity | FR-ORM-001 |
| `Entity/ContactFormFieldTranslation.php` | Field i18n | FR-ORM-001 |
| `Entity/ContactSubmission.php` | Submission | FR-ORM-001 |
| `Entity/ContactSubmissionValue.php` | Field values | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactForm.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactFormField.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactFormFieldTranslation.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactFormTranslation.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactSubmission.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Resources/config/doctrine-xml/ContactSubmissionValue.orm.xml` | XML mapping, same as the attributes (1.9, opt-in) | FR-ORM-001 |
| `Doctrine/ExcludedEntityNamespaceDriver.php` | Maps nothing for the bundle namespace on every other entity manager | FR-ORM-001, FR-WORKER-001 |

## Repositories

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/WorkerSafeServiceEntityRepository.php` | Worker-safe repo base | FR-WORKER-001 |
| `Repository/ContactFormRepository.php` | Form repo | FR-ORM-002 / FR-WORKER-001 |
| `Repository/ContactFormTranslationRepository.php` | Form i18n repo | FR-ORM-002 / FR-WORKER-001 |
| `Repository/ContactFormFieldRepository.php` | Field repo | FR-ORM-002 / FR-WORKER-001 |
| `Repository/ContactFormFieldTranslationRepository.php` | Field i18n repo | FR-ORM-002 / FR-WORKER-001 |
| `Repository/ContactSubmissionRepository.php` | Submission repo | FR-ORM-002 / FR-WORKER-001 |
| `Repository/ContactSubmissionValueRepository.php` | Values repo | FR-ORM-002 / FR-WORKER-001 |

## Enums & events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/ContactFieldType.php` | Field types | FR-ENUM-001 |
| `Enum/ContactPhoneWidget.php` | Phone widgets | FR-ENUM-001 |
| `Event/ContactSubmissionCreatedEvent.php` | Submission event | FR-EVT-001 |
| `EventSubscriber/ContactFormAdminLocaleSubscriber.php` | Admin locale | FR-EVT-001 |
| `EventSubscriber/ContactFormAdminAccessSubscriber.php` | Admin access gate | FR-SEC-001 |
| `EventSubscriber/ContactFormEntityManagerSubscriber.php` | Closed EM recovery (worker) | FR-WORKER-001 |
| `Event/ContactSubmissionsDeletedEvent.php` | Dispatched after admin / retention deletions are flushed (1.7) | FR-EVT-001 |
| `EventSubscriber/ContactFormEntityManagerClearSubscriber.php` | Clears the bundle's own entity manager at the outermost unit-of-work boundary | FR-WORKER-001 |
| `EventSubscriber/ContactFormConflictListener.php` | 409 + `CONFLICT_001` / `CONFLICT_002` on lock conflicts, domain 409 on admin duplicate keys (REQ-RUNTIME-003) | FR-CONC-001 |

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
| `Form/ContactFormFieldStepDataInterface.php` | Flow → step data mapping | FR-FORM-002 |
| `Form/ContactPhoneType.php` | Phone field | FR-FORM-001 |

## Services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/ContactSubmissionProcessor.php` | Submit pipeline | FR-SVC-001 / FR-WORKER-001 |
| `Service/ContactFormEntityManagerResolver.php` | Open EM for worker | FR-WORKER-001 |
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
| `Service/ContactFormEnabledLocales.php` | Admin content locales | FR-SVC-006 |
| `Service/ContactSubmissionRecorderInterface.php` | Request-free write port: record a submission from plain values (1.7) | FR-SVC-007 |
| `Service/SubmissionRetentionCleanerInterface.php` | Port behind `nowo:contact-form:cleanup-expired` (1.8) | FR-SVC-005 |
| `Exception/ContactFormNotFoundException.php` | No contact form has the given slug | FR-SVC-007 |
| `Exception/ContactFormFileException.php` | A submitted file cannot be read or stored | FR-SVC-004 |

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

## UI view models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Ui/ContactFormPageFactory.php` | UiKit view models of the pages | FR-UI-001 |
| `Ui/ContactFormPages.php` | Layouts, per-page templates, 1.x layout bridge | FR-UI-001 |

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
| `Resources/views/admin/_legacy_layout.html.twig` | Deprecated 1.x layout bridge | FR-UI-001 |
| `Resources/views/admin/form/index.html.twig` | Form list (list scaffold) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/admin/form/form.html.twig` | Form edit (form scaffold) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/admin/form/_translation_tabs.html.twig` | Form i18n tabs | FR-TWIG-002 |
| `Resources/views/admin/field/index.html.twig` | Field list (list scaffold) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/admin/field/form.html.twig` | Field wizard (wizard scaffold) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/admin/field/_translation_tabs.html.twig` | Field i18n tabs | FR-TWIG-002 |
| `Resources/views/admin/submission/index.html.twig` | Submissions list (list scaffold) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/admin/submission/show.html.twig` | Submission detail (show scaffold) | FR-TWIG-002, FR-UI-001 |

## Twig views — public

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/public/contact_form.html.twig` | Public page (unique page, guest layout) | FR-TWIG-002, FR-UI-001 |
| `Resources/views/public/_form_fields.html.twig` | Field partial | FR-TWIG-002 |
| `Resources/views/form/contact_phone.html.twig` | Phone widget | FR-TWIG-002 |

## Client references

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Client/ClientReference.php` | Host client of a submission as an id reference + optional label (1.7) | FR-SVC-002 |
| `Client/ClientReferenceResolverInterface.php` | Host contract `client_reference_resolver` | FR-SVC-002 |
| `Client/ObjectClientReferenceResolver.php` | Default resolver adapting `ClientResolverInterface` | FR-SVC-002 |

## File upload ports

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Upload/ContactFormFileUploaderInterface.php` | Host port that stores a submitted file (1.8) | FR-SVC-004 |
| `Upload/UploadedContactFile.php` | Framework-free submitted-file value object | FR-SVC-004 |
| `Upload/UploadedContactFileData.php` | Keeps the file values outside the VO so dumps cannot leak paths / names | FR-SVC-004 |
| `Upload/ContactFormFileTarget.php` | File field ids, names and admin options (no entity) | FR-SVC-004 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config | 3 | 3 |
| Bundle & DI | 12 | 12 |
| Security | 6 | 6 |
| Entities | 13 | 13 |
| Repositories | 7 | 7 |
| Enums & events | 9 | 9 |
| Controllers | 5 | 5 |
| Forms | 11 | 11 |
| Services | 23 | 23 |
| Notifications | 4 | 4 |
| Phone | 1 | 1 |
| CLI | 1 | 1 |
| Twig PHP | 1 | 1 |
| UI view models | 2 | 2 |
| Translations | 7 | 7 |
| Twig views — admin | 9 | 9 |
| Twig views — public | 3 | 3 |
| Client references | 3 | 3 |
| File upload ports | 4 | 4 |
| **Total production sources** | **124** | **124** |

Audit command: `git ls-files src | wc -l` → 124.
