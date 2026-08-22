<?php
// admin/invoice/create_invoice.php
require_once '../../config/db.php';

try {
    // 1. Fetch Customers
    $stmt_contacts = $pdo->query("SELECT id, company_name, currency_code FROM contacts WHERE contact_type IN ('customer', 'both') ORDER BY company_name ASC");
    $customers = $stmt_contacts->fetchAll();

    // 2. Fetch Items Library
    $stmt_items = $pdo->query("SELECT i.*, tr.rate_percentage FROM items i LEFT JOIN tax_rates tr ON i.tax_rate_id = tr.id ORDER BY i.name ASC");
    $items = $stmt_items->fetchAll();

    // 3. Fetch Currencies
    $stmt_curr = $pdo->query("SELECT code, symbol, exchange_rate FROM currencies");
    $currencies = $stmt_curr->fetchAll();

} catch (PDOException $e) {
    die("Error loading invoice form data: " . $e->getMessage());
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Create Enterprise Invoice</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-8">
        <form action="process_invoice.php" method="POST"
            class="bg-white p-8 rounded-xl shadow-sm border border-gray-100 max-w-6xl mx-auto">
            <?= csrf_field() ?>

            <!-- Metadata Section -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 border-b border-gray-100 pb-8">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Customer *</label>
                    <select name="contact_id" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-currency="<?= $c['currency_code'] ?>">
                                <?= htmlspecialchars($c['company_name']) ?> (<?= $c['currency_code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Date *</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Due Date *</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg outline-none">
                </div>
            </div>

            <!-- Line Items Table -->
            <div class="mb-8">
                <h3 class="text-lg font-medium text-gray-800 mb-4">Line Items</h3>
                <div class="overflow-x-auto border border-gray-200 rounded-lg">
                    <table class="w-full text-left border-collapse" id="invoiceTable">
                        <thead>
                            <tr class="bg-gray-50 text-gray-600 text-sm border-b border-gray-200">
                                <th class="p-3 font-medium w-1/3">Item / Service</th>
                                <th class="p-3 font-medium w-20">Qty</th>
                                <th class="p-3 font-medium w-32">Unit Price</th>
                                <th class="p-3 font-medium w-28">Tax Rate</th>
                                <th class="p-3 font-medium w-32">Total</th>
                                <th class="p-3 font-medium w-12 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="invoiceItems" class="divide-y divide-gray-100">
                            <!-- Initial Template Row -->
                            <tr class="item-row">
                                <td class="p-3">
                                    <select name="item_id[]"
                                        class="w-full px-3 py-2 border border-gray-300 rounded outline-none item-select"
                                        required>
                                        <option value="">-- Select Item --</option>
                                        <?php foreach ($items as $it): ?>
                                            <option value="<?= $it['id'] ?>" data-price="<?= $it['unit_price'] ?>"
                                                data-tax="<?= $it['rate_percentage'] ?? 0 ?>">
                                                <?= htmlspecialchars($it['name']) ?>
                                                ($<?= number_format($it['unit_price'], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="description[]" placeholder="Custom note or description..."
                                        class="w-full mt-1 px-3 py-1 text-xs border border-gray-200 rounded outline-none text-gray-600">
                                </td>
                                <td class="p-3"><input type="number" name="quantity[]"
                                        class="w-full px-3 py-2 border border-gray-300 rounded outline-none qty"
                                        value="1" min="0.01" step="any" required></td>
                                <td class="p-3"><input type="number" name="unit_price[]" step="0.01"
                                        class="w-full px-3 py-2 border border-gray-300 rounded outline-none price"
                                        placeholder="0.00" required></td>
                                <td class="p-3">
                                    <select name="tax_rate[]"
                                        class="w-full px-3 py-2 border border-gray-300 rounded outline-none tax-rate">
                                        <option value="0">0%</option>
                                        <option value="5">5%</option>
                                        <option value="10">10%</option>
                                        <option value="15">15%</option>
                                    </select>
                                </td>
                                <td class="p-3"><input type="text"
                                        class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded text-gray-600 row-total"
                                        value="0.00" readonly></td>
                                <td class="p-3 text-center">
                                    <button type="button"
                                        class="text-red-500 hover:text-red-700 font-bold remove-row text-xl">&times;</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" id="addRow"
                    class="mt-4 text-sm text-blue-600 font-medium hover:text-blue-800 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add Line Item
                </button>
            </div>

            <!-- Calculation Totals Block -->
            <div class="flex justify-end mb-8 border-t border-gray-100 pt-6">
                <div class="w-80 space-y-3 text-gray-700">
                    <div class="flex justify-between items-center">
                        <span class="font-medium">Subtotal:</span>
                        <span class="font-semibold text-lg" id="subtotal">$0.00</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="font-medium">Total Tax:</span>
                        <span class="font-semibold text-lg" id="totalTax">$0.00</span>
                    </div>
                    <div class="flex justify-between items-center pt-3 border-t border-gray-200">
                        <span class="font-bold text-xl text-gray-900">Grand Total:</span>
                        <span class="font-bold text-xl text-blue-600" id="grandTotal">$0.00</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-4">
                <a href="invoices.php"
                    class="bg-gray-200 text-gray-700 font-semibold px-6 py-3 rounded-lg hover:bg-gray-300 transition-colors">Cancel</a>
                <button type="submit"
                    class="bg-blue-600 text-white font-semibold px-8 py-3 rounded-lg hover:bg-blue-700 transition-colors shadow-md">
                    Generate & Post Invoice
                </button>
            </div>
        </form>
    </div>
</main>

<!-- Frontend Calculation Script -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tbody = document.getElementById('invoiceItems');

        // Auto-fill price and tax when an item is selected from dropdown
        tbody.addEventListener('change', function (e) {
            if (e.target.classList.contains('item-select')) {
                const opt = e.target.selectedOptions[0];
                const row = e.target.closest('tr');
                const price = opt.getAttribute('data-price') || 0;
                const tax = opt.getAttribute('data-tax') || 0;

                row.querySelector('.price').value = parseFloat(price).toFixed(2);
                const taxSelect = row.querySelector('.tax-rate');
                for (let option of taxSelect.options) {
                    if (parseFloat(option.value) === parseFloat(tax)) {
                        option.selected = true;
                        break;
                    }
                }
                calculateTotals();
            }
        });

        const calculateTotals = () => {
            let subtotal = 0;
            let totalTax = 0;

            document.querySelectorAll('#invoiceItems tr').forEach(row => {
                const qty = parseFloat(row.querySelector('.qty').value) || 0;
                const price = parseFloat(row.querySelector('.price').value) || 0;
                const taxRate = parseFloat(row.querySelector('.tax-rate').value) || 0;

                const lineSubtotal = qty * price;
                const lineTax = lineSubtotal * (taxRate / 100);
                const lineTotal = lineSubtotal + lineTax;

                row.querySelector('.row-total').value = lineTotal.toFixed(2);

                subtotal += lineSubtotal;
                totalTax += lineTax;
            });

            document.getElementById('subtotal').innerText = '$' + subtotal.toFixed(2);
            document.getElementById('totalTax').innerText = '$' + totalTax.toFixed(2);
            document.getElementById('grandTotal').innerText = '$' + (subtotal + totalTax).toFixed(2);
        };

        document.getElementById('addRow').addEventListener('click', () => {
            const firstRow = document.querySelector('.item-row');
            const newRow = firstRow.cloneNode(true);
            newRow.querySelector('select.item-select').value = '';
            newRow.querySelector('input[name="description[]"]').value = '';
            newRow.querySelector('.qty').value = '1';
            newRow.querySelector('.price').value = '';
            newRow.querySelector('.row-total').value = '0.00';
            tbody.appendChild(newRow);
        });

        tbody.addEventListener('input', calculateTotals);
        tbody.addEventListener('change', calculateTotals);

        tbody.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row') && tbody.children.length > 1) {
                e.target.closest('tr').remove();
                calculateTotals();
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
