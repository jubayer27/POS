<?php
// admin/employee.php
require_once '../config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$success_msg = '';
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "Employee added successfully!";
}

try {
    $stmt = $pdo->query("SELECT * FROM employees ORDER BY joined_date DESC");
    $employees = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching employees: " . $e->getMessage());
}
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Employee Directory</h2>
        <a href="add_employee.php"
            class="bg-blue-600 text-white font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Add Employee
        </a>
    </header>

    <div class="flex-1 overflow-y-auto p-8">

        <?php if ($success_msg): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
                <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                <h3 class="text-lg font-medium text-gray-700">Staff Members</h3>
                <span class="text-sm text-gray-500">Total:
                    <?= count($employees) ?>
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-white text-gray-500 text-sm border-b border-gray-200">
                            <th class="px-6 py-4 font-medium">Name</th>
                            <th class="px-6 py-4 font-medium">Designation</th>
                            <th class="px-6 py-4 font-medium">Contact</th>
                            <th class="px-6 py-4 font-medium">Base Salary</th>
                            <th class="px-6 py-4 font-medium">Status</th>
                            <th class="px-6 py-4 font-medium text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (count($employees) > 0): ?>
                            <?php foreach ($employees as $emp): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <p class="text-sm font-semibold text-gray-900">
                                            <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                        </p>
                                        <p class="text-xs text-gray-500">Joined:
                                            <?= date('M d, Y', strtotime($emp['joined_date'])) ?>
                                        </p>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <?= htmlspecialchars($emp['designation']) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="text-sm text-gray-700">
                                    
                                            <?= htmlspecialchars($emp['email'] ?: 'N/A') ?>
                                        </p>

                                                                            <p class="text-xs text-gray-500">
                                            <?= htmlspecialchars($emp['phone'] ?: 'N/A') ?>
                                        </p>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-800">
                                        $
                                        <?= number_format($emp['base_salary'], 2) ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                        <?php if ($emp['status'] === 'active'): ?>
                                            <span
                                                class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-green-100 text-green-800">Active</span>
                                        <?php else: ?>
                                                <span
                                                    class="px-3 py-1 inline-flex rounded-full text-xs font-semibold bg-red-100 text-red-800">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-center">
                                        <a href="view_employee.php?id=<?= $emp['id'] ?>"
                                                class="text-blue-500 hover:text-blue-700 font-medium">Profile</a>
                                            </td>
                                    </tr>
                            <?php endforeach; ?>
                    <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500 text-sm">
                                        No employees found. Click "Add Employee" to register staff.
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