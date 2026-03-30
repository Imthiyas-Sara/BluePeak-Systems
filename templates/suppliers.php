<?php
$action = $_GET['action'] ?? 'index';

$statusClass = [
    'Confirmed' => 'success',
    'Pending' => 'warning',
    'Received' => 'primary',
];

function parseCsvValues(string $value): array {
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts, function ($v) {
        return $v !== '';
    }));
}

function buildSupplierItemsFromPost(): array {
    $items = parseCsvValues($_POST['items'] ?? '');
    $quantities = parseCsvValues($_POST['quantities'] ?? '');
    $unitPrices = parseCsvValues($_POST['unit_prices'] ?? '');

    $result = [];
    foreach ($items as $idx => $itemName) {
        $qty = isset($quantities[$idx]) ? (int) $quantities[$idx] : 0;
        $price = isset($unitPrices[$idx]) ? (float) $unitPrices[$idx] : 0;
        $result[] = [
            'item_name' => $itemName,
            'quantity' => max(0, $qty),
            'unit_price' => max(0, $price),
        ];
    }

    return $result;
}

if ($action === 'download_report') {
    $currency = $settings['currency_symbol'] ?? 'LKR';
    $rows = $pdo->query("SELECT s.supplier_code, s.name, s.status, s.order_date, s.created_at, si.item_name, si.quantity, si.unit_price FROM suppliers s LEFT JOIN supplier_items si ON si.supplier_id = s.id ORDER BY s.created_at DESC, s.id DESC, si.id ASC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);

    $drawText = function ($x, $y, $text, $fontSize, $bold) {
        $safe = str_replace(['\\', '(', ')', "\r", "\n", "\t"], ['\\\\', '\\(', '\\)', ' ', ' ', ' '], (string) $text);
        $font = $bold ? '/F2' : '/F1';
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm ({$safe}) Tj\nET\n";
    };

    $drawRightText = function ($xRight, $y, $text, $fontSize, $bold) {
        $safe = str_replace(['\\', '(', ')', "\r", "\n", "\t"], ['\\\\', '\\(', '\\)', ' ', ' ', ' '], (string) $text);
        $font = $bold ? '/F2' : '/F1';
        $charWidth = $fontSize * 0.52;
        $textWidth = strlen((string) $text) * $charWidth;
        $x = $xRight - $textWidth;
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm ({$safe}) Tj\nET\n";
    };

    $trimCell = function ($text, $maxChars) {
        $text = trim((string) $text);
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, max(0, $maxChars - 3)) . '...';
    };

    $reportRows = [];
    $supplierCountSet = [];
    $overallValue = 0.0;

    foreach ($rows as $row) {
        $supplierCode = $row['supplier_code'] ?? '';
        $supplierName = $row['name'] ?? '';
        $status = $row['status'] ?? 'Pending';
        $orderDate = !empty($row['order_date']) ? date('d M Y', strtotime($row['order_date'])) : '-';
        $itemName = $row['item_name'] ?? '';
        $qty = (int) ($row['quantity'] ?? 0);
        $unit = (float) ($row['unit_price'] ?? 0);
        $lineTotal = $qty * $unit;

        if (!empty($supplierCode)) {
            $supplierCountSet[$supplierCode] = true;
        }
        $overallValue += $lineTotal;

        $reportRows[] = [
            'supplier' => $trimCell($supplierCode . ' - ' . $supplierName, 28),
            'item' => $trimCell($itemName !== '' ? $itemName : '-', 22),
            'qty' => $qty > 0 ? (string) $qty : '-',
            'unit' => $currency . ' ' . number_format($unit, 2),
            'total' => $currency . ' ' . number_format($lineTotal, 2),
            'status' => $trimCell($status, 10),
            'date' => $orderDate,
        ];
    }

    $rowsPerPage = 28;
    $pages = array_chunk($reportRows, $rowsPerPage);
    if (empty($pages)) {
        $pages = [[]];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $pageRefs = [];
    $nextObj = 5;

    $pageWidth = 842;
    $margin = 26;
    $tableWidth = $pageWidth - ($margin * 2);
    $columns = [
        ['key' => 'supplier', 'label' => 'Supplier', 'width' => 200, 'align' => 'L'],
        ['key' => 'item', 'label' => 'Item', 'width' => 145, 'align' => 'L'],
        ['key' => 'qty', 'label' => 'Qty', 'width' => 45, 'align' => 'R'],
        ['key' => 'unit', 'label' => 'Unit Price', 'width' => 115, 'align' => 'R'],
        ['key' => 'total', 'label' => 'Line Total', 'width' => 125, 'align' => 'R'],
        ['key' => 'status', 'label' => 'Status', 'width' => 75, 'align' => 'L'],
        ['key' => 'date', 'label' => 'Order Date', 'width' => 85, 'align' => 'L'],
    ];

    foreach ($pages as $pageIndex => $pageRows) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageRefs[] = $pageObj . ' 0 R';

        $content = '';
        $content .= "q\n0.07 0.38 0.73 rg\n{$margin} 558 {$tableWidth} 26 re f\nQ\n";
        $content .= $drawText($margin + 10, 567, 'SUPPLIER DETAILED REPORT', 12, true);
        $content .= $drawText($margin + 10, 548, 'Generated: ' . date('Y-m-d H:i:s'), 8.5, false);
        $content .= $drawText($margin + 250, 548, 'Suppliers: ' . count($supplierCountSet), 8.5, false);
        $content .= $drawText($margin + 360, 548, 'Rows: ' . count($reportRows), 8.5, false);
        $content .= $drawRightText($margin + $tableWidth, 548, 'Total Value: ' . $currency . ' ' . number_format($overallValue, 2), 8.5, true);

        $tableTop = 530;
        $headerHeight = 18;
        $rowHeight = 17;
        $content .= "q\n0.92 0.94 0.98 rg\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re f\nQ\n";
        $content .= "q\n0.72 0.78 0.90 RG\n0.8 w\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re S\nQ\n";

        $x = $margin;
        foreach ($columns as $col) {
            $content .= "q\n0.72 0.78 0.90 RG\n0.5 w\n{$x} " . ($tableTop - $headerHeight) . " 0 {$headerHeight} re S\nQ\n";
            $content .= $drawText($x + 4, $tableTop - 12, $col['label'], 8.5, true);
            $x += $col['width'];
        }
        $content .= "q\n0.72 0.78 0.90 RG\n0.5 w\n{$x} " . ($tableTop - $headerHeight) . " 0 {$headerHeight} re S\nQ\n";

        $y = $tableTop - $headerHeight;
        foreach ($pageRows as $idx => $dataRow) {
            $y -= $rowHeight;
            if ($idx % 2 === 0) {
                $content .= "q\n0.985 0.99 1 rg\n{$margin} {$y} {$tableWidth} {$rowHeight} re f\nQ\n";
            }
            $content .= "q\n0.86 0.89 0.95 RG\n0.4 w\n{$margin} {$y} {$tableWidth} {$rowHeight} re S\nQ\n";

            $x = $margin;
            foreach ($columns as $col) {
                $value = (string) ($dataRow[$col['key']] ?? '');
                if ($col['align'] === 'R') {
                    $content .= $drawRightText($x + $col['width'] - 4, $y + 5, $value, 8, false);
                } else {
                    $content .= $drawText($x + 4, $y + 5, $value, 8, false);
                }
                $x += $col['width'];
                $content .= "q\n0.86 0.89 0.95 RG\n0.4 w\n{$x} {$y} 0 {$rowHeight} re S\nQ\n";
            }
        }

        $content .= $drawRightText($margin + $tableWidth, 22, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 8, false);

        $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
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

    $fileName = 'supplier-detailed-report-' . date('Ymd-His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename=' . $fileName);
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $supplierCode = trim($_POST['supplier_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Pending';
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $items = buildSupplierItemsFromPost();

        if ($supplierCode !== '' && $name !== '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, name, status, order_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$supplierCode, $name, $status, $orderDate]);
                $supplierDbId = (int) $pdo->lastInsertId();

                if (!empty($items)) {
                    $itemStmt = $pdo->prepare("INSERT INTO supplier_items (supplier_id, item_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $itemStmt->execute([$supplierDbId, $item['item_name'], $item['quantity'], $item['unit_price']]);
                    }
                }

                $pdo->commit();
                header("Location: ?page=suppliers&success=1");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formError = 'Failed to add supplier. Please check supplier code uniqueness.';
            }
        } else {
            $formError = 'Supplier ID and Name are required.';
        }
    } elseif ($action === 'update') {
        $id = (int) ($_GET['id'] ?? 0);
        $supplierCode = trim($_POST['supplier_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Pending';
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $items = buildSupplierItemsFromPost();

        if ($id > 0 && $supplierCode !== '' && $name !== '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE suppliers SET supplier_code = ?, name = ?, status = ?, order_date = ? WHERE id = ?");
                $stmt->execute([$supplierCode, $name, $status, $orderDate, $id]);

                $pdo->prepare("DELETE FROM supplier_items WHERE supplier_id = ?")->execute([$id]);

                if (!empty($items)) {
                    $itemStmt = $pdo->prepare("INSERT INTO supplier_items (supplier_id, item_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $itemStmt->execute([$id, $item['item_name'], $item['quantity'], $item['unit_price']]);
                    }
                }

                $pdo->commit();
                header("Location: ?page=suppliers&success=2");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formError = 'Failed to update supplier. Please check supplier code uniqueness.';
            }
        } else {
            $formError = 'Supplier ID and Name are required.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
        }
        header("Location: ?page=suppliers&success=3");
        exit;
    }
}

include 'header.php';

if ($action === 'create' || $action === 'edit') {
    $supplier = null;
    $supplierItems = [];

    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$supplier) {
            echo '<div class="alert alert-danger">Supplier not found</div>';
            include 'footer.php';
            exit;
        }

        $itemStmt = $pdo->prepare("SELECT item_name, quantity, unit_price FROM supplier_items WHERE supplier_id = ? ORDER BY id");
        $itemStmt->execute([$id]);
        $supplierItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $itemsText = '';
    $quantitiesText = '';
    $unitPricesText = '';

    if (!empty($supplierItems)) {
        $itemsText = implode(', ', array_column($supplierItems, 'item_name'));
        $quantitiesText = implode(', ', array_map('intval', array_column($supplierItems, 'quantity')));
        $unitPricesText = implode(', ', array_map(function ($v) {
            return (float) $v;
        }, array_column($supplierItems, 'unit_price')));
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><?= $action === 'create' ? 'Add Supplier' : 'Edit Supplier' ?></h4>
        <a href="?page=suppliers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>

    <?php if (!empty($formError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="?page=suppliers&action=<?= $action === 'create' ? 'store' : 'update' ?>&id=<?= (int) ($supplier['id'] ?? 0) ?>">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Supplier ID *</label>
                            <input type="text" name="supplier_id" class="form-control" required value="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($supplier['name'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="Pending" <?= ($supplier['status'] ?? 'Pending') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Confirmed" <?= ($supplier['status'] ?? '') === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <option value="Received" <?= ($supplier['status'] ?? '') === 'Received' ? 'selected' : '' ?>>Received</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Order Date</label>
                            <input type="date" name="order_date" class="form-control" value="<?= htmlspecialchars($supplier['order_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Items (comma separated)</label>
                            <input type="text" name="items" class="form-control" value="<?= htmlspecialchars($itemsText) ?>" placeholder="e.g. Color Sparklers, Flower Pots">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantities (comma separated)</label>
                            <input type="text" name="quantities" class="form-control" value="<?= htmlspecialchars($quantitiesText) ?>" placeholder="e.g. 120,80,150">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Prices (comma separated)</label>
                            <input type="text" name="unit_prices" class="form-control" value="<?= htmlspecialchars($unitPricesText) ?>" placeholder="e.g. 180,250,90">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save</button>
            </form>
        </div>
    </div>
    <?php
} elseif ($action === 'view') {
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        echo '<div class="alert alert-danger">Supplier not found</div>';
        include 'footer.php';
        exit;
    }

    $itemStmt = $pdo->prepare("SELECT item_name, quantity, unit_price FROM supplier_items WHERE supplier_id = ? ORDER BY id");
    $itemStmt->execute([$id]);
    $supplierItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">View Supplier</h4>
        <a href="?page=suppliers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Supplier Information</h6>
                    <p><strong>ID:</strong> <?= htmlspecialchars($supplier['supplier_code']) ?></p>
                    <p><strong>Name:</strong> <?= htmlspecialchars($supplier['name']) ?></p>
                    <p><strong>Status:</strong> <span class="badge bg-<?= $statusClass[$supplier['status']] ?? 'secondary' ?>"><?= htmlspecialchars($supplier['status']) ?></span></p>
                    <p><strong>Order Date:</strong> <?= $supplier['order_date'] ? date('d M Y', strtotime($supplier['order_date'])) : '-' ?></p>
                </div>
                <div class="col-md-6">
                    <h6>Supply Items</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total = 0;
                            foreach ($supplierItems as $item):
                                $qty = (int) $item['quantity'];
                                $price = (float) $item['unit_price'];
                                $itemTotal = $qty * $price;
                                $total += $itemTotal;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($item['item_name']) ?></td>
                                <td><?= $qty ?></td>
                                <td>LKR <?= number_format($price, 2) ?></td>
                                <td>LKR <?= number_format($itemTotal, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-active">
                                <td colspan="3"><strong>Total Value</strong></td>
                                <td><strong>LKR <?= number_format($total, 2) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
} else {
    if (isset($_GET['success'])) {
        $messages = [
            1 => 'Supplier added successfully',
            2 => 'Supplier updated successfully',
            3 => 'Supplier deleted successfully'
        ];
        echo '<div class="alert alert-success">' . ($messages[$_GET['success']] ?? 'Operation completed') . '</div>';
    }

    $summary = $pdo->query("SELECT COUNT(*) AS total_suppliers, SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_orders, SUM(CASE WHEN status <> 'Received' THEN 1 ELSE 0 END) AS pending_deliveries FROM suppliers")->fetch(PDO::FETCH_ASSOC);

    $supplierRows = $pdo->query("SELECT s.*, si.item_name, si.quantity, si.unit_price FROM suppliers s LEFT JOIN supplier_items si ON si.supplier_id = s.id ORDER BY s.created_at DESC, si.id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $suppliers = [];
    foreach ($supplierRows as $row) {
        $supplierId = (int) $row['id'];
        if (!isset($suppliers[$supplierId])) {
            $suppliers[$supplierId] = [
                'id' => $supplierId,
                'supplier_code' => $row['supplier_code'],
                'name' => $row['name'],
                'status' => $row['status'],
                'order_date' => $row['order_date'],
                'items' => [],
                'quantity' => [],
                'unit_price' => [],
            ];
        }

        if (!empty($row['item_name'])) {
            $suppliers[$supplierId]['items'][] = $row['item_name'];
            $suppliers[$supplierId]['quantity'][] = (int) $row['quantity'];
            $suppliers[$supplierId]['unit_price'][] = (float) $row['unit_price'];
        }
    }
    ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Manage Suppliers</h4>
    <div class="d-flex gap-2">
        <a href="?page=suppliers&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Supplier</a>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white border-0 pt-4 px-4">
        <div class="row text-center">
            <div class="col-md-4">
                <h6 class="text-muted mb-1">Total Suppliers</h6>
                <h3 class="mb-0 text-primary"><?= (int) ($summary['total_suppliers'] ?? 0) ?></h3>
            </div>
            <div class="col-md-4">
                <h6 class="text-muted mb-1">Confirmed Orders</h6>
                <h3 class="mb-0 text-success"><?= (int) ($summary['confirmed_orders'] ?? 0) ?></h3>
            </div>
            <div class="col-md-4">
                <h6 class="text-muted mb-1">Pending Deliveries</h6>
                <h3 class="mb-0 text-warning"><?= (int) ($summary['pending_deliveries'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="card-body pt-3">
        <div class="table-responsive">
            <table class="table align-middle table-striped">
                <thead class="table-light">
                    <tr>
                        <th>Supplier ID</th>
                        <th>Name</th>
                        <th>Supplying Items</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Order Status</th>
                        <th>Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?= htmlspecialchars($supplier['supplier_code']) ?></td>
                        <td><?= htmlspecialchars($supplier['name']) ?></td>
                        <td><?php foreach ($supplier['items'] as $item): ?><div><?= htmlspecialchars($item) ?></div><?php endforeach; ?></td>
                        <td><?php foreach ($supplier['quantity'] as $qty): ?><div><?= $qty ?></div><?php endforeach; ?></td>
                        <td><?php foreach ($supplier['unit_price'] as $price): ?><div>LKR <?= number_format($price, 2) ?></div><?php endforeach; ?></td>
                        <td><span class="badge bg-<?= $statusClass[$supplier['status']] ?? 'secondary' ?>"><?= htmlspecialchars($supplier['status']) ?></span></td>
                        <td><?= $supplier['order_date'] ? date('d M Y', strtotime($supplier['order_date'])) : '-' ?></td>
                        <td class="text-center text-nowrap">
                            <a href="?page=suppliers&action=view&id=<?= (int) $supplier['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="?page=suppliers&action=edit&id=<?= (int) $supplier['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="?page=suppliers&action=delete" style="display:inline;">
                                <input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this supplier?')"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No suppliers found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-4">
            <a class="btn btn-primary" href="?page=suppliers&action=download_report"><i class="bi bi-download me-2"></i>Download Report</a>
        </div>
    </div>
</div>
<?php
}

include 'footer.php';
?>