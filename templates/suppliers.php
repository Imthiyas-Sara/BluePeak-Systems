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
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-download me-2"></i>Download Report</button>
        </div>
    </div>
</div>
<?php
}

include 'footer.php';
?>