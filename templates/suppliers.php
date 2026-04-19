<?php
$action = $_GET['action'] ?? 'index';

$statusClass = [
    'Confirmed' => 'success',
    'Pending' => 'warning',
    'Received' => 'primary',
];

function parseCsvValues($value): array {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = array_map('trim', explode(',', $value));
    }

    return array_values(array_filter($parts, function ($v) {
        return $v !== '';
    }));
}

function buildSupplierItemsFromPost(): array {
    $items = parseCsvValues($_POST['items'] ?? []);
    $quantities = parseCsvValues($_POST['quantities'] ?? []);
    $unitPrices = parseCsvValues($_POST['unit_prices'] ?? []);

    $result = [];
    foreach ($items as $idx => $itemName) {
        $qty = isset($quantities[$idx]) ? (int) $quantities[$idx] : 0;
        $price = isset($unitPrices[$idx]) ? (float) $unitPrices[$idx] : 0;
        if ($itemName === '') {
            continue;
        }
        $result[] = [
            'item_name' => $itemName,
            'quantity' => max(0, $qty),
            'unit_price' => max(0, $price),
        ];
    }

    return $result;
}

if ($action === 'download_report') {
    include 'header.php';
    $currency = $settings['currency_symbol'] ?? 'LKR';

    $rows = $pdo->query("SELECT s.id, s.supplier_code, s.name, s.status, s.order_date, s.advance_paid, si.quantity, si.unit_price FROM suppliers s LEFT JOIN supplier_items si ON si.supplier_id = s.id ORDER BY s.created_at DESC, s.id DESC, si.id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $suppliers = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($suppliers[$id])) {
            $suppliers[$id] = [
                'supplier_code' => $row['supplier_code'],
                'name' => $row['name'],
                'status' => $row['status'] ?? 'Pending',
                'order_date' => $row['order_date'],
                'advance_paid' => isset($row['advance_paid']) ? (float) $row['advance_paid'] : 0.0,
                'total' => 0.0,
            ];
        }

        $qty = (int) ($row['quantity'] ?? 0);
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $suppliers[$id]['total'] += $qty * $unitPrice;
    }

    $totalPayment = 0.0;
    $totalDue = 0.0;
    foreach ($suppliers as $supplier) {
        $due = max(0, $supplier['total'] - $supplier['advance_paid']);
        $totalPayment += $supplier['total'];
        $totalDue += $due;
    }
    ?>
    <div class="card">
        <div class="card-body">
            <div class="text-center mb-4">
                <h2 class="text-primary">Supplier Report</h2>
                <p class="text-muted">Generated on <?= date('d/m/Y') ?></p>
            </div>
            <p class="text-muted">This report includes only Supplier ID, Name, Order Status, Total Payment, Due Payment, and Date.</p>
            <div class="table-responsive mt-4">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier ID</th>
                            <th>Name</th>
                            <th>Order Status</th>
                            <th class="text-end">Total Payment</th>
                            <th class="text-end">Due Payment</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                        <?php $due = max(0, $supplier['total'] - $supplier['advance_paid']); ?>
                        <tr>
                            <td><?= htmlspecialchars($supplier['supplier_code']) ?></td>
                            <td><?= htmlspecialchars($supplier['name']) ?></td>
                            <td><?= htmlspecialchars($supplier['status']) ?></td>
                            <td class="text-end"><?= htmlspecialchars($currency) ?> <?= number_format($supplier['total'], 2) ?></td>
                            <td class="text-end"><?= htmlspecialchars($currency) ?> <?= number_format($due, 2) ?></td>
                            <td><?= $supplier['order_date'] ? date('d/m/Y', strtotime($supplier['order_date'])) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <th colspan="3">TOTAL</th>
                            <th class="text-end"><?= htmlspecialchars($currency) ?> <?= number_format($totalPayment, 2) ?></th>
                            <th class="text-end"><?= htmlspecialchars($currency) ?> <?= number_format($totalDue, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-4">
                <a href="?page=suppliers" class="btn btn-secondary">Back to Suppliers</a>
                <button onclick="window.print()" class="btn btn-primary">Print Report</button>
            </div>
        </div>
    </div>
    <?php
    include 'footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $supplierCode = trim($_POST['supplier_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Pending';
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $advancePaid = isset($_POST['advance_paid']) ? (float) $_POST['advance_paid'] : 0.0;
        $items = buildSupplierItemsFromPost();

        if ($supplierCode !== '' && $name !== '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, name, status, order_date, advance_paid) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$supplierCode, $name, $status, $orderDate, $advancePaid]);
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
                if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                    $formError = 'Supplier ID already exists. Please use a different Supplier ID.';
                } else {
                    $formError = 'Failed to add supplier. Please try again.';
                }
                $action = 'create';
            }
        } else {
            $formError = 'Supplier ID and Name are required.';
            $action = 'create';
        }
    } elseif ($action === 'update') {
        $id = (int) ($_GET['id'] ?? 0);
        $supplierCode = trim($_POST['supplier_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Pending';
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $advancePaid = isset($_POST['advance_paid']) ? (float) $_POST['advance_paid'] : 0.0;
        $items = buildSupplierItemsFromPost();

        if ($id > 0 && $supplierCode !== '' && $name !== '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE suppliers SET supplier_code = ?, name = ?, status = ?, order_date = ?, advance_paid = ? WHERE id = ?");
                $stmt->execute([$supplierCode, $name, $status, $orderDate, $advancePaid, $id]);

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
                if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                    $formError = 'Supplier ID already exists. Please use a different Supplier ID.';
                } else {
                    $formError = 'Failed to update supplier. Please try again.';
                }
                $action = 'edit';
            }
        } else {
            $formError = 'Supplier ID and Name are required.';
            $action = 'edit';
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

    // If there was an error during store/update, preserve the submitted data
    if (!empty($formError) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $supplier = [
            'supplier_code' => $_POST['supplier_id'] ?? '',
            'name' => $_POST['name'] ?? '',
            'status' => $_POST['status'] ?? 'Pending',
            'order_date' => $_POST['order_date'] ?? date('Y-m-d'),
            'advance_paid' => $_POST['advance_paid'] ?? '0',
            'id' => (int) ($_GET['id'] ?? 0),
        ];
        $supplierItems = buildSupplierItemsFromPost();
    } elseif ($action === 'edit') {
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
        <h4 class="mb-0"><?= $action === 'create' ? 'Add New Supplier' : 'Edit Supplier' ?></h4>
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
                            <input type="text" name="supplier_id" class="form-control" required minlength="2" maxlength="50" pattern="[A-Za-z0-9\-_]+" title="Supplier ID must be 2-50 characters and contain only letters, numbers, hyphens, or underscores" value="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Supplier Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($supplier['name'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Supplying Items</label>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mb-0" id="supplierItemsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item name</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th class="text-center">Remove</th>
                                        </tr>
                                    </thead>
                                    <tbody id="supplierItemsBody">
                                        <?php if (!empty($supplierItems)): ?>
                                            <?php foreach ($supplierItems as $item): ?>
                                            <tr>
                                                <td><input type="text" name="items[]" class="form-control" value="<?= htmlspecialchars($item['item_name']) ?>" placeholder="Item name"></td>
                                                <td><input type="number" name="quantities[]" class="form-control" min="0" step="1" value="<?= (int) $item['quantity'] ?>"></td>
                                                <td><input type="text" name="unit_prices[]" class="form-control" pattern="^\d+(\.\d{1,2})?$" title="Enter a valid price (e.g., 30.00)" value="<?= number_format((float) $item['unit_price'], 2, '.', '') ?>" placeholder="0.00"></td>
                                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSupplierItemRow(this)">Remove</button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td><input type="text" name="items[]" class="form-control" placeholder="Item name"></td>
                                                <td><input type="number" name="quantities[]" class="form-control" min="0" step="1" value="0"></td>
                                                <td><input type="text" name="unit_prices[]" class="form-control" pattern="^\d+(\.\d{1,2})?$" title="Enter a valid price (e.g., 30.00)" value="0.00" placeholder="0.00"></td>
                                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSupplierItemRow(this)">Remove</button></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addSupplierItemRow()">Add Item</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="Pending" <?= ($supplier['status'] ?? 'Pending') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Confirmed" <?= ($supplier['status'] ?? '') === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                    <option value="Received" <?= ($supplier['status'] ?? '') === 'Received' ? 'selected' : '' ?>>Received</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date</label>
                                <input type="date" name="order_date" class="form-control" value="<?= htmlspecialchars($supplier['order_date'] ?? date('Y-m-d')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Advance Paid</label>
                                <input type="number" name="advance_paid" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($supplier['advance_paid'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="?page=suppliers" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Supplier</button>
                </div>
            </form>

            <script>
                function addSupplierItemRow(name = '', qty = 0, price = 0) {
                    const tbody = document.getElementById('supplierItemsBody');
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><input type="text" name="items[]" class="form-control" value="${name}" placeholder="Item name"></td>
                        <td><input type="number" name="quantities[]" class="form-control" min="0" step="1" value="${qty}"></td>
                        <td><input type="text" name="unit_prices[]" class="form-control" pattern="^\d+(\.\d{1,2})?$" title="Enter a valid price (e.g., 30.00)" value="${price}" placeholder="0.00"></td>
                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSupplierItemRow(this)">Remove</button></td>
                    `;
                    tbody.appendChild(row);
                }

                function removeSupplierItemRow(button) {
                    const row = button.closest('tr');
                    if (!row) return;
                    const tbody = document.getElementById('supplierItemsBody');
                    if (tbody.querySelectorAll('tr').length === 1) {
                        row.querySelectorAll('input').forEach(input => input.value = input.type === 'number' ? '0' : '');
                        return;
                    }
                    row.remove();
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const tbody = document.getElementById('supplierItemsBody');
                    if (!tbody || tbody.querySelectorAll('tr').length === 0) {
                        addSupplierItemRow();
                    }
                });
            </script>
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
        <h4 class="mb-0">Supplier Details</h4>
        <a href="?page=suppliers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>

    <div class="supplier-detail-overlay">
        <div class="supplier-detail-modal">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0">Supplier Details</h4>
                <a href="?page=suppliers" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="row align-items-center mb-4">
                <div class="col-md-6">
                    <h6 class="mb-1">Supplier ID: <span class="fw-semibold"><?= htmlspecialchars($supplier['supplier_code']) ?></span></h6>
                </div>
                <div class="col-md-6 text-md-end">
                    <h6 class="mb-1">Name: <span class="fw-semibold"><?= htmlspecialchars($supplier['name']) ?></span></h6>
                </div>
            </div>

            <hr>

            <h6 class="mb-3">Supplying Items:</h6>
            <div class="table-responsive mb-4">
                <table class="table table-borderless mb-0">
                    <thead>
                        <tr class="border-bottom">
                            <th>Item</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
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
                            <td class="text-end"><?= $qty ?></td>
                            <td class="text-end">LKR <?= number_format($price, 2) ?></td>
                            <td class="text-end">LKR <?= number_format($itemTotal, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $advancePaid = isset($supplier['advance_paid']) ? (float) $supplier['advance_paid'] : 0.00; ?>
            <?php $balanceDue = $total - $advancePaid; ?>

            <div class="row mb-4">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Total Amount:</strong> LKR <?= number_format($total, 2) ?></p>
                    <p class="mb-0"><strong>Advance Paid:</strong> LKR <?= number_format($advancePaid, 2) ?></p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-1"><strong>Balance Due:</strong> LKR <?= number_format($balanceDue, 2) ?></p>
                </div>
            </div>

            <hr>

            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0"><strong>Status:</strong> <span class="badge bg-<?= $statusClass[$supplier['status']] ?? 'secondary' ?>"><?= htmlspecialchars($supplier['status']) ?></span></p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0"><strong>Date:</strong> <?= $supplier['order_date'] ? date('n/j/Y', strtotime($supplier['order_date'])) : '-' ?></p>
                </div>
            </div>
        </div>
    </div>
    <style>
        .supplier-detail-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 1050;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .supplier-detail-modal {
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 20px 55px rgba(0,0,0,0.15);
            max-width: 900px;
            width: 100%;
            padding: 2rem;
            overflow: auto;
            max-height: 90vh;
        }
        @media (max-width: 768px) {
            .supplier-detail-modal {
                padding: 1.25rem;
            }
        }
    </style>
    <?php
} elseif ($action === 'print') {
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        echo '<div class="alert alert-danger">Supplier not found</div>';
        exit;
    }

    $itemStmt = $pdo->prepare("SELECT item_name, quantity, unit_price FROM supplier_items WHERE supplier_id = ? ORDER BY id");
    $itemStmt->execute([$id]);
    $supplierItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAmount = 0;
    foreach ($supplierItems as $item) {
        $totalAmount += (int) $item['quantity'] * (float) $item['unit_price'];
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Supplier Invoice - <?= htmlspecialchars($supplier['supplier_code']) ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                background-color: #f5f5f5;
            }
            .invoice-container {
                max-width: 900px;
                margin: 0 auto;
                background-color: white;
                padding: 30px;
                border: 1px solid #ddd;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            .invoice-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
            }
            .invoice-header h1 {
                margin: 0;
                color: #333;
            }
            .invoice-header p {
                margin: 5px 0;
                color: #666;
            }
            .supplier-info {
                margin-bottom: 30px;
                background-color: #f9f9f9;
                padding: 15px;
                border-left: 4px solid #007bff;
            }
            .supplier-info h3 {
                margin-top: 0;
                color: #333;
            }
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            .info-item {
                margin-bottom: 8px;
            }
            .info-label {
                font-weight: bold;
                color: #555;
            }
            .info-value {
                color: #333;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
            }
            th {
                background-color: #007bff;
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: bold;
            }
            td {
                padding: 12px;
                border-bottom: 1px solid #ddd;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .text-right {
                text-align: right;
            }
            .summary-section {
                margin-top: 30px;
                padding: 20px;
                background-color: #f9f9f9;
                border: 1px solid #ddd;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 15px;
                font-size: 16px;
            }
            .summary-row.total {
                font-weight: bold;
                font-size: 18px;
                background-color: #e7f3ff;
                padding: 10px;
                border-radius: 4px;
                margin-top: 15px;
            }
            .print-button {
                text-align: center;
                margin-top: 30px;
                display: flex;
                gap: 10px;
                justify-content: center;
            }
            .print-button button,
            .print-button a {
                background-color: #007bff;
                color: white;
                border: none;
                padding: 12px 30px;
                font-size: 16px;
                cursor: pointer;
                border-radius: 4px;
                text-decoration: none;
                display: inline-block;
            }
            .print-button button:hover,
            .print-button a:hover {
                background-color: #0056b3;
            }
            .print-button a.back-btn {
                background-color: #6c757d;
            }
            .print-button a.back-btn:hover {
                background-color: #5a6268;
            }
            @media print {
                body {
                    background-color: white;
                    margin: 0;
                }
                .invoice-container {
                    box-shadow: none;
                    border: none;
                }
                .print-button {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <div class="invoice-header">
                <h1>SUPPLIER INVOICE</h1>
                <p>Invoice Date: <?= date('d M Y') ?></p>
            </div>

            <div class="supplier-info">
                <h3>Supplier Information</h3>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">Supplier ID:</span>
                            <span class="info-value"><?= htmlspecialchars($supplier['supplier_code']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Supplier Name:</span>
                            <span class="info-value"><?= htmlspecialchars($supplier['name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Order Status:</span>
                            <span class="info-value"><?= htmlspecialchars($supplier['status']) ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">Order Date:</span>
                            <span class="info-value"><?= $supplier['order_date'] ? date('d M Y', strtotime($supplier['order_date'])) : '-' ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created Date:</span>
                            <span class="info-value"><?= date('d M Y', strtotime($supplier['created_at'] ?? 'now')) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <h3>Supplied Items</h3>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Unit Price (LKR)</th>
                        <th class="text-right">Total (LKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td class="text-right"><?= (int) $item['quantity'] ?></td>
                        <td class="text-right"><?= number_format((float) $item['unit_price'], 2) ?></td>
                        <td class="text-right"><?= number_format((int) $item['quantity'] * (float) $item['unit_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary-section">
                <h3>Financial Summary</h3>
                <div class="summary-row">
                    <span>Total Amount Due:</span>
                    <span>LKR <?= number_format($totalAmount, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Advance Paid:</span>
                    <span>LKR <?= number_format((float) ($supplier['advance_paid'] ?? 0), 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Amount to be Paid:</span>
                    <span>LKR <?= number_format(max(0, $totalAmount - (float) ($supplier['advance_paid'] ?? 0)), 2) ?></span>
                </div>
                <div class="summary-row total">
                    <span>NET TOTAL:</span>
                    <span>LKR <?= number_format($totalAmount, 2) ?></span>
                </div>
            </div>

            <div class="print-button">
                <button onclick="window.print()">Print</button>
                <a href="?page=suppliers" class="back-btn">Back to Suppliers</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
} elseif ($action === 'past') {
    $summary = $pdo->query("SELECT COUNT(*) AS total_suppliers, COUNT(*) AS received_orders, COUNT(*) AS completed_deliveries FROM suppliers WHERE status = 'Received'")->fetch(PDO::FETCH_ASSOC);

    $supplierRows = $pdo->query("SELECT s.*, si.item_name, si.quantity, si.unit_price FROM suppliers s LEFT JOIN supplier_items si ON si.supplier_id = s.id WHERE s.status = 'Received' ORDER BY s.created_at DESC, si.id ASC")->fetchAll(PDO::FETCH_ASSOC);

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

<div class="supplier-detail-overlay">
    <div class="supplier-detail-modal">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Past Suppliers</h4>
            <a href="?page=suppliers" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white border-0 pt-4 px-4">
                <div class="row text-center">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-1">Total Past Suppliers</h6>
                        <h3 class="mb-0 text-primary"><?= (int) ($summary['total_suppliers'] ?? 0) ?></h3>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-1">Received Orders</h6>
                        <h3 class="mb-0 text-success"><?= (int) ($summary['received_orders'] ?? 0) ?></h3>
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
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No past suppliers found</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
    .supplier-detail-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 1050;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }

    .supplier-detail-modal {
        background: #fff;
        border-radius: 0.75rem;
        box-shadow: 0 20px 55px rgba(0,0,0,0.15);
        max-width: 1100px;
        width: 100%;
        padding: 1.5rem;
        overflow: auto;
        max-height: 90vh;
    }

    @media (max-width: 768px) {
        .supplier-detail-modal {
            padding: 1rem;
        }
    }
</style>
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

    $filter = $_GET['filter'] ?? 'all';
    $where = '';
    if ($filter === 'confirmed') {
        $where = "WHERE s.status = 'Confirmed'";
    } elseif ($filter === 'pending') {
        $where = "WHERE s.status = 'Pending'";
    }

    $summary = $pdo->query("SELECT COUNT(*) AS total_suppliers, SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_orders, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_deliveries FROM suppliers")->fetch(PDO::FETCH_ASSOC);

    $supplierRows = $pdo->query("SELECT s.*, si.item_name, si.quantity, si.unit_price FROM suppliers s LEFT JOIN supplier_items si ON si.supplier_id = s.id $where ORDER BY s.created_at DESC, si.id ASC")->fetchAll(PDO::FETCH_ASSOC);

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

    $title = 'Manage Suppliers';
    if ($filter === 'confirmed') {
        $title = 'Confirmed Suppliers';
    } elseif ($filter === 'pending') {
        $title = 'Pending Suppliers';
    }

    $noMessage = 'No suppliers found';
    if ($filter === 'confirmed') {
        $noMessage = 'No confirmed suppliers found';
    } elseif ($filter === 'pending') {
        $noMessage = 'No pending suppliers found';
    }
    ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= $title ?></h4>
    <div class="d-flex gap-2">
        <a href="?page=suppliers&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Supplier</a>
        <a href="?page=suppliers&action=past" class="btn btn-secondary"><i class="bi bi-clock-history me-2"></i>View Past Suppliers</a>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white border-0 pt-4 px-4">
        <div class="row text-center">
            <div class="col-md-4">
                <a href="?page=suppliers&filter=all" class="text-decoration-none">
                    <h6 class="text-muted mb-1">Total Suppliers</h6>
                    <h3 class="mb-0 text-primary"><?= (int) ($summary['total_suppliers'] ?? 0) ?></h3>
                </a>
            </div>
            <div class="col-md-4">
                <a href="?page=suppliers&filter=confirmed" class="text-decoration-none">
                    <h6 class="text-muted mb-1">Confirmed Orders</h6>
                    <h3 class="mb-0 text-success"><?= (int) ($summary['confirmed_orders'] ?? 0) ?></h3>
                </a>
            </div>
            <div class="col-md-4">
                <a href="?page=suppliers&filter=pending" class="text-decoration-none">
                    <h6 class="text-muted mb-1">Pending Orders</h6>
                    <h3 class="mb-0 text-warning"><?= (int) ($summary['pending_deliveries'] ?? 0) ?></h3>
                </a>
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
                            <a href="?page=suppliers&action=print&id=<?= (int) $supplier['id'] ?>" class="btn btn-sm btn-outline-info" title="Print"><i class="bi bi-printer"></i></a>
                            <form method="POST" action="?page=suppliers&action=delete" style="display:inline;">
                                <input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this supplier?')"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4"><?= $noMessage ?></td>
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
