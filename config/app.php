<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Dhaka');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_settings(PDO $pdo, bool $refresh = false): array
{
    static $settings = null;
    if ($settings === null || $refresh) {
        $settings = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function setting(PDO $pdo, string $key, string $default = ''): string
{
    return (string) (app_settings($pdo)[$key] ?? $default);
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
    app_settings($pdo, true);
}

function module_enabled(PDO $pdo, string $module): bool
{
    return setting($pdo, 'module_' . $module, '1') === '1';
}

function money(PDO $pdo, float|string|null $amount, ?string $symbol = null): string
{
    return ($symbol ?? setting($pdo, 'currency_symbol', '$')) . number_format((float) $amount, 2);
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session token expired. Go back, refresh the page, and try again.');
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function post_journal(PDO $pdo, string $date, string $reference, string $description, array $lines, string $sourceType = 'manual', ?int $sourceId = null): int
{
    $debits = array_sum(array_column($lines, 'debit'));
    $credits = array_sum(array_column($lines, 'credit'));
    if (abs($debits - $credits) > 0.005 || $debits <= 0) throw new InvalidArgumentException('Journal entry must be balanced and greater than zero.');
    $stmt = $pdo->prepare('INSERT INTO journal_entries (entry_date, reference_number, description, source_type, source_id, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$date, $reference, $description, $sourceType, $sourceId, $_SESSION['user_id'] ?? null]);
    $entryId = (int) $pdo->lastInsertId();
    $lineStmt = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, contact_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $lineStmt->execute([$entryId, $line['account_id'], $line['contact_id'] ?? null, $line['description'] ?? '', $line['debit'] ?? 0, $line['credit'] ?? 0]);
    }
    return $entryId;
}
