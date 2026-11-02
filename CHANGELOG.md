# Changelog

## Unreleased

- Validate every configuration field before saving; reject invalid selection values and malformed input.
- Report failed configuration writes without a success message and explain partial persistence.
- Require POST and module configure permission for settings writes; use the native configure URL.
- Keep malformed submissions renderable, show the module version, and add isolated settings regressions.

## 1.1.7

- Fix module config redirect URL for Nginx compatibility

## 1.1.6

- Add test mode option (disabled, always passes, always fails, force interactive)

## 1.1.5

- Add appearance mode option (always, execute, interaction-only/invisible)
- Add German, Spanish and Italian translations

## 1.1.4

- Add newsletter registration form ([@jf-viguier](https://github.com/jf-viguier))

## 1.1.3

- Fixed widget not showing on third party form

## 1.1.2

- Fix current theme directory retrieval ([@jf-viguier](https://github.com/jf-viguier))

## 1.1.1

- Fix renew password form turnstile error

## 1.1.0

- Prestashop 8.0.0 compatibility

## 1.0.3

- Custom or third party form validation

## 1.0.2

- Action option added

## 1.0.1

- Display full error messages instead of error codes
- PHPDoc updated

## 1.0.0

- First stable release