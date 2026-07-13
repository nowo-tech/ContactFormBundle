# Installation

1. `composer require nowo-tech/contact-form-bundle`
2. Register `Nowo\ContactFormBundle\NowoContactFormBundle` in `config/bundles.php` if Flex does not register it automatically.
3. Import `@NowoContactFormBundle/Resources/config/routes.yaml` in `config/routes.yaml`.
4. Ensure Doctrine is configured; bundle entities are auto-mapped.
5. Create the database schema:
   - `php bin/console doctrine:schema:update --force`, **or**
   - add a migration in your application (see reference SQL in `demo/symfony8/migrations/Version20250619100000.php`).
6. Optional: copy defaults from `vendor/nowo-tech/contact-form-bundle/src/Resources/config/packages/nowo_contact_form.yaml` to `config/packages/nowo_contact_form.yaml`.
7. Schedule GDPR cleanup in production: `php bin/console nowo:contact-form:cleanup-submissions` (see [`CONFIGURATION.md`](CONFIGURATION.md)).

Tables created: `nowo_contact_form`, `nowo_contact_form_translation`, `nowo_contact_form_field`, `nowo_contact_form_field_translation`, `nowo_contact_submission`, `nowo_contact_submission_value`.
