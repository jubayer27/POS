<?php
// admin/items.php
require_once '../config/db.php';

$success_msg = '';
$error_msg = '';

// 1. Process Form Submission First (Prevents header errors)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    verify_csrf();
    $item_type = $_POST['item_type'];
    $name = trim($_POST['name']);
    $sku = trim($_POST['sku']) ?: null; // Optional
    $description = trim($_POST['description']);
    $unit_price = floatval($_POST['unit_price']);
    $income_account_id = intval($_POST['income_account_id']);
    $expense_account_id = !empty($_POST['expense_account_id']) ? intval($_POST['expense_account_id']) : null;
    $tax_rate_id = !empty($_POST['tax_rate_id']) ? intval($_POST['tax_rate_id']) : null;

    if (empty($name) || empty($income_account_id)) {
        $error_msg = "Item Name and Income Account are strictly required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO items (item_type, name, sku, description, unit_price, income_account_id, expense_account_id, tax_rate_id) 
                                   VALUES (:type, :name, :sku, :desc, :price, :inc_acc, :exp_acc, :tax_id)");
            $stmt->execute([
                'type' => $item_type,
                'name' => $name,
                'sku' => $sku,
                'desc' => $description,
                'price' => $unit_price,
                'inc_acc' => $income_account_id,
                'exp_acc' => $expense_account_id,
                'tax_id' => $tax_rate_id
            ]);

            header("Location: items.php?status=success");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate SKU)
                $error_msg = "Error: That SKU is already in use. SKUs must be unique.";
            } else {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "New item added successfully!";
}

// 2. Fetch Data for Dropdowns and the Table
try {
    // Get Revenue Accounts
    $stmt_inc = $pdo->query("SELECT id, account_code, name FROM chart_of_accounts WHERE type = 'Revenue' AND is_active = 1 ORDER BY account_code ASC");
    $income_accounts = $stmt_inc->fetchAll();

    // Get Expense Accounts (COGS)
    $stmt_exp = $pdo->query("SELECT id, account_code, name FROM chart_of_accounts WHERE type = 'Expense' AND is_active = 1 ORDER BY account_code ASC");
    $expense_accounts = $stmt_exp->fetchAll();

    // Get Tax Rates
    $stmt_tax = $pdo->query("SELECT id, name, rate_percentage FROM tax_rates WHERE is_active = 1 ORDER BY rate_percentage ASC");
    $tax_rates = $stmt_tax->fetchAll();

    // Get all Items for the list
    $stmt_items = $pdo->query("
        SELECT i.*, 
               inc.name AS income_name, 
               tr.name AS tax_name, tr.rate_percentage
        FROM items i
        LEFT JOIN chart_of_accounts inc ON i.income_account_id = inc.id
        LEFT JOIN tax_rates tr ON i.tax_rate_id = tr.id
        ORDER BY i.name ASC
    ");
    $items = $stmt_items->fetchAll();

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// 3. Load UI Layout
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Products & Services Library</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-8">

        <?php if ($success_msg): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
                <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm">
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

            <!-- Left Column: Add Item Form -->
            <div class="xl:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Add New Item</h3>

                    <form action="items.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Item Type *</label>
                                <select name="item_type" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                    <option value="product">Product</option>
                                    <option value="service">Service</option>
                                    <option value="digital">Digital Good</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SKU / Code</label>
                                <input type="text" name="sku" placeholder="e.g. PRD-001"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Item Name *</label>
                            <input type="text" name="name" required placeholder="e.g. Web Design Package"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default Selling Price ($)
                                *</label>
                            <input type="number" name="unit_price" step="0.01" min="0" value="0.00" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div class="pt-2 border-t border-gray-100">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Income Account (Revenue)
                                *</label>
                            <select name="income_account_id" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="">-- Where does the money go? --</option>
                                <?php foreach ($income_accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= $acc['account_code'] ?> -
                                        <?= htmlspecialchars($acc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Select the GL account to credit when this item is
                                sold.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expense Account (Optional
                                COGS)</label>
                            <select name="expense_account_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="">-- None --</option>
                                <?php foreach ($expense_accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= $acc['account_code'] ?> -
                                        <?= htmlspecialchars($acc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default Tax Rate</label>
                            <select name="tax_rate_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="">-- No Tax --</option>
                                <?php foreach ($tax_rates as $tax): ?>
                                    <option value="<?= $tax['id'] ?>">
                                        <?= htmlspecialchars($tax['name']) ?> (
                                        <?= floatval($tax['rate_percentage']) ?>%)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                            <textarea name="description" rows="2"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                        </div>

                        <button type="submit" name="add_item"
                            class="w-full bg-blue-600 text-white font-semibold py-2 rounded-lg hover:bg-blue-700 transition-colors mt-2">
                            Save Item
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Item Library -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Item Library</h3>
                    </div>

                    <div class="overflow-x-auto max-h-[700px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Name & SKU</th>
                                    <th class="px-6 py-3 font-medium">Type</th>
                                    <th class="px-6 py-3 font-medium">Income Account</th>
                                    <th class="px-6 py-3 font-medium">Tax Rate</th>
                                    <th class="px-6 py-3 font-medium text-right">Price</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($items) > 0): ?>
                                    <?php foreach ($items as $item): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?= htmlspecialchars($item['name']) ?>
                                                </p>
                                                <?php if ($item['sku']): ?>
                                                    <p class="text-xs text-gray-500">SKU:
                                                        <?= htmlspecialchars($item['sku']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800 capitalize">
                                                    <?= htmlspecialchars($item['item_type']) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= htmlspecialchars($item['income_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= $item['tax_name'] ? htmlspecialchars($item['tax_name']) . ' (' . floatval($item['rate_percentage']) . '%)' : 'None' ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900 text-right">
                                                $
                                                <?= number_format($item['unit_price'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No items found. Add your first product or service on the left.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
