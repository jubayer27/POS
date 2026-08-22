<?php
// admin/add_employee.php
require_once '../config/db.php';

$error_msg = '';

// 1. Process Form Data FIRST (before any HTML is output)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $designation = trim($_POST['designation']);
    $base_salary = floatval($_POST['base_salary']);
    $joined_date = $_POST['joined_date'];
    $status = $_POST['status'];

    if (empty($first_name) || empty($last_name) || empty($designation)) {
        $error_msg = "First Name, Last Name, and Designation are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO employees (first_name, last_name, email, phone, designation, base_salary, status, joined_date) 
                                   VALUES (:fname, :lname, :email, :phone, :designation, :salary, :status, :joined_date)");

            $stmt->execute([
                'fname' => $first_name,
                'lname' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'designation' => $designation,
                'salary' => $base_salary,
                'status' => $status,
                'joined_date' => $joined_date
            ]);

            // The redirect will now work perfectly!
            header("Location: employee.php?status=success");
            exit;
        } catch (PDOException $e) {
            // Handle duplicate email errors gracefully
            if ($e->getCode() == 23000) {
                $error_msg = "An employee with this email already exists.";
            } else {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// 2. NOW it is safe to include the visual layout components
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex items-center gap-4 border-b border-gray-200">
        <a href="employee.php" class="text-gray-500 hover:text-blue-600 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18">
                </path>
            </svg>
        </a>
        <h2 class="text-2xl font-semibold text-gray-800">Register New Employee</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-8">

        <?php if ($error_msg): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm max-w-3xl">
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white p-8 rounded-xl shadow-sm border border-gray-100 max-w-3xl">
            <form action="add_employee.php" method="POST" class="space-y-6">
                <?= csrf_field() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                        <input type="text" name="first_name" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                        <input type="text" name="last_name" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                        <input type="email" name="email"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input type="text" name="phone"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-gray-100">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Designation / Role *</label>
                        <input type="text" name="designation" placeholder="e.g., Software Engineer" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Base Salary ($) *</label>
                        <input type="number" name="base_salary" step="0.01" min="0" value="0.00" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Joined *</label>
                        <input type="date" name="joined_date" value="<?= date('Y-m-d') ?>" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account Status *</label>
                        <select name="status"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 flex justify-end">
                    <button type="submit"
                        class="bg-blue-600 text-white font-semibold px-8 py-3 rounded-lg hover:bg-blue-700 transition-colors shadow-sm">
                        Save Employee Record
                    </button>
                </div>

            </form>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
