<?php
// admin/invoice_templates.php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error = '';
$allowedBlocks = ['company', 'invoice_meta', 'customer', 'items', 'totals', 'bank', 'custom', 'notes'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        // 1. Delete Template
        if (isset($_POST['delete_template'])) {
            $id = (int) $_POST['template_id'];
            $s = $pdo->prepare('SELECT is_default FROM invoice_templates WHERE id = ?');
            $s->execute([$id]);
            if ($s->fetchColumn()) {
                throw new RuntimeException('The default template cannot be deleted. Set another template as default first.');
            }
            $pdo->prepare('DELETE FROM invoice_templates WHERE id = ?')->execute([$id]);
            header('Location: invoice_templates.php?saved=deleted');
            exit;
        }

        // 2. Save / Update Template
        if (isset($_POST['save_template'])) {
            $id = (int) ($_POST['template_id'] ?? 0);
            $name = trim((string) $_POST['name']);
            if ($name === '')
                throw new RuntimeException('Template name is required.');

            $order = json_decode((string) $_POST['block_order'], true);
            $order = array_values(array_filter(is_array($order) ? $order : [], fn($b) => in_array($b, $allowedBlocks, true)));
            if (!in_array('items', $order, true) || !in_array('totals', $order, true)) {
                throw new RuntimeException('Line items and totals blocks are required.');
            }

            $labels = [];
            foreach (['description', 'quantity', 'unit_price', 'tax', 'amount'] as $key) {
                $labels[$key] = trim((string) $_POST['column_' . $key]) ?: ucwords(str_replace('_', ' ', $key));
            }

            $logo = $_POST['existing_logo'] ?? null;
            if (!empty($_FILES['logo']['tmp_name'])) {
                $f = $_FILES['logo'];
                if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2097152) {
                    throw new RuntimeException('Logo must be a valid PNG or JPEG under 2 MB.');
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
                if (!$ext || !getimagesize($f['tmp_name'])) {
                    throw new RuntimeException('Logo must be a valid PNG or JPEG image.');
                }
                $dir = dirname(__DIR__) . '/assets/uploads/invoice-logos';
                if (!is_dir($dir))
                    mkdir($dir, 0755, true);
                $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                    throw new RuntimeException('Logo upload failed.');
                }
                $logo = 'assets/uploads/invoice-logos/' . $filename;
            }

            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            $pdo->beginTransaction();
            if ($isDefault) {
                $pdo->exec('UPDATE invoice_templates SET is_default = 0');
            }

            $values = [
                $name,
                $isDefault,
                $logo,
                $_POST['primary_color'],
                $_POST['accent_color'],
                $_POST['font_family'],
                json_encode($labels),
                json_encode($order),
                trim((string) $_POST['bank_details']),
                trim((string) $_POST['custom_details']),
                trim((string) $_POST['footer_text'])
            ];

            if ($id) {
                $pdo->prepare('UPDATE invoice_templates SET name=?, is_default=?, logo_path=?, primary_color=?, accent_color=?, font_family=?, column_labels=?, block_order=?, bank_details=?, custom_details=?, footer_text=? WHERE id=?')->execute([...$values, $id]);
            } else {
                $pdo->prepare('INSERT INTO invoice_templates(name, is_default, logo_path, primary_color, accent_color, font_family, column_labels, block_order, bank_details, custom_details, footer_text) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
            }

            $pdo->commit();
            header('Location: invoice_templates.php?saved=template');
            exit;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    $error = ($e instanceof PDOException && $e->getCode() === '23000') ? 'Template name must be unique.' : $e->getMessage();
}

// Fetch active bank accounts to populate quick details
$bankAccounts = [];
try {
    $bankAccounts = $pdo->query("SELECT id, account_name, account_type FROM accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
} catch (Exception $e) {
}

$templates = $pdo->query('SELECT * FROM invoice_templates ORDER BY is_default DESC, name ASC')->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$template = null;

if ($editId) {
    $s = $pdo->prepare('SELECT * FROM invoice_templates WHERE id = ?');
    $s->execute([$editId]);
    $template = $s->fetch();
}

$template = $template ?: [
    'id' => 0,
    'name' => '',
    'is_default' => 0,
    'logo_path' => '',
    'primary_color' => '#2563eb',
    'accent_color' => '#0f172a',
    'font_family' => 'Helvetica',
    'column_labels' => '{"description":"Description","quantity":"Qty","unit_price":"Rate","tax":"Tax","amount":"Amount"}',
    'block_order' => '["company","invoice_meta","customer","items","totals","bank","custom","notes"]',
    'bank_details' => "Bank: First National Bank\nAccount: 1234 5678 9012\nRouting/Swift: FNBKUS33",
    'custom_details' => '',
    'footer_text' => 'Thank you for your business. Payment is due within 30 days.'
];

$labels = json_decode($template['column_labels'], true) ?: [];
$order = json_decode($template['block_order'], true) ?: $allowedBlocks;
$blockNames = [
    'company' => 'Company Header & Logo',
    'invoice_meta' => 'Invoice #, Dates & Due Date',
    'customer' => 'Customer / Bill To Details',
    'items' => 'Line Items Table',
    'totals' => 'Subtotal, Taxes & Balance',
    'bank' => 'Bank & Payment Details',
    'custom' => 'Custom Info / Project Scope',
    'notes' => 'Terms & Footer Note'
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
$input = 'mt-1 w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Invoice Template Designer</h2>
                <p class="text-xs text-slate-500">Reorder sections, format text alignment, customize branding, and configure bank instructions</p>
            </div>
        </div>
        <span class="text-xs font-semibold px-3 py-1 bg-blue-50 text-blue-700 rounded-full border border-blue-200">
            <?= count($templates) ?> Saved <?= count($templates) === 1 ? 'Template' : 'Templates' ?>
        </span>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto space-y-6">
        <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl shadow-sm text-sm"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['saved'])): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl shadow-sm text-sm">Template configurations updated successfully.</div>
        <?php endif; ?>

        <div class="grid xl:grid-cols-12 gap-7">
            
            <!-- Controls Form (5 Cols) -->
            <form method="post" enctype="multipart/form-data" class="xl:col-span-5 bg-white border rounded-2xl p-6 space-y-5 shadow-sm h-fit">
                <?= csrf_field() ?>
                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                <input type="hidden" name="existing_logo" value="<?= h($template['logo_path']) ?>">
                <input type="hidden" name="block_order" id="blockOrder" value="<?= h(json_encode($order)) ?>">

                <div class="flex justify-between items-center border-b pb-3">
                    <div>
                        <h3 class="font-bold text-slate-900"><?= $template['id'] ? 'Edit Template' : 'New Template' ?></h3>
                        <p class="text-xs text-slate-500">Customize styling and block flow</p>
                    </div>
                    <label class="text-xs font-semibold flex gap-2 items-center text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_default" <?= $template['is_default'] ? 'checked' : '' ?> class="rounded text-blue-600"> Default Template
                    </label>
                </div>

                <label class="block text-xs font-bold uppercase text-slate-600">Template Name *
                    <input name="name" required value="<?= h($template['name']) ?>" placeholder="e.g. Modern Minimalist" class="<?= $input ?>">
                </label>

                <!-- Branding & Typography -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <label class="text-xs font-semibold text-slate-700">Primary Color
                        <input type="color" name="primary_color" value="<?= h($template['primary_color']) ?>" class="<?= $input ?> h-10 p-1 template-control cursor-pointer">
                    </label>
                    <label class="text-xs font-semibold text-slate-700">Header / Accent
                        <input type="color" name="accent_color" value="<?= h($template['accent_color']) ?>" class="<?= $input ?> h-10 p-1 template-control cursor-pointer">
                    </label>
                    <label class="text-xs font-semibold text-slate-700">Font Family
                        <select name="font_family" class="<?= $input ?> bg-white template-control">
                            <?php foreach (['Helvetica', 'Arial', 'Times New Roman', 'Georgia', 'Courier New'] as $f): ?>
                                    <option <?= $template['font_family'] === $f ? 'selected' : '' ?>><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-700">Upload Logo
                        <input type="file" name="logo" accept="image/png,image/jpeg" class="<?= $input ?> file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-slate-100">
                    </label>
                </div>

                <!-- Drag-and-Drop Blocks Reordering -->
                <div class="pt-2 border-t">
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-xs font-bold uppercase text-slate-600">Section Hierarchy</p>
                        <span class="text-[11px] text-slate-400">Drag items to rearrange</span>
                    </div>
                    <div id="blockList" class="space-y-1.5">
                        <?php foreach ($order as $block): ?>
                                <div draggable="true" data-block="<?= $block ?>" class="template-block border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 hover:bg-slate-100 transition-colors cursor-grab active:cursor-grabbing flex justify-between items-center text-xs font-medium text-slate-700">
                                    <span class="flex items-center gap-2">
                                        <span class="text-slate-400">☰</span> <?= h($blockNames[$block] ?? $block) ?>
                                    </span>
                                    <?php if (!in_array($block, ['items', 'totals'], true)): ?>
                                            <label class="text-[11px] flex items-center gap-1 cursor-pointer">
                                                <input type="checkbox" class="block-visible rounded text-blue-600" checked> Show
                                            </label>
                                    <?php endif; ?>
                                </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Table Column Names -->
                <div class="pt-2 border-t">
                    <p class="text-xs font-bold uppercase text-slate-600 mb-2">Custom Table Labels</p>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach (['description', 'quantity', 'unit_price', 'tax', 'amount'] as $k): ?>
                                <label class="text-[11px] text-slate-500"><?= h(ucwords(str_replace('_', ' ', $k))) ?>
                                    <input name="column_<?= $k ?>" value="<?= h($labels[$k] ?? '') ?>" class="mt-0.5 w-full border rounded px-2 py-1 text-xs column-control" data-column="<?= $k ?>">
                                </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bank Instructions & Quick Injector -->
                <div class="pt-2 border-t">
                    <div class="flex justify-between items-center mb-1">
                        <label class="text-xs font-bold uppercase text-slate-600">Bank & Payment Details</label>
                        <?php if ($bankAccounts): ?>
                                <select id="bankInjector" class="text-[11px] border rounded px-1.5 py-0.5 bg-white text-slate-700">
                                    <option value="">+ Insert Bank Details</option>
                                    <?php foreach ($bankAccounts as $ba): ?>
                                            <option value="<?= h($ba['account_name']) ?>">Insert <?= h($ba['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                        <?php endif; ?>
                    </div>
                    <textarea id="bankDetailsInput" name="bank_details" rows="3" class="<?= $input ?> template-text" data-preview="previewBank"><?= h($template['bank_details']) ?></textarea>
                </div>

                <!-- Custom Details -->
                <div class="pt-2 border-t">
                    <label class="block text-xs font-bold uppercase text-slate-600">Custom Details / Terms
                        <textarea name="custom_details" rows="2" placeholder="Registration numbers, project scope, extra notes..." class="<?= $input ?> template-text" data-preview="previewCustom"><?= h($template['custom_details']) ?></textarea>
                    </label>
                </div>

                <!-- Footer Text Formatting -->
                <div class="pt-2 border-t">
                    <div class="flex justify-between items-center mb-1">
                        <label class="text-xs font-bold uppercase text-slate-600">Footer Text</label>
                        <div class="flex gap-1">
                            <button type="button" class="format-btn px-2 py-0.5 text-xs border rounded hover:bg-slate-100 font-bold" data-action="bold">B</button>
                            <button type="button" class="format-btn px-2 py-0.5 text-xs border rounded hover:bg-slate-100" data-align="text-left">⯇</button>
                            <button type="button" class="format-btn px-2 py-0.5 text-xs border rounded hover:bg-slate-100" data-align="text-center">≡</button>
                            <button type="button" class="format-btn px-2 py-0.5 text-xs border rounded hover:bg-slate-100" data-align="text-right">⯈</button>
                        </div>
                    </div>
                    <textarea id="footerTextInput" name="footer_text" rows="2" class="<?= $input ?> template-text" data-preview="previewNotes"><?= h($template['footer_text']) ?></textarea>
                </div>

                <div class="flex gap-2 pt-3 border-t">
                    <button type="submit" name="save_template" class="bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-6 py-2.5 font-semibold text-sm flex-1 transition-colors">
                        Save Template
                    </button>
                    <?php if ($template['id']): ?>
                            <a href="invoice_templates.php" class="border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2.5 text-sm font-semibold flex items-center justify-center">New</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Live Preview (7 Cols) -->
            <div class="xl:col-span-7 space-y-6">
                
                <section class="bg-slate-300/70 rounded-2xl p-6 border shadow-inner">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600">Document Canvas Preview</span>
                        <span class="text-xs bg-white text-slate-600 px-2.5 py-1 rounded-md border font-medium">Standard A4 Layout</span>
                    </div>

                    <div id="templatePreview" class="bg-white shadow-xl mx-auto p-10 text-sm rounded-sm" style="min-height: 840px; font-family: <?= h($template['font_family']) ?>;">
                        <div id="previewBlocks">
                            <?php foreach ($order as $block): ?>
                                    <div data-preview-block="<?= $block ?>" class="preview-block mb-6">
                                    
                                        <?php if ($block === 'company'): ?>
                                                <div class="flex justify-between items-start border-b pb-5">
                                                    <div>
                                                        <?php if ($template['logo_path']): ?>
                                                                <img id="previewLogo" src="../<?= h($template['logo_path']) ?>" class="max-h-16 max-w-44 mb-2 object-contain">
                                                        <?php endif; ?>
                                                        <h2 class="text-xl font-black text-slate-900"><?= h(setting($pdo, 'company_name', 'Acme Corporation')) ?></h2>
                                                        <p class="text-xs text-slate-500 mt-0.5"><?= h(setting($pdo, 'company_address', '123 Business Parkway, Suite 100')) ?></p>
                                                        <p class="text-xs text-slate-500"><?= h(setting($pdo, 'company_email', 'billing@acme.test')) ?></p>
                                                    </div>
                                                    <div class="text-right">
                                                        <strong class="text-3xl font-black tracking-tight" style="color: <?= $template['primary_color'] ?>">INVOICE</strong>
                                                    </div>
                                                </div>

                                        <?php elseif ($block === 'invoice_meta'): ?>
                                                <div class="flex justify-between items-end text-xs pt-1">
                                                    <div class="text-slate-500">
                                                        <span>Status: <span class="font-bold text-emerald-600 uppercase">Paid</span></span>
                                                    </div>
                                                    <div class="text-right space-y-0.5">
                                                        <p class="font-bold text-sm text-slate-900">INV-01001</p>
                                                        <p class="text-slate-600">Issue Date: <?= date('M j, Y') ?></p>
                                                        <p class="text-slate-600">Due Date: <?= date('M j, Y', strtotime('+30 days')) ?></p>
                                                    </div>
                                                </div>

                                        <?php elseif ($block === 'customer'): ?>
                                                <div class="bg-slate-50/70 border border-slate-100 rounded-lg p-4 text-xs">
                                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Billed To</p>
                                                    <strong class="text-sm font-bold text-slate-800">Globex Corporation</strong>
                                                    <p class="text-slate-600 mt-0.5">Attn: Hank Scorpio</p>
                                                    <p class="text-slate-500">hank@globex.test · +1-555-0199</p>
                                                    <p class="text-slate-500">123 Volcano Lair, Sector 7</p>
                                                </div>

                                        <?php elseif ($block === 'items'): ?>
                                                <div class="overflow-x-auto rounded-lg border border-slate-200">
                                                    <table class="w-full text-xs">
                                                        <thead style="background: <?= $template['accent_color'] ?>; color: #ffffff;">
                                                            <tr>
                                                                <?php foreach (['description', 'quantity', 'unit_price', 'tax', 'amount'] as $k): ?>
                                                                        <th class="p-2.5 text-left preview-col-<?= $k ?>"><?= h($labels[$k] ?? $k) ?></th>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-slate-100">
                                                            <tr>
                                                                <td class="p-2.5 font-medium text-slate-800">Software Architecture Consulting</td>
                                                                <td class="p-2.5 text-slate-600">10</td>
                                                                <td class="p-2.5 text-slate-600">$125.00</td>
                                                                <td class="p-2.5 text-slate-600">10%</td>
                                                                <td class="p-2.5 font-bold text-slate-900">$1,375.00</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="p-2.5 font-medium text-slate-800">Cloud Workspace Monthly Subscription</td>
                                                                <td class="p-2.5 text-slate-600">1</td>
                                                                <td class="p-2.5 text-slate-600">$49.99</td>
                                                                <td class="p-2.5 text-slate-600">0%</td>
                                                                <td class="p-2.5 font-bold text-slate-900">$49.99</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>

                                        <?php elseif ($block === 'totals'): ?>
                                                <div class="ml-auto w-64 space-y-1.5 text-xs">
                                                    <p class="flex justify-between text-slate-600"><span>Subtotal:</span><span class="font-medium">$1,299.99</span></p>
                                                    <p class="flex justify-between text-slate-600"><span>Tax Total (10%):</span><span class="font-medium">$125.00</span></p>
                                                    <p class="flex justify-between p-2.5 rounded text-white font-bold text-sm" style="background: <?= $template['primary_color'] ?>">
                                                        <span>Grand Total:</span><span>$1,424.99</span>
                                                    </p>
                                                </div>

                                        <?php elseif ($block === 'bank'): ?>
                                                <div class="bg-slate-50 border border-slate-200/80 rounded-lg p-3 text-xs">
                                                    <p class="font-bold text-slate-800 mb-1">Remittance & Payment Instructions</p>
                                                    <p id="previewBank" class="whitespace-pre-line text-slate-600 leading-relaxed"><?= h($template['bank_details']) ?></p>
                                                </div>

                                        <?php elseif ($block === 'custom'): ?>
                                                <div id="previewCustom" class="whitespace-pre-line text-xs text-slate-600 leading-relaxed"><?= h($template['custom_details']) ?></div>

                                        <?php else: ?>
                                                <div id="previewNotes" class="border-t pt-3 text-xs text-slate-500 whitespace-pre-line leading-relaxed"><?= h($template['footer_text']) ?></div>
                                        <?php endif; ?>

                                    </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- Saved Templates List -->
                <section class="bg-white border rounded-2xl p-6 shadow-sm">
                    <h3 class="font-bold text-slate-900 mb-3">Saved Invoice Templates</h3>
                    <div class="divide-y">
                        <?php foreach ($templates as $t): ?>
                                <div class="flex justify-between items-center py-3">
                                    <div>
                                        <strong class="text-sm font-bold text-slate-800"><?= h($t['name']) ?></strong>
                                        <?php if ($t['is_default']): ?>
                                                <span class="ml-2 text-xs bg-emerald-100 text-emerald-800 font-semibold rounded-full px-2.5 py-0.5">Default Template</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <a href="invoice_templates.php?edit=<?= $t['id'] ?>" class="text-blue-600 hover:text-blue-800 font-semibold text-xs">Edit Layout</a>
                                        <?php if (!$t['is_default']): ?>
                                                <form method="post" onsubmit="return confirm('Delete this template?')" class="inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" name="delete_template" class="text-rose-600 hover:text-rose-800 text-xs font-semibold">Delete</button>
                                                </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                        <?php endforeach; ?>
                    </div>
                </section>

            </div>

        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('blockList');
    const orderInput = document.getElementById('blockOrder');
    const preview = document.getElementById('previewBlocks');
    let dragged = null;

    function sync() {
        const blocks = [...list.querySelectorAll('.template-block')];
        orderInput.value = JSON.stringify(blocks.filter(b => b.querySelector('.block-visible')?.checked !== false).map(b => b.dataset.block));
        blocks.forEach(b => {
            const p = preview.querySelector(`[data-preview-block="${b.dataset.block}"]`);
            if (p) {
                preview.append(p);
                p.style.display = b.querySelector('.block-visible')?.checked === false ? 'none' : '';
            }
        });
    }

    list.addEventListener('dragstart', e => {
        dragged = e.target.closest('.template-block');
    });

    list.addEventListener('dragover', e => {
        e.preventDefault();
        const target = e.target.closest('.template-block');
        if (target && target !== dragged) {
            const rect = target.getBoundingClientRect();
            list.insertBefore(dragged, e.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
            sync();
        }
    });

    list.addEventListener('change', sync);

    // Live column labels updating
    document.querySelectorAll('.column-control').forEach(i => {
        i.addEventListener('input', () => {
            const col = document.querySelector('.preview-col-' + i.dataset.column);
            if (col) col.textContent = i.value;
        });
    });

    // Live textarea update
    document.querySelectorAll('.template-text').forEach(i => {
        i.addEventListener('input', () => {
            const target = document.getElementById(i.dataset.preview);
            if (target) target.textContent = i.value;
        });
    });

    // Live style updating
    document.querySelectorAll('.template-control').forEach(i => {
        i.addEventListener('input', () => {
            document.getElementById('templatePreview').style.fontFamily = document.querySelector('[name=font_family]').value;
            document.querySelectorAll('[data-preview-block="items"] thead').forEach(e => e.style.background = document.querySelector('[name=accent_color]').value);
            document.querySelectorAll('[data-preview-block="totals"] p:last-child').forEach(e => e.style.background = document.querySelector('[name=primary_color]').value);
            const headerText = document.querySelector('[data-preview-block="company"] strong');
            if (headerText) headerText.style.color = document.querySelector('[name=primary_color]').value;
        });
    });

    // Quick bank account insertion
    const bankInjector = document.getElementById('bankInjector');
    const bankTextarea = document.getElementById('bankDetailsInput');
    if (bankInjector && bankTextarea) {
        bankInjector.addEventListener('change', (e) => {
            if (!e.target.value) return;
            const newDetail = `Bank Name: ${e.target.value}\nAccount Number: [Insert Account]\nRouting / Swift: [Insert SWIFT]`;
            bankTextarea.value = bankTextarea.value ? bankTextarea.value + '\n\n' + newDetail : newDetail;
            document.getElementById('previewBank').textContent = bankTextarea.value;
            e.target.value = '';
        });
    }

    // Alignment and Bold controls
    document.querySelectorAll('.format-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const notesPreview = document.getElementById('previewNotes');
            if (btn.dataset.action === 'bold') {
                notesPreview.classList.toggle('font-bold');
            } else if (btn.dataset.align) {
                notesPreview.classList.remove('text-left', 'text-center', 'text-right');
                notesPreview.classList.add(btn.dataset.align);
            }
        });
    });

    sync();
});
</script>

<?php require_once 'includes/footer.php'; ?>