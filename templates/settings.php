<?php
$currency = $settings['currency_symbol'] ?? 'LKR';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = trim($_POST['company_name'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $company_phone = trim($_POST['company_phone'] ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $currency_symbol = trim($_POST['currency_symbol'] ?? 'LKR');
    $tax_percentage = floatval($_POST['tax_percentage'] ?? 0);
    $invoice_prefix = trim($_POST['invoice_prefix'] ?? 'SRF');
    
    $settingsData = [
        'company_name' => $company_name,
        'company_address' => $company_address,
        'company_phone' => $company_phone,
        'company_email' => $company_email,
        'currency_symbol' => $currency_symbol,
        'tax_percentage' => $tax_percentage,
        'invoice_prefix' => $invoice_prefix
    ];
    
    foreach ($settingsData as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $value]);
    }
    
    header("Location: ?page=settings&success=1");
    exit;
}

include 'header.php';
?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>Settings saved successfully!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>System Settings</h4>
</div>

<form method="POST">
    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-building me-2"></i>Company Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name'] ?? 'Sri Ram Fire Works') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="company_address" class="form-control" rows="3"><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="company_phone" class="form-control" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-receipt me-2"></i>Billing Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Currency Symbol</label>
                        <select name="currency_symbol" class="form-select">
                            <option value="LKR" <?= ($settings['currency_symbol'] ?? 'LKR') == 'LKR' ? 'selected' : '' ?>>LKR - Sri Lankan Rupee</option>
                            <option value="Rs" <?= ($settings['currency_symbol'] ?? '') == 'Rs' ? 'selected' : '' ?>>Rs - Rupees</option>
                            <option value="$" <?= ($settings['currency_symbol'] ?? '') == '$' ? 'selected' : '' ?>>$ - US Dollar</option>
                            <option value="€" <?= ($settings['currency_symbol'] ?? '') == '€' ? 'selected' : '' ?>>€ - Euro</option>
                            <option value="£" <?= ($settings['currency_symbol'] ?? '') == '£' ? 'selected' : '' ?>>£ - British Pound</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax Percentage (%)</label>
                        <input type="number" name="tax_percentage" class="form-control" step="0.01" min="0" max="100" value="<?= htmlspecialchars($settings['tax_percentage'] ?? 0) ?>">
                        <small class="text-muted">Applied to all bills</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Invoice Prefix</label>
                        <input type="text" name="invoice_prefix" class="form-control" value="<?= htmlspecialchars($settings['invoice_prefix'] ?? 'SRF') ?>" maxlength="10">
                        <small class="text-muted">Bill numbers will be: PREFIX-R-000001 (retail), PREFIX-E-000001 (event)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-shield-check me-2"></i>Quick Actions</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <div class="d-grid">
                        <a href="?page=categories" class="btn btn-outline-primary"><i class="bi bi-tags me-2"></i>Manage Categories</a>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="d-grid">
                        <a href="?page=products" class="btn btn-outline-success"><i class="bi bi-box-seam me-2"></i>Manage Stocks</a>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="d-grid">
                        <a href="?page=customers" class="btn btn-outline-info"><i class="bi bi-people me-2"></i>Manage Customers</a>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="d-grid">
                        <a href="?page=reports" class="btn btn-outline-warning"><i class="bi bi-graph-up me-2"></i>View Reports</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-2"></i>Save Settings</button>
    </div>
</form>

<!-- System Info -->
<div class="card">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Information</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <p><strong>Application:</strong> Sri Ram Fire Works Billing System</p>
                <p><strong>Version:</strong> 1.0.0</p>
            </div>
            <div class="col-md-4">
                <p><strong>PHP Version:</strong> <?= PHP_VERSION ?></p>
                <p><strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></p>
            </div>
            <div class="col-md-4">
                <?php
                $totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
                $totalBills = $pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
                $totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
                ?>
                <p><strong>Total Products:</strong> <?= $totalProducts ?></p>
                <p><strong>Total Bills:</strong> <?= $totalBills ?></p>
                <p><strong>Total Customers:</strong> <?= $totalCustomers ?></p>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
