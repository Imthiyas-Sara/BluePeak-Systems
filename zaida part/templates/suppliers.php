<?php
include 'header.php';

$suppliers = [
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

$statusClass = [
    'Confirmed' => 'success',
    'Pending' => 'warning',
    'Received' => 'primary',
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Manage Suppliers</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Suppliers</button>
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
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
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

<?php include 'footer.php'; ?>
