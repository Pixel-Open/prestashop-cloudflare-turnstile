<?php
/**
 * Copyright (C) 2022 Pixel Développement
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_4($module)
{
    return $module->registerHook([
        'displayNewsletterRegistration',
        'actionNewsletterRegistrationBefore',
    ]);

}
