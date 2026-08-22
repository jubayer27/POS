<?php
// admin/debit.php
require_once '../config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // 1. Handle Form Submission (Add New Debit)
    if (isset($_POST['add_debit'])) {
        $account_id = intval($_POST['account_id']);
        $payee_name = trim($_POST['payee_name']);
        $amount = floatval($_POST['amount']);
        $debit_date = $_POST['debit_date'];
        $description = trim($_POST['description']);
        $status = $_POST['status'];

        if (empty($account_id) || empty($payee_name) || $amount <= 0) {
            $error_msg = "Please fill in all required fields with a valid amount.";
        } else {
            try {
                $pdo->beginTransaction();

                // Insert the payable record
                $stmt = $pdo->prepare("INSERT INTO debits (account_id, payee_name, amount, debit_date, description, status) 
                                       VALUES (:acc_id, :payee, :amt, :date, :desc, :status)");
                $stmt->execute([
                    'acc_id' => $account_id,
                    'payee' => $payee_name,
                    'amt' => $amount,
                    'date' => $debit_date,
                    'desc' => $description,
                    'status' => $status
                ]);

                // If marked as cleared immediately, deduct from the account
                if ($status === 'cleared') {
                    $stmt_update = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - :amt WHERE id = :acc_id");
                    $stmt_update->execute([
                        'amt' => $amount,
                        'acc_id' => $account_id
                    ]);
                }

                $pdo->commit();
                header("Location: debit.php?status=added");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_msg = "Error recording payable: " . $e->getMessage();
            }
        }
    }

    // 2. Handle Marking a Pending Debit as Cleared (Paid)
    if (isset($_POST['mark_cleared'])) {
        $debit_id = intval($_POST['debit_id']);

        try {
            $pdo->beginTransaction();

            // Fetch the debit record to ensure it is still pending and get the exact amount/account
            $stmt_check = $pdo->prepare("SELECT account_id, amount, status FROM debits WHERE id = :id FOR UPDATE");
            $stmt_check->execute(['id' => $debit_id]);
            $debit = $stmt_check->fetch();

            if ($debit && $debit['status'] === 'pending') {
                // Update debit status
                $stmt_upd_debit = $pdo->prepare("UPDATE debits SET status = 'cleared' WHERE id = :id");
                $stmt_upd_debit->execute(['id' => $debit_id]);

                // Deduct from account balance
                $stmt_upd_acc = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - :amt WHERE id = :acc_id");
                $stmt_upd_acc->execute([
                    'amt' => $debit['amount'],
                    'acc_id' => $debit['account_id']
                ]);

                $pdo->commit();
                header("Location: debit.php?status=cleared");
                exit;
            } else {
                $pdo->rollBack();
                $error_msg = "This record has already been cleared or does not exist.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Error updating status: " . $e->getMessage();
        }
    }
}

// Check for success flags in URL
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'added')
        $success_msg = "New payable record added successfully!";
    if ($_GET['status'] === 'cleared')
        $success_msg = "Payment cleared and account balance updated!";
}

// 3. Fetch Data for the Interface
try {
    // Fetch active accounts for the dropdown
    $stmt_acc = $pdo->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name ASC");
    $accounts = $stmt_acc->fetchAll();

    // Fetch debit history with account names
    $stmt_deb = $pdo->query("SELECT d.*, a.account_name 
                             FROM debits d 
                             JOIN accounts a ON d.account_id = a.id 
                             ORDER BY d.status DESC, d.debit_date DESC");
    $debits = $stmt_deb->fetchAll();

    // Calculate Total Pending Payables
    $stmt_pending = $pdo->query("SELECT SUM(amount) AS total_pending FROM debits WHERE status = 'pending'");
    $total_pending = $stmt_pending->fetch()['total_pending'] ?? 0.00;

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <!-- Header -->
    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Accounts Payable (Debits)</h2>
        <div class="bg-red-50 border border-red-200 px-4 py-2 rounded-lg">
            <span class="text-sm text-red-600 font-medium mr-2">Total Unpaid:</span>
            <span class="text-lg font-bold text-red-800">$
                <?= number_format($total_pending, 2) ?>
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

            <!-- Left Column: Add Debit Form -->
            <div class="xl:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Record Payable</h3>

                    <form action="debit.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payee Name *</label>
                            <input type="text" name="payee_name" placeholder="Supplier, Vendor, etc." required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Account *</label>
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
                                <label class="block text-sm font-medium text-gray-700 mb-1">Due/Paid Date *</label>
                                <input type="date" name="debit_date" value="<?= date('Y-m-d') ?>" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" rows="2" placeholder="Invoice details, terms, etc."
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Current Status *</label>
                            <select name="status"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="pending">Pending (Unpaid)</option>
                                <option value="cleared">Cleared (Paid)</option>
                            </select>
                        </div>

                        <button type="submit" name="add_debit"
                            class="w-full bg-gray-800 text-white font-semibold py-2 rounded-lg hover:bg-gray-900 transition-colors mt-2">
                            Save Record
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Debits History Table -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Payables Directory</h3>
                    </div>

                    <div class="overflow-x-auto max-h-[600px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Date</th>
                                    <th class="px-6 py-3 font-medium">Payee & Info</th>
                                    <th class="px-6 py-3 font-medium">Amount</th>
                                    <th class="px-6 py-3 font-medium">Status</th>
                                    <th class="px-6 py-3 font-medium text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($debits) > 0): ?>
                                    <?php foreach ($debits as $deb): ?>
                                        <tr
                                            class="hover:bg-gray-50 transition-colors <?= $deb['status'] === 'pending' ? 'bg-red-50/30' : '' ?>">
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <?= date('M d, Y', strtotime($deb['debit_date'])) ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?= htmlspecialchars($deb['payee_name']) ?>
                                                </p>
                                                <p class="text-xs text-gray-500">From:
                                                    <?= htmlspecialchars($deb['account_name']) ?>
                                                </p>
                                                <?php if (!empty($deb['description'])): ?>
                                                    <p class="text-xs text-gray-400 mt-1 truncate max-w-[150px]"
                                                        title="<?= htmlspecialchars($deb['description']) ?>">
                                                        <?= htmlspecialchars($deb['description']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                $
                                                <?= number_format($deb['amount'], 2) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <?php if ($deb['status'] === 'cleared'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-green-100 text-green-800">Cleared</span>
                                                <?php else: ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-center">
                                                <?php if ($deb['status'] === 'pending'): ?>
                                                    <form action="debit.php" method="POST"
                                                        onsubmit="return confirm('Mark this as paid? This will deduct $<?= number_format($deb['amount'], 2) ?> from <?= htmlspecialchars($deb['account_name']) ?>.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="debit_id" value="<?= $deb['id'] ?>">
                                                        <button type="submit" name="mark_cleared"
                                                            class="text-white bg-green-500 hover:bg-green-600 px-3 py-1 rounded text-xs font-medium transition-colors">
                                                            Pay Now
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-xs">Settled</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No payable records found.
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
