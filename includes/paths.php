<?php
if (!defined('MBOX_PROJECT_ROOT')) {
    define('MBOX_PROJECT_ROOT', realpath(__DIR__ . '/..'));
}

if (!defined('MBOX_PUBLIC_DIR')) {
    define('MBOX_PUBLIC_DIR', MBOX_PROJECT_ROOT . '/public');
}

if (!defined('MBOX_INCLUDES_DIR')) {
    define('MBOX_INCLUDES_DIR', MBOX_PROJECT_ROOT . '/includes');
}

if (!defined('MBOX_CONFIG_DIR')) {
    define('MBOX_CONFIG_DIR', MBOX_PROJECT_ROOT . '/config');
}

if (!defined('MBOX_BIN_DIR')) {
    define('MBOX_BIN_DIR', MBOX_PROJECT_ROOT . '/bin');
}

if (!defined('MBOX_BIN_BASH')) {
    define('MBOX_BIN_BASH', MBOX_BIN_DIR . '/bash');
}

if (!defined('MBOX_BIN_PYTHON')) {
    define('MBOX_BIN_PYTHON', MBOX_BIN_DIR . '/python');
}

if (!defined('MBOX_BIN_SQL')) {
    define('MBOX_BIN_SQL', MBOX_BIN_DIR . '/sql');
}

if (!defined('MBOX_BIN_PHP')) {
    define('MBOX_BIN_PHP', MBOX_BIN_DIR . '/php');
}

if (!defined('MBOX_DATA_DIR')) {
    define('MBOX_DATA_DIR', MBOX_PROJECT_ROOT . '/data');
}
