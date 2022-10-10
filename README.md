# Prestashop Cloudflare Turnstile

## Presentation

[Turnstile](https://developers.cloudflare.com/turnstile/) is Cloudflare's smart CAPTCHA alternative. The module allows Turnstile to protect your Prestashop forms:

- Contact
- Login
- Register
- Reset password

## Requirements

- Prestashop >= 1.7.6.0
- PHP >= 7.2.0

## Installation

Copy the **pixel_cloudflare_turnstile** module in your prestashop **modules** directory.

Then, install the module in the Admin module catalog.

## Configuration

- **Sitekey**: the sitekey given for the site in your Cloudflare dashboard
- **Secret key**: the secret key given for the site in your Cloudflare dashboard
- **Theme**: the Turnstile theme (auto, light or dark)
- **Forms to validate**: the forms where a Turnstile validation is required

For the registration form, the widget is automatically added with a hook. For "contact", "login" and "reset password" forms, **you need to manually add the widget in the template files**, usually before the validation button.

**Never select a form to validate without the widget in the form template.**

### Widget

```html
{widget name='pixel_cloudflare_turnstile'}
```

Override the default configured theme by adding a theme option (auto, light or dark):

```html
{widget name='pixel_cloudflare_turnstile' theme='dark'}
```

### Forms

| Form           | Template                                                                      |
|----------------|-------------------------------------------------------------------------------|
| Contact        | themes/{themeName}/modules/contactform/views/templates/widget/contactform.tpl |
| Login          | themes/{themeName}/templates/customer/_partials/login-form.tpl                |
| Reset password | themes/{themeName}/templates/customer/password-email.tpl                      |

### Testing

Use the following sitekeys and secret keys for testing purposes:

**Sitekey**

| Sitekey                  | Description                     |
|--------------------------|---------------------------------|
| 1x00000000000000000000AA | Always passes                   |
| 2x00000000000000000000AB | Always blocks                   |
| 3x00000000000000000000FF | Forces an interactive challenge |

**Secret key**

| Secret key                          | Description                          |
|-------------------------------------|--------------------------------------|
| 1x0000000000000000000000000000000AA | Always passes                        |
| 2x0000000000000000000000000000000AA | Always fails                         |
| 3x0000000000000000000000000000000AA | Yields a "token already spent" error |