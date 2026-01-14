<?php

/*
 * FileGator Entry Point
 *
 * For Replit environment - serves on port 5000
 */

define('APP_ENV', 'production');
define('APP_PUBLIC_PATH', '');
define('APP_PUBLIC_DIR', __DIR__.'/dist');

require __DIR__.'/dist/index.php';
