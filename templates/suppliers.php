<?php
// Initialize suppliers in session if not set
if (!isset($_SESSION['suppliers'])) {
    $_SESSION['suppliers'] = [
        [
            'id' => 'SUP-101',
            'name' => 'Colombo Fireworks Traders',
            'items' => ['Color Sparklers', 'Flower Pots', 'Ground Chakras'],
            'quantity' => [120, 80, 150],
            'unit_price' => [180, 250, 90],
            'status' => 'Confirmed',
            'date' => '2026-03-15',
        ],
        [
            'id' => 'SUP-102',
            'name' => 'Lanka Pyro Supplies',
            'items' => ['Sky Rockets', 'Fancy Crackers'],
            'quantity' => [60, 100],
            'unit_price' => [450, 320],
            'status' => 'Confirmed',
            'date' => '2026-03-14',
        ],
        [
            'id' => 'SUP-103',
            'name' => 'Temple Road Dealers',
            'items' => ['Fountains', 'Sparklers'],
            'quantity' => [40, 200],
            'unit_price' => [300, 160],
            'status' => 'Pending',
            'date' => '2026-03-12',
        ],
        [
            'id' => 'SUP-104',
            'name' => 'Blue Star Wholesale',
            'items' => ['Crackers', 'Shells', 'Rockets'],
            'quantity' => [300, 70, 50],
            'unit_price' => [120, 540, 480],
            'status' => 'Received',
            'date' => '2026-03-10',
        ],
    ];
}

$suppliers = &$_SESSION['suppliers'];

$action = $_GET['action'] ?? 'index';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $newSupplier = [
            'id' => trim($_POST['supplier_id']),
            'name' => trim($_POST['name']),
            'items' => array_map('trim', explode(',', $_POST['items'] ?? '')),
            'quantity' => array_map('intval', explode(',', $_POST['quantities'] ?? '')),
            'unit_price' => array_map('floatval', explode(',', $_POST['unit_prices'] ?? '')),
            'status' => $_POST['status'] ?? 'Pending',
            'date' => $_POST['order_date'] ?? date('Y-m-d'),
        ];
        $suppliers[] = $newSupplier;
        header("Location: ?page=suppliers&success=1");
        exit;
    } elseif ($action === 'update') {
        $id = $_GET['id'] ?? '';
        foreach ($suppliers as &$supplier) {
            if ($supplier['id'] === $id) {
                $supplier['id'] = trim($_POST['supplier_id']);
                $supplier['name'] = trim($_POST['name']);
                $supplier['items'] = array_map('trim', explode(',', $_POST['items'] ?? ''));
                $supplier['quantity'] = array_map('intval', explode(',', $_POST['quantities'] ?? ''));
                $supplier['unit_price'] = array_map('floatval', explode(',', $_POST['unit_prices'] ?? ''));
                $supplier['status'] = $_POST['status'] ?? 'Pending';
                $supplier['date'] = $_POST['order_date'] ?? date('Y-m-d');
                break;
            }
        }
        header("Location: ?page=suppliers&success=2");
        exit;
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $_SESSION['suppliers'] = array_filter($_SESSION['suppliers'], fn($s) => $s['id'] !== $id);
        $suppliers = &$_SESSION['suppliers'];
        header("Location: ?page=suppliers&success=3");
        exit;
    }
}

include 'header.php';

$statusClass = [
    'Confirmed' => 'success',
    'Pending' => 'warning',
    'Received' => 'primary',
];

if ($action === 'create' || $action === 'edit') {
    $supplier = null;
    if ($action === 'edit') {
        $id = $_GET['id'] ?? '';
        foreach ($suppliers as $s) {
            if ($s['id'] === $id) {
                $supplier = $s;
                break;
            }
        }
        if (!$supplier) {
            echo '<div class="alert alert-danger">Supplier not found</div>';
            include 'footer.php';
            exit;
        }
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><?= $action === 'create' ? 'Add Supplier' : 'Edit Supplier' ?></h4>
        <a href="?page=suppliers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="?page=suppliers&action=<?= $action === 'create' ? 'store' : 'update' ?>&id=<?= htmlspecialchars($supplier['id'] ?? '') ?>">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Supplier ID *</label>
                            <input type="text" name="supplier_id" class="form-control" required value="<?= htmlspecialchars($supplier['id'] ?? '') ?>">
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
                            <input type="date" name="order_date" class="form-control" value="<?= $supplier['date'] ?? date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Items (comma separated)</label>
                            <input type="text" name="items" class="form-control" value="<?= htmlspecialchars(implode(', ', $supplier['items'] ?? [])) ?>" placeholder="e.g. Color Sparklers, Flower Pots">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantities (comma separated)</label>
                            <input type="text" name="quantities" class="form-control" value="<?= htmlspecialchars(implode(', ', $supplier['quantity'] ?? [])) ?>" placeholder="e.g. 120,80,150">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Prices (comma separated)</label>
                            <input type="text" name="unit_prices" class="form-control" value="<?= htmlspecialchars(implode(', ', $supplier['unit_price'] ?? [])) ?>" placeholder="e.g. 180,250,90">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save</button>
            </form>
        </div>
    </div>
    <?php
} elseif ($action === 'view') {
    $id = $_GET['id'] ?? '';
    $supplier = null;
    foreach ($suppliers as $s) {
        if ($s['id'] === $id) {
            $supplier = $s;
            break;
        }
    }
    if (!$supplier) {
        echo '<div class="alert alert-danger">Supplier not found</div>';
        include 'footer.php';
        exit;
    }
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
                    <p><strong>ID:</strong> <?= htmlspecialchars($supplier['id']) ?></p>
                    <p><strong>Name:</strong> <?= htmlspecialchars($supplier['name']) ?></p>
                    <p><strong>Status:</strong> <span class="badge bg-<?= $statusClass[$supplier['status']] ?? 'secondary' ?>"><?= htmlspecialchars($supplier['status']) ?></span></p>
                    <p><strong>Order Date:</strong> <?= date('d M Y', strtotime($supplier['date'])) ?></p>
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
                            foreach ($supplier['items'] as $index => $item):
                                $qty = $supplier['quantity'][$index] ?? 0;
                                $price = $supplier['unit_price'][$index] ?? 0;
                                $itemTotal = $qty * $price;
                                $total += $itemTotal;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($item) ?></td>
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
    // Index page
    if (isset($_GET['success'])) {
        $messages = [
            1 => 'Supplier added successfully',
            2 => 'Supplier updated successfully',
            3 => 'Supplier deleted successfully'
        ];
        echo '<div class="alert alert-success">' . ($messages[$_GET['success']] ?? 'Operation completed') . '</div>';
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
                <h3 class="mb-0 text-primary"><?= count($suppliers) ?></h3>
            </div>
            <div class="col-md-4">
                <h6 class="text-muted mb-1">Confirmed Orders</h6>
                <h3 class="mb-0 text-success"><?= count(array_filter($suppliers, fn($s) => $s['status'] === 'Confirmed')) ?></h3>
            </div>
            <div class="col-md-4">
                <h6 class="text-muted mb-1">Pending Deliveries</h6>
                <h3 class="mb-0 text-warning"><?= count(array_filter($suppliers, fn($s) => $s['status'] !== 'Received')) ?></h3>
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
                        <td><?= htmlspecialchars($supplier['id']) ?></td>
                        <td><?= htmlspecialchars($supplier['name']) ?></td>
                        <td><?php foreach ($supplier['items'] as $item): ?><div><?= htmlspecialchars($item) ?></div><?php endforeach; ?></td>
                        <td><?php foreach ($supplier['quantity'] as $qty): ?><div><?= $qty ?></div><?php endforeach; ?></td>
                        <td><?php foreach ($supplier['unit_price'] as $price): ?><div>LKR <?= number_format($price, 2) ?></div><?php endforeach; ?></td>
                        <td><span class="badge bg-<?= $statusClass[$supplier['status']] ?? 'secondary' ?>"><?= htmlspecialchars($supplier['status']) ?></span></td>
                        <td><?= date('d M Y', strtotime($supplier['date'])) ?></td>
                        <td class="text-center text-nowrap">
                            <a href="?page=suppliers&action=view&id=<?= htmlspecialchars($supplier['id']) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="?page=suppliers&action=edit&id=<?= htmlspecialchars($supplier['id']) ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="?page=suppliers&action=delete" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($supplier['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this supplier?')"><i class="bi bi-x-lg"></i></button>
                                </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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
