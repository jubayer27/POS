<?php
// admin/employee_form.php (or include inside add_employee.php / edit_employee.php)
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$editingId = $editingId ?? (int) ($_GET['id'] ?? 0);
$error_msg = '';
$employee = [];

$fields = [
    'employee_number',
    'first_name',
    'last_name',
    'email',
    'personal_email',
    'phone',
    'alternate_phone',
    'date_of_birth',
    'gender',
    'marital_status',
    'address',
    'city',
    'state',
    'postal_code',
    'country',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
    'designation',
    'department',
    'employment_type',
    'work_location',
    'manager_name',
    'base_salary',
    'hire_date',
    'probation_end_date',
    'end_date',
    'bank_name',
    'bank_account_name',
    'bank_account_number',
    'bank_routing_number',
    'tax_id',
    'national_id',
    'notes',
    'status'
];

if ($editingId) {
    $s = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $s->execute([$editingId]);
    $employee = $s->fetch();
    if (!$employee) {
        http_response_code(404);
        exit('Employee not found.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($fields as $field) {
        $employee[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    try {
        if ($employee['first_name'] === '' || $employee['last_name'] === '' || $employee['designation'] === '' || $employee['hire_date'] === '') {
            throw new RuntimeException('First name, last name, designation, and hire date are strictly required.');
        }

        if ((float) $employee['base_salary'] < 0) {
            throw new RuntimeException('Base salary cannot be negative.');
        }

        if ($employee['email'] !== '' && !filter_var($employee['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid work email address.');
        }

        if ($employee['personal_email'] !== '' && !filter_var($employee['personal_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid personal email address.');
        }

        // Convert empty strings to NULL for nullable DB columns
        $nullable = [
            'employee_number',
            'email',
            'personal_email',
            'phone',
            'alternate_phone',
            'date_of_birth',
            'gender',
            'marital_status',
            'address',
            'city',
            'state',
            'postal_code',
            'country',
            'emergency_contact_name',
            'emergency_contact_relationship',
            'emergency_contact_phone',
            'department',
            'work_location',
            'manager_name',
            'probation_end_date',
            'end_date',
            'bank_name',
            'bank_account_name',
            'bank_account_number',
            'bank_routing_number',
            'tax_id',
            'national_id',
            'notes'
        ];

        foreach ($nullable as $f) {
            if ($employee[$f] === '') {
                $employee[$f] = null;
            }
        }

        $values = array_map(fn($f) => $employee[$f], $fields);

        if ($editingId) {
            $assign = implode(', ', array_map(fn($f) => "$f = ?", $fields));
            $stmt = $pdo->prepare("UPDATE employees SET $assign WHERE id = ?");
            $stmt->execute([...$values, $editingId]);
            header('Location: view_employee.php?id=' . $editingId . '&updated=1');
            exit;
        } else {
            $columns = implode(', ', $fields);
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO employees ($columns) VALUES ($marks)");
            $stmt->execute($values);
            $newId = (int) $pdo->lastInsertId();
            header('Location: view_employee.php?id=' . $newId . '&created=1');
            exit;
        }

    } catch (Throwable $e) {
        $error_msg = ($e instanceof PDOException && $e->getCode() === '23000')
            ? 'That employee number or work email already exists in the system.'
            : $e->getMessage();
    }
}

function fv(array $e, string $key, string $default = ''): string
{
    return htmlspecialchars((string) ($e[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
$input = 'mt-1 w-full border border-slate-300 rounded-lg px-3 py-2.5 bg-white text-sm outline-none focus:ring-2 focus:ring-blue-500 transition-shadow';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">

    <!-- Top Action Bar -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <a href="<?= $editingId ? 'view_employee.php?id=' . $editingId : 'employee.php' ?>"
                class="text-slate-400 hover:text-slate-700 font-bold text-lg">←</a>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">
                    <?= $editingId ? 'Edit Employee Profile' : 'Add New Employee' ?></h2>
                <p class="text-xs text-slate-500">Maintain HR records, compensation data, and banking credentials</p>
            </div>
        </div>
    </header>

    <div class="p-5 md:p-8 max-w-6xl mx-auto space-y-6">

        <?php if ($error_msg): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl shadow-sm text-sm">
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $editingId ? 'edit_employee.php?id=' . $editingId : 'add_employee.php' ?>"
            class="space-y-6">
            <?= csrf_field() ?>

            <!-- 1. Identity & Personal Details -->
            <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                <div class="border-b pb-3">
                    <h3 class="font-bold text-base text-slate-900">Personal & Identity Details</h3>
                    <p class="text-xs text-slate-500">Government identity, demographics, and primary contact information
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <label class="block text-xs font-bold uppercase text-slate-600">Employee ID / Number
                        <input name="employee_number" value="<?= fv($employee, 'employee_number') ?>"
                            placeholder="e.g. EMP-0010" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">First Name *
                        <input name="first_name" value="<?= fv($employee, 'first_name') ?>" required placeholder="John"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Last Name *
                        <input name="last_name" value="<?= fv($employee, 'last_name') ?>" required placeholder="Doe"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Work Email
                        <input type="email" name="email" value="<?= fv($employee, 'email') ?>"
                            placeholder="john.doe@company.test" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Personal Email
                        <input type="email" name="personal_email" value="<?= fv($employee, 'personal_email') ?>"
                            placeholder="john.personal@gmail.com" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Primary Phone
                        <input name="phone" value="<?= fv($employee, 'phone') ?>" placeholder="+1 555-0199"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Alternate Phone
                        <input name="alternate_phone" value="<?= fv($employee, 'alternate_phone') ?>"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Date of Birth
                        <input type="date" name="date_of_birth" value="<?= fv($employee, 'date_of_birth') ?>"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Gender
                        <select name="gender" class="<?= $input ?>">
                            <option value="">Prefer not to say</option>
                            <?php foreach (['Female', 'Male', 'Non-binary', 'Other'] as $v): ?>
                                <option value="<?= $v ?>" <?= ($employee['gender'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Marital Status
                        <select name="marital_status" class="<?= $input ?>">
                            <option value="">Not specified</option>
                            <?php foreach (['Single', 'Married', 'Divorced', 'Widowed'] as $v): ?>
                                <option value="<?= $v ?>" <?= ($employee['marital_status'] ?? '') === $v ? 'selected' : '' ?>>
                                    <?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">National ID / Passport #
                        <input name="national_id" value="<?= fv($employee, 'national_id') ?>" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Tax ID / SSN
                        <input name="tax_id" value="<?= fv($employee, 'tax_id') ?>" class="<?= $input ?>">
                    </label>

                    <label
                        class="block text-xs font-bold uppercase text-slate-600 md:col-span-2 lg:col-span-3">Residential
                        Address
                        <textarea name="address" rows="2" placeholder="Street address, apartment/suite number..."
                            class="<?= $input ?>"><?= fv($employee, 'address') ?></textarea>
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">City
                        <input name="city" value="<?= fv($employee, 'city') ?>" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">State / Province
                        <input name="state" value="<?= fv($employee, 'state') ?>" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Postal / ZIP Code
                        <input name="postal_code" value="<?= fv($employee, 'postal_code') ?>" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Country
                        <input name="country" value="<?= fv($employee, 'country') ?>" class="<?= $input ?>">
                    </label>
                </div>
            </section>

            <!-- 2. Employment & Compensation -->
            <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                <div class="border-b pb-3">
                    <h3 class="font-bold text-base text-slate-900">Employment & Payroll Parameters</h3>
                    <p class="text-xs text-slate-500">Designation, department allocation, and monthly base remuneration
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <label class="block text-xs font-bold uppercase text-slate-600">Designation / Role *
                        <input name="designation" value="<?= fv($employee, 'designation') ?>" required
                            placeholder="e.g. Senior Software Engineer" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Department
                        <input name="department" value="<?= fv($employee, 'department') ?>"
                            placeholder="e.g. Engineering" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Employment Type
                        <select name="employment_type" class="<?= $input ?>">
                            <?php foreach (['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contractor', 'intern' => 'Intern', 'temporary' => 'Temporary'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($employee['employment_type'] ?? 'full_time') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Work Location
                        <input name="work_location" value="<?= fv($employee, 'work_location') ?>"
                            placeholder="e.g. Headquarters / Remote" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Reporting Manager
                        <input name="manager_name" value="<?= fv($employee, 'manager_name') ?>" class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Base Salary
                        (<?= h(setting($pdo, 'base_currency', 'USD')) ?>) *
                        <input type="number" step="0.01" min="0" name="base_salary"
                            value="<?= fv($employee, 'base_salary', '0.00') ?>" required class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Hire / Joined Date *
                        <input type="date" name="hire_date" value="<?= fv($employee, 'hire_date', date('Y-m-d')) ?>"
                            required class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Probation End Date
                        <input type="date" name="probation_end_date" value="<?= fv($employee, 'probation_end_date') ?>"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Employment End Date
                        <input type="date" name="end_date" value="<?= fv($employee, 'end_date') ?>"
                            class="<?= $input ?>">
                    </label>

                    <label class="block text-xs font-bold uppercase text-slate-600">Employment Status
                        <select name="status" class="<?= $input ?>">
                            <?php foreach (['active' => 'Active', 'on_leave' => 'On Leave', 'terminated' => 'Terminated'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($employee['status'] ?? 'active') === $k ? 'selected' : '' ?>>
                                    <?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </section>

            <!-- 3. Emergency Contacts & Bank Accounts -->
            <div class="grid lg:grid-cols-2 gap-6">

                <!-- Emergency Contact -->
                <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                    <div class="border-b pb-3">
                        <h3 class="font-bold text-base text-slate-900">Emergency Contact</h3>
                        <p class="text-xs text-slate-500">Point of contact in case of medical or workplace emergency</p>
                    </div>

                    <div class="space-y-3">
                        <label class="block text-xs font-bold uppercase text-slate-600">Contact Person Name
                            <input name="emergency_contact_name" value="<?= fv($employee, 'emergency_contact_name') ?>"
                                class="<?= $input ?>">
                        </label>

                        <label class="block text-xs font-bold uppercase text-slate-600">Relationship
                            <input name="emergency_contact_relationship"
                                value="<?= fv($employee, 'emergency_contact_relationship') ?>"
                                placeholder="e.g. Spouse, Parent, Sibling" class="<?= $input ?>">
                        </label>

                        <label class="block text-xs font-bold uppercase text-slate-600">Emergency Phone
                            <input name="emergency_contact_phone"
                                value="<?= fv($employee, 'emergency_contact_phone') ?>" class="<?= $input ?>">
                        </label>
                    </div>
                </section>

                <!-- Banking Remittance -->
                <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                    <div class="border-b pb-3">
                        <h3 class="font-bold text-base text-slate-900">Salary Disbursement Details</h3>
                        <p class="text-xs text-slate-500">Direct deposit banking credentials for payroll runs</p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <label class="block text-xs font-bold uppercase text-slate-600">Bank Name
                            <input name="bank_name" value="<?= fv($employee, 'bank_name') ?>"
                                placeholder="e.g. Standard Chartered" class="<?= $input ?>">
                        </label>

                        <label class="block text-xs font-bold uppercase text-slate-600">Account Holder Name
                            <input name="bank_account_name" value="<?= fv($employee, 'bank_account_name') ?>"
                                class="<?= $input ?>">
                        </label>

                        <label class="block text-xs font-bold uppercase text-slate-600">Account / IBAN Number
                            <input name="bank_account_number" value="<?= fv($employee, 'bank_account_number') ?>"
                                class="<?= $input ?>">
                        </label>

                        <label class="block text-xs font-bold uppercase text-slate-600">Routing / SWIFT Code
                            <input name="bank_routing_number" value="<?= fv($employee, 'bank_routing_number') ?>"
                                class="<?= $input ?>">
                        </label>
                    </div>
                </section>

            </div>

            <!-- 4. Internal Notes -->
            <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Internal HR & Performance Notes
                    <textarea name="notes" rows="3"
                        placeholder="Add confidential internal notes, performance remarks, or equipment assignments..."
                        class="<?= $input ?>"><?= fv($employee, 'notes') ?></textarea>
                </label>
            </section>

            <!-- Submit Button Bar -->
            <div class="flex justify-end items-center gap-3 pt-2">
                <a href="<?= $editingId ? 'view_employee.php?id=' . $editingId : 'employee.php' ?>"
                    class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-5 py-2.5 rounded-lg font-semibold text-sm transition-colors">
                    Cancel
                </a>
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-7 py-2.5 rounded-lg font-semibold text-sm transition-colors shadow-sm">
                    <?= $editingId ? 'Update Employee Profile' : 'Save Employee Record' ?>
                </button>
            </div>

        </form>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>