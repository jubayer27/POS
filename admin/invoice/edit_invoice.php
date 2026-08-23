<?php
// admin/invoice/edit_invoice.php
require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$error = '';

// 1. Fetch Existing Invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

if (in_array($invoice['status'], ['paid', 'voided'], true)) {
    header("Location: view_invoice.php?id={$id}");
    exit;
}

// 2. Process Invoice Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_invoice'])) {
    verify_csrf();

    try {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $issue = trim((string) ($_POST['issue_date'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? ''));
        $currencyCode = trim((string) ($_POST['currency_code'] ?? ''));
        $templateId = (int) ($_POST['invoice_template_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $terms = trim((string) ($_POST['terms'] ?? ''));

        if (!$contactId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
            throw new InvalidArgumentException('Please select a valid customer and issue date.');
        }

        $dueDateVal = null;
        if ($due !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                throw new InvalidArgumentException('Invalid due date format.');
            }
            if ($due < $issue) {
                throw new InvalidArgumentException('Due date cannot be earlier than issue date.');
            }
            $dueDateVal = $due;
        }

        $itemIds = $_POST['item_id'] ?? [];
        $descriptions = $_POST['description'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        $rates = $_POST['tax_rate'] ?? [];

        if (!is_array($itemIds) || count($itemIds) === 0) {
            throw new InvalidArgumentException('At least one line item is required.');
        }

        $pdo->beginTransaction();

        // Check exchange rate
        $s = $pdo->prepare('SELECT exchange_rate FROM currencies WHERE code = ?');
        $s->execute([$currencyCode]);
        $exchangeRate = (float) $s->fetchColumn();
        if ($exchangeRate <= 0)
            $exchangeRate = 1.000000;

        // Process Line Items
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];
        $revenue = [];

        $itemStmt = $pdo->prepare('SELECT name, income_account_id FROM items WHERE id = ?');

        foreach ($itemIds as $i => $rawId) {
            $itemId = (int) $rawId;
            $qty = round((float) ($quantities[$i] ?? 0), 3);
            $price = round((float) ($prices[$i] ?? 0), 2);
            $rate = max(0, (float) ($rates[$i] ?? 0));

            if (!$itemId || $qty <= 0 || $price < 0)
                continue;

            $itemStmt->execute([$itemId]);
            $item = $itemStmt->fetch();
            if (!$item)
                throw new InvalidArgumentException('One of the selected items is invalid.');

            $base = round($qty * $price, 2);
            $tax = round($base * ($rate / 100), 2);
            $subtotal += $base;
            $taxTotal += $tax;

            $desc = trim((string) ($descriptions[$i] ?? '')) ?: $item['name'];
            $lines[] = [$itemId, $desc, $qty, $price, $tax, $base + $tax];

            $acc = (int) $item['income_account_id'];
            $revenue[$acc] = ($revenue[$acc] ?? 0) + $base;
        }

        if (empty($lines)) {
            throw new InvalidArgumentException('Please provide at least one valid line item.');
        }

        $grand = round($subtotal + $taxTotal, 2);

        // Update Invoice Record
        $stmt = $pdo->prepare("UPDATE invoices SET contact_id = ?, invoice_template_id = ?, issue_date = ?, due_date = ?, currency_code = ?, exchange_rate = ?, subtotal = ?, tax_total = ?, grand_total = ?, notes = ? WHERE id = ?");
        $stmt->execute([
            $contactId,
            $templateId ?: null,
            $issue,
            $dueDateVal,
            $currencyCode,
            $exchangeRate,
            $subtotal,
            $taxTotal,
            $grand,
            $notes,
            $id
        ]);

        // Replace Line Items
        $pdo->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$id]);
        $lineStmt = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, item_id, description, quantity, unit_price, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($lines as $line) {
            $lineStmt->execute([$id, ...$line]);
        }

        // Re-post General Ledger Journal Entry
        $baseGrand = round($grand / $exchangeRate, 2);
        $baseTax = round($taxTotal / $exchangeRate, 2);

        $arAccount = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1200'")->fetchColumn();
        $journal = [
            [
                'account_id' => $arAccount,
                'contact_id' => $contactId,
                'description' => "Updated invoice {$invoice['invoice_number']}",
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
                'description' => "Revenue: {$invoice['invoice_number']}",
                'debit' => 0.00,
                'credit' => $baseAmount
            ];
        }

        if ($baseTax > 0) {
            $taxAccount = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2100'")->fetchColumn();
            $rounding = $baseGrand - ($baseRevenueTotal + $baseTax);
            $journal[] = [
                'account_id' => $taxAccount,
                'contact_id' => $contactId,
                'description' => "Tax liability for {$invoice['invoice_number']}",
                'debit' => 0.00,
                'credit' => $baseTax + $rounding
            ];
        } else {
            $lastIdx = count($journal) - 1;
            $journal[$lastIdx]['credit'] += ($baseGrand - $baseRevenueTotal);
        }

        if (!empty($invoice['journal_entry_id'])) {
            $pdo->prepare("UPDATE journal_entries SET entry_date = ?, description = ? WHERE id = ?")
                ->execute([$issue, "Customer invoice {$invoice['invoice_number']}", $invoice['journal_entry_id']]);

            $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id = ?")->execute([$invoice['journal_entry_id']]);

            $jlStmt = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, contact_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($journal as $jl) {
                $jlStmt->execute([
                    $invoice['journal_entry_id'],
                    $jl['account_id'],
                    $jl['contact_id'],
                    $jl['description'],
                    $jl['debit'],
                    $jl['credit']
                ]);
            }
        }

        $pdo->commit();
        header("Location: view_invoice.php?id={$id}&updated=1");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// 3. Fetch Data for View
$customers = $pdo->query("SELECT c.id, c.company_name, c.currency_code, COALESCE(t.name, c.contact_type) AS type_name FROM contacts c LEFT JOIN contact_types t ON t.id = c.contact_type_id WHERE COALESCE(t.base_kind, c.contact_type) IN ('customer', 'both') ORDER BY c.company_name ASC")->fetchAll();

$items = $pdo->query("SELECT i.id, i.name, i.description, i.unit_price, COALESCE(tr.rate_percentage, 0) AS tax_rate, COALESCE(GROUP_CONCAT(CONCAT(p.currency_code, ':', p.unit_price) SEPARATOR '|'), '') AS prices FROM items i LEFT JOIN tax_rates tr ON tr.id = i.tax_rate_id LEFT JOIN item_prices p ON p.item_id = i.id GROUP BY i.id ORDER BY i.name ASC")->fetchAll();

foreach ($items as &$it) {
    $priceMap = [];
    if (!empty($it['prices'])) {
        foreach (explode('|', (string) $it['prices']) as $pair) {
            if (!$pair)
                continue;
            [$code, $pr] = explode(':', $pair, 2);
            $priceMap[$code] = (float) $pr;
        }
    }
    $it['price_map'] = $priceMap;
}
unset($it);

$currencies = $pdo->query('SELECT code, name, symbol, exchange_rate FROM currencies ORDER BY is_base_currency DESC, name ASC')->fetchAll();
$taxes = $pdo->query('SELECT rate_percentage, name FROM tax_rates WHERE is_active = 1 ORDER BY rate_percentage ASC')->fetchAll();
$templates = $pdo->query('SELECT id, name, is_default FROM invoice_templates ORDER BY is_default DESC, name ASC')->fetchAll();

$stmt_lines = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id ASC");
$stmt_lines->execute([$id]);
$currentLines = $stmt_lines->fetchAll();

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
$input = 'mt-1 w-full border border-gray-300 rounded-lg px-3 py-2.5 bg-white text-sm outline-none focus:ring-2 focus:ring-blue-500';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    <header class="bg-white border-b px-5 md:px-8 py-5 flex justify-between items-center sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl">☰</button>
            <a href="view_invoice.php?id=<?= $id ?>" class="text-slate-400 hover:text-slate-700 font-bold text-lg">←</a>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Edit Invoice
                    <?= h($invoice['invoice_number']) ?>
                </h2>
                <p class="text-xs text-slate-500">Update line items, recalculate totals, and sync ledger entries</p>
            </div>
        </div>
        <a href="view_invoice.php?id=<?= $id ?>"
            class="border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
            Cancel
        </a>
    </header>

    <div class="p-5 md:p-8 max-w-6xl mx-auto space-y-6">
        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl shadow-sm text-sm">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 md:p-8 space-y-7">
            <?= csrf_field() ?>

            <!-- Header Parameters -->
            <div class="grid md:grid-cols-2 lg:grid-cols-6 gap-4 pb-7 border-b border-slate-100">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Customer *</label>
                    <select id="customer" name="contact_id" required class="<?= $input ?>">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-currency="<?= $c['currency_code'] ?>"
                                <?= (int) $invoice['contact_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= h($c['company_name']) ?> (
                                <?= h($c['type_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Currency *</label>
                    <select id="invoiceCurrency" name="currency_code" class="<?= $input ?>">
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= $c['code'] ?>" data-symbol="<?= h($c['symbol']) ?>"
                                <?= $invoice['currency_code'] === $c['code'] ? 'selected' : '' ?>>
                                <?= h($c['code']) ?> ·
                                <?= h($c['symbol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lg:col-span-3">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Template</label>
                    <select name="invoice_template_id" class="<?= $input ?>">
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= (int) $invoice['invoice_template_id'] === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= h($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date" value="<?= h($invoice['issue_date']) ?>" required
                        class="<?= $input ?>">
                </div>

                <div class="lg:col-span-2">
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-bold uppercase text-slate-600">Due Date</label>
                        <label class="text-[11px] text-slate-500 flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" id="hasDueDate" <?= !empty($invoice['due_date']) ? 'checked' : '' ?>
                            class="rounded text-blue-600"> Enable Due Date
                        </label>
                    </div>
                    <div id="dueDateWrapper" class="<?= empty($invoice['due_date']) ? 'hidden' : '' ?>">
                        <input type="date" id="dueDateInput" name="due_date"
                            value="<?= h($invoice['due_date'] ?? '') ?>" <?= empty($invoice['due_date']) ? 'disabled' : '' ?> class="
                        <?= $input ?>">
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Reference / PO</label>
                    <input name="notes" value="<?= h($invoice['notes'] ?? '') ?>" placeholder="e.g. PO-8921"
                        class="<?= $input ?>">
                </div>
            </div>

            <!-- Line Items -->
            <div>
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-bold text-slate-900 text-base">Line Items & Services</h3>
                    <button type="button" id="addRow"
                        class="bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1.5 rounded-lg border border-blue-200 transition-colors">
                        + Add Line Item
                    </button>
                </div>

                <div class="overflow-x-auto border border-slate-200 rounded-xl">
                    <table class="w-full text-left text-sm min-w-[850px] border-collapse">
                        <thead class="bg-slate-50 text-slate-600 text-xs border-b">
                            <tr>
                                <th class="p-3 font-semibold w-2/5">Item & Details</th>
                                <th class="p-3 font-semibold w-24">Qty</th>
                                <th class="p-3 font-semibold w-32">Rate / Price</th>
                                <th class="p-3 font-semibold w-36">Tax Rate</th>
                                <th class="p-3 font-semibold text-right w-32">Amount</th>
                                <th class="p-3 w-10 text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="invoiceItems" class="divide-y divide-slate-100">
                            <?php foreach ($currentLines as $line):
                                $matchedItem = null;
                                foreach ($items as $it) {
                                    if ($it['id'] == $line['item_id']) {
                                        $matchedItem = $it;
                                        break;
                                    }
                                }
                                $lineTaxRate = 0;
                                if ($line['tax_amount'] > 0 && ($line['quantity'] * $line['unit_price']) > 0) {
                                    $lineTaxRate = round(($line['tax_amount'] / ($line['quantity'] * $line['unit_price'])) * 100, 2);
                                }
                                ?>
                                <tr class="item-row">
                                    <td class="p-3">
                                        <select name="item_id[]" required
                                            class="item-select w-full border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white outline-none">
                                            <option value="">-- Choose Item --</option>
                                            <?php foreach ($items as $i): ?>
                                                <option value="<?= $i['id'] ?>" data-base-price="<?= $i['unit_price'] ?>"
                                                    data-prices='<?= json_encode($i['price_map']) ?>'
                                                    data-tax="
                                            <?= $i['tax_rate'] ?>"
                                                    data-description="
                                            <?= h($i['description']) ?>"
                                                    <?= (int) $line['item_id'] === (int) $i['id'] ? 'selected' : '' ?>>
                                                    <?= h($i['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input name="description[]" value="<?= h($line['description']) ?>"
                                            placeholder="Line description..."
                                            class="description mt-1.5 w-full border border-slate-200 rounded px-2.5 py-1 text-xs text-slate-600 outline-none">
                                    </td>
                                    <td class="p-3 align-top">
                                        <input type="number" name="quantity[]" value="<?= floatval($line['quantity']) ?>"
                                            min="0.001" step="any"
                                            class="qty w-full border border-slate-300 rounded-lg px-2.5 py-2 text-xs outline-none">
                                    </td>
                                    <td class="p-3 align-top">
                                        <input type="number" name="unit_price[]"
                                            value="<?= number_format((float) $line['unit_price'], 2, '.', '') ?>" min="0"
                                            step="0.01"
                                            class="price w-full border border-slate-300 rounded-lg px-2.5 py-2 text-xs font-semibold outline-none">
                                    </td>
                                    <td class="p-3 align-top">
                                        <select name="tax_rate[]"
                                            class="tax-rate w-full border border-slate-300 rounded-lg px-2 py-2 text-xs bg-white outline-none">
                                            <?php foreach ($taxes as $t): ?>
                                                <option value="<?= $t['rate_percentage'] ?>" <?= abs((float) $t['rate_percentage'] - $lineTaxRate) < 0.01 ? 'selected' : '' ?>>
                                                    <?= h($t['name']) ?> (
                                                    <?= floatval($t['rate_percentage']) ?>%)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="p-3 text-right font-bold text-slate-800 align-top pt-4">
                                        <span class="row-total">
                                            <?= number_format((float) $line['line_total'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-center align-top pt-3">
                                        <button type="button"
                                            class="remove-row text-slate-400 hover:text-rose-600 font-bold text-xl">×</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Summary & Update Button -->
            <div class="flex justify-end pt-6 border-t border-slate-100">
                <div class="w-full max-w-sm space-y-3 bg-slate-50/80 border border-slate-200 rounded-2xl p-5">
                    <div class="flex justify-between text-sm text-slate-600">
                        <span>Subtotal:</span>
                        <strong id="subtotal" class="text-slate-900 font-bold">0.00</strong>
                    </div>
                    <div class="flex justify-between text-sm text-slate-600">
                        <span>Tax Total:</span>
                        <strong id="totalTax" class="text-slate-900 font-bold">0.00</strong>
                    </div>
                    <div class="flex justify-between text-lg border-t border-slate-200 pt-3 text-slate-900">
                        <strong class="font-black">Total Due:</strong>
                        <strong id="grandTotal" class="font-black text-blue-600 text-xl">0.00</strong>
                    </div>
                    <button type="submit" name="update_invoice"
                        class="bg-blue-600 hover:bg-blue-700 text-white rounded-xl px-6 py-3 font-bold text-sm w-full transition-colors shadow-md mt-2">
                        Update & Re-post Invoice
                    </button>
                </div>
            </div>

        </form>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.getElementById('invoiceItems');
        const currency = document.getElementById('invoiceCurrency');
        const hasDueDate = document.getElementById('hasDueDate');
        const dueDateWrapper = document.getElementById('dueDateWrapper');
        const dueDateInput = document.getElementById('dueDateInput');

        function symbol() {
            return currency.selectedOptions[0]?.dataset.symbol || '$';
        }

        hasDueDate.addEventListener('change', () => {
            if (hasDueDate.checked) {
                dueDateWrapper.classList.remove('hidden');
                dueDateInput.disabled = false;
            } else {
                dueDateWrapper.classList.add('hidden');
                dueDateInput.disabled = true;
                dueDateInput.value = '';
            }
        });

        function calculate() {
            let sub = 0;
            let tax = 0;
            const sym = symbol();

            tbody.querySelectorAll('.item-row').forEach(r => {
                const qty = Number(r.querySelector('.qty').value) || 0;
                const price = Number(r.querySelector('.price').value) || 0;
                const taxRate = Number(r.querySelector('.tax-rate').value) || 0;

                const base = qty * price;
                const t = base * (taxRate / 100);
                sub += base;
                tax += t;

                r.querySelector('.row-total').textContent = sym + (base + t).toFixed(2);
            });

            document.getElementById('subtotal').textContent = sym + sub.toFixed(2);
            document.getElementById('totalTax').textContent = sym + tax.toFixed(2);
            document.getElementById('grandTotal').textContent = sym + (sub + tax).toFixed(2);
        }

        tbody.addEventListener('input', calculate);
        tbody.addEventListener('change', calculate);

        tbody.addEventListener('click', e => {
            if (e.target.classList.contains('remove-row') && tbody.children.length > 1) {
                e.target.closest('tr').remove();
                calculate();
            }
        });

        document.getElementById('addRow').onclick = () => {
            const firstRow = tbody.firstElementChild;
            const row = firstRow.cloneNode(true);
            row.querySelector('.item-select').value = '';
            row.querySelector('.description').value = '';
            row.querySelector('.qty').value = 1;
            row.querySelector('.price').value = '0.00';
            row.querySelector('.row-total').textContent = symbol() + '0.00';
            tbody.append(row);
        };

        currency.onchange = calculate;
        calculate();
    });
</script>

<?php require_once '../includes/footer.php'; ?>