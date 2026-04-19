<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'sri_ram_fireworks');
define('DB_USER', 'root');
define('DB_PASS', '');
define('CURRENCY', 'LKR');

// Create database connection
$pdo = null;
$db_error = null;

try {
    // Try connection with longer timeout
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]
    );
    
    // Create database if not exists
    @$pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    @$pdo->exec("USE " . DB_NAME);

    // Database is healthy; ensure demo mode is disabled.
    if (isset($_SESSION['demo_mode'])) {
        unset($_SESSION['demo_mode']);
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $pdo = null;
}

// If connection failed, create a mock PDO for basic operations
if (!$pdo) {
    // Create session-based mock for demo mode
    $_SESSION['demo_mode'] = true;
    
    // Mock PDO class for demo mode
    class MockPDO {
        public function query($sql) {
            return new MockResultSet([]);
        }
        public function exec($sql) {
            return 0;
        }
        public function prepare($sql) {
            return new MockStatement();
        }
        public function lastInsertId() {
            return 1;
        }
    }
    
    class MockResultSet {
        protected $data;
        public function __construct($data = []) {
            $this->data = $data;
        }
        public function fetch($mode = PDO::FETCH_ASSOC) {
            if (empty($this->data)) {
                return false;
            }

            $row = array_shift($this->data);

            if ($mode === PDO::FETCH_COLUMN) {
                if (is_array($row)) {
                    return reset($row);
                }
                return $row;
            }

            return $row;
        }
        public function fetchAll($mode = PDO::FETCH_ASSOC) {
            if ($mode === PDO::FETCH_COLUMN) {
                return array_map(function ($row) {
                    return is_array($row) ? reset($row) : $row;
                }, $this->data);
            }

            if ($mode === PDO::FETCH_KEY_PAIR) {
                $result = [];
                foreach ($this->data as $row) {
                    if (is_array($row)) {
                        $values = array_values($row);
                        if (count($values) >= 2) {
                            $result[$values[0]] = $values[1];
                        }
                    }
                }
                return $result;
            }

            return $this->data;
        }
        public function fetchColumn($column = 0) {
            if (empty($this->data)) {
                return 0;
            }

            $row = $this->data[0];
            if (is_array($row)) {
                $values = array_values($row);
                return $values[$column] ?? 0;
            }

            return $column === 0 ? $row : 0;
        }
        public function rowCount() {
            return count($this->data);
        }
    }
    
    class MockStatement {
        public function execute($params = []) {
            return true;
        }
        public function bindParam($param, &$var, $type = null, $maxLength = null, $driverOptions = null) {
            return true;
        }
        public function bindValue($param, $value, $type = null) {
            return true;
        }
        public function fetch($mode = PDO::FETCH_ASSOC) {
            return false;
        }
        public function fetchAll($mode = PDO::FETCH_ASSOC) {
            if ($mode === PDO::FETCH_KEY_PAIR) {
                return [];
            }
            return [];
        }
        public function fetchColumn($column = 0) {
            return 0;
        }
        public function rowCount() {
            return 0;
        }
    }
    
    $pdo = new MockPDO();
}

// Tables assumed to already exist in database
// If you need to initialize tables, run setup/init-db.php instead

try {
    // Only run table creation if database is connected
    if ($pdo) {
        // Verify database is usable
        $testQuery = $pdo->query("SELECT 1");
        
        // Create tables only if they don't exist (non-blocking)
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
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uid VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        address TEXT,
        employee_type ENUM('daily_paid', 'monthly_paid') DEFAULT 'daily_paid',
        phone VARCHAR(20),
        daily_wage DECIMAL(10,2),
        monthly_salary DECIMAL(10,2),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('present', 'absent', 'leave') DEFAULT 'present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        UNIQUE KEY unique_attendance (employee_id, attendance_date)
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        month VARCHAR(7) NOT NULL,
        status ENUM('pending', 'paid') DEFAULT 'pending',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        UNIQUE KEY unique_salary_payment (employee_id, month)
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(150) NOT NULL,
        status ENUM('Pending', 'Confirmed', 'Received') DEFAULT 'Pending',
        order_date DATE,
        advance_paid DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    try {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS advance_paid DECIMAL(10,2) DEFAULT 0");
    } catch (Exception $e) {
        // Keep bootstrapping even if the schema is already in the desired state or the server rejects the migration.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        item_name VARCHAR(200) NOT NULL,
        quantity INT DEFAULT 0,
        unit_price DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
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
    

    
    // Insert sample employees if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO employees (uid, name, address, employee_type, phone, daily_wage) VALUES 
            ('EMP-001', 'Mithlesh Kumar Singh', 'Kiribathgoda, Colombo', 'daily_paid', '0987569326', 2000),
            ('EMP-002', 'Suron Maherjan', 'Nattandiya, Puttalam', 'daily_paid', '0987569327', 2000),
            ('EMP-003', 'Sandesh Bajracharya', 'Borella, Colombo', 'daily_paid', '0987569328', 2200),
            ('EMP-004', 'Subin Sedhai', 'Kaduwela, Colombo', 'daily_paid', '0987569329', 2000),
            ('EMP-005', 'Wonjala Joshi', 'Maharagama, Colombo', 'daily_paid', '0987569330', 1950)
        ");
    }
    
    // Insert sample attendance data if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM attendance");
    if ($stmt->fetchColumn() == 0) {
        // Get existing employee IDs
        $stmt = $pdo->query("SELECT id FROM employees ORDER BY id LIMIT 5");
        $employeeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($employeeIds) >= 5) {
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            
            $attendanceData = [
                [$employeeIds[0], $today, 'present'],
                [$employeeIds[1], $today, 'present'],
                [$employeeIds[2], $today, 'absent'],
                [$employeeIds[3], $today, 'present'],
                [$employeeIds[4], $today, 'present'],
                [$employeeIds[0], $yesterday, 'present'],
                [$employeeIds[1], $yesterday, 'present'],
                [$employeeIds[2], $yesterday, 'present'],
                [$employeeIds[3], $yesterday, 'absent'],
                [$employeeIds[4], $yesterday, 'present']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, ?)");
            foreach ($attendanceData as $record) {
                $stmt->execute($record);
            }
        }
    }
    
    // Database integrity fix - run on every load to ensure consistency
    try {
        // Remove orphaned attendance records
        $stmt = $pdo->prepare("DELETE a FROM attendance a LEFT JOIN employees e ON a.employee_id = e.id WHERE e.id IS NULL");
        $stmt->execute();
        
        // Clean up duplicate attendance records (keep the latest)
        $pdo->exec("
            DELETE a1 FROM attendance a1
            INNER JOIN attendance a2
            WHERE a1.employee_id = a2.employee_id
            AND a1.attendance_date = a2.attendance_date
            AND a1.id < a2.id
        ");
    } catch (Exception $e) {
        // Ignore errors during cleanup
    }
    }
    
} catch (PDOException $e) {
    // Database error - continue in demo mode
    $_SESSION['demo_mode'] = true;
}

// Load settings
$settings = [];
if ($pdo) {
    try {
        $settingsQuery = $pdo->query("SELECT setting_key, setting_value FROM settings");
        if ($settingsQuery) {
            while ($row = $settingsQuery->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {
        // If MySQL drops during bootstrap, fall back to demo mode instead of crashing.
        $_SESSION['demo_mode'] = true;
        $pdo = null;
    }
}

// Set default settings if not loaded from database
if (empty($settings)) {
    $settings['company_name'] = 'Sri Ram Fire Works';
    $settings['currency_symbol'] = 'LKR';
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

    $user = null;
    $isDemoMode = !empty($_SESSION['demo_mode']);

    if ($isDemoMode) {
        // Viva-safe fallback login when database is unavailable.
        if (
            ($email === 'admin@sriram.com' && $password === 'admin123') ||
            ($email === 'admin' && $password === 'admin123')
        ) {
            $user = [
                'id' => 1,
                'name' => 'Admin',
                'role' => 'admin'
            ];
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dbUser && password_verify($password, $dbUser['password'])) {
            $user = $dbUser;
        }
    }

    if ($user) {
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
