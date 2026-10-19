# Templates

Admin templates live in `src/Resources/views/admin/` (Twig namespace `@NowoContactFormBundle`). Override one by
copying it to `templates/bundles/NowoContactFormBundle/admin/<same path>`.

## Table of contents

- [Translation tabs](#translation-tabs)
- [Layout and scripts](#layout-and-scripts)

## Translation tabs

| Template | Page | Tabs id |
| --- | --- | --- |
| `admin/form/_translation_tabs.html.twig` | contact form new / edit (`form.translations`, `ContactFormTranslationType` entries) | `form-translation` |
| `admin/field/_translation_tabs.html.twig` | field wizard, content step (`stepForm.translations`, `ContactFormFieldTranslationType` entries) | `field-translation` |

Both take `form` (the form holding `translations`) and include FormKit's `@NowoFormKitBundle/form/_locale_tabs.html.twig`
in mode `entries`: `default_locale` = `contact_form_admin_default_locale()` (`kernel.default_locale` when enabled, else
the first enabled locale), `required_locales` = every enabled locale (title / label are required), tab names from
`nowo_contact_form.admin.locales.<code>`. FormKit renders through UiKit's `locale_tabs` partial: root `#<tabs id>`,
buttons `#tab-<tabs id>-<locale>`, panels `#tab-<tabs id>-<locale>-panel`, theme slots `tabs`, `tab`, `tab_active`,
`tab_panel`. Without translations the partial prints the `translations_empty` message.

## Layout and scripts

`admin/layout.html.twig` loads the Bootstrap CSS (Bootstrap / Tabler frameworks) but no JavaScript from a CDN.
`admin/base.html.twig` prints UiKit's `scripts` partial (tabs, confirm, …) in `nowo_ui_scripts`, plus the nonce-aware
locale switch / `data-confirm` handler. `admin/_locale_switch.html.twig` is a switch (2 locales) or a native
`<details>` menu (3+ locales).
