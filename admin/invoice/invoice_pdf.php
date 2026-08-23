<?php
// admin/invoice/invoice_pdf.php
require_once '../../config/db.php';
require_once '../../config/invoice_renderer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);

// Fetch Invoice & Customer Details
$s = $pdo->prepare('SELECT i.*, c.company_name, c.contact_person, c.email, c.billing_address, cur.symbol 
                    FROM invoices i 
                    JOIN contacts c ON c.id = i.contact_id 
                    JOIN currencies cur ON cur.code = i.currency_code 
                    WHERE i.id = ?');
$s->execute([$id]);
$invoice = $s->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

// Fetch Line Items
$s = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id ASC');
$s->execute([$id]);
$lines = $s->fetchAll();

// Fetch Template
$s = $pdo->prepare('SELECT * FROM invoice_templates WHERE id = ?');
$s->execute([$invoice['invoice_template_id']]);
$template = $s->fetch();

if (!$template) {
    $template = $pdo->query('SELECT * FROM invoice_templates ORDER BY is_default DESC, id ASC LIMIT 1')->fetch();
}

// Render Document HTML
$document = render_invoice_document($pdo, $invoice, $lines, $template);
$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice['invoice_number']) . '.pdf';

// Check if Dompdf is installed via Composer
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;

    if (class_exists('Dompdf\Dompdf')) {
        $html = '<!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>' . h($invoice['invoice_number']) . '</title>
            <style>
                @page { margin: 28px; }
                body { margin: 0; font-family: ' . ($template['font_family'] ?? 'Helvetica') . ', Arial, sans-serif; }
                * { box-sizing: border-box; }
            </style>
        </head>
        <body>' . $document . '</body>
        </html>';

        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->setChroot(dirname(__DIR__, 2));

        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $canvas->page_text(500, 810, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.4, 0.45, 0.5]);

        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }
}

// Native Browser-Print Fallback if DomPDF is not present
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title><?= h($invoice['invoice_number']) ?> - Print / PDF</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @page {
            size: A4;
            margin: 12mm;
        }

        body {
            background: #f8fafc;
            margin: 0;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .no-print {
                display: none !important;
            }

            .invoice-card {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
        }
    </style>
</head>

<body class="min-h-screen py-8 print:py-0">
    <div class="no-print max-w-4xl mx-auto mb-5 px-4 flex justify-between items-center">
        <a href="view_invoice.php?id=<?= $id ?>" class="text-sm font-semibold text-slate-600 hover:text-slate-900">←
            Back to Invoice</a>
        <button onclick="window.print()"
            class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 py-2.5 rounded-lg text-sm shadow-md transition-colors">
            Download PDF / Print
        </button>
    </div>

    <div
        class="invoice-card max-w-4xl mx-auto bg-white border border-slate-200 shadow-xl rounded-2xl overflow-hidden print:rounded-none">
        <?= $document ?>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            window.print();
        });
    </script>
</body>

</html>