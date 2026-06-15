<?php

const APP_NAME = 'LMS DEMO';
const APP_BASE_PATH = '';

const DB_CHARSET = 'utf8mb4';

// LOCAL DEMO CONFIG
$serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$httpHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

if (
    $serverName === 'localhost' ||
    $serverName === '127.0.0.1' ||
    $httpHost === '127.0.0.1' ||
    strpos($httpHost, '.test') !== false
) {
    // LOCAL (Laragon)
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'lms_db'); // LMS local DB
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Non-local fallback for local demos
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'lms_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'ChangeThisAdminPassword!';
const ADMIN_PASSWORD_HASH = '';

const REFLECTION_MIN_SECONDS = 3;
const REFLECTION_MAX_PER_HOUR = 8;
