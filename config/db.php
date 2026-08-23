<?php
// config/db.php

$host = '127.0.0.1';
$db = 'accountsbase';
$user = 'root';
$pass = ''; // Set your MySQL password if any
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int) $e->getCode());
}

// ==========================================
// GLOBAL HELPER FUNCTIONS
// ==========================================

// 1. Fetch System Settings from system_settings table
if (!function_exists('setting')) {
    function setting($pdo, $key, $default = '')
    {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

// 2. Safe HTML Escaping
if (!function_exists('h')) {
    function h($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

// 3. Currency Formatter
if (!function_exists('money')) {
    function money($pdo, $amount, $currencyCode = null)
    {
        $amount = (float) $amount;
        if (!$currencyCode) {
            $currencyCode = setting($pdo, 'base_currency', 'USD');
        }

        // Fetch currency symbol
        try {
            $stmt = $pdo->prepare("SELECT symbol FROM currencies WHERE code = ? LIMIT 1");
            $stmt->execute([$currencyCode]);
            $symbol = $stmt->fetchColumn() ?: '$';
        } catch (Exception $e) {
            $symbol = '$';
        }

        return $symbol . number_format($amount, 2);
    }
}
// Check if a specific system module is active
if (!function_exists('module_enabled')) {
    function module_enabled($pdo, $moduleKey = null)
    {
        // If only 1 argument was passed (e.g., module_enabled('payroll'))
        if ($moduleKey === null && is_string($pdo)) {
            $moduleKey = $pdo;
            global $pdo;
        }

        if (!$pdo || !$moduleKey) {
            return true; // Default fallback to enabled
        }

        try {
            $settingKey = 'module_' . strtolower(trim($moduleKey));
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$settingKey]);
            $val = $stmt->fetchColumn();

            // If setting exists, evaluate truthy value; otherwise default to enabled
            return $val !== false ? (bool) $val && $val !== '0' && $val !== 'false' : true;
        } catch (Exception $e) {
            return true;
        }
    }
}
// 4. Safe Redirect Helper
if (!function_exists('redirect')) {
    function redirect($url)
    {
        header("Location: " . $url);
        exit;
    }
}

// 5. CSRF Token Helpers
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE)
            session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf()
    {
        if (session_status() === PHP_SESSION_NONE)
            session_start();
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new RuntimeException('Invalid CSRF token validation.');
        }
    }
}