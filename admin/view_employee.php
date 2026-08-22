<?php
// admin/view_employee.php
require_once '../config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// Check if an ID was passed in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: employee.php");
    exit;
}

$employee_id = intval($_GET['id']);

try {
    // 1. Fetch Employee Details
    $stmt_emp = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $stmt_emp->execute(['id' => $employee_id]);
    $employee = $stmt_emp->fetch();

    if (!$employee) {
        die("Employee not found.");
    }

    // 2. Fetch Employee's Payslip History
    $stmt_payslips = $pdo->prepare("SELECT * FROM payslips WHERE employee_id = :id ORDER BY month_year DESC");
    $stmt_payslips->execute(['id' => $employee_id]);
    $payslips = $stmt_payslips->fetchAll();

    // Calculate total amount paid to this employee
    $stmt_total = $pdo->prepare("SELECT SUM(net_payable) AS total_paid FROM payslips WHERE employee_id = :id AND status = 'paid'");
    $stmt_total->execute(['id' => $employee_id]);
    $total_paid = $stmt_total->fetch()['total_paid'] ?? 0.00;

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <!-- Top Headerbar -->
    <header class="bg-white shadow-sm px-8 py-4 flex items-center gap-4 border-b border-gray-200">
        <a href="employee.php" class="text-gray-500 hover:text-blue-600 transition-colors" title="Back to Directory">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18">
                </path>
            </svg>
        </a>
        <h2 class="text-2xl font-semibold text-gray-800">Employee Profile</h2>
    </header>

    <!-- Content Area -->
    <div class="flex-1 overflow-y-auto p-8">

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

            <!-- Left Column: Profile Card -->
            <div class="xl:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 text-center">
                    <div
                        class="w-24 h-24 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center text-3xl font-bold mx-auto mb-4">
                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                    </h3>
                    <p class="text-gray-500 font-medium mb-4">
                        <?= htmlspecialchars($employee['designation']) ?>
                    </p>

                    <?php if ($employee['status'] === 'active'): ?>
                        <span class="px-4 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">Active
                            Employee</span>
                    <?php else: ?>
                        <span class="px-4 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">Inactive</span>
                    <?php endif; ?>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4 border-b pb-2">Contact
                        & Details</h4>
                    <div class="space-y-4 text-sm text-gray-700">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                            <?= htmlspecialchars($employee['email'] ?: 'Not Provided') ?>
                        </div>
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z">
                                </path>
                            </svg>
                            <?= htmlspecialchars($employee['phone'] ?: 'Not Provided') ?>
                        </div>
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                                </path>
                            </svg>
                            Joined:
                            <?= date('F d, Y', strtotime($employee['joined_date'])) ?>
                        </div>
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                                </path>
                            </svg>
                            Base Salary: $
                            <?= number_format($employee['base_salary'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="bg-green-50 border border-green-200 p-6 rounded-xl text-center">
                    <h4 class="text-sm font-semibold text-green-700 uppercase mb-1">Total Paid To Date</h4>
                    <span class="text-3xl font-bold text-green-900">$
                        <?= number_format($total_paid, 2) ?>
                    </span>
                </div>
            </div>

            <!-- Right Column: Payslip History Table -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Salary History</h3>
                        <a href="payslip.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Generate New
                            &rarr;</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Month</th>
                                    <th class="px-6 py-3 font-medium">Basic</th>
                                    <th class="px-6 py-3 font-medium">Net Payable</th>
                                    <th class="px-6 py-3 font-medium">Status</th>
                                    <th class="px-6 py-3 font-medium text-center">Date Paid</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($payslips) > 0): ?>
                                    <?php foreach ($payslips as $slip): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                                <?= date('F Y', strtotime($slip['month_year'] . '-01')) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                $
                                                <?= number_format($slip['basic_salary'], 2) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-800">
                                                $
                                                <?= number_format($slip['net_payable'], 2) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <?php if ($slip['status'] === 'paid'): ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-green-100 text-green-800">Paid</span>
                                                <?php else: ?>
                                                    <span
                                                        class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-red-100 text-red-800">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-center text-gray-500">
                                                <?= !empty($slip['payment_date']) ? date('M d, Y', strtotime($slip['payment_date'])) : '--' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No salary records found for this employee.
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