<?php
$action = $_GET['action'] ?? 'index';
$currency = $settings['currency_symbol'] ?? 'LKR';

if ($action === 'download') {
    $filterMode = $_GET['filter_mode'] ?? 'all';
    if (!in_array($filterMode, ['all', 'date', 'price'], true)) {
        $filterMode = 'all';
    }
    $dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']) ? $_GET['date_to'] : '';
    $priceFrom = (isset($_GET['price_from']) && $_GET['price_from'] !== '') ? max(0, floatval($_GET['price_from'])) : null;
    $priceTo = (isset($_GET['price_to']) && $_GET['price_to'] !== '') ? max(0, floatval($_GET['price_to'])) : null;

    $query = "SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.type = 'retail'";
    $params = [];

    if ($filterMode === 'date') {
        if ($dateFrom !== '') {
            $query .= " AND DATE(b.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $query .= " AND DATE(b.created_at) <= ?";
            $params[] = $dateTo;
        }
    }
    if ($filterMode === 'price') {
        if ($priceFrom !== null) {
            $query .= " AND b.total_amount >= ?";
            $params[] = $priceFrom;
        }
        if ($priceTo !== null) {
            $query .= " AND b.total_amount <= ?";
            $params[] = $priceTo;
        }
    }
    $query .= " ORDER BY b.created_at DESC LIMIT 1000";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $billsForExport = $stmt->fetchAll();

    $pdfEscape = function ($text) {
        $text = (string)$text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        return $text;
    };

    $trimCell = function ($text, $maxChars) {
        $text = trim((string)$text);
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, max(0, $maxChars - 3)) . '...';
    };

    $rows = [];
    $sumTotal = 0.0;
    $sumPaid = 0.0;
    $sumBalance = 0.0;

    foreach ($billsForExport as $bill) {
        $total = floatval($bill['total_amount'] ?? 0);
        $paid = floatval($bill['paid_amount'] ?? 0);
        $balance = $total - $paid;
        $sumTotal += $total;
        $sumPaid += $paid;
        $sumBalance += $balance;

        $rows[] = [
            'bill_no' => $trimCell($bill['bill_number'] ?? '', 15),
            'date' => date('d M Y', strtotime($bill['created_at'] ?? 'now')),
            'customer' => $trimCell($bill['customer_name'] ?? 'Walk-in', 22),
            'total' => number_format($total, 2, '.', ','),
            'paid' => number_format($paid, 2, '.', ','),
            'balance' => number_format($balance, 2, '.', ','),
            'status' => $trimCell(ucfirst($bill['payment_status'] ?? 'pending'), 10),
        ];
    }

    $rowsPerPage = 29;
    $rowPages = array_chunk($rows, $rowsPerPage);
    if (empty($rowPages)) {
        $rowPages = [[]];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
    $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>';

    $pageRefs = [];
    $nextObj = 7;

    $pageWidth = 595;
    $margin = 30;
    $tableWidth = $pageWidth - ($margin * 2);
    $columns = [
        ['key' => 'bill_no', 'label' => 'Bill No', 'width' => 70, 'align' => 'L'],
        ['key' => 'date', 'label' => 'Date', 'width' => 70, 'align' => 'L'],
        ['key' => 'customer', 'label' => 'Customer', 'width' => 120, 'align' => 'L'],
        ['key' => 'total', 'label' => 'Total', 'width' => 80, 'align' => 'R'],
        ['key' => 'paid', 'label' => 'Paid', 'width' => 80, 'align' => 'R'],
        ['key' => 'balance', 'label' => 'Balance', 'width' => 80, 'align' => 'R'],
        ['key' => 'status', 'label' => 'Status', 'width' => 35, 'align' => 'L'],
    ];

    $drawText = function ($x, $y, $text, $fontSize, $bold, $tableMode = false) use ($pdfEscape) {
        if ($tableMode) {
            $font = $bold ? '/F6' : '/F5';
        } else {
            $font = $bold ? '/F2' : '/F1';
        }
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    $drawRightText = function ($xRight, $y, $text, $fontSize, $bold, $tableMode = false) use ($pdfEscape) {
        if ($tableMode) {
            $font = $bold ? '/F6' : '/F5';
            $charWidth = $fontSize * 0.60;
        } else {
            $font = $bold ? '/F2' : '/F1';
            $charWidth = $fontSize * 0.50;
        }
        $textWidth = strlen((string)$text) * $charWidth;
        $x = $xRight - $textWidth;
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    foreach ($rowPages as $pageIndex => $rowPage) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageRefs[] = $pageObj . ' 0 R';

        $content = "";

        $content .= "q\n0.13 0.35 0.75 rg\n{$margin} 785 {$tableWidth} 34 re f\nQ\n";
        $content .= $drawText($margin + 10, 798, 'Retail Billing Report', 14, true);

        $summaryTop = 776;
        $summaryRowHeight = 16;
        $summaryColWidth = $tableWidth / 3;
        $summaryRows = [
            [
                'Generated: ' . date('Y-m-d H:i:s'),
                'Records: ' . count($rows),
                'Page: ' . ($pageIndex + 1) . '/' . count($rowPages),
            ],
            [
                'Total: ' . number_format($sumTotal, 2),
                'Paid: ' . number_format($sumPaid, 2),
                'Balance: ' . number_format($sumBalance, 2),
            ],
        ];

        foreach ($summaryRows as $rowIndex => $summaryRow) {
            $cellY = $summaryTop - ($rowIndex * $summaryRowHeight);
            foreach ($summaryRow as $colIndex => $cellText) {
                $cellX = $margin + ($colIndex * $summaryColWidth);
                if ($rowIndex === 0) {
                    $content .= "q\n0.97 0.98 1 rg\n{$cellX} " . ($cellY - $summaryRowHeight) . " {$summaryColWidth} {$summaryRowHeight} re f\nQ\n";
                }
                $content .= "q\n0.85 0.88 0.94 RG\n0.5 w\n{$cellX} " . ($cellY - $summaryRowHeight) . " {$summaryColWidth} {$summaryRowHeight} re S\nQ\n";
                $content .= $drawText($cellX + 6, $cellY - 11, $cellText, 8.5, false);
            }
        }

        $tableTop = 730;
        $headerHeight = 20;
        $rowHeight = 18;
        $content .= "q\n0.90 0.93 0.98 rg\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re f\nQ\n";
        $content .= "q\n0.75 0.80 0.90 RG\n0.8 w\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re S\nQ\n";

        $x = $margin;
        foreach ($columns as $col) {
            $content .= $drawText($x + 4, $tableTop - 14, $col['label'], 9, true, true);
            $x += $col['width'];
            $content .= "q\n0.85 0.88 0.94 RG\n0.5 w\n{$x} " . ($tableTop - $headerHeight) . " m {$x} " . ($tableTop - $headerHeight - ($rowHeight * max(1, count($rowPage)))) . " l S\nQ\n";
        }

        $currentY = $tableTop - $headerHeight;
        if (empty($rowPage)) {
            $content .= "q\n0.92 0.92 0.92 RG\n0.5 w\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re S\nQ\n";
            $content .= $drawText($margin + 8, $currentY - 13, 'No records found for selected filters.', 9, false, true);
            $currentY -= $rowHeight;
        } else {
            foreach ($rowPage as $i => $row) {
                if ($i % 2 === 0) {
                    $content .= "q\n0.98 0.99 1 rg\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re f\nQ\n";
                }
                $content .= "q\n0.92 0.92 0.92 RG\n0.5 w\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re S\nQ\n";

                $x = $margin;
                foreach ($columns as $col) {
                    $value = $row[$col['key']] ?? '';
                    if ($col['align'] === 'R') {
                        $content .= $drawRightText($x + $col['width'] - 6, $currentY - 12, $value, 9, false, true);
                    } else {
                        $content .= $drawText($x + 4, $currentY - 12, $value, 9, false, true);
                    }
                    $x += $col['width'];
                }

                $currentY -= $rowHeight;
            }
        }

        $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F5 5 0 R /F6 6 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';

    ksort($objects);
    $maxObj = max(array_keys($objects));

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    for ($i = 1; $i <= $maxObj; $i++) {
        if (!isset($objects[$i])) {
            continue;
        }
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n";
    $pdf .= '0 ' . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $off) . "\n";
    }
    $pdf .= "trailer\n";
    $pdf .= '<< /Size ' . ($maxObj + 1) . ' /Root 1 0 R >>' . "\n";
    $pdf .= "startxref\n";
    $pdf .= $xrefOffset . "\n";
    $pdf .= "%%EOF";

    $fileName = 'retail-report-' . date('Ymd-His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename=' . $fileName);
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'clear_before') {
        $clearBeforeDate = trim($_POST['clear_before_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clearBeforeDate)) {
            header('Location: ?page=retail&clear_error=1');
            exit;
        }

        try {
            $idsStmt = $pdo->prepare("SELECT id FROM bills WHERE type = 'retail' AND DATE(created_at) < ?");
            $idsStmt->execute([$clearBeforeDate]);
            $billIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
            $clearedCount = count($billIds);

            if ($clearedCount > 0) {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, $clearedCount, '?'));

                $deleteItemsStmt = $pdo->prepare("DELETE FROM bill_items WHERE bill_id IN ($placeholders)");
                $deleteItemsStmt->execute($billIds);

                $deleteBillsStmt = $pdo->prepare("DELETE FROM bills WHERE id IN ($placeholders)");
                $deleteBillsStmt->execute($billIds);

                $pdo->commit();
            }

            header('Location: ?page=retail&cleared=' . intval($clearedCount) . '&before=' . urlencode($clearBeforeDate));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: ?page=retail&clear_error=1');
            exit;
        }
    } elseif ($action === 'store') {
        $customer_id = $_POST['customer_id'] ?: null;
        $items = $_POST['items'] ?? [];
        $discountPercent = floatval($_POST['discount_percentage'] ?? ($_POST['discount_amount'] ?? 0));
        $paid = floatval($_POST['paid_amount'] ?? 0);
        $payment_method = 'cash';
        
        if (!empty($items)) {
            $subtotal = 0;
            foreach ($items as $item) {
                $quantity = floatval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $itemDiscount = floatval($item['discount'] ?? 0);
                $itemDiscount = min(max($itemDiscount, 0), $price);
                $subtotal += $quantity * max($price - $itemDiscount, 0);
            }
            $discountPercent = max(0, min($discountPercent, 100));
            $discount = ($subtotal * $discountPercent) / 100;
            $tax = ($subtotal - $discount) * (floatval($settings['tax_percentage'] ?? 0) / 100);
            $total = $subtotal - $discount + $tax;
            $paid = max(0, $paid);
            $status = 'pending';
            if ($paid >= $total && $total > 0) {
                $status = 'paid';
            } elseif ($paid > 0 && $paid < $total) {
                $status = 'partial';
            }
            
            $lastBill = $pdo->query("SELECT bill_number FROM bills WHERE type = 'retail' ORDER BY id DESC LIMIT 1")->fetch();
            $nextNum = $lastBill ? intval(substr($lastBill['bill_number'], -6)) + 1 : 1;
            $billNumber = ($settings['invoice_prefix'] ?? 'SRF') . '-R-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO bills (bill_number, type, customer_id, user_id, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, payment_method) VALUES (?, 'retail', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$billNumber, $customer_id, $_SESSION['user_id'], $subtotal, $discount, $tax, $total, $paid, $status, $payment_method]);
                $billId = $pdo->lastInsertId();
                
                $itemStmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");
                $stockStmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                
                foreach ($items as $item) {
                    $quantity = floatval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    $itemDiscount = floatval($item['discount'] ?? 0);
                    $itemDiscount = min(max($itemDiscount, 0), $price);
                    $itemTotal = $quantity * max($price - $itemDiscount, 0);
                    $itemStmt->execute([$billId, intval($item['product_id'] ?? 0), $quantity, $price, $itemDiscount, $itemTotal]);
                    $stockStmt->execute([$quantity, intval($item['product_id'] ?? 0)]);
                }
                
                $pdo->commit();
                header("Location: ?page=retail&action=view&id=$billId&success=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_GET['id'] ?? 0);
        $customer_id = $_POST['customer_id'] ?: null;
        $items = $_POST['items'] ?? [];
        $discountPercent = floatval($_POST['discount_percentage'] ?? ($_POST['discount_amount'] ?? 0));
        $paid = floatval($_POST['paid_amount'] ?? 0);
        $payment_method = 'cash';

        if ($id > 0 && !empty($items)) {
            $existingBillStmt = $pdo->prepare("SELECT id FROM bills WHERE id = ? AND type = 'retail'");
            $existingBillStmt->execute([$id]);
            $existingBill = $existingBillStmt->fetch();

            if ($existingBill) {
                $subtotal = 0;
                foreach ($items as $item) {
                    $quantity = floatval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    $itemDiscount = floatval($item['discount'] ?? 0);
                    $itemDiscount = min(max($itemDiscount, 0), $price);
                    $subtotal += $quantity * max($price - $itemDiscount, 0);
                }

                $discountPercent = max(0, min($discountPercent, 100));
                $discount = ($subtotal * $discountPercent) / 100;
                $tax = ($subtotal - $discount) * (floatval($settings['tax_percentage'] ?? 0) / 100);
                $total = $subtotal - $discount + $tax;
                $paid = max(0, $paid);

                $status = 'pending';
                if ($paid >= $total && $total > 0) {
                    $status = 'paid';
                } elseif ($paid > 0 && $paid < $total) {
                    $status = 'partial';
                }

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM bill_items WHERE bill_id = ?")->execute([$id]);

                    $itemStmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $quantity = floatval($item['quantity'] ?? 0);
                        $price = floatval($item['price'] ?? 0);
                        $itemDiscount = floatval($item['discount'] ?? 0);
                        $itemDiscount = min(max($itemDiscount, 0), $price);
                        $itemTotal = $quantity * max($price - $itemDiscount, 0);
                        $itemStmt->execute([$id, intval($item['product_id'] ?? 0), $quantity, $price, $itemDiscount, $itemTotal]);
                    }

                    $updateStmt = $pdo->prepare("UPDATE bills SET customer_id = ?, subtotal = ?, discount_amount = ?, tax_amount = ?, total_amount = ?, paid_amount = ?, payment_status = ?, payment_method = ? WHERE id = ? AND type = 'retail'");
                    $updateStmt->execute([$customer_id, $subtotal, $discount, $tax, $total, $paid, $status, $payment_method, $id]);

                    $pdo->commit();
                    header("Location: ?page=retail&action=view&id=$id&updated=1");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

include 'header.php';
?>

<?php if ($action === 'index'): ?>
<?php
$period = $_GET['period'] ?? '';
if (!in_array($period, ['today', 'month'], true)) {
    $period = '';
}

$topSalesCondition = "b.type = 'retail' AND b.bill_number LIKE '%-R-%'";
if ($period === 'today') {
    $topSalesCondition .= " AND DATE(b.created_at) = CURRENT_DATE()";
} elseif ($period === 'month') {
    $topSalesCondition .= " AND MONTH(b.created_at) = MONTH(CURRENT_DATE()) AND YEAR(b.created_at) = YEAR(CURRENT_DATE())";
}

$topSellingByQtyStmt = $pdo->prepare(
    "SELECT 
        bi.product_id,
        COALESCE(p.name, 'Unknown Product') AS product_name,
        COALESCE(p.sku, '-') AS product_sku,
        SUM(bi.quantity) AS total_qty,
        SUM(bi.total) AS total_revenue,
        COUNT(DISTINCT bi.bill_id) AS bill_count
    FROM bill_items bi
    INNER JOIN bills b ON b.id = bi.bill_id
    LEFT JOIN products p ON p.id = bi.product_id
    WHERE {$topSalesCondition}
    GROUP BY bi.product_id, p.name, p.sku
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 5"
);
$topSellingByQtyStmt->execute();
$topSellingByQty = $topSellingByQtyStmt->fetchAll();

$topSellingByRevenueStmt = $pdo->prepare(
    "SELECT 
        bi.product_id,
        COALESCE(p.name, 'Unknown Product') AS product_name,
        COALESCE(p.sku, '-') AS product_sku,
        SUM(bi.quantity) AS total_qty,
        SUM(bi.total) AS total_revenue,
        COUNT(DISTINCT bi.bill_id) AS bill_count
    FROM bill_items bi
    INNER JOIN bills b ON b.id = bi.bill_id
    LEFT JOIN products p ON p.id = bi.product_id
    WHERE {$topSalesCondition}
    GROUP BY bi.product_id, p.name, p.sku
    ORDER BY total_revenue DESC, total_qty DESC
    LIMIT 5"
);
$topSellingByRevenueStmt->execute();
$topSellingByRevenue = $topSellingByRevenueStmt->fetchAll();

$maxTopQty = 0;
foreach ($topSellingByQty as $item) {
    $maxTopQty = max($maxTopQty, floatval($item['total_qty'] ?? 0));
}

$maxTopRevenue = 0;
foreach ($topSellingByRevenue as $item) {
    $maxTopRevenue = max($maxTopRevenue, floatval($item['total_revenue'] ?? 0));
}
?>
<?php if (isset($_GET['cleared'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>
    Cleared <?= intval($_GET['cleared']) ?> retail bill(s) before <?= htmlspecialchars($_GET['before'] ?? '') ?>.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['clear_error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Failed to clear retail bills. Please check the date and try again.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<style>
    .bill-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    .bill-toolbar-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .clear-date-form {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        border: 1px solid #f3d1d6;
        border-radius: 12px;
        padding: 8px;
    }
    .clear-date-form .form-control {
        min-width: 170px;
    }
    .clear-date-form .btn {
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .bill-toolbar-right {
            width: 100%;
        }
        .clear-date-form {
            width: 100%;
        }
        .clear-date-form .form-control,
        .clear-date-form .btn,
        .bill-toolbar-right .dropdown,
        .bill-toolbar-right > a {
            width: 100%;
        }
    }

    .insight-item-title {
        font-weight: 600;
        font-size: 0.95rem;
    }

    .chart-track {
        position: relative;
        width: 100%;
        height: 10px;
        border-radius: 999px;
        background: #e9edf5;
        overflow: hidden;
    }

    .chart-fill {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        border-radius: 999px;
    }

    .chart-fill.qty {
        background: linear-gradient(90deg, #f97316, #ef4444);
    }

    .chart-fill.revenue {
        background: linear-gradient(90deg, #22c55e, #0ea5e9);
    }
</style>

<div class="bill-toolbar mb-4">
    <h4 class="mb-0">Retail Bills</h4>
    <div class="bill-toolbar-right">
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-2"></i>Download Report
            </button>
            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 360px;">
                <form method="GET" action="?page=retail&action=download" id="reportFilterForm">
                    <input type="hidden" name="page" value="retail">
                    <input type="hidden" name="action" value="download">
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Generate By</label>
                        <div class="border rounded p-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="fm_all" name="filter_mode" value="all" checked>
                                <label class="form-check-label" for="fm_all">All Records</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="fm_date" name="filter_mode" value="date">
                                <label class="form-check-label" for="fm_date">Date Range</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="fm_price" name="filter_mode" value="price">
                                <label class="form-check-label" for="fm_price">Price Range</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Date Range</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="date" name="date_from" class="form-control form-control-sm date-filter-input" placeholder="From Date" disabled>
                            </div>
                            <div class="col-6">
                                <input type="date" name="date_to" class="form-control form-control-sm date-filter-input" placeholder="To Date" disabled>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Price Range (<?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?>)</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="number" name="price_from" class="form-control form-control-sm price-filter-input" placeholder="Min Price" step="0.01" min="0" disabled>
                            </div>
                            <div class="col-6">
                                <input type="number" name="price_to" class="form-control form-control-sm price-filter-input" placeholder="Max Price" step="0.01" min="0" disabled>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-file-earmark-pdf me-2"></i>Download PDF</button>
                </form>
            </div>
        </div>
        <a href="?page=retail&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>New Retail Bill</a>
    </div>
</div>

<?php if ($period === 'today' || $period === 'month'): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>
        <i class="bi bi-funnel me-2"></i>
        Showing <?= $period === 'today' ? "today's" : "this month's" ?> retail bills
    </span>
    <a href="?page=retail" class="btn btn-sm btn-outline-info">Clear Filter</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead><tr><th>Bill No</th><th>Date</th><th>Customer</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php
                $query = "SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.type = 'retail'";
                $params = [];
                if ($period === 'today') {
                    $query .= " AND DATE(b.created_at) = CURRENT_DATE()";
                } elseif ($period === 'month') {
                    $query .= " AND MONTH(b.created_at) = MONTH(CURRENT_DATE()) AND YEAR(b.created_at) = YEAR(CURRENT_DATE())";
                }
                $query .= " ORDER BY b.created_at DESC LIMIT 50";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $bills = $stmt->fetchAll();
                foreach ($bills as $bill):
                    $balanceAmount = floatval($bill['paid_amount']) - floatval($bill['total_amount']);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($bill['bill_number']) ?></strong></td>
                    <td><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></td>
                    <td><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></td>
                    <td class="text-end <?= $balanceAmount >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold' ?>"><?= $currency ?> <?= number_format($balanceAmount, 2) ?></td>
                    <td><span class="badge bg-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($bill['payment_status']) ?></span></td>
                    <td class="text-end">
                        <a href="?page=retail&action=view&id=<?= $bill['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                        <a href="?page=retail&action=edit&id=<?= $bill['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="?page=retail&action=print&id=<?= $bill['id'] ?>" class="btn btn-sm btn-secondary" target="_blank"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?><tr><td colspan="8" class="text-center text-muted py-4">No bills found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3 mb-3" id="topSellingSummaryCard">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <span><i class="bi bi-stars me-2 text-primary"></i>Top Selling Retail Items Summary</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#topSellingInsights" aria-expanded="false" aria-controls="topSellingInsights">
            <i class="bi bi-chevron-down me-1"></i>Show Charts
        </button>
    </div>
    <div class="collapse" id="topSellingInsights">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="bi bi-fire me-2 text-danger"></i>Top 5 by Quantity</h6>
                    <?php if (!empty($topSellingByQty)): ?>
                        <?php foreach ($topSellingByQty as $index => $item): ?>
                            <?php $qtyPercent = $maxTopQty > 0 ? min(100, (floatval($item['total_qty']) / $maxTopQty) * 100) : 0; ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <div class="insight-item-title">#<?= $index + 1 ?> <?= htmlspecialchars($item['product_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['product_sku']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold"><?= number_format($item['total_qty']) ?> qty</div>
                                        <small class="text-muted"><?= $currency ?> <?= number_format($item['total_revenue'], 2) ?></small>
                                    </div>
                                </div>
                                <div class="chart-track"><div class="chart-fill qty" style="width: <?= number_format($qtyPercent, 2, '.', '') ?>%;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">No retail sales data available.</div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="bi bi-cash-coin me-2 text-success"></i>Top 5 by Revenue</h6>
                    <?php if (!empty($topSellingByRevenue)): ?>
                        <?php foreach ($topSellingByRevenue as $index => $item): ?>
                            <?php $revenuePercent = $maxTopRevenue > 0 ? min(100, (floatval($item['total_revenue']) / $maxTopRevenue) * 100) : 0; ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <div class="insight-item-title">#<?= $index + 1 ?> <?= htmlspecialchars($item['product_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['product_sku']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold"><?= $currency ?> <?= number_format($item['total_revenue'], 2) ?></div>
                                        <small class="text-muted"><?= number_format($item['total_qty']) ?> qty</small>
                                    </div>
                                </div>
                                <div class="chart-track"><div class="chart-fill revenue" style="width: <?= number_format($revenuePercent, 2, '.', '') ?>%;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">No retail sales data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mt-3">
    <form method="POST" action="?page=retail&action=clear_before" class="d-flex gap-2 align-items-center" data-confirm-message="CAUTION: This will permanently delete retail bills before the selected date. This action cannot be undone. Continue?" data-confirm-title="Confirm Retail Bill Deletion">
        <div class="clear-date-form">
            <input type="date" name="clear_before_date" class="form-control form-control-sm" required title="Delete bills before this date">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-2"></i>Clear Before Date</button>
        </div>
    </form>
</div>

<script>
(() => {
    const reportForm = document.getElementById('reportFilterForm');
    if (!reportForm) return;

    const modeRadios = reportForm.querySelectorAll('input[name="filter_mode"]');
    const dateInputs = reportForm.querySelectorAll('.date-filter-input');
    const priceInputs = reportForm.querySelectorAll('.price-filter-input');

    function getMode() {
        const selected = reportForm.querySelector('input[name="filter_mode"]:checked');
        return selected ? selected.value : 'all';
    }

    function setInputState() {
        const mode = getMode();
        const dateEnabled = mode === 'date';
        const priceEnabled = mode === 'price';

        dateInputs.forEach(input => {
            input.disabled = !dateEnabled;
        });

        priceInputs.forEach(input => {
            input.disabled = !priceEnabled;
        });
    }

    modeRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            const mode = getMode();
            if (mode !== 'date') {
                dateInputs.forEach(input => {
                    input.value = '';
                });
            }
            if (mode !== 'price') {
                priceInputs.forEach(input => {
                    input.value = '';
                });
            }
            setInputState();
        });
    });

    setInputState();

    if (window.location.hash === '#topSellingSummaryCard') {
        const collapseElement = document.getElementById('topSellingInsights');
        const toggleButton = document.querySelector('[data-bs-target="#topSellingInsights"]');

        if (collapseElement) {
            // Fallback first: force visible even if Bootstrap JS has not initialized yet.
            collapseElement.classList.add('show');
        }

        if (toggleButton) {
            toggleButton.setAttribute('aria-expanded', 'true');
            toggleButton.innerHTML = '<i class="bi bi-chevron-up me-1"></i>Hide Charts';
        }

        if (collapseElement && window.bootstrap && window.bootstrap.Collapse) {
            window.bootstrap.Collapse.getOrCreateInstance(collapseElement).show();
        }
    }
})();
</script>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<?php
$isEdit = $action === 'edit';
$editBill = null;
$editItems = [];

if ($isEdit) {
    $editId = intval($_GET['id'] ?? 0);
    $editBillStmt = $pdo->prepare("SELECT * FROM bills WHERE id = ? AND type = 'retail'");
    $editBillStmt->execute([$editId]);
    $editBill = $editBillStmt->fetch();

    if (!$editBill) {
        echo '<div class="alert alert-danger">Retail bill not found</div>';
        include 'footer.php';
        exit;
    }

    $editItemsStmt = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku, p.category_id, p.stock_quantity FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ? ORDER BY bi.id ASC");
    $editItemsStmt->execute([$editId]);
    $editItems = $editItemsStmt->fetchAll();
}

$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 AND stock_quantity > 0 ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();

$initialDiscountPercent = $isEdit && floatval($editBill['subtotal'] ?? 0) > 0
    ? (floatval($editBill['discount_amount'] ?? 0) / floatval($editBill['subtotal'])) * 100
    : 0;

$initialPaidAmount = $isEdit ? floatval($editBill['paid_amount'] ?? 0) : 0;
$selectedCustomerId = $isEdit ? ($editBill['customer_id'] ?? '') : '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= $isEdit ? 'Edit Retail Bill' : 'New Retail Bill' ?></h4>
    <a href="?page=retail" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<form method="POST" action="?page=retail&action=<?= $isEdit ? 'update&id=' . intval($editBill['id']) : 'store' ?>" id="billForm">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-grid me-2"></i>Select Category First</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="categorySelect">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Product</label>
                            <select class="form-select" id="productSelect" disabled>
                                <option value="">-- Select a category first --</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-id="<?= $p['id'] ?>" data-category="<?= $p['category_id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-price="<?= $p['selling_price'] ?>" data-stock="<?= $p['stock_quantity'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>) - Stock: <?= $p['stock_quantity'] ?> - <?= $currency ?> <?= number_format($p['selling_price'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-list-ul me-2"></i>Bill Items</div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0" id="itemsTable">
                        <thead class="table-dark"><tr><th>#</th><th>Product</th><th style="width:80px">Stock</th><th style="width:80px">Qty</th><th style="width:100px">Price</th><th style="width:80px">Disc.</th><th style="width:100px">Total</th><th style="width:50px"></th></tr></thead>
                        <tbody id="itemsBody"><tr id="noItemsRow"><td colspan="8" class="text-center text-muted py-4">No items added</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-calculator me-2"></i>Bill Summary</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Customer (Optional)</label>
                        <select name="customer_id" class="form-select">
                            <option value="">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= (string)$selectedCustomerId === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['phone']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span id="subtotal"><?= $currency ?> 0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 align-items-center">
                        <span>Discount (%):</span>
                        <div class="input-group" style="width:120px"><input type="number" name="discount_percentage" id="discountPercent" class="form-control form-control-sm" value="<?= number_format($initialDiscountPercent, 2, '.', '') ?>" min="0" max="100" step="0.01"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span>Tax (<?= $settings['tax_percentage'] ?? 0 ?>%):</span><span id="taxAmount"><?= $currency ?> 0.00</span></div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary" id="grandTotal"><?= $currency ?> 0.00</strong></div>
                    <div class="d-flex justify-content-between mb-2 align-items-center">
                        <span>Paid Amount:</span>
                        <div class="input-group" style="width:140px"><span class="input-group-text"><?= $currency ?></span><input type="number" name="paid_amount" id="paidAmount" class="form-control form-control-sm" value="<?= number_format($initialPaidAmount, 2, '.', '') ?>" min="0" step="0.01"></div>
                    </div>
                    <div class="d-grid mb-2">
                        <button type="button" class="btn btn-outline-success btn-sm" id="payFullBtn"><i class="bi bi-cash-coin me-1"></i>Paid Full Amount</button>
                    </div>
                    <div class="d-flex justify-content-between mb-1"><strong>Balance:</strong><strong id="balanceAmount" class="text-danger"><?= $currency ?> 0.00</strong></div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="saveBillBtn" disabled><i class="bi bi-check-lg me-2"></i>Save Bill</button>
            </div>
        </div>
    </div>
</form>

<script>
const currency = '<?= $currency ?>';
const taxRate = <?= $settings['tax_percentage'] ?? 0 ?>;
const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
const editItemsData = <?= json_encode(array_map(function ($item) {
    return [
        'product_id' => $item['product_id'],
        'name' => $item['product_name'] ?? 'Unknown Product',
        'sku' => $item['sku'] ?? '',
        'price' => floatval($item['unit_price'] ?? 0),
        'stock' => intval($item['stock_quantity'] ?? 0),
        'quantity' => intval($item['quantity'] ?? 1),
        'discount' => floatval($item['discount'] ?? 0),
    ];
}, $editItems)) ?>;
let items = [];
let itemIndex = 0;
let currentGrandTotal = 0;

const categorySelect = document.getElementById('categorySelect');
const productSelect = document.getElementById('productSelect');
const productOptions = Array.from(productSelect.querySelectorAll('option[data-id]')).map(option => ({
    id: option.value,
    category: option.dataset.category || '',
    name: option.dataset.name,
    sku: option.dataset.sku,
    price: parseFloat(option.dataset.price),
    stock: parseInt(option.dataset.stock, 10),
    label: option.textContent.trim()
}));

categorySelect.addEventListener('change', function () {
    const categoryId = this.value;
    productSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = categoryId ? '-- Select Product --' : '-- Select a category first --';
    productSelect.appendChild(placeholder);
    productSelect.disabled = !categoryId;

    if (!categoryId) {
        return;
    }

    productOptions
        .filter(product => product.category === categoryId)
        .forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.dataset.name = product.name;
            option.dataset.sku = product.sku;
            option.dataset.price = product.price;
            option.dataset.stock = product.stock;
            option.textContent = product.label;
            productSelect.appendChild(option);
        });
});

productSelect.addEventListener('change', function() {
    if (this.value) {
        const opt = this.selectedOptions[0];
        addItem({ id: this.value, name: opt.dataset.name, sku: opt.dataset.sku, price: parseFloat(opt.dataset.price), stock: parseInt(opt.dataset.stock, 10) });
        this.value = '';
    }
});

function addItem(product) {
    const existing = items.findIndex(i => i.product_id == product.id);
    if (existing >= 0) {
        const row = document.querySelector(`tr[data-index="${existing}"]`);
        const qtyInput = row.querySelector('.qty-input');
        if (parseInt(qtyInput.value) < product.stock) { qtyInput.value = parseInt(qtyInput.value) + 1; items[existing].quantity++; updateRowTotal(existing); }
        return;
    }

    document.getElementById('noItemsRow').style.display = 'none';
    const initialQty = parseInt(product.quantity || 1, 10);
    const initialDiscount = parseFloat(product.discount || 0);
    const item = { index: itemIndex, product_id: product.id, name: product.name, sku: product.sku, price: product.price, stock: product.stock, quantity: initialQty, discount: initialDiscount };
    items.push(item);

    const row = document.createElement('tr');
    row.dataset.index = itemIndex;
    row.innerHTML = `<td>${itemIndex + 1}</td><td><strong>${product.name}</strong><br><small class="text-muted">${product.sku}</small><input type="hidden" name="items[${itemIndex}][product_id]" value="${product.id}"></td><td><span class="badge bg-secondary">${product.stock}</span></td><td><input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm qty-input" value="${initialQty}" min="1" max="${product.stock}" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][price]" class="form-control form-control-sm price-input" value="${parseFloat(product.price).toFixed(2)}" min="0" step="0.01" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][discount]" class="form-control form-control-sm discount-input" value="${initialDiscount > 0 ? initialDiscount : ''}" min="0" step="0.01" data-index="${itemIndex}"></td><td class="row-total">${currency} ${(initialQty * Math.max(parseFloat(product.price) - initialDiscount, 0)).toFixed(2)}</td><td><button type="button" class="btn btn-sm btn-danger remove-item" data-index="${itemIndex}"><i class="bi bi-trash"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(row);
    itemIndex++;
    updateTotals();
}

document.getElementById('itemsBody').addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('price-input') || e.target.classList.contains('discount-input')) {
        updateRowTotal(parseInt(e.target.dataset.index));
    }
});

document.getElementById('itemsBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        const index = parseInt(e.target.closest('.remove-item').dataset.index);
        document.querySelector(`tr[data-index="${index}"]`).remove();
        items = items.filter(i => i.index !== index);
        if (items.length === 0) document.getElementById('noItemsRow').style.display = '';
        updateTotals();
    }
});

function updateRowTotal(index) {
    const row = document.querySelector(`tr[data-index="${index}"]`);
    const qty = parseInt(row.querySelector('.qty-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const discountInput = row.querySelector('.discount-input');
    let discount = parseFloat(discountInput.value) || 0;
    discount = Math.min(Math.max(discount, 0), price);
    discountInput.value = discount > 0 ? String(discount) : '';
    const lineTotal = qty * Math.max(price - discount, 0);
    row.querySelector('.row-total').textContent = `${currency} ${lineTotal.toFixed(2)}`;
    const idx = items.findIndex(i => i.index === index);
    if (idx >= 0) { items[idx].quantity = qty; items[idx].price = price; items[idx].discount = discount; }
    updateTotals();
}

function updateTotals() {
    let subtotal = 0;
    items.forEach(item => {
        const unitDiscount = Math.min(Math.max(item.discount || 0, 0), item.price || 0);
        subtotal += item.quantity * Math.max((item.price || 0) - unitDiscount, 0);
    });
    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const normalizedDiscountPercent = Math.max(0, Math.min(100, discountPercent));
    const discount = (subtotal * normalizedDiscountPercent) / 100;
    const tax = ((subtotal - discount) * taxRate) / 100;
    const grandTotal = subtotal - discount + tax;
    currentGrandTotal = grandTotal;
    const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
    const balance = paid - grandTotal;
    
    document.getElementById('subtotal').textContent = `${currency} ${subtotal.toFixed(2)}`;
    document.getElementById('taxAmount').textContent = `${currency} ${tax.toFixed(2)}`;
    document.getElementById('grandTotal').textContent = `${currency} ${grandTotal.toFixed(2)}`;
    const balanceElement = document.getElementById('balanceAmount');
    balanceElement.textContent = `${currency} ${balance.toFixed(2)}`;
    balanceElement.classList.remove('text-danger', 'text-success');
    balanceElement.classList.add(balance >= 0 ? 'text-success' : 'text-danger');
    document.getElementById('saveBillBtn').disabled = items.length === 0;
}

document.getElementById('discountPercent').addEventListener('input', updateTotals);
document.getElementById('paidAmount').addEventListener('input', updateTotals);
document.getElementById('payFullBtn').addEventListener('click', function () {
    document.getElementById('paidAmount').value = currentGrandTotal.toFixed(2);
    updateTotals();
});

if (isEditMode && Array.isArray(editItemsData) && editItemsData.length > 0) {
    document.getElementById('noItemsRow').style.display = 'none';
    editItemsData.forEach(item => {
        addItem({
            id: item.product_id,
            name: item.name,
            sku: item.sku,
            price: parseFloat(item.price),
            stock: parseInt(item.stock, 10),
            quantity: parseInt(item.quantity, 10),
            discount: parseFloat(item.discount)
        });
    });
}

updateTotals();
</script>

<?php elseif ($action === 'view'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.*, c.name as customer_name, c.phone as customer_phone, u.name as user_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo '<div class="alert alert-danger">Bill not found</div>'; include 'footer.php'; exit; }

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$showDiscountColumn = false;
foreach ($items as $item) {
    if (floatval($item['discount'] ?? 0) > 0) {
        $showDiscountColumn = true;
        break;
    }
}

$billDiscountPercent = floatval($bill['subtotal']) > 0 ? (floatval($bill['discount_amount']) / floatval($bill['subtotal'])) * 100 : 0;
?>

<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Bill created successfully!</div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Bill - <?= htmlspecialchars($bill['bill_number']) ?></h4>
    <div>
        <a href="?page=retail&action=print&id=<?= $bill['id'] ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-2"></i>Print</a>
        <a href="?page=retail" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <span><i class="bi bi-receipt me-2"></i>Bill Information</span>
                <span class="badge bg-light text-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?> fs-6"><?= ucfirst($bill['payment_status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3"><label class="text-muted small">Bill Number</label><p class="fw-bold"><?= htmlspecialchars($bill['bill_number']) ?></p></div>
                    <div class="col-md-3"><label class="text-muted small">Type</label><p><span class="badge bg-primary"><?= ucfirst($bill['type']) ?></span></p></div>
                    <div class="col-md-3"><label class="text-muted small">Date</label><p><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></p></div>
                    <div class="col-md-3"><label class="text-muted small">Customer</label><p><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></p></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>Bill Items</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark"><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-end">Price</th><?php if ($showDiscountColumn): ?><th class="text-end">Discount</th><?php endif; ?><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($item['product_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small></td>
                            <td class="text-center"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['unit_price'], 2) ?></td>
                            <?php if ($showDiscountColumn): ?>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['discount'], 2) ?></td>
                            <?php endif; ?>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-calculator me-2"></i>Bill Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($bill['subtotal'], 2) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span>Discount (<?= number_format($billDiscountPercent, 2) ?>%):</span><span class="text-danger">- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span>Tax:</span><span><?= $currency ?> <?= number_format($bill['tax_amount'], 2) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></strong></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><span>Paid:</span><span class="text-success"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between"><strong>Balance:</strong><strong class="<?= ($bill['paid_amount'] - $bill['total_amount']) >= 0 ? 'text-success' : 'text-danger' ?>"><?= $currency ?> <?= number_format($bill['paid_amount'] - $bill['total_amount'], 2) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'print'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.*, c.name as customer_name, c.phone as customer_phone FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo 'Bill not found'; exit; }

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$showDiscountColumn = false;
foreach ($items as $item) {
    if (floatval($item['discount'] ?? 0) > 0) {
        $showDiscountColumn = true;
        break;
    }
}

$billDiscountPercent = floatval($bill['subtotal']) > 0 ? (floatval($bill['discount_amount']) / floatval($bill['subtotal'])) * 100 : 0;
$brandLogoPath = 'WhatsApp Image 2026-03-30 at 21.39.04.jpeg';
$brandLogoSrc = str_replace(' ', '%20', $brandLogoPath);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Invoice - <?= htmlspecialchars($bill['bill_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; padding: 20px; }
        .invoice { max-width: 800px; margin: 0 auto; }
        .header { border-bottom: 2px solid #333; padding-bottom: 12px; margin-bottom: 20px; }
        .letterhead { display: grid; grid-template-columns: 130px 1fr 130px; column-gap: 16px; align-items: center; }
        .logo-wrap img { width: 130px; height: 130px; object-fit: cover; border-radius: 50%; border: 1px solid #444; }
        .logo-spacer { width: 130px; height: 130px; visibility: hidden; }
        .brand-block { flex: 1; text-align: center; }
        .brand-title { font-size: 40px; font-weight: 800; letter-spacing: 1px; line-height: 1.05; }
        .brand-line { font-size: 16px; line-height: 1.3; margin-top: 3px; }
        .reg-line { margin-top: 8px; font-weight: 600; text-align: center; }
        .invoice-tag { margin-top: 10px; display: inline-block; background: #333; color: #fff; padding: 4px 14px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #333; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .totals { float: right; width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .grand-total { font-size: 16px; font-weight: bold; border-top: 2px solid #333; }
        .footer { clear: both; margin-top: 52px; text-align: center; font-size: 10px; color: #666; line-height: 1.6; }
        .footer-divider { border-top: 1px solid #cbd5e1; width: 70%; margin: 10px auto; }
        .footer-main { font-weight: 600; margin: 0; }
        .footer-sub { margin: 0; }
        .footer-credit { margin-top: 10px; font-size: 9.5px; color: #4b5563; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="header">
            <div class="letterhead">
                <div class="logo-wrap"><img src="<?= htmlspecialchars($brandLogoSrc) ?>" alt="Sriram Fireworks Logo"></div>
                <div class="brand-block">
                    <div class="brand-title">SRIRAM FIREWORKS</div>
                    <div class="brand-line">No. 319, Galmankada, Kimbulapitiya.</div>
                    <div class="brand-line">Prop : K.S.S.K. Fernando. Tel : 077 877 92 71</div>
                    <div class="brand-line reg-line">Reg : No WAA/4327</div>
                </div>
                <div class="logo-spacer" aria-hidden="true"></div>
            </div>
            <div style="text-align:center"><span class="invoice-tag">RETAIL INVOICE</span></div>
        </div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:20px">
            <div><strong>Bill To:</strong><br><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in Customer') ?></div>
            <div style="text-align:right"><strong>Invoice:</strong> <?= htmlspecialchars($bill['bill_number']) ?><br><strong>Date:</strong> <?= date('d M Y', strtotime($bill['created_at'])) ?></div>
        </div>

        <table>
            <thead><tr><th>#</th><th>Product</th><th>Qty</th><th>Price</th><?php if ($showDiscountColumn): ?><th>Discount</th><?php endif; ?><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($item['product_name']) ?></td><td><?= $item['quantity'] ?></td><td><?= $currency ?> <?= number_format($item['unit_price'], 2) ?></td><?php if ($showDiscountColumn): ?><td><?= $currency ?> <?= number_format($item['discount'], 2) ?></td><?php endif; ?><td><?= $currency ?> <?= number_format($item['total'], 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($bill['subtotal'], 2) ?></span></div>
            <?php if (floatval($bill['discount_amount']) > 0): ?>
            <div class="totals-row"><span>Discount (<?= number_format($billDiscountPercent, 2) ?>%):</span><span>- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row grand-total"><span>Grand Total:</span><span><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Paid:</span><span><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Balance:</span><span><?= $currency ?> <?= number_format($bill['paid_amount'] - $bill['total_amount'], 2) ?></span></div>
        </div>

        <div class="footer">
            <div class="footer-divider"></div>
            <p class="footer-main">Thanks for choosing us!</p>
            <div class="footer-divider"></div>
            <p class="footer-sub">Light safe, stay safe. Please read all instructions before use.</p>
            <p class="footer-credit">System by - BluePeak Systems</p>
        </div>
        <div class="no-print" style="text-align:center; margin-top:20px"><button onclick="window.print()" style="padding:10px 30px; background:#333; color:white; border:none; border-radius:5px; cursor:pointer">Print Invoice</button></div>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php include 'footer.php'; ?>
