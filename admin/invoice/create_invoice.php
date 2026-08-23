<?php
// admin/invoice/create_invoice.php
require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Fallback helper functions
if (!function_exists('h')) {
    function h($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}
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

// 1. Fetch Customers
$customers = $pdo->query("
    SELECT c.id, c.company_name, c.currency_code, COALESCE(t.name, c.contact_type) AS type_name 
    FROM contacts c 
    LEFT JOIN contact_types t ON t.id = c.contact_type_id 
    WHERE COALESCE(t.base_kind, c.contact_type) IN ('customer', 'both') 
    ORDER BY c.company_name ASC
")->fetchAll();

// 2. Fetch Active Bank / Cash Accounts for payment selector
$bankAccounts = [];
try {
    $bankAccounts = $pdo->query("
        SELECT a.id, a.account_name, a.account_type,
               GROUP_CONCAT(CONCAT(f.label, ': ', COALESCE(v.field_value, '')) SEPARATOR '\n') AS custom_details
        FROM accounts a
        LEFT JOIN account_field_values v ON v.account_id = a.id
        LEFT JOIN account_field_definitions f ON f.id = v.field_definition_id
        WHERE a.is_active = 1
        GROUP BY a.id
        ORDER BY a.account_name ASC
    ")->fetchAll();
} catch (Exception $e) {
    // Fallback if custom field tables do not exist
    $bankAccounts = $pdo->query("SELECT id, account_name, account_type FROM accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
}

// 3. Fetch Items with multi-currency price overrides & tax mapping
$itemsQuery = "
    SELECT i.id, i.name, i.description, i.unit_price, 
           COALESCE(tr.rate_percentage, 0) AS tax_rate,
           COALESCE(GROUP_CONCAT(CONCAT(p.currency_code, ':', p.unit_price) SEPARATOR '|'), '') AS prices
    FROM items i 
    LEFT JOIN tax_rates tr ON tr.id = i.tax_rate_id 
    LEFT JOIN item_prices p ON p.item_id = i.id 
    GROUP BY i.id 
    ORDER BY i.name ASC
";
$items = $pdo->query($itemsQuery)->fetchAll();

foreach ($items as &$item) {
    $priceMap = [];
    if (!empty($item['prices'])) {
        foreach (explode('|', (string) $item['prices']) as $pair) {
            if (!$pair)
                continue;
            [$code, $price] = explode(':', $pair, 2);
            $priceMap[$code] = (float) $price;
        }
    }
    $item['price_map'] = $priceMap;
}
unset($item);

// 4. Fetch Currencies, Taxes, and Templates
$currencies = $pdo->query('SELECT code, name, symbol, exchange_rate FROM currencies ORDER BY is_base_currency DESC, name ASC')->fetchAll();
$taxes = $pdo->query('SELECT rate_percentage, name FROM tax_rates WHERE is_active = 1 ORDER BY rate_percentage ASC')->fetchAll();
$templates = $pdo->query('SELECT id, name, is_default FROM invoice_templates ORDER BY is_default DESC, name ASC')->fetchAll();

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
$input = 'mt-1 w-full border border-gray-300 rounded-lg px-3 py-2.5 bg-white text-sm outline-none focus:ring-2 focus:ring-blue-500';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">

    <!-- Top Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex justify-between items-center sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Create Invoice</h2>
                <p class="text-xs text-slate-500">Configurable due dates, bank routing, and line-item details</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="invoices.php"
                class="border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                Cancel
            </a>
        </div>
    </header>

    <div class="p-5 md:p-8 max-w-6xl mx-auto">
        <form action="process_invoice.php" method="post"
            class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 md:p-8 space-y-7">
            <?= csrf_field() ?>

            <!-- Invoice Header Configuration -->
            <div class="grid md:grid-cols-2 lg:grid-cols-6 gap-4 pb-7 border-b border-slate-100">

                <div class="lg:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Customer / Client *</label>
                    <select id="customer" name="contact_id" required class="<?= $input ?>">
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-currency="<?= $c['currency_code'] ?>">
                                <?= h($c['company_name']) ?> (<?= h($c['type_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Currency *</label>
                    <select id="invoiceCurrency" name="currency_code" class="<?= $input ?>">
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= $c['code'] ?>" data-symbol="<?= h($c['symbol']) ?>"
                                data-rate="<?= $c['exchange_rate'] ?>">
                                <?= h($c['code']) ?> · <?= h($c['symbol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Deposit Bank Account</label>
                    <select id="bankAccountSelector" name="account_id" class="<?= $input ?>">
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= $ba['id'] ?>" data-details="<?= h($ba['custom_details'] ?? '') ?>">
                                <?= h($ba['account_name']) ?> (<?= ucfirst($ba['account_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Invoice Template</label>
                    <div class="flex gap-2">
                        <select name="invoice_template_id" class="<?= $input ?> flex-1">
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>>
                                    <?= h($t['name']) ?>         <?= $t['is_default'] ? '(Default)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="../invoice_templates.php"
                            class="self-end border border-slate-200 hover:bg-slate-50 text-blue-600 text-xs font-semibold px-3 py-2.5 rounded-lg transition-colors"
                            title="Manage Templates">
                            ⚙
                        </a>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="<?= $input ?>">
                </div>

                <!-- Due Date (Optional & Hideable) -->
                <div class="lg:col-span-2">
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-bold uppercase text-slate-600">Due Date</label>
                        <label class="text-[11px] text-slate-500 flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" id="hasDueDate" checked class="rounded text-blue-600 focus:ring-0">
                            Enable Due Date
                        </label>
                    </div>
                    <div id="dueDateWrapper">
                        <input type="date" id="dueDateInput" name="due_date"
                            value="<?= date('Y-m-d', strtotime('+30 days')) ?>" class="<?= $input ?>">
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Order / PO Reference</label>
                    <input name="notes" placeholder="e.g. PO-8921, Contract Reference, Sprint 4 Deliverables"
                        class="<?= $input ?>">
                </div>

            </div>

            <!-- Line Items Table -->
            <div>
                <div class="flex justify-between items-center mb-3">
                    <div class="flex items-center gap-4">
                        <h3 class="font-bold text-slate-900 text-base">Line Items & Services</h3>
                        <label
                            class="text-xs text-slate-600 flex items-center gap-1.5 cursor-pointer bg-slate-50 border px-2.5 py-1 rounded-lg">
                            <input type="checkbox" id="toggleDescriptions" checked class="rounded text-blue-600"> Show
                            Additional Descriptions
                        </label>
                    </div>
                    <button type="button" id="addRow"
                        class="bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1.5 rounded-lg border border-blue-200 transition-colors flex items-center gap-1">
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

                            <!-- Item Row Template -->
                            <tr class="item-row">
                                <td class="p-3">
                                    <select name="item_id[]" required
                                        class="item-select w-full border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">-- Choose Item / Service --</option>
                                        <?php foreach ($items as $i): ?>
                                            <option value="<?= $i['id'] ?>" data-base-price="<?= $i['unit_price'] ?>"
                                                data-prices='<?= json_encode($i['price_map']) ?>'
                                                data-tax="<?= $i['tax_rate'] ?>"
                                                data-description="<?= h($i['description']) ?>">
                                                <?= h($i['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="desc-wrapper mt-1.5">
                                        <input name="description[]"
                                            placeholder="Additional line note or description (optional)..."
                                            class="description w-full border border-slate-200 rounded px-2.5 py-1 text-xs text-slate-600 outline-none">
                                    </div>
                                </td>
                                <td class="p-3 align-top">
                                    <input type="number" name="quantity[]" value="1" min="0.001" step="any"
                                        class="qty w-full border border-slate-300 rounded-lg px-2.5 py-2 text-xs outline-none">
                                </td>
                                <td class="p-3 align-top">
                                    <input type="number" name="unit_price[]" value="0.00" min="0" step="0.01"
                                        class="price w-full border border-slate-300 rounded-lg px-2.5 py-2 text-xs font-semibold outline-none">
                                </td>
                                <td class="p-3 align-top">
                                    <select name="tax_rate[]"
                                        class="tax-rate w-full border border-slate-300 rounded-lg px-2 py-2 text-xs bg-white outline-none">
                                        <?php foreach ($taxes as $t): ?>
                                            <option value="<?= $t['rate_percentage'] ?>"><?= h($t['name']) ?>
                                                (<?= floatval($t['rate_percentage']) ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="p-3 text-right font-bold text-slate-800 align-top pt-4">
                                    <span class="row-total">0.00</span>
                                </td>
                                <td class="p-3 text-center align-top pt-3">
                                    <button type="button"
                                        class="remove-row text-slate-400 hover:text-rose-600 font-bold text-xl transition-colors">×</button>
                                </td>
                            </tr>

                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer Notes & Calculation Summary -->
            <div class="grid md:grid-cols-2 gap-8 pt-6 border-t border-slate-100">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Payment Instructions & Bank
                            Remittance</label>
                        <textarea id="paymentTerms" name="terms" rows="4" class="<?= $input ?>"
                            placeholder="Payment instructions, bank transfer guidelines, late fee policy..."><?= h(setting($pdo, 'invoice_terms', 'Payment is due within 30 days of issue date.')) ?></textarea>
                    </div>
                </div>

                <div class="ml-auto w-full max-w-sm space-y-3 bg-slate-50/80 border border-slate-200 rounded-2xl p-5">
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
                    <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white rounded-xl px-6 py-3 font-bold text-sm w-full transition-colors shadow-md mt-2">
                        Create & Post Invoice
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
        const customer = document.getElementById('customer');
        const bankSelector = document.getElementById('bankAccountSelector');
        const paymentTerms = document.getElementById('paymentTerms');
        const hasDueDate = document.getElementById('hasDueDate');
        const dueDateWrapper = document.getElementById('dueDateWrapper');
        const dueDateInput = document.getElementById('dueDateInput');
        const toggleDescriptions = document.getElementById('toggleDescriptions');

        function symbol() {
            return currency.selectedOptions[0]?.dataset.symbol || '$';
        }

        // 1. Due Date Toggle Handler
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

        // 2. Bank Account Selector Remittance Injector
        bankSelector.addEventListener('change', () => {
            const opt = bankSelector.selectedOptions[0];
            if (!opt || !opt.value) return;

            const accName = opt.textContent.trim();
            const customDetails = opt.dataset.details;

            let bankNotice = `Bank Payment Instructions:\nAccount Name: ${accName}`;
            if (customDetails && customDetails.trim() !== '') {
                bankNotice += `\n${customDetails}`;
            }

            if (paymentTerms.value.includes('Bank Payment Instructions:')) {
                paymentTerms.value = paymentTerms.value.replace(/Bank Payment Instructions:[\s\S]*?(?=\n\n|$)/, bankNotice);
            } else {
                paymentTerms.value = (paymentTerms.value ? paymentTerms.value + '\n\n' : '') + bankNotice;
            }
        });

        // 3. Additional Description Visibility Handler
        toggleDescriptions.addEventListener('change', () => {
            const wrappers = document.querySelectorAll('.desc-wrapper');
            wrappers.forEach(w => {
                if (toggleDescriptions.checked) {
                    w.classList.remove('hidden');
                } else {
                    w.classList.add('hidden');
                }
            });
        });

        function setItem(row) {
            const opt = row.querySelector('.item-select').selectedOptions[0];
            if (!opt || !opt.value) return;

            let prices = {};
            try {
                prices = JSON.parse(opt.dataset.prices || '{}');
            } catch (e) { }

            const basePrice = parseFloat(opt.dataset.basePrice || 0);
            const currCode = currency.value;
            const assignedPrice = prices[currCode] !== undefined ? prices[currCode] : basePrice;

            row.querySelector('.price').value = Number(assignedPrice).toFixed(2);
            row.querySelector('.description').value = opt.dataset.description || '';

            const tax = Number(opt.dataset.tax || 0);
            [...row.querySelector('.tax-rate').options].forEach(o => {
                o.selected = Number(o.value) === tax;
            });
        }

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

        tbody.addEventListener('change', e => {
            const r = e.target.closest('.item-row');
            if (e.target.classList.contains('item-select')) setItem(r);
            calculate();
        });

        tbody.addEventListener('input', calculate);

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

            // Preserve description visibility state on new row
            const descWrapper = row.querySelector('.desc-wrapper');
            if (descWrapper) {
                if (toggleDescriptions.checked) {
                    descWrapper.classList.remove('hidden');
                } else {
                    descWrapper.classList.add('hidden');
                }
            }

            tbody.append(row);
        };

        currency.onchange = () => {
            tbody.querySelectorAll('.item-row').forEach(r => {
                if (r.querySelector('.item-select').value) setItem(r);
            });
            calculate();
        };

        customer.onchange = () => {
            const code = customer.selectedOptions[0]?.dataset.currency;
            if (code && currency.value !== code) {
                currency.value = code;
                currency.dispatchEvent(new Event('change'));
            }
        };

        calculate();
    });
</script>

<?php require_once '../includes/footer.php'; ?>