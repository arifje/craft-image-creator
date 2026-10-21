<?php

declare(strict_types=1);

// Native test scripts receive these paths from an external Craft bootstrap at
// runtime. During static analysis, Composer's current project is the context.
if (!defined('CRAFT_BASE_PATH')) {
    define('CRAFT_BASE_PATH', getcwd());
}
if (!defined('CRAFT_VENDOR_PATH')) {
    define('CRAFT_VENDOR_PATH', getcwd() . '/vendor');
}
