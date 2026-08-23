<?php
// admin/employee.php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$status = $_GET['status'] ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = [];

if (in_array($status, ['active', 'on_leave', 'terminated'], true)) {
    $where[] = 'e.status = ?';
    $params[] = $status;
}

if ($q !== '') {
    $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.designation LIKE ?)';
    $searchTerm = '%' . $q . '%';
    $params = array_merge($params, array_fill(0, 4, $searchTerm));
}

// Fixed query: Ordering by e.hire_date and e.id
$sql = "SELECT e.*, 
               (SELECT COUNT(*) FROM payslips p WHERE p.employee_id = e.id) AS payslips_count 
        FROM employees e"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY e.hire_date DESC, e.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Aggregated HR KPI Metrics
$kpis = $pdo->query("
    SELECT 
        COUNT(*) AS total_staff,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_staff,
        COALESCE(SUM(CASE WHEN status = 'active' THEN base_salary ELSE 0 END), 0) AS monthly_payroll,
        COALESCE(SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END), 0) AS on_leave
    FROM employees
")->fetch();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    <header class="bg-white border-b px-5 md:px-8 py-5 flex justify-between items-center sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Employees & HR Directory</h2>
                <p class="text-xs text-slate-500">Manage personnel, payroll profiles, and employment statuses</p>
            </div>
        </div>
        <a href="add_employee.php"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Add Employee
        </a>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto space-y-6">

        <!-- Summary Cards -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Staff</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= (int) $kpis['total_staff'] ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Headcount Recorded</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Staff</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= (int) $kpis['active_staff'] ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Currently on Payroll</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Monthly Payroll Commitment</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= money($pdo, $kpis['monthly_payroll']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Base Salary Total</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">On Leave</p>
                <p class="text-2xl font-black text-amber-600 mt-1"><?= (int) $kpis['on_leave'] ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Away on approved leave</p>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
            <form method="get" class="p-4 border-b flex flex-wrap md:flex-nowrap gap-3 bg-slate-50/70">
                <input name="q" value="<?= h($q) ?>" placeholder="Search by name, designation, or email..."
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm flex-1 outline-none bg-white focus:ring-2 focus:ring-blue-500">
                <select name="status"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white outline-none">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="on_leave" <?= $status === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="terminated" <?= $status === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                </select>
                <button type="submit"
                    class="bg-slate-900 hover:bg-slate-800 text-white rounded-lg px-5 py-2 text-sm font-semibold transition-colors">
                    Filter
                </button>
            </form>

            <!-- Table View -->
            <div class="overflow-x-auto max-h-[720px]">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-slate-50 text-slate-600 text-xs border-b sticky top-0">
                        <tr>
                            <th class="p-4 font-semibold">Employee</th>
                            <th class="p-4 font-semibold">Designation</th>
                            <th class="p-4 font-semibold">Hire Date</th>
                            <th class="p-4 font-semibold text-right">Base Salary</th>
                            <th class="p-4 font-semibold text-center">Status</th>
                            <th class="p-4 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($employees as $emp): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="p-4">
                                    <a href="view_employee.php?id=<?= $emp['id'] ?>"
                                        class="font-bold text-blue-600 hover:underline">
                                        <?= h($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                    </a>
                                    <p class="text-xs text-slate-400 mt-0.5"><?= h($emp['email'] ?: 'No email assigned') ?>
                                    </p>
                                </td>

                                <td class="p-4">
                                    <p class="font-semibold text-slate-800"><?= h($emp['designation']) ?></p>
                                </td>

                                <td class="p-4 text-xs text-slate-600">
                                    <?= date('M j, Y', strtotime($emp['hire_date'])) ?>
                                </td>

                                <td class="p-4 text-right font-bold text-slate-900">
                                    <?= money($pdo, $emp['base_salary']) ?>
                                </td>

                                <td class="p-4 text-center">
                                    <?php
                                    $badge = 'bg-slate-100 text-slate-700';
                                    if ($emp['status'] === 'active')
                                        $badge = 'bg-emerald-100 text-emerald-800';
                                    elseif ($emp['status'] === 'on_leave')
                                        $badge = 'bg-amber-100 text-amber-800';
                                    elseif ($emp['status'] === 'terminated')
                                        $badge = 'bg-rose-100 text-rose-800';
                                    ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge ?>">
                                        <?= h(ucwords(str_replace('_', ' ', $emp['status']))) ?>
                                    </span>
                                </td>

                                <td class="p-4 text-right space-x-2">
                                    <a href="view_employee.php?id=<?= $emp['id'] ?>"
                                        class="text-slate-600 hover:text-slate-900 font-semibold text-xs">View</a>
                                    <a href="edit_employee.php?id=<?= $emp['id'] ?>"
                                        class="text-blue-600 hover:text-blue-800 font-semibold text-xs">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$employees): ?>
                            <tr>
                                <td colspan="6" class="p-12 text-center text-slate-500 text-sm">
                                    No employee records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<?php require_once 'includes/footer.php'; ?>