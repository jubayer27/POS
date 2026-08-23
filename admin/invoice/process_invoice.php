<?php
// admin/invoice/process_invoice.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create_invoice.php');
    exit;
}

// Fallback helper for posting double-entry journals
if (!function_exists('post_journal')) {
    function post_journal($pdo, $entryDate, $reference, $description, $lines, $sourceType = 'invoice', $sourceId = null)
    {
        $userId = $_SESSION['user_id'] ?? null;

        $stmt = $pdo->prepare("INSERT INTO journal_entries (entry_date, reference_number, description, status, created_by) VALUES (?, ?, ?, 'posted', ?)");
        $stmt->execute([$entryDate, $reference, $description, $userId]);
        $journalEntryId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, contact_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($lines as $line) {
            $lineStmt->execute([
                $journalEntryId,
                $line['account_id'],
                $line['contact_id'] ?? null,
                $line['description'] ?? $reference,
                (float) ($line['debit'] ?? 0.00),
                (float) ($line['credit'] ?? 0.00)
            ]);
        }

        return $journalEntryId;
    }
}

verify_csrf();

try {
    $contactId = (int) ($_POST['contact_id'] ?? 0);
    $issue = trim((string) ($_POST['issue_date'] ?? ''));
    $due = trim((string) ($_POST['due_date'] ?? ''));
    $currencyCode = trim((string) ($_POST['currency_code'] ?? ''));
    $templateId = (int) ($_POST['invoice_template_id'] ?? 0);

    // 1. Validate Customer & Issue Date
    if (!$contactId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
        throw new InvalidArgumentException('Please select a customer and provide a valid issue date.');
    }

    // 2. Validate Due Date ONLY if provided
    $dueDateVal = null;
    if ($due !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new InvalidArgumentException('Invalid due date format.');
        }
        if ($due < $issue) {
            throw new InvalidArgumentException('Due date cannot be earlier than the issue date.');
        }
        $dueDateVal = $due;
    }

    $itemIds = $_POST['item_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $rates = $_POST['tax_rate'] ?? [];

    if (!is_array($itemIds) || count($itemIds) === 0) {
        throw new InvalidArgumentException('Add at least one line item.');
    }

    $pdo->beginTransaction();

    // 3. Customer Check
    $s = $pdo->prepare("SELECT c.id FROM contacts c LEFT JOIN contact_types t ON t.id=c.contact_type_id WHERE c.id=? AND COALESCE(t.base_kind,c.contact_type) IN('customer','both') FOR UPDATE");
    $s->execute([$contactId]);
    if (!$s->fetchColumn()) {
        throw new InvalidArgumentException('Customer not found or not eligible for invoicing.');
    }

    // 4. Currency Check
    $s = $pdo->prepare('SELECT exchange_rate FROM currencies WHERE code=?');
    $s->execute([$currencyCode]);
    $exchangeRate = (float) $s->fetchColumn();
    if ($exchangeRate <= 0) {
        throw new InvalidArgumentException('Choose a valid invoice currency.');
    }

    // 5. Template Check
    if ($templateId > 0) {
        $s = $pdo->prepare('SELECT id FROM invoice_templates WHERE id=?');
        $s->execute([$templateId]);
        if (!$s->fetchColumn())
            $templateId = null;
    } else {
        $templateId = (int) $pdo->query('SELECT id FROM invoice_templates WHERE is_default=1 LIMIT 1')->fetchColumn() ?: null;
    }

    // 6. Next Sequential Invoice Number
    $s = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='invoice_next_number' FOR UPDATE");
    $next = max(1, (int) $s->fetchColumn());
    $prefix = setting($pdo, 'invoice_prefix', 'INV-');
    $invoiceNo = $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('invoice_next_number', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string) ($next + 1)]);

    // 7. Calculate Lines and Revenue
    $subtotal = 0.0;
    $taxTotal = 0.0;
    $lines = [];
    $revenue = [];

    $itemStmt = $pdo->prepare('SELECT name, income_account_id FROM items WHERE id=?');

    foreach ($itemIds as $i => $rawId) {
        $id = (int) $rawId;
        $qty = round((float) ($quantities[$i] ?? 0), 3);
        $price = round((float) ($prices[$i] ?? 0), 2);
        $rate = max(0, (float) ($rates[$i] ?? 0));

        if (!$id || $qty <= 0 || $price < 0)
            continue;

        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();
        if (!$item)
            throw new InvalidArgumentException('One selected item is unavailable.');

        $base = round($qty * $price, 2);
        $tax = round($base * ($rate / 100), 2);

        $subtotal += $base;
        $taxTotal += $tax;

        $desc = trim((string) ($descriptions[$i] ?? '')) ?: $item['name'];
        $lines[] = [$id, $desc, $qty, $price, $rate, $tax, $base + $tax];

        $acc = (int) $item['income_account_id'];
        $revenue[$acc] = ($revenue[$acc] ?? 0) + $base;
    }

    if (!$lines)
        throw new InvalidArgumentException('Add at least one valid invoice line.');

    $grand = round($subtotal + $taxTotal, 2);

    // 8. Insert Invoice Record
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $terms = trim((string) ($_POST['terms'] ?? setting($pdo, 'invoice_terms', '')));

    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, contact_id, invoice_template_id, issue_date, due_date, currency_code, exchange_rate, subtotal, tax_total, grand_total, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?)");
    $stmt->execute([
        $invoiceNo,
        $contactId,
        $templateId,
        $issue,
        $dueDateVal,
        $currencyCode,
        $exchangeRate,
        $subtotal,
        $taxTotal,
        $grand,
        $notes
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    // 9. Insert Line Items
    $lineStmt = $pdo->prepare('INSERT INTO invoice_lines (invoice_id, item_id, description, quantity, unit_price, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $lineStmt->execute([
            $invoiceId,
            $line[0], // item_id
            $line[1], // description
            $line[2], // quantity
            $line[3], // unit_price
            $line[5], // tax_amount
            $line[6]  // line_total
        ]);
    }

    // 10. Balance Double-Entry Journal
    $baseGrand = round($grand / $exchangeRate, 2);
    $baseTax = round($taxTotal / $exchangeRate, 2);

    $arAccount = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='1200'")->fetchColumn();
    if (!$arAccount)
        throw new RuntimeException("Account '1200' (Accounts Receivable) missing from Chart of Accounts.");

    $journal = [
        [
            'account_id' => $arAccount,
            'contact_id' => $contactId,
            'description' => "Invoice {$invoiceNo} receivable",
            'debit' => $baseGrand,
            'credit' => 0.00
        ]
    ];

    $baseRevenueTotal = 0;
    foreach ($revenue as $accountId => $amount) {
        $baseAmount = round($amount / $exchangeRate, 2);
        $baseRevenueTotal += $baseAmount;
        $journal[] = [
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'description' => "Revenue: Invoice {$invoiceNo}",
            'debit' => 0.00,
            'credit' => $baseAmount
        ];
    }

    if ($baseTax > 0) {
        $taxAccount = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='2100'")->fetchColumn();
        if (!$taxAccount)
            throw new RuntimeException("Account '2100' (Tax Payable) missing from Chart of Accounts.");
        $rounding = $baseGrand - ($baseRevenueTotal + $baseTax);
        $journal[] = [
            'account_id' => $taxAccount,
            'contact_id' => $contactId,
            'description' => "Tax liability for {$invoiceNo}",
            'debit' => 0.00,
            'credit' => $baseTax + $rounding
        ];
    } else {
        $lastIdx = count($journal) - 1;
        $journal[$lastIdx]['credit'] += ($baseGrand - $baseRevenueTotal);
    }

    // 11. Post Journal Entry
    $journalId = post_journal($pdo, $issue, $invoiceNo, "Customer invoice {$invoiceNo}", $journal, 'invoice', $invoiceId);
    $pdo->prepare('UPDATE invoices SET journal_entry_id=? WHERE id=?')->execute([$journalId, $invoiceId]);

    $pdo->commit();
    header('Location: view_invoice.php?id=' . $invoiceId . '&created=1');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-100 flex items-center justify-center min-h-screen p-5">';
    echo '<div class="bg-white border border-rose-200 rounded-2xl p-8 max-w-lg w-full text-center space-y-4 shadow-sm">';
    echo '<div class="w-12 h-12 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto text-xl font-bold">✕</div>';
    echo '<h2 class="text-xl font-bold text-slate-800">Invoice could not be saved</h2>';
    echo '<p class="text-sm text-slate-600 leading-relaxed">' . h($e->getMessage()) . '</p>';
    echo '<div class="pt-2"><a href="create_invoice.php" class="inline-block bg-slate-900 text-white font-semibold px-6 py-2.5 rounded-lg text-sm">Return to Invoice</a></div>';
    echo '</div></body></html>';
    exit;
}