<?php

/**
 * BOB – Percorsi filesystem
 * cloud/ è NFS, fuori dal web root
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__, 2));

define(
    'BOB_CLOUD_BASE',
    realpath(dirname(APP_ROOT) . '/cloud/cantieri/clienti')
);
