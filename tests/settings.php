<?php
/** Isolated configuration regressions; no shop, database or provider calls. */
namespace PrestaShop\PrestaShop\Core\Module {
    interface WidgetInterface {}
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    define('_PS_VERSION_', '9.2.0');
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    class Configuration
    {
        public static $values = [];
        public static $writes = [];
        public static $failKey;
        public static $throwOnFailure = false;

        public static function get($key) { return self::$values[$key] ?? false; }

        public static function updateValue($key, $value)
        {
            self::$writes[] = $key;
            if ($key === self::$failKey) {
                if (self::$throwOnFailure) {
                    throw new \RuntimeException('private database diagnostic');
                }
                return false;
            }
            self::$values[$key] = $value;
            return true;
        }
    }

    class Tools
    {
        public static function getValue($key, $default = false) { return $_POST[$key] ?? $_GET[$key] ?? $default; }
        public static function isSubmit($key) { return isset($_POST[$key]) || isset($_GET[$key]); }
        public static function getAdminTokenLite($controller) { return 'synthetic-native-token'; }
    }

    class ReviewLink
    {
        public $params;
        public function getAdminLink($controller, $withToken = true, $route = [], $params = [])
        {
            $this->params = $params;
            return isset($params['configure']) ? '/native/configure/' . $params['configure'] : '/legacy?controller=' . $controller;
        }
    }

    class Context
    {
        public static $instance;
        public $link;
        public static function getContext() { return self::$instance; }
    }

    class Module
    {
        public static $canConfigure = true;
        public $context, $name, $version, $author, $tab, $need_instance, $bootstrap;
        public $displayName, $description, $ps_versions_compliancy, $table;
        public function __construct() { $this->context = Context::getContext(); }
        public function trans($text, $parameters = [], $domain = '') { return strtr($text, $parameters); }
        public function displayError($text) { return 'ERROR:' . $text; }
        public function displayConfirmation($text) { return 'SUCCESS:' . $text; }
        public function getPermission($permission) { return $permission === 'configure' && self::$canConfigure; }
    }

    class HelperForm
    {
        public $table, $name_controller, $token, $currentIndex, $submit_action, $default_form_language;
        public $fields_value = [];
        public static $last;
        public function generateForm($forms)
        {
            self::$last = $this;
            foreach ($this->fields_value as $key => $value) {
                if (substr($key, -2) === '[]') {
                    expect(is_array($value), 'multiple form value remains an array');
                    foreach ($value as $selection) {
                        expect(is_string($selection), 'multiple form selections remain strings');
                    }
                } else {
                    expect(is_string($value) || $value === false, 'scalar form values remain safe to render');
                }
            }
            return 'FORM';
        }
    }

    require $argv[1] ?? dirname(__DIR__) . '/pixel_cloudflare_turnstile.php';
    class ReviewTurnstile extends Pixel_cloudflare_turnstile
    {
        public function getCurrentThemeName(): string { return 'review-theme'; }
    }

    function expect($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function defaults()
    {
        return [
            'CLOUDFLARE_TURNSTILE_SITEKEY' => 'synthetic-sitekey',
            'CLOUDFLARE_TURNSTILE_SECRET_KEY' => 'synthetic-secret',
            'CLOUDFLARE_TURNSTILE_THEME' => 'light',
            'CLOUDFLARE_TURNSTILE_APPEARANCE' => 'always',
            'CLOUDFLARE_TURNSTILE_TEST_MODE' => 'disabled',
            'CLOUDFLARE_TURNSTILE_FORMS' => 'contact,register,newsletter',
        ];
    }

    function resetCase()
    {
        Configuration::$values = defaults();
        Configuration::$writes = [];
        Configuration::$failKey = null;
        Configuration::$throwOnFailure = false;
        Module::$canConfigure = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = defaults();
        $_POST['submitpixel_cloudflare_turnstile'] = '1';
        $_POST['CLOUDFLARE_TURNSTILE_FORMS'] = ['contact', 'register'];
        Context::$instance = new Context();
        Context::$instance->link = new ReviewLink();
        return new ReviewTurnstile();
    }

    $tests = [];
    $tests['invalid later field leaves all stored settings untouched'] = function () {
        $module = resetCase();
        $_POST['CLOUDFLARE_TURNSTILE_SITEKEY'] = 'changed-sitekey';
        $_POST['CLOUDFLARE_TURNSTILE_SECRET_KEY'] = '';
        $html = $module->getContent();
        expect(strpos($html, 'ERROR:') !== false, 'validation error shown');
        expect(Configuration::$writes === [], 'invalid form must not write earlier fields');
        expect(Configuration::$values === defaults(), 'stored configuration unchanged');
    };
    foreach ([
        ['SITEKEY', []], ['SECRET_KEY', ['nested']], ['SECRET_KEY', '   '],
        ['THEME', 'invalid'], ['APPEARANCE', 'invalid'], ['TEST_MODE', 'invalid'],
        ['TEST_MODE', []], ['FORMS', 'contact'], ['FORMS', [['contact']]], ['FORMS', ['invalid']],
    ] as $case) {
        $label = $case[0] . ':' . json_encode($case[1]);
        $tests['reject malformed ' . $label] = function () use ($case) {
            $module = resetCase();
            $_POST['CLOUDFLARE_TURNSTILE_' . $case[0]] = $case[1];
            $html = $module->getContent();
            expect(strpos($html, 'ERROR:') !== false, 'invalid value rejected');
            expect(Configuration::$writes === [], 'no writes for malformed input');
            expect(Configuration::$values === defaults(), 'malformed input not stored');
        };
    }
    foreach (['THEME' => ['auto','light','dark'], 'APPEARANCE' => ['always','execute','interaction-only'], 'TEST_MODE' => ['disabled','pass','fail','interactive']] as $field => $options) {
        foreach ($options as $option) {
            $tests['accept ' . $field . ':' . $option] = function () use ($field, $option) {
                $module = resetCase();
                $_POST['CLOUDFLARE_TURNSTILE_' . $field] = $option;
                expect(strpos($module->getContent(), 'SUCCESS:') !== false, 'valid form succeeds');
                expect(Configuration::$values['CLOUDFLARE_TURNSTILE_' . $field] === $option, 'option stored exactly');
                expect(Configuration::$values['CLOUDFLARE_TURNSTILE_FORMS'] === 'contact,register', 'selections stored');
            };
        }
    }
    $tests['missing optional selections and test mode are normalized'] = function () {
        $module = resetCase();
        unset($_POST['CLOUDFLARE_TURNSTILE_FORMS'], $_POST['CLOUDFLARE_TURNSTILE_TEST_MODE']);
        expect(strpos($module->getContent(), 'SUCCESS:') !== false, 'optional inputs accepted');
        expect(Configuration::$values['CLOUDFLARE_TURNSTILE_FORMS'] === '', 'no selections stored as empty string');
        expect(Configuration::$values['CLOUDFLARE_TURNSTILE_TEST_MODE'] === 'disabled', 'missing mode stays production');
    };
    foreach ([false, true] as $throw) {
        $tests[$throw ? 'database exception is sanitized' : 'failed write never reports success'] = function () use ($throw) {
            $module = resetCase();
            Configuration::$failKey = 'CLOUDFLARE_TURNSTILE_SECRET_KEY';
            Configuration::$throwOnFailure = $throw;
            $html = $module->getContent();
            expect(strpos($html, 'ERROR:') !== false && strpos($html, 'SUCCESS:') === false, 'failure reported without success');
            expect(strpos($html, 'private database diagnostic') === false, 'exception detail not exposed');
            expect(count(Configuration::$writes) === 2, 'writes stop at failure');
            expect(strpos($html, 'Reload') !== false, 'partial persistence instructions shown');
        };
    }
    $tests['GET cannot change settings'] = function () {
        $module = resetCase();
        $_GET = $_POST;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $module->getContent();
        expect(Configuration::$writes === [], 'GET is read-only');
    };
    $tests['configure permission required for writes'] = function () {
        $module = resetCase();
        Module::$canConfigure = false;
        $module->getContent();
        expect(Configuration::$writes === [], 'configure denial is read-only');
    };
    $tests['configure route receives parameters before conversion'] = function () {
        $module = resetCase();
        $_POST = [];
        $module->displayForm();
        expect(Context::$instance->link->params === ['configure' => 'pixel_cloudflare_turnstile'], 'native configure parameter supplied');
        expect(HelperForm::$last->currentIndex === '/native/configure/pixel_cloudflare_turnstile', 'no legacy concatenation');
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . "\n";
        } catch (\Throwable $error) {
            ++$failed;
            echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n";
        }
    }
    echo count($tests) . ' cases, ' . $failed . ' failures on PHP ' . PHP_VERSION . ". No shop or network calls.\n";
    exit($failed ? 1 : 0);
}
