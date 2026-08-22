<?php
// admin/contacts.php
require_once '../config/db.php';

$success_msg = '';
$error_msg = '';

// 1. Process Form Submission First
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_contact'])) {
    verify_csrf();
    $contact_type = $_POST['contact_type'];
    $company_name = trim($_POST['company_name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $billing_address = trim($_POST['billing_address']);
    $tax_id_number = trim($_POST['tax_id_number']);
    $currency_code = $_POST['currency_code'];

    if (empty($company_name) || empty($currency_code)) {
        $error_msg = "Company Name and Currency are strictly required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO contacts (contact_type, company_name, contact_person, email, phone, billing_address, tax_id_number, currency_code) 
                                   VALUES (:type, :company, :person, :email, :phone, :address, :tax_id, :currency)");
            $stmt->execute([
                'type' => $contact_type,
                'company' => $company_name,
                'person' => $contact_person,
                'email' => $email,
                'phone' => $phone,
                'address' => $billing_address,
                'tax_id' => $tax_id_number,
                'currency' => $currency_code
            ]);

            header("Location: contacts.php?status=success");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Database Error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $success_msg = "Contact added successfully!";
}

// 2. Fetch Data for Dropdowns and the Table
try {
    // Get Currencies
    $stmt_curr = $pdo->query("SELECT code, name, symbol FROM currencies ORDER BY is_base_currency DESC, name ASC");
    $currencies = $stmt_curr->fetchAll();

    // Get all Contacts
    $stmt_contacts = $pdo->query("
        SELECT c.*, curr.symbol 
        FROM contacts c
        LEFT JOIN currencies curr ON c.currency_code = curr.code
        ORDER BY c.company_name ASC
    ");
    $contacts = $stmt_contacts->fetchAll();

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// 3. Load UI Layout
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 bg-gray-100 flex flex-col h-screen overflow-hidden">

    <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
        <h2 class="text-2xl font-semibold text-gray-800">Customers & Vendors Directory</h2>
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

            <!-- Left Column: Add Contact Form -->
            <div class="xl:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="text-lg font-medium text-gray-800 mb-4 border-b pb-2">Add New Entity</h3>

                    <form action="contacts.php" method="POST" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Entity Type *</label>
                            <select name="contact_type" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                <option value="customer">Customer (Client)</option>
                                <option value="vendor">Vendor (Supplier)</option>
                                <option value="both">Both</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Company / Display Name *</label>
                            <input type="text" name="company_name" required placeholder="e.g. Acme Corp"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Primary Contact Person</label>
                            <input type="text" name="contact_person" placeholder="John Doe"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" name="email"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="phone"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID / VAT No.</label>
                                <input type="text" name="tax_id_number"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Currency *</label>
                                <select name="currency_code" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                    <?php foreach ($currencies as $curr): ?>
                                        <option value="<?= $curr['code'] ?>"><?= $curr['code'] ?> -
                                            <?= htmlspecialchars($curr['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Billing Address</label>
                            <textarea name="billing_address" rows="2"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                        </div>

                        <button type="submit" name="add_contact"
                            class="w-full bg-blue-600 text-white font-semibold py-2 rounded-lg hover:bg-blue-700 transition-colors mt-2">
                            Save Contact
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Contacts Directory -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-700">Entity Directory</h3>
                        <span class="text-sm text-gray-500">Total: <?= count($contacts) ?></span>
                    </div>

                    <div class="overflow-x-auto max-h-[700px]">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 bg-white shadow-sm">
                                <tr class="text-gray-500 text-sm border-b border-gray-200">
                                    <th class="px-6 py-3 font-medium">Company / Name</th>
                                    <th class="px-6 py-3 font-medium">Type</th>
                                    <th class="px-6 py-3 font-medium">Contact Details</th>
                                    <th class="px-6 py-3 font-medium text-center">Currency</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($contacts) > 0): ?>
                                    <?php foreach ($contacts as $contact): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-bold text-gray-900">
                                                    <?= htmlspecialchars($contact['company_name']) ?></p>
                                                <?php if ($contact['contact_person']): ?>
                                                    <p class="text-xs text-gray-500">Attn:
                                                        <?= htmlspecialchars($contact['contact_person']) ?></p>
                                                <?php endif; ?>
                                                <?php if ($contact['tax_id_number']): ?>
                                                    <p class="text-xs text-gray-400 mt-1">Tax ID:
                                                        <?= htmlspecialchars($contact['tax_id_number']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($contact['contact_type'] === 'customer'): ?>
                                                    <span
                                                        class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Customer</span>
                                                <?php elseif ($contact['contact_type'] === 'vendor'): ?>
                                                    <span
                                                        class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">Vendor</span>
                                                <?php else: ?>
                                                    <span
                                                        class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Both</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">
                                                <div><?= htmlspecialchars($contact['email'] ?: 'No email') ?></div>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?= htmlspecialchars($contact['phone'] ?: 'No phone') ?></div>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-medium text-gray-800 text-center">
                                                <?= htmlspecialchars($contact['currency_code']) ?>
                                                <span class="text-gray-400">(<?= htmlspecialchars($contact['symbol']) ?>)</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-12 text-center text-gray-500 text-sm">
                                            No contacts found. Add your first customer or vendor on the left.
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
