<?php
// admin/investments.php
require_once '../config/db.php';

$success_msg = '';
$error_msg = '';

// 1. Process Form Data FIRST to prevent header errors
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_investment'])) {
    verify_csrf();
    $account_id = intval($_POST['account_id']);
    $investment_title = trim($_POST['investment_title']);
    $amount = floatval($_POST['amount']);
    $investment_date = $_POST['investment_date'];
    $expected_return_rate = floatval($_POST['expected_return_rate'] ?? 0);
    $status = $_POST['status'];

    if (empty($account_id) || empty($investment_title) || $amount <= 0) {
        $error_msg = "Please provide an account, title, and a valid amount.";
    } else {
        try {
            $pdo->beginTransaction();

            // Insert the investment record
            $stmt = $pdo->prepare("INSERT INTO investments (account_id, investment_title, amount, investment_date, expected_return_rate, status) 
                                   VALUES (:acc_id, :title, :amt, :date, :rate, :status)");
            $stmt->execute([
                'acc_id' => $account_id,
                'title' => $investment_title,
                'amt' => $amount,
                'date' => $investment_date,
                'rate' => $expected_return_rate,
                'status' => $status
            ]);

            // Deduct the invested amount from the company's liquid account
            $stmt_update = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - :amt WHERE id = :acc_id");
            $stmt_update->execute([
                'amt' => $amount,
                'acc_id' => $account_id
            ]);

            $pdo->commit();
            header("Location: investments.php?status=success");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Error recording investment: " . $e->getMessage();
        }
    }
}

// Check for success flag
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "Investment portfolio updated and funds transferred successfully!";
}

// 2. Fetch Data for the Interface
try {
    // Fetch active accounts for the funding dropdown
    $stmt_acc = $pdo->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name ASC");
    $accounts = $stmt_acc->fetchAll();

    // Fetch investment history
    $stmt_inv = $pdo->query("SELECT i.*, a.account_name 
                             FROM investments i 
                             JOIN accounts a ON i.account_id = a.id 
                             ORDER BY i.status ASC, i.investment_date DESC");
    $investments = $stmt_inv->fetchAll();

    // Calculate Total Active Investments
    $stmt_total = $pdo->query("SELECT SUM(amount) AS total_active FROM investments WHERE status = 'active'");
    $total_active = $stmt_total->fetch()['total_active'] ?? 0.00;

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// 3. Load HTML Layout
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <!-- Header -->
    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Company Investments</h2>
        <div class="bg-purple-50 border border-purple-200 px-4 py-2 rounded-lg">
            <span class="text-sm text-purple-600 font-medium mr-2">Active Portfolio:</span>
            <span class="text-lg font-bold text-purple-800">$
                <?= number_format($total_active, 2) ?>
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

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

            <!-- Left Column: Add Investment Form -->
            <div class="xl:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Record New Investment</h3>

                    <form action="investments.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Asset / Portfolio Title
                                *</label>
                            <input type="text" name="investment_title" placeholder="e.g., Fixed Deposit, Govt Bonds"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Funding Account *</label>
                            <select name="account_id" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="">-- Select Source Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= htmlspecialchars($acc['account_name']) ?> (Bal: $
                                        <?= number_format($acc['current_balance'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($) *</label>
                                <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                                <input type="date" name="investment_date" value="<?= date('Y-m-d') ?>" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Expected Return (%)</label>
                                <input type="number" name="expected_return_rate" step="0.01" placeholder="e.g., 5.5"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Current Status *</label>
                                <select name="status"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                    <option value="active">Active</option>
                                    <option value="matured">Matured</option>
                                    <option value="withdrawn">Withdrawn</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" name="add_investment"
                            class="w-full bg-purple-600 text-white font-semibold py-2 rounded-lg hover:bg-purple-700 transition-colors mt-2">
                            Add to Portfolio
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Investment History Table -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Portfolio Ledger</h3>
                    </div>

                    <div class="overflow-x-auto max-h-[600px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Asset & Date</th>
                                    <th class="px-6 py-3 font-medium">Funded From</th>
                                    <th class="px-6 py-3 font-medium">Est. Return</th>
                                    <th class="px-6 py-3 font-medium">Principal</th>
                                    <th class="px-6 py-3 font-medium text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($investments) > 0): ?>
                                    <?php foreach ($investments as $inv): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?= htmlspecialchars($inv['investment_title']) ?>
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    <?= date('M d, Y', strtotime($inv['investment_date'])) ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= htmlspecialchars($inv['account_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= $inv['expected_return_rate'] > 0 ? htmlspecialchars($inv['expected_return_rate']) . '%' : '--' ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                $
                                                <?= number_format($inv['amount'], 2) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-center">
                                                <?php if ($inv['status'] === 'active'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-green-100 text-green-800">Active</span>
                                                <?php elseif ($inv['status'] === 'matured'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-blue-100 text-blue-800">Matured</span>
                                                <?php else: ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-gray-100 text-gray-800">Withdrawn</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No investments recorded yet.
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
