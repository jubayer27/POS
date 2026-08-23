<?php
// admin/contacts.php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Helper formatting & escaping
if (!function_exists('h')) {
    function h($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // 1. Save or Update Custom Contact Type
        if (isset($_POST['save_type'])) {
            $name = trim((string) $_POST['type_name']);
            $kind = in_array($_POST['base_kind'], ['customer', 'vendor', 'both'], true) ? $_POST['base_kind'] : 'customer';
            $typeId = (int) ($_POST['type_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Type name is required.');
            }

            if ($typeId) {
                $stmt = $pdo->prepare('UPDATE contact_types SET name = ?, base_kind = ? WHERE id = ?');
                $stmt->execute([$name, $kind, $typeId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO contact_types (name, base_kind) VALUES (?, ?)');
                $stmt->execute([$name, $kind]);
            }
            header('Location: contacts.php?saved=type');
            exit;
        }

        // 2. Delete Contact Type
        if (isset($_POST['delete_type'])) {
            $typeId = (int) $_POST['type_id'];
            $used = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE contact_type_id = ?');
            $used->execute([$typeId]);
            if ($used->fetchColumn()) {
                throw new RuntimeException('This type is currently assigned to contacts. Reassign them before deleting.');
            }
            $pdo->prepare('DELETE FROM contact_types WHERE id = ?')->execute([$typeId]);
            header('Location: contacts.php?saved=type');
            exit;
        }

        // 3. Save or Update Contact
        if (isset($_POST['save_contact'])) {
            $id = (int) ($_POST['contact_id'] ?? 0);
            $typeId = (int) $_POST['contact_type_id'];

            $s = $pdo->prepare('SELECT base_kind FROM contact_types WHERE id = ? AND is_active = 1');
            $s->execute([$typeId]);
            $kind = $s->fetchColumn();

            if (!$kind) {
                throw new RuntimeException('Please choose a valid contact type.');
            }

            $company_name = trim((string) $_POST['company_name']);
            $contact_person = trim((string) $_POST['contact_person']);
            $email = trim((string) $_POST['email']);
            $phone = trim((string) $_POST['phone']);
            $billing_address = trim((string) $_POST['billing_address']);
            $tax_id_number = trim((string) $_POST['tax_id_number']);
            $currency_code = $_POST['currency_code'] ?? 'USD';

            if ($company_name === '') {
                throw new RuntimeException('Company / Display name is required.');
            }

            $values = [
                $kind,
                $typeId,
                $company_name,
                $contact_person,
                $email,
                $phone,
                $billing_address,
                $tax_id_number,
                $currency_code
            ];

            if ($id) {
                $stmt = $pdo->prepare('UPDATE contacts SET contact_type = ?, contact_type_id = ?, company_name = ?, contact_person = ?, email = ?, phone = ?, billing_address = ?, tax_id_number = ?, currency_code = ? WHERE id = ?');
                $stmt->execute([...$values, $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO contacts (contact_type, contact_type_id, company_name, contact_person, email, phone, billing_address, tax_id_number, currency_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute($values);
            }
            header('Location: contacts.php?saved=contact');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = ($e instanceof PDOException && $e->getCode() === '23000') ? 'That type name or contact entry already exists.' : $e->getMessage();
}

// Data Fetching for Views
$editId = (int) ($_GET['edit'] ?? 0);
$edit = [];
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $s->execute([$editId]);
    $edit = $s->fetch() ?: [];
}

$types = $pdo->query('SELECT * FROM contact_types WHERE is_active = 1 ORDER BY base_kind, name')->fetchAll();
$currencies = $pdo->query('SELECT code, name, symbol FROM currencies ORDER BY is_base_currency DESC, name ASC')->fetchAll();

// Search & Type Filtering
$filterType = (int) ($_GET['type'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];

if ($filterType) {
    $where[] = 'c.contact_type_id = ?';
    $params[] = $filterType;
}
if ($q !== '') {
    $where[] = '(c.company_name LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql = 'SELECT c.*, COALESCE(t.name, CONCAT(UCASE(LEFT(c.contact_type, 1)), SUBSTRING(c.contact_type, 2))) AS type_name, t.base_kind, cur.symbol 
        FROM contacts c 
        LEFT JOIN contact_types t ON t.id = c.contact_type_id 
        LEFT JOIN currencies cur ON cur.code = c.currency_code'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY c.company_name ASC';

$stmt_contacts = $pdo->prepare($sql);
$stmt_contacts->execute($params);
$contacts = $stmt_contacts->fetchAll();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
$input = 'mt-1 w-full border border-gray-300 rounded-lg px-3 py-2.5 outline-none focus:ring-2 focus:ring-blue-500';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-gray-100">
    
    <!-- Top Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Customers & Vendors</h2>
            <p class="text-sm text-gray-500">Create custom contact types and manage customer/supplier records</p>
        </div>
        <span class="text-xs font-semibold bg-gray-100 text-gray-700 px-3 py-1 rounded-full border">
            Total Contacts: <?= count($contacts) ?>
        </span>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto">
        
        <!-- Alerts -->
        <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl mb-5 shadow-sm">
                    <?= h($error) ?>
                </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['saved'])): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-5 shadow-sm">
                    Changes saved successfully.
                </div>
        <?php endif; ?>

        <div class="grid xl:grid-cols-3 gap-7">
            
            <!-- Left Column: Form & Type Manager -->
            <div class="space-y-6">
                
                <!-- Contact Form -->
                <form method="post" class="bg-white border rounded-2xl p-6 space-y-4 shadow-sm">
                    <input type="hidden" name="contact_id" value="<?= h((string) $editId) ?>">
                    <div>
                        <h3 class="font-bold text-lg text-gray-900"><?= $editId ? 'Edit Contact' : 'Add New Contact' ?></h3>
                        <p class="text-xs text-gray-500">Contact types define role behavior in ledger postings.</p>
                    </div>

                    <label class="block text-sm font-medium text-gray-700">Contact Type *
                        <select name="contact_type_id" required class="<?= $input ?> bg-white">
                            <?php foreach ($types as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= ($edit['contact_type_id'] ?? 0) == $t['id'] ? 'selected' : '' ?>>
                                        <?= h($t['name']) ?> (<?= h(ucfirst($t['base_kind'])) ?>)
                                    </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block text-sm font-medium text-gray-700">Company / Display Name *
                        <input name="company_name" required value="<?= h($edit['company_name'] ?? '') ?>" placeholder="e.g. Stark Industries" class="<?= $input ?>">
                    </label>

                    <label class="block text-sm font-medium text-gray-700">Primary Contact Person
                        <input name="contact_person" value="<?= h($edit['contact_person'] ?? '') ?>" placeholder="e.g. Pepper Potts" class="<?= $input ?>">
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm font-medium text-gray-700">Email
                            <input type="email" name="email" value="<?= h($edit['email'] ?? '') ?>" class="<?= $input ?>">
                        </label>
                        <label class="text-sm font-medium text-gray-700">Phone
                            <input name="phone" value="<?= h($edit['phone'] ?? '') ?>" class="<?= $input ?>">
                        </label>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm font-medium text-gray-700">Tax ID / VAT No.
                            <input name="tax_id_number" value="<?= h($edit['tax_id_number'] ?? '') ?>" class="<?= $input ?>">
                        </label>
                        <label class="text-sm font-medium text-gray-700">Currency *
                            <select name="currency_code" class="<?= $input ?> bg-white">
                                <?php foreach ($currencies as $c): ?>
                                        <option value="<?= $c['code'] ?>" <?= ($edit['currency_code'] ?? 'USD') === $c['code'] ? 'selected' : '' ?>>
                                            <?= h($c['code'] . ' - ' . $c['name']) ?>
                                        </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label class="block text-sm font-medium text-gray-700">Billing Address
                        <textarea name="billing_address" rows="2" class="<?= $input ?>"><?= h($edit['billing_address'] ?? '') ?></textarea>
                    </label>

                    <div class="flex gap-2 pt-2">
                        <button type="submit" name="save_contact" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-semibold flex-1 transition-colors">
                            <?= $editId ? 'Update' : 'Save' ?> Contact
                        </button>
                        <?php if ($editId): ?>
                                <a href="contacts.php" class="border border-gray-300 hover:bg-gray-50 px-4 py-2.5 rounded-lg text-gray-700 text-center flex items-center justify-center">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Custom Type Manager -->
                <section class="bg-white border rounded-2xl p-6 shadow-sm">
                    <h3 class="font-bold text-lg text-gray-900">Custom Contact Types</h3>
                    <p class="text-xs text-gray-500 mb-4">Set roles to categorize customers or vendors.</p>
                    
                    <form method="post" class="grid grid-cols-2 gap-2 mb-4">
                        <input name="type_name" placeholder="e.g. Wholesale" required class="border rounded-lg px-3 py-2 text-sm outline-none">
                        <select name="base_kind" class="border rounded-lg px-3 py-2 bg-white text-sm outline-none">
                            <option value="customer">Customer Role</option>
                            <option value="vendor">Vendor Role</option>
                            <option value="both">Both Roles</option>
                        </select>
                        <button type="submit" name="save_type" class="col-span-2 bg-slate-900 hover:bg-slate-800 text-white rounded-lg px-4 py-2 text-sm font-semibold transition-colors">
                            Create Type
                        </button>
                    </form>

                    <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                        <?php foreach ($types as $t): ?>
                                <details class="border rounded-lg p-3 bg-gray-50">
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-800 flex justify-between items-center">
                                        <span><?= h($t['name']) ?></span>
                                        <span class="text-xs text-gray-400 font-normal"><?= h($t['base_kind']) ?></span>
                                    </summary>
                                    <form method="post" class="grid grid-cols-2 gap-2 mt-3 pt-2 border-t">
                                        <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                                        <input name="type_name" value="<?= h($t['name']) ?>" class="border rounded px-2 py-1 text-sm bg-white">
                                        <select name="base_kind" class="border rounded px-2 py-1 text-sm bg-white">
                                            <?php foreach (['customer', 'vendor', 'both'] as $k): ?>
                                                    <option value="<?= $k ?>" <?= $t['base_kind'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="save_type" class="bg-slate-900 text-white rounded py-1 text-xs font-medium">Update</button>
                                        <button type="submit" name="delete_type" onclick="return confirm('Delete this unused type?')" class="border border-rose-200 text-rose-700 hover:bg-rose-50 rounded py-1 text-xs">Delete</button>
                                    </form>
                                </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <!-- Right Column: Contacts Table & Filtering -->
            <section class="xl:col-span-2 bg-white border rounded-2xl overflow-hidden h-fit shadow-sm">
                
                <!-- Search & Filter Bar -->
                <form method="get" class="p-4 border-b flex flex-wrap md:flex-nowrap gap-3 bg-gray-50">
                    <input name="q" value="<?= h($q) ?>" placeholder="Search contacts by name, person, or email..." class="border rounded-lg px-3 py-2 flex-1 outline-none bg-white text-sm">
                    <select name="type" class="border rounded-lg px-3 py-2 bg-white text-sm outline-none">
                        <option value="">All Types</option>
                        <?php foreach ($types as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $filterType === $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2 rounded-lg text-sm font-semibold transition-colors">
                        Filter
                    </button>
                </form>

                <div class="overflow-x-auto max-h-[720px]">
                    <table class="w-full text-sm text-left border-collapse">
                        <thead class="bg-gray-100 text-gray-600 sticky top-0 shadow-sm border-b">
                            <tr>
                                <th class="p-4 font-semibold">Company / Name</th>
                                <th class="p-4 font-semibold">Type</th>
                                <th class="p-4 font-semibold">Contact Info</th>
                                <th class="p-4 font-semibold text-center">Currency</th>
                                <th class="p-4 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($contacts) > 0): ?>
                                    <?php foreach ($contacts as $c): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="p-4">
                                                    <p class="font-bold text-gray-900"><?= h($c['company_name']) ?></p>
                                                    <p class="text-xs text-gray-500"><?= h($c['contact_person'] ?: 'No primary person') ?></p>
                                                    <?php if ($c['tax_id_number']): ?>
                                                            <p class="text-xs text-gray-400 mt-0.5">Tax ID: <?= h($c['tax_id_number']) ?></p>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4">
                                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold 
                                                <?= $c['base_kind'] === 'customer' ? 'bg-blue-100 text-blue-800' : ($c['base_kind'] === 'vendor' ? 'bg-purple-100 text-purple-800' : 'bg-gray-200 text-gray-800') ?>">
                                                        <?= h($c['type_name']) ?>
                                                    </span>
                                                </td>
                                                <td class="p-4 text-gray-600">
                                                    <div><?= h($c['email'] ?: '—') ?></div>
                                                    <div class="text-xs text-gray-500 mt-0.5"><?= h($c['phone'] ?: '—') ?></div>
                                                </td>
                                                <td class="p-4 text-center font-semibold text-gray-800">
                                                    <?= h($c['currency_code']) ?> <span class="text-gray-400 text-xs">(<?= h($c['symbol'] ?? '$') ?>)</span>
                                                </td>
                                                <td class="p-4 text-right">
                                                    <a href="contacts.php?edit=<?= $c['id'] ?>" class="text-blue-600 hover:text-blue-800 font-semibold text-sm">Edit</a>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="p-10 text-center text-gray-500">
                                            No contacts found matching your criteria.
                                        </td>
                                    </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>