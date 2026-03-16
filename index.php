<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'sri_ram_fireworks');
define('DB_USER', 'root');
define('DB_PASS', '');
define('CURRENCY', 'LKR');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE " . DB_NAME);
    
    // Create tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'manager', 'cashier') DEFAULT 'cashier',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(200) NOT NULL,
        category_id INT,
        cost_price DECIMAL(10,2) DEFAULT 0,
        selling_price DECIMAL(10,2) NOT NULL,
        wholesale_price DECIMAL(10,2),
        stock_quantity INT DEFAULT 0,
        min_stock_level INT DEFAULT 10,
        unit VARCHAR(20) DEFAULT 'pcs',
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        email VARCHAR(100),
        address TEXT,
        type ENUM('retail', 'wholesale') DEFAULT 'retail',
        balance DECIMAL(10,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS bills (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_number VARCHAR(50) UNIQUE NOT NULL,
        type ENUM('retail', 'wholesale') NOT NULL,
        customer_id INT,
        user_id INT,
        subtotal DECIMAL(10,2) DEFAULT 0,
        discount_amount DECIMAL(10,2) DEFAULT 0,
        tax_amount DECIMAL(10,2) DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL,
        paid_amount DECIMAL(10,2) DEFAULT 0,
        payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'cash',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS bill_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        product_id INT,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        discount DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert default admin user if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Admin', 'admin@sriram.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin')");
    }
    
    // Insert default settings if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES 
            ('company_name', 'Sri Ram Fire Works'),
            ('company_address', 'Main Street, Colombo, Sri Lanka'),
            ('company_phone', '+94 11 234 5678'),
            ('currency_symbol', 'LKR'),
            ('tax_percentage', '0'),
            ('invoice_prefix', 'SRF')
        ");
    }
    
    // Insert sample categories if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO categories (name, description) VALUES 
            ('Crackers', 'Firecrackers and sound crackers'),
            ('Sparklers', 'Hand-held sparklers'),
            ('Rockets', 'Sky rockets and missiles'),
            ('Fountains', 'Ground fountains'),
            ('Flower Pots', 'Colorful flower pots')
        ");
    }
    
    // Insert sample products if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO products (sku, name, category_id, cost_price, selling_price, wholesale_price, stock_quantity) VALUES 
            ('SRF-0001', 'Lakshmi Crackers 100pcs', 1, 800, 1000, 900, 50),
            ('SRF-0002', 'Color Sparklers 10pcs', 2, 150, 200, 180, 100),
            ('SRF-0003', 'Sky Rocket 5pcs', 3, 400, 500, 450, 30),
            ('SRF-0004', 'Golden Fountain', 4, 250, 350, 300, 40),
            ('SRF-0005', 'Flower Pot Deluxe', 5, 180, 250, 220, 60)
        ");
    }
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Load settings
$settings = [];
$settingsQuery = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $settingsQuery->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?");
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        header("Location: ?page=dashboard");
        exit;
    } else {
        $loginError = "Invalid email or password";
    }
}

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    include 'templates/login.php';
    exit;
}

// Get current page
$page = $_GET['page'] ?? 'dashboard';
if ($page === 'wholesale') {
    $page = 'event';
}
$validPages = ['dashboard', 'retail', 'event', 'products', 'categories', 'customers', 'suppliers', 'employees', 'reports', 'settings'];

if (!in_array($page, $validPages)) {
    $page = 'dashboard';
}

// Include the appropriate template
$templateFile = "templates/{$page}.php";
if (file_exists($templateFile)) {
    include $templateFile;
} else {
    echo "<div class='alert alert-danger'>Page not found: {$page}</div>";
}
?>
