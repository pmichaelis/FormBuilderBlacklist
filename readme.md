# FormBuilderBlacklist

## ProcessWire - FormBuilder Blacklist

Checks the content of [ProcessWire](https://processwire.com) [FormBuilder](https://processwire.com/store/form-builder/) submissions against blacklists and spam heuristics. Can be enabled per form.

For bot protection with a proof-of-work challenge, see [FormBuilderAltcha](https://github.com/pmichaelis/FormBuilderAltcha).

## Requirements

- PHP 8.1+
- ProcessWire 3.0.200+
- FormBuilder

## Installation

1. Copy the module to `site/modules/FormBuilderBlacklist/`. Dependencies are included in `vendor/`.
2. Modules > Refresh, then install "FormBuilder Blacklist".
3. Edit a form > tab "Actions" > check the action "Filter spam".
4. In the section "Filter spam", open the fieldset "Blacklist & content check" and enable it.

What happens with detected spam (discard silently, show an error, save as spam entry) is controlled by FormBuilder's setting "What should happen when spam is detected?".

## Checks (per form)

- **Blacklists**: keyword lists in `blacklists/` (one regex per line, `#` starts a comment), each list can be enabled separately. Keywords only match as whole words, except in the lists `injection`, `misc` and `urls`.
- **Disposable email addresses** in email fields ([fgribreau/mailchecker](https://github.com/FGRibreau/mailchecker))
- **Maximum number of links** (`http://`, `https://`, `www.`)
- **BBCode and HTML links** (`[url=…]`, `<a href…>`)
- **Foreign scripts**: at least 10 characters and more than 30 % of the text Cyrillic, CJK, Arabic or Thai
- **Fields to check**: all text fields or a selection

Selection fields (select, radios, checkboxes) are not checked, as their values are defined by the admin. Detected spam is written to Setup > Logs > `form-builder-blacklist` when logging is enabled.

## Translations

All strings are translatable with ProcessWire's language support, the source language is English.
A German translation is included in `languages/de.csv`. To import it: Modules > FormBuilder Blacklist > module info > row "Languages" > "install translations".

## Updating dependencies

Run `composer update` in the module directory. A script then removes files from `vendor/fgribreau/mailchecker` that are not needed for PHP.

## License

MIT, see `LICENSE`.

Included third-party code and data:

- Blacklists in `blacklists/` from [IQAndreas/php-spam-filter](https://github.com/IQAndreas/php-spam-filter) (GPLv3, see `blacklists/LICENSE.md`), as of 2013-10-11. The entry `jersey` in `blacklist-merchandise.txt` is commented out because of false positives. The PHP class of that library is not used.
- [fgribreau/mailchecker](https://github.com/FGRibreau/mailchecker) 6 (MIT) in `vendor/`
