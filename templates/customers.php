<?php
$action = $_GET['action'] ?? 'index';
$currency = $settings['currency_symbol'] ?? 'LKR';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $type = $_POST['type'] ?? 'retail';
        $balance = floatval($_POST['balance'] ?? 0);
        
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address, type, balance) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email, $address, $type, $balance]);
            header("Location: ?page=customers&success=1");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_GET['id'] ?? 0);
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $type = $_POST['type'] ?? 'retail';
        $balance = floatval($_POST['balance'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($name) {
            $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, type = ?, balance = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $email, $address, $type, $balance, $is_active, $id]);
            header("Location: ?page=customers&success=2");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
        header("Location: ?page=customers&success=3");
        exit;
    } elseif ($action === 'payment') {
        $id = intval($_GET['id'] ?? 0);
        $amount = floatval($_POST['amount']);
        if ($amount > 0) {
            $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $id]);
            header("Location: ?page=customers&action=view&id=$id&success=payment");
            exit;
        }
    }
}

include 'header.php';
?>

<?php if (isset($_GET['success']) && $action === 'index'): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?= $_GET['success'] == 1 ? 'Customer added successfully!' : ($_GET['success'] == 2 ? 'Customer updated successfully!' : 'Customer deleted!') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'index'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Customers</h4>
    <a href="?page=customers&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Customer</a>
</div>

<!-- Customer Stats -->
<div class="row mb-4">
    <?php
    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
    $retailCount = $pdo->query("SELECT COUNT(*) FROM customers WHERE type = 'retail' AND is_active = 1")->fetchColumn();
    $wholesaleCount = $pdo->query("SELECT COUNT(*) FROM customers WHERE type = 'wholesale' AND is_active = 1")->fetchColumn();
    $totalBalance = $pdo->query("SELECT SUM(balance) FROM customers WHERE is_active = 1")->fetchColumn() ?: 0;
    ?>
    <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body text-center"><h4><?= $totalCustomers ?></h4><small>Total Customers</small></div></div></div>
    <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body text-center"><h4><?= $retailCount ?></h4><small>Retail</small></div></div></div>
    <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body text-center"><h4><?= $wholesaleCount ?></h4><small>Wholesale</small></div></div></div>
    <div class="col-md-3"><div class="card bg-danger text-white"><div class="card-body text-center"><h4><?= $currency ?> <?= number_format($totalBalance, 2) ?></h4><small>Total Outstanding</small></div></div></div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr><th>Name</th><th>Phone</th><th>Email</th><th>Type</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php
                $customers = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();
                foreach ($customers as $c):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['phone'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
                    <td><span class="badge bg-<?= $c['type'] == 'wholesale' ? 'success' : 'info' ?>"><?= ucfirst($c['type']) ?></span></td>
                    <td class="text-end <?= $c['balance'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $currency ?> <?= number_format($c['balance'], 2) ?></td>
                    <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end">
                        <a href="?page=customers&action=view&id=<?= $c['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                        <a href="?page=customers&action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomer(<?= $c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?><tr><td colspan="7" class="text-center text-muted py-4">No customers found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none"></form>
<script>
function deleteCustomer(id, name) {
    if (confirm('Delete customer "' + name + '"?')) {
        document.getElementById('deleteForm').action = '?page=customers&action=delete&id=' + id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php elseif ($action === 'create'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person-plus me-2"></i>Add New Customer</h4>
    <a href="?page=customers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="?page=customers&action=store">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="Full name">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="Phone number">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="Email address">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Customer Type</label>
                    <select name="type" class="form-select">
                        <option value="retail">Retail Customer</option>
                        <option value="wholesale">Wholesale Customer</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="Address"></textarea>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Opening Balance (<?= $currency ?>)</label>
                    <input type="number" name="balance" class="form-control" step="0.01" min="0" value="0">
                    <small class="text-muted">Outstanding amount customer owes</small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save Customer</button>
                <a href="?page=customers" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($action === 'edit'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$customer = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$id]);
$customer = $customer->fetch();
if (!$customer) { echo '<div class="alert alert-danger">Customer not found</div>'; include 'footer.php'; exit; }
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Customer</h4>
    <a href="?page=customers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="?page=customers&action=update&id=<?= $customer['id'] ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($customer['name']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Customer Type</label>
                    <select name="type" class="form-select">
                        <option value="retail" <?= $customer['type'] == 'retail' ? 'selected' : '' ?>>Retail Customer</option>
                        <option value="wholesale" <?= $customer['type'] == 'wholesale' ? 'selected' : '' ?>>Wholesale Customer</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($customer['address']) ?></textarea>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Balance (<?= $currency ?>)</label>
                    <input type="number" name="balance" class="form-control" step="0.01" value="<?= $customer['balance'] ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Status</label>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" name="is_active" class="form-check-input" id="isActive" <?= $customer['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Update Customer</button>
                <a href="?page=customers" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($action === 'view'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$customer = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$id]);
$customer = $customer->fetch();
if (!$customer) { echo '<div class="alert alert-danger">Customer not found</div>'; include 'footer.php'; exit; }

$bills = $pdo->prepare("SELECT * FROM bills WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
$bills->execute([$id]);
$bills = $bills->fetchAll();

$totalPurchases = $pdo->prepare("SELECT SUM(total_amount) FROM bills WHERE customer_id = ?");
$totalPurchases->execute([$id]);
$totalPurchases = $totalPurchases->fetchColumn() ?: 0;
?>

<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Payment recorded successfully!</div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person me-2"></i><?= htmlspecialchars($customer['name']) ?></h4>
    <div>
        <a href="?page=customers&action=edit&id=<?= $customer['id'] ?>" class="btn btn-primary"><i class="bi bi-pencil me-2"></i>Edit</a>
        <a href="?page=customers" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>
</div>

<div class="row">
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person-badge me-2"></i>Customer Details</div>
            <div class="card-body">
                <p><strong>Type:</strong> <span class="badge bg-<?= $customer['type'] == 'wholesale' ? 'success' : 'info' ?>"><?= ucfirst($customer['type']) ?></span></p>
                <p><strong>Phone:</strong> <?= htmlspecialchars($customer['phone'] ?: 'N/A') ?></p>
                <p><strong>Email:</strong> <?= htmlspecialchars($customer['email'] ?: 'N/A') ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($customer['address'] ?: 'N/A') ?></p>
                <hr>
                <p><strong>Total Purchases:</strong> <?= $currency ?> <?= number_format($totalPurchases, 2) ?></p>
                <p><strong>Outstanding Balance:</strong> <span class="<?= $customer['balance'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $currency ?> <?= number_format($customer['balance'], 2) ?></span></p>
            </div>
        </div>

        <?php if ($customer['balance'] > 0): ?>
        <div class="card">
            <div class="card-header bg-success text-white"><i class="bi bi-cash me-2"></i>Record Payment</div>
            <div class="card-body">
                <form method="POST" action="?page=customers&action=payment&id=<?= $customer['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Payment Amount (<?= $currency ?>)</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?= $customer['balance'] ?>" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg me-2"></i>Record Payment</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-receipt me-2"></i>Recent Bills</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark"><tr><th>Bill No</th><th>Date</th><th>Type</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($bills as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['bill_number']) ?></strong></td>
                            <td><?= date('d M Y', strtotime($b['created_at'])) ?></td>
                            <td><span class="badge bg-<?= $b['type'] == 'wholesale' ? 'success' : 'primary' ?>"><?= ucfirst($b['type']) ?></span></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($b['total_amount'], 2) ?></td>
                            <td><span class="badge bg-<?= $b['payment_status'] == 'paid' ? 'success' : ($b['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($b['payment_status']) ?></span></td>
                            <td><a href="?page=<?= $b['type'] ?>&action=view&id=<?= $b['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bills)): ?><tr><td colspan="6" class="text-center text-muted py-4">No bills found</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
