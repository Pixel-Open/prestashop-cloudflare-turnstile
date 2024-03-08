<?php
/**
 * Copyright (c) Since 2022 Pixel Développement and contributors
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Addon\Theme\ThemeProviderInterface;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Pixel_cloudflare_turnstile extends Module implements WidgetInterface
{
    public const TURNSTILE_SESSION_ERROR_KEY = 'turnstile_error';

    public const CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY = 'CLOUDFLARE_TURNSTILE_SITEKEY';
    public const CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY = 'CLOUDFLARE_TURNSTILE_SECRET_KEY';
    public const CONFIG_CLOUDFLARE_TURNSTILE_THEME = 'CLOUDFLARE_TURNSTILE_THEME';
    public const CONFIG_CLOUDFLARE_TURNSTILE_FORMS = 'CLOUDFLARE_TURNSTILE_FORMS';

    public const FORM_CONTACT = 'contact';
    public const FORM_LOGIN = 'login';
    public const FORM_REGISTER = 'register';
    public const FORM_PASSWORD = 'password';
    public const FORM_NEWSLETTER = 'newsletter';

    protected $templateFile;

    protected static $validationError;

    /**
     * Module's constructor.
     */
    public function __construct()
    {
        $this->name = 'pixel_cloudflare_turnstile';
        $this->version = '1.1.4';
        $this->author = 'Pixel Open';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans(
            'Cloudflare Turnstile',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $this->description = $this->trans(
            'Protect your store from spam messages and spam user accounts.',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $this->ps_versions_compliancy = [
            'min' => '1.7.6.0',
            'max' => _PS_VERSION_,
        ];

        $this->templateFile = 'module:' . $this->name . '/pixel_cloudflare_turnstile.tpl';
    }

    /***************************/
    /** MODULE INITIALIZATION **/
    /***************************/

    /**
     * Install the module
     *
     * @return bool
     */
    public function install(): bool
    {
        return parent::install() &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayCustomerAccountForm') &&
            $this->registerHook('displayNewsletterRegistration') &&
            $this->registerHook('actionNewsletterRegistrationBefore') &&
            $this->registerHook('actionFrontControllerInitBefore');
    }

    /**
     * Uninstall the module
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return parent::uninstall() && $this->deleteConfigurations();
    }

    /**
     * Use the new translation system
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /***********/
    /** HOOKS **/
    /***********/

    /**
     * Adds CSS and JS
     *
     * @return void
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $this->context->controller->registerStylesheet(
            'cloudflare-turnstile',
            'modules/' . $this->name . '/views/css/turnstile.css',
            [
                'position'   => 'head',
                'priority'   => 100,
            ]
        );
        $this->context->controller->registerJavascript(
            'cloudflare-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            [
                'server'     => 'remote',
                'position'   => 'head',
                'priority'   => 100,
                'attributes' => 'async',
            ]
        );
    }

    /**
     * Display turnstile widget on the create account form
     *
     * @return string
     */
    public function hookDisplayCustomerAccountForm(): string
    {
        if ($this->context->customer->isLogged()) {
            return '';
        }
        if (!$this->isAvailable(self::FORM_REGISTER)) {
            return '';
        }
        return $this->renderWidget('displayCustomerAccountForm', []);
    }

    /**
     * Turnstile validation
     *
     * @param array $params
     *
     * @return void
     * @throws Exception
     */
    public function hookActionFrontControllerInitBefore(array $params): void
    {
        if (!$this->canProcess(get_class($params['controller']))) {
            return;
        }

        if (!$this->getSecretKey()) {
            $this->context->controller->errors[] = $this->trans(
                'Cloudflare turnstile secret key is missing',
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            return;
        }
        if (!$this->getSitekey()) {
            $this->context->controller->errors[] = $this->trans(
                'Cloudflare turnstile sitekey is missing',
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            return;
        }

        $cookie = Context::getContext()->cookie;

        if ($cookie->__get(self::TURNSTILE_SESSION_ERROR_KEY)) {
            $this->context->controller->errors[] = $this->trans(
                $cookie->__get(self::TURNSTILE_SESSION_ERROR_KEY),
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            $cookie->__unset(self::TURNSTILE_SESSION_ERROR_KEY);
        }

        if ($this->canProcess(get_class($params['controller']), true)) {
            $this->turnstileValidationAndRedirect();
        }
    }

    /**
     * Display turnstile widget on the create account form
     *
     * @return string
     */
    public function hookDisplayNewsletterRegistration($params)
    {
        if ($this->context->customer->isLogged()) {
            return '';
        }
        if (!$this->isAvailable(self::FORM_NEWSLETTER)) {
            return '';
        }

        return $this->renderWidget('displayNewsletterRegistration', []);
    }

    /**
     * Hook to validate newsletter registration
     *
     * @param array $params
     *
     * @return void
     *
     */
    public function hookActionNewsletterRegistrationBefore($params)
    {
        if ($this->isAvailable(self::FORM_NEWSLETTER)) {
            if (!self::turnstileValidation()) {
                  if (!empty(static::$validationError)) {
                    $params['hookError'] = static::$validationError;
                } else {
                    $params['hookError'] = Context::getContext()->getTranslator()->trans(
                        'Security validation error',
                        [],
                        'Modules.Pixelcloudflareturnstile.Shop'
                    );
                }
            }
        }
    }

    /**
     * Check if turnstile is available for current action
     *
     * @param string $controllerClass
     * @param bool   $validate
     *
     * @return bool
     */
    protected function canProcess(string $controllerClass, bool $validate = false): bool
    {
        $isLoggedIn = $this->context->customer->isLogged();

        if ($controllerClass === 'OrderController') {
            return false;
        }

        // Contact
        if ($controllerClass === 'ContactController' && $this->isAvailable(self::FORM_CONTACT)) {
            if ($validate && !Tools::isSubmit('submitMessage')) {
                return false;
            }
            return true;
        }

        // Register
        if ($controllerClass === 'AuthController' &&
            $this->isAvailable(self::FORM_REGISTER) &&
            Tools::getValue('create_account') &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitCreate')) {
                return false;
            }
            return true;
        }

        // Register Prestashop >= 8.0.0
        if ($controllerClass === 'RegistrationController' &&
            $this->isAvailable(self::FORM_REGISTER) &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitCreate')) {
                return false;
            }
            return true;
        }

        // Login
        if ($controllerClass === 'AuthController' &&
            $this->isAvailable(self::FORM_LOGIN) &&
            !Tools::getValue('create_account') &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitLogin')) {
                return false;
            }
            return true;
        }

        // Reset Password
        if ($controllerClass === 'PasswordController' && $this->isAvailable(self::FORM_PASSWORD)) {
            if ($validate && (empty($_POST) || (isset($_POST['token'], $_POST['id_customer']) && !isset($_POST['email'])))) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Check if turnstile is available for the form
     *
     * @param string $form
     *
     * @return bool
     */
    public function isAvailable(string $form): bool
    {
        return in_array($form, $this->getForms());
    }

    /**
     * Validate turnstile
     *
     * @return void
     * @throws Exception
     */
    public static function turnstileValidationAndRedirect(): void
    {
        if (!self::turnstileValidation()) {
            $referer = $_SERVER['HTTP_REFERER'] ?? 'index';

            if (!empty(static::$validationError)) {
                $cookie  = Context::getContext()->cookie;
                $cookie->__set(
                    self::TURNSTILE_SESSION_ERROR_KEY,
                    static::$validationError
                );
            }

            Tools::redirect($referer);
        }
    }

    /**
     * Validate turnstile
     *
     * @return bool
     * @throws Exception
     */
    public static function turnstileValidation(): bool
    {
        $response = Tools::getValue('cf-turnstile-response');
        if (!$response) {
            static::$validationError =
                Context::getContext()->getTranslator()->trans(
                    'Please validate the security field.',
                    [],
                    'Modules.Pixelcloudflareturnstile.Shop'
                )
            ;

            return false;
        }

        $data = [
            'secret'   => Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY),
            'response' => $response,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        $curlResult = curl_exec($ch);
        if (!$curlResult) {
            static::$validationError ='Curl error: ' . curl_error($ch);

            return false;
        }

        $result = json_decode($curlResult, true);

        if (!($result['success'] ?? false)) {
            $errors = $result['error-codes'] ?? ['unavailable'];
            foreach ($errors as $key => $errorCode) {
                $errors[$key] = self::getErrorMessage($errorCode);
            }
            static::$validationError =
                Context::getContext()->getTranslator()->trans(
                    'Security validation error:',
                    [],
                    'Modules.Pixelcloudflareturnstile.Shop'
                ) . ' ' . join(', ', $errors)
            ;

            return false;
        }

        return true;
    }

    /**
     * Retrieve error message from error code
     *
     * @param string $code
     *
     * @return string
     */
    protected static function getErrorMessage(string $code): string
    {
        $messages = [
            'missing-input-secret'   => 'the secret parameter was not passed.',
            'invalid-input-secret'   => 'the secret parameter was invalid or did not exist.',
            'missing-input-response' => 'the response parameter was not passed.',
            'invalid-input-response' => 'the response parameter is invalid or has expired.',
            'bad-request'            => 'the request was rejected because it was malformed.',
            'timeout-or-duplicate'   => 'the response parameter has already been validated before.',
            'internal-error'         => 'an internal error happened while validating the response. The request can be retried.',
            'unavailable'            => 'unable to contact Cloudflare to validate the form',
        ];

        return $messages[$code] ?? 'unknown error';
    }

    /*********************/
    /** FRONTEND WIDGET **/
    /*********************/

    /**
     * Render the turnstile widget
     *
     * @param string $hookName
     * @param string[] $configuration
     *
     * @return string
     */
    public function renderWidget($hookName, array $configuration): string
    {
        $className = get_class($this->context->controller);
        $isCustom = (int)($configuration['custom'] ?? 0) === 1;
        if (!$isCustom && (!$this->canProcess($className) && $hookName != 'displayNewsletterRegistration')) {
            return '';
        }
        $keys = [$this->name, $className, $this->getFormName()];
        $cacheId = join('_', $keys);
        if (!$this->isCached($this->templateFile, $this->getCacheId($cacheId))) {
            $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));
        }

        return $this->fetch($this->templateFile, $this->getCacheId($cacheId));
    }

    /**
     * Retrieve the widget variables
     *
     * @param string $hookName
     * @param string[] $configuration
     *
     * @return string[]
     */
    public function getWidgetVariables($hookName, array $configuration): array
    {
        return [
            'sitekey' => $this->getSitekey(),
            'theme'   => $configuration['theme'] ?? $this->getTheme(),
            'action'  => $configuration['action'] ?? $this->getFormName(),
        ];
    }

    /**
     * Retrieve the current form name
     *
     * @return string
     */
    public function getFormName(): string
    {
        $controllerClass = get_class($this->context->controller);
        switch ($controllerClass) {
            case 'ContactController':
                $action = self::FORM_CONTACT;
                break;
            case 'PasswordController':
                $action = self::FORM_PASSWORD;
                break;
            case 'AuthController':
                $action = Tools::getValue('create_account') ? self::FORM_REGISTER : self::FORM_LOGIN;
                break;
            default:
                $action = strtolower(str_replace('Controller', '', $controllerClass));
        }

        return $action;
    }

    /*************************/
    /** ADMIN CONFIGURATION **/
    /*************************/

    /**
     * Retrieve config fields
     *
     * @return array[]
     */
    protected function getConfigFields(): array
    {
        return [
            self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY => [
                'type'     => 'text',
                'label'    => $this->trans('Sitekey', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY,
                'required' => true,
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY => [
                'type'     => 'text',
                'label'    => $this->trans('Secret key', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY,
                'required' => true,
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_THEME => [
                'type'     => 'select',
                'label'    => $this->trans('Theme', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_THEME,
                'required' => true,
                'options' => [
                    'query' => [
                        [
                            'value' => 'auto',
                            'name'  => 'Auto',
                        ],
                        [
                            'value' => 'light',
                            'name'  => 'Light',
                        ],
                        [
                            'value' => 'dark',
                            'name'  => 'Dark',
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS => [
                'type'     => 'select',
                'multiple' => true,
                'label'    => $this->trans('Forms to validate', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS . '[]',
                'required' => false,
                'options' => [
                    'query' => [
                        [
                            'value' => self::FORM_CONTACT,
                            'name'  => $this->trans('Contact', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_LOGIN,
                            'name'  => $this->trans('Login', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_REGISTER,
                            'name'  => $this->trans('Register', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_PASSWORD,
                            'name'  => $this->trans('Reset Password', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_NEWSLETTER,
                            'name'  => $this->trans('Newsletter', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
                'desc' => $this->trans(
                    'For contact, login and reset password forms, you need to manually add the widget in the template files.',
                    [],
                    'Modules.Pixelcloudflareturnstile.Admin'
                ),
            ],
        ];
    }

    /**
     * This method handles the module's configuration page
     *
     * @return string
     * @throws Exception
     */
    public function getContent(): string
    {
        $themeName = $this->getCurrentThemeName();

        $message = $this->trans(
            'For contact, login and reset password forms, you need to manually add the widget in the template files.',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $message .= '<br /><br />{widget name=\'pixel_cloudflare_turnstile\'}<br />';
        $message .= '<br /><strong>- Contact:</strong> themes/' . $themeName . '/modules/contactform/views/templates/widget/contactform.tpl';
        $message .= '<br /><strong>- Login:</strong> themes/' . $themeName . '/templates/customer/_partials/login-form.tpl';
        $message .= '<br /><strong>- Reset password:</strong> themes/' . $themeName . '/templates/customer/password-email.tpl';

        $output = '<div class="alert alert-info">' . $message . '</div>';

        if (Tools::isSubmit('submit' . $this->name)) {
            foreach ($this->getConfigFields() as $code => $field) {
                $value = Tools::getValue($code);
                if ($field['required'] && empty($value)) {
                    return $this->displayError(
                            $this->trans(
                                '%field% is empty',
                                ['%field%' => $field['label']],
                                'Modules.Pixelcloudflareturnstile.Admin'
                            )
                        ) . $this->displayForm();
                }
                if ($value && ($field['multiple'] ?? false) === true) {
                    $value = join(',', $value);
                }
                Configuration::updateValue($code, $value);
            }

            $output .= $this->displayConfirmation(
                $this->trans('Settings updated', [], 'Modules.Pixelcloudflareturnstile.Admin')
            );
        }

        return $output . $this->displayForm();
    }

    /**
     * Retrieve current theme name
     *
     * @return string
     * @throws Exception
     */
    public function getCurrentThemeName(): string
    {
        return basename(Context::getContext()->shop->theme->getName());
    }

    /**
     * Builds the configuration form
     *
     * @return string
     */
    public function displayForm(): string
    {
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                ],
                'input' => $this->getConfigFields(),
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();

        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;

        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        foreach ($this->getConfigFields() as $code => $field) {
            $value = Tools::getValue($code, Configuration::get($code));
            if (!is_array($value) && ($field['multiple'] ?? false) === true) {
                $value = explode(',', $value);
            }
            $helper->fields_value[$field['name']] = $value;
        }

        return $helper->generateForm([$form]);
    }

    /**
     * Retrieve available forms for turnstile
     *
     * @return string[]
     */
    public function getForms(): array
    {
        $forms = Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS);
        if (!$forms) {
            return [];
        }

        return explode(',', $forms);
    }

    /**
     * Retrieve the theme
     *
     * @return string
     */
    public function getTheme(): string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_THEME) ?: 'auto';
    }

    /**
     * Retrieve the secret key
     *
     * @return string|null
     */
    protected function getSecretKey(): ?string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY) ?: null;
    }

    /**
     * Retrieve the sitekey
     *
     * @return string|null
     */
    protected function getSitekey(): ?string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY) ?: null;
    }

    /**
     * Delete configurations
     *
     * @return bool
     */
    protected function deleteConfigurations(): bool
    {
        foreach ($this->getConfigFields() as $key => $options) {
            Configuration::deleteByName($key);
        }

        return true;
    }
}
