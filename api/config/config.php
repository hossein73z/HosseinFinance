<?php

date_default_timezone_set('Asia/Tehran');

// --- VERCEL CONFIGURATION ---
define('SHARED_SECRET', getenv('SHARED_SECRET'));
define('BOT_ID', getenv('BOT_ID'));
define('BOT_TOKEN', getenv('BOT_TOKEN'));

// Database Constants (Required for DatabaseManager)
define('DB_HOST', getenv('DB_HOST'));
define('DB_PORT', getenv('DB_PORT'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_API_SECRET', getenv('DB_API_SECRET'));

// Base URL for Web Apps
define('BASE_URL', getenv('BASE_URL'));
