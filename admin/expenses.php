<?php
// admin/expenses.php
require_once '../config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$success_msg = '';
$error_msg = '';

// 1. Handle Form Submission (Add New Expense)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    verify_csrf();
    $account_id = intval($_POST['account_id']);
    $category = trim($_POST['category']);
    $amount = floatval($_POST['amount']);
    $expense_date = $_POST['expense_date'];
    $reference_note = trim($_POST['reference_note']);

    if (empty($account_id) || empty($category) || $amount <= 0) {
        $error_msg = "Please fill in all required fields with a valid amount.";
    } else {
        try {
            // Begin Transaction: We need to update two tables at once safely
            $pdo->beginTransaction();

            // Insert into the expenses table
            $stmt = $pdo->prepare("INSERT INTO expenses (account_id, category, amount, expense_date, reference_note) 
                                   VALUES (:acc_id, :cat, :amt, :date, :note)");
            $stmt->execute([
                'acc_id' => $account_id,
                'cat' => $category,
                'amt' => $amount,
                'date' => $expense_date,
                'note' => $reference_note
            ]);

            // Deduct the amount from the selected account's balance
            $stmt_update = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - :amt WHERE id = :acc_id");
            $stmt_update->execute([
                'amt' => $amount,
                'acc_id' => $account_id
            ]);

            // Commit the transaction
            $pdo->commit();

            header("Location: expenses.php?status=success");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Error recording expense: " . $e->getMessage();
        }
    }
}

// Check for success flag in URL
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "Expense logged and account balance updated successfully!";
}

// 2. Fetch Data for the Interface
try {
    // Fetch active accounts for the dropdown
    $stmt_acc = $pdo->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name ASC");
    $accounts = $stmt_acc->fetchAll();

    // Fetch expense history with the associated account name
    $stmt_exp = $pdo->query("SELECT e.*, a.account_name 
                             FROM expenses e 
                             JOIN accounts a ON e.account_id = a.id 
                             ORDER BY e.expense_date DESC, e.id DESC");
    $expenses = $stmt_exp->fetchAll();

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <!-- Header -->
    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Expense Tracker</h2>
    </header>

    <!-- Main Content -->
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Left Column: Record Expense Form -->
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Record New Expense</h3>

                    <form action="expenses.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Account *</label>
                            <select name="account_id" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= htmlspecialchars($acc['account_name']) ?> (Bal: $
                                        <?= number_format($acc['current_balance'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expense Category *</label>
                            <input type="text" name="category" placeholder="e.g., Office Supplies, Marketing, Utilities"
                                required list="category-presets"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">

                            <!-- Datalist for autocomplete suggestions -->
                            <datalist id="category-presets">
                                <option value="Software Subscriptions">
                                <option value="Marketing & Ads">
                                <option value="Travel & Transport">
                                <option value="Office Supplies">
                                <option value="Meals & Entertainment">
                                <option value="Utilities">
                            </datalist>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($) *</label>
                                <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                                <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reference Note</label>
                            <textarea name="reference_note" rows="2" placeholder="Invoice #, receipt details, etc."
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                        </div>

                        <button type="submit" name="add_expense"
                            class="w-full bg-red-600 text-white font-semibold py-2 rounded-lg hover:bg-red-700 transition-colors mt-2 flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4"></path>
                            </svg>
                            Log Expense
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Expense History Table -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Expense History</h3>
                    </div>

                    <div class="overflow-x-auto max-h-[600px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Date</th>
                                    <th class="px-6 py-3 font-medium">Details</th>
                                    <th class="px-6 py-3 font-medium">Paid From</th>
                                    <th class="px-6 py-3 font-medium text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($expenses) > 0): ?>
                                    <?php foreach ($expenses as $exp): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= date('M d, Y', strtotime($exp['expense_date'])) ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?= htmlspecialchars($exp['category']) ?>
                                                </p>
                                                <?php if (!empty($exp['reference_note'])): ?>
                                                    <p class="text-xs text-gray-500 truncate max-w-[200px]"
                                                        title="<?= htmlspecialchars($exp['reference_note']) ?>">
                                                        <?= htmlspecialchars($exp['reference_note']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= htmlspecialchars($exp['account_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <span class="text-sm font-bold text-red-600">
                                                    -$
                                                    <?= number_format($exp['amount'], 2) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No expenses logged yet.
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
