<?php
// admin/accounts.php
require_once '../config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$success_msg = '';
$error_msg = '';

// 1. Handle Form Submission (Add New Account)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    verify_csrf();
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $account_number = trim($_POST['account_number']);
    $initial_balance = floatval($_POST['initial_balance'] ?? 0.00);

    if (empty($account_name) || empty($account_type)) {
        $error_msg = "Account Name and Type are required.";
    } else {
        try {
            $ledgerCode = $account_type === 'cash' ? '1000' : '1010';
            $ledgerStmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
            $ledgerStmt->execute([$ledgerCode]);
            $stmt = $pdo->prepare("INSERT INTO accounts (ledger_account_id, account_name, account_type, account_number, current_balance) 
                                   VALUES (:ledger, :name, :type, :acc_num, :balance)");
            $stmt->execute([
                'ledger' => $ledgerStmt->fetchColumn() ?: null,
                'name' => $account_name,
                'type' => $account_type,
                'acc_num' => $account_number,
                'balance' => $initial_balance
            ]);

            header("Location: accounts.php?status=success");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Error adding account: " . $e->getMessage();
        }
    }
}

// Check for success flag in URL
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "New account added successfully!";
}

// 2. Fetch All Accounts for the Table
try {
    $stmt = $pdo->query("SELECT * FROM accounts ORDER BY account_type, account_name ASC");
    $accounts = $stmt->fetchAll();

    // Calculate Total Company Liquidity (Sum of all accounts)
    $total_liquidity = array_sum(array_column($accounts, 'current_balance'));

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <!-- Header -->
    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Bank & Cash Accounts</h2>
        <div class="bg-blue-50 border border-blue-200 px-4 py-2 rounded-lg">
            <span class="text-sm text-blue-600 font-medium mr-2">Total Liquidity:</span>
            <span class="text-lg font-bold text-blue-800">$
                <?= number_format($total_liquidity, 2) ?>
            </span>
        </div>
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

            <!-- Left Column: Add Account Form -->
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Add New Account</h3>

                    <form action="accounts.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Name *</label>
                            <input type="text" name="account_name" placeholder="e.g., Main Business Checking" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Type *</label>
                            <select name="account_type" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="bank">Bank Account</option>
                                <option value="cash">Petty Cash</option>
                                <option value="mobile_money">Mobile Money (e.g., bKash, TNG)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Number
                                (Optional)</label>
                            <input type="text" name="account_number" placeholder="**** **** 1234"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Initial Balance ($)</label>
                            <input type="number" name="initial_balance" step="0.01" value="0.00"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <button type="submit" name="add_account"
                            class="w-full bg-blue-600 text-white font-semibold py-2 rounded-lg hover:bg-blue-700 transition-colors mt-2">
                            Create Account
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Accounts Table -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Ledger Balances</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Account Details</th>
                                    <th class="px-6 py-3 font-medium">Type</th>
                                    <th class="px-6 py-3 font-medium text-right">Current Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($accounts) > 0): ?>
                                    <?php foreach ($accounts as $acc): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?= htmlspecialchars($acc['account_name']) ?>
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    <?= !empty($acc['account_number']) ? 'Acc: ' . htmlspecialchars($acc['account_number']) : 'No Account Number' ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($acc['account_type'] === 'bank'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">Bank</span>
                                                <?php elseif ($acc['account_type'] === 'cash'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">Cash</span>
                                                <?php else: ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">Mobile
                                                        Money</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <span
                                                    class="text-sm font-bold <?= $acc['current_balance'] < 0 ? 'text-red-600' : 'text-gray-800' ?>">
                                                    $
                                                    <?= number_format($acc['current_balance'], 2) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No accounts configured yet. Add your first bank or cash account to begin.
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
