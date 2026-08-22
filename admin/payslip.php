<?php
// admin/payslip.php
require_once '../config/db.php';

$success_msg = '';
$error_msg = '';

// 1. Handle Form Submission (Generate Payslip)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payslip'])) {
    verify_csrf();
    $employee_id = intval($_POST['employee_id']);
    $month_year = $_POST['month_year']; // Format: YYYY-MM
    $basic_salary = floatval($_POST['basic_salary']);
    $allowances = floatval($_POST['allowances'] ?? 0);
    $deductions = floatval($_POST['deductions'] ?? 0);
    $status = $_POST['status'];

    // Calculate net payable server-side for security
    $net_payable = $basic_salary + $allowances - $deductions;
    $payment_date = ($status === 'paid') ? date('Y-m-d') : null;

    if (empty($employee_id) || empty($month_year)) {
        $error_msg = "Employee and Month/Year are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO payslips (employee_id, month_year, basic_salary, allowances, deductions, net_payable, payment_date, status) 
                                   VALUES (:emp_id, :my, :basic, :allowances, :deductions, :net, :p_date, :status)");
            $stmt->execute([
                'emp_id' => $employee_id,
                'my' => $month_year,
                'basic' => $basic_salary,
                'allowances' => $allowances,
                'deductions' => $deductions,
                'net' => $net_payable,
                'p_date' => $payment_date,
                'status' => $status
            ]);

            header("Location: payslip.php?status=success");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Error generating payslip: " . $e->getMessage();
        }
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "Payslip generated successfully!";
}

// 2. Fetch Data for the Interface
try {
    // Fetch active employees for the dropdown
    $stmt_emp = $pdo->query("SELECT id, first_name, last_name, base_salary FROM employees WHERE status = 'active' ORDER BY first_name ASC");
    $employees = $stmt_emp->fetchAll();

    // Fetch all payslips with employee names
    $stmt_pay = $pdo->query("SELECT p.*, e.first_name, e.last_name 
                             FROM payslips p 
                             JOIN employees e ON p.employee_id = e.id 
                             ORDER BY p.month_year DESC, p.id DESC");
    $payslips = $stmt_pay->fetchAll();
} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Payroll & Payslips</h2>
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

            <!-- Left Column: Generate Form -->
            <div class="xl:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Generate Payslip</h3>

                    <form action="payslip.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Employee *</label>
                            <select name="employee_id" id="employeeSelect" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="" data-salary="0">-- Choose Active Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" data-salary="<?= $emp['base_salary'] ?>" <?= (int)($_GET['employee_id'] ?? 0) === (int)$emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Salary Month *</label>
                            <input type="month" name="month_year" value="<?= date('Y-m') ?>" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Basic Salary ($) *</label>
                            <input type="number" name="basic_salary" id="basicSalary" step="0.01" value="0.00" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-gray-50">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Allowances (+)</label>
                                <input type="number" name="allowances" id="allowances" step="0.01" value="0.00"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Deductions (-)</label>
                                <input type="number" name="deductions" id="deductions" step="0.01" value="0.00"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Net Payable Amount</label>
                            <div class="text-2xl font-bold text-blue-600 mb-4" id="netPayableDisplay">$0.00</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status *</label>
                            <select name="status"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="unpaid">Unpaid</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>

                        <button type="submit" name="generate_payslip"
                            class="w-full bg-blue-600 text-white font-semibold py-2 rounded-lg hover:bg-blue-700 transition-colors mt-2">
                            Save Payslip
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Payslip History -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Payroll Records</h3>
                    </div>

                    <div class="overflow-x-auto max-h-[700px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Month</th>
                                    <th class="px-6 py-3 font-medium">Employee</th>
                                    <th class="px-6 py-3 font-medium">Net Salary</th>
                                    <th class="px-6 py-3 font-medium">Status</th>
                                    <th class="px-6 py-3 font-medium text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($payslips) > 0): ?>
                                    <?php foreach ($payslips as $slip): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 text-sm font-medium text-gray-800">
                                                <?= date('F Y', strtotime($slip['month_year'] . '-01')) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-700">
                                                <?= htmlspecialchars($slip['first_name'] . ' ' . $slip['last_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
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
                                            <td class="px-6 py-4 text-sm text-center">
                                                <a href="view_payslip.php?id=<?= $slip['id'] ?>" class="text-blue-500 hover:text-blue-700 font-medium">View / Print</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No payslips generated yet.
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

<script>
    // Live calculation for the Payslip Form
    document.addEventListener('DOMContentLoaded', function () {
        const empSelect = document.getElementById('employeeSelect');
        const basicSalary = document.getElementById('basicSalary');
        const allowances = document.getElementById('allowances');
        const deductions = document.getElementById('deductions');
        const display = document.getElementById('netPayableDisplay');

        function calculateNet() {
            const base = parseFloat(basicSalary.value) || 0;
            const allow = parseFloat(allowances.value) || 0;
            const deduct = parseFloat(deductions.value) || 0;

            const net = base + allow - deduct;
            display.innerText = '$' + net.toFixed(2);
        }

        // Auto-fill salary when employee changes
        empSelect.addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            basicSalary.value = selectedOption.getAttribute('data-salary');
            calculateNet();
        });

        // Recalculate if user types in the boxes
        basicSalary.addEventListener('input', calculateNet);
        allowances.addEventListener('input', calculateNet);
        deductions.addEventListener('input', calculateNet);
        if (empSelect.value) {
            basicSalary.value = empSelect.options[empSelect.selectedIndex].getAttribute('data-salary');
            calculateNet();
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>
