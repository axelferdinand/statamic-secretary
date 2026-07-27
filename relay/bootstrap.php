<?php

require __DIR__.'/vendor/autoload.php';

if (is_file(__DIR__.'/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
}
