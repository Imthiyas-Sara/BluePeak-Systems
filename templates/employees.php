 <?php
// ========================================
// Employee Management Module Bootstrap
// ========================================
// Handle form submissions BEFORE any output
$success = null;
$error = null;

// Flash messages (persist after redirect)
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ========================================
// Monthly Salary Calculation Helper
// ========================================
$buildMonthlySalaryData = function ($month, $includeInactive = false) use ($pdo) {
    $year = date('Y', strtotime($month . '-01'));
    $month_num = date('m', strtotime($month . '-01'));

    $first_day = strtotime($year . '-' . $month_num . '-01');
    $last_day = strtotime(date('Y-m-t', $first_day));
    $working_days = 0;
    for ($date = $first_day; $date <= $last_day; $date = strtotime('+1 day', $date)) {
        $day_of_week = date('N', $date);
        if ($day_of_week <= 5) {
            $working_days++;
        }
    }

    $stmt = $pdo->prepare("SELECT employee_id, status FROM salary_payments WHERE month = ?");
    $stmt->execute([$month]);
    $paymentStatuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT employee_id, bonus_amount FROM employee_salary_bonus WHERE month = ?");
    $stmt->execute([$month]);
    $bonusAmounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $employeeQuery = "SELECT * FROM employees";
    if (!$includeInactive) {
        $employeeQuery .= " WHERE is_active = 1";
    }
    $employeeQuery .= " ORDER BY id ASC";

    $stmt = $pdo->query($employeeQuery);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $salaryData = [];
    foreach ($employees as $employee) {
        $rawType = strtolower(trim($employee['employee_type'] ?? ''));
        if ($rawType === '') {
            if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                $employee_type = 'monthly_paid';
            } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                $employee_type = 'daily_paid';
            } else {
                $employee_type = 'daily_paid';
            }
        } elseif (strpos($rawType, 'month') !== false) {
            $employee_type = 'monthly_paid';
        } elseif (strpos($rawType, 'day') !== false) {
            $employee_type = 'daily_paid';
        } else {
            $employee_type = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) as present_count FROM attendance WHERE employee_id = ? AND attendance_date LIKE ? AND status = 'present'");
        $stmt->execute([$employee['id'], $year . '-' . $month_num . '-%']);
        $present_days = (int)($stmt->fetch(PDO::FETCH_ASSOC)['present_count'] ?? 0);

        $final_salary = 0;
        $salary_type_label = '';
        if ($employee_type === 'daily_paid') {
            $daily_rate = (float)($employee['daily_wage'] ?? 0);
            $final_salary = $daily_rate * $present_days;
            $salary_type_label = 'Daily Rate: LKR ' . number_format($daily_rate, 2);
        } else {
            $monthly_salary = (float)($employee['monthly_salary'] ?? 0);
            if ($monthly_salary > 0 && $working_days > 0) {
                $per_day_salary = $monthly_salary / $working_days;
                $final_salary = $per_day_salary * $present_days;
            }
            $salary_type_label = 'Monthly Salary: LKR ' . number_format($monthly_salary, 2);
        }

        $base_salary = $final_salary;
        $bonus_amount = (float)($bonusAmounts[$employee['id']] ?? 0);
        $final_salary_with_bonus = $base_salary + $bonus_amount;

        $salaryData[] = [
            'id' => $employee['id'],
            'uid' => $employee['uid'] ?? 'N/A',
            'name' => $employee['name'] ?? 'N/A',
            'address' => $employee['address'] ?? 'N/A',
            'type' => $employee_type,
            'phone' => $employee['phone'] ?? 'N/A',
            'is_active' => (int)($employee['is_active'] ?? 1),
            'created_at' => $employee['created_at'] ?? null,
            'updated_at' => $employee['updated_at'] ?? null,
            'present_days' => $present_days,
            'total_working_days' => $working_days,
            'salary_type_label' => $salary_type_label,
            'base_salary' => number_format($base_salary, 2, '.', ''),
            'bonus_amount' => number_format($bonus_amount, 2, '.', ''),
            'final_salary' => number_format($base_salary, 2, '.', ''),
            'final_salary_with_bonus' => number_format($final_salary_with_bonus, 2, '.', ''),
            'payment_status' => $paymentStatuses[$employee['id']] ?? 'pending'
        ];
    }

    return [
        'salaryData' => $salaryData,
        'workingDays' => $working_days,
    ];
};

// ========================================
// Report Support Tables
// ========================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_bonus (
        employee_id INT NOT NULL,
        month VARCHAR(7) NOT NULL,
        bonus_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (employee_id, month)
    )");
} catch (Exception $e) {
    // Keep existing workflow unchanged if initialization fails.
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_deletion_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        uid VARCHAR(50),
        name VARCHAR(100),
        address TEXT,
        phone VARCHAR(20),
        employee_type VARCHAR(30),
        daily_wage DECIMAL(10,2) DEFAULT 0,
        monthly_salary DECIMAL(10,2) DEFAULT 0,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_month VARCHAR(7)
    )");
} catch (Exception $e) {
    // Keep existing workflow unchanged if initialization fails.
}

// ========================================
// PDF Report Endpoint
// ========================================
$requestAction = $_GET['action'] ?? '';
if ($requestAction === 'download_salary_report') {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $reportData = $buildMonthlySalaryData($month, true);
    $salaryData = $reportData['salaryData'];

    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    $totalEmployees = count($salaryData);
    $newEmployees = 0;
    $employeesLeft = 0;
    $paidEmployees = 0;
    $pendingEmployees = 0;
    $totalSalaryPaid = 0.0;
    $totalSalaryPending = 0.0;

    foreach ($salaryData as $row) {
        if (!empty($row['created_at'])) {
            $created = substr((string)$row['created_at'], 0, 10);
            if ($created >= $monthStart && $created <= $monthEnd) {
                $newEmployees++;
            }
        }

        if ((int)($row['is_active'] ?? 1) === 0 && !empty($row['updated_at'])) {
            $updated = substr((string)$row['updated_at'], 0, 10);
            if ($updated >= $monthStart && $updated <= $monthEnd) {
                $employeesLeft++;
            }
        }

        $salaryAmount = (float)($row['final_salary_with_bonus'] ?? 0);
        if (($row['payment_status'] ?? 'pending') === 'paid') {
            $paidEmployees++;
            $totalSalaryPaid += $salaryAmount;
        } else {
            $pendingEmployees++;
            $totalSalaryPending += $salaryAmount;
        }
    }

    $pdfEscape = function ($text) {
        $text = (string)$text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        return $text;
    };

    $drawText = function ($x, $y, $text, $size = 10, $font = '/F1') use ($pdfEscape) {
        return "BT\n{$font} {$size} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    $lines = [];
    $lines[] = ['text' => 'EMPLOYEE MONTHLY PAYROLL REPORT', 'size' => 14, 'font' => '/F2'];
    $lines[] = ['text' => 'Month: ' . $month . '    Generated: ' . date('Y-m-d H:i:s'), 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => str_repeat('-', 110), 'size' => 8, 'font' => '/F1'];

    $lines[] = ['text' => 'SECTION A - EMPLOYEE DETAILS', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'UID        Name                          Type      Phone         Status', 'size' => 9, 'font' => '/F2'];
    foreach ($salaryData as $row) {
        $uid = str_pad(substr((string)$row['uid'], 0, 10), 10);
        $name = str_pad(substr((string)$row['name'], 0, 28), 30);
        $typeLabel = (($row['type'] ?? 'daily_paid') === 'daily_paid') ? 'Daily' : 'Monthly';
        $type = str_pad($typeLabel, 9);
        $phone = str_pad(substr((string)($row['phone'] ?? 'N/A'), 0, 12), 12);
        $status = ((int)($row['is_active'] ?? 1) === 1) ? 'Active' : 'Inactive';
        $lines[] = ['text' => $uid . '  ' . $name . '  ' . $type . '  ' . $phone . '  ' . $status, 'size' => 8, 'font' => '/F3'];
    }

    $lines[] = ['text' => ' ', 'size' => 8, 'font' => '/F1'];
    $lines[] = ['text' => 'SECTION B - ATTENDANCE & SALARY DETAILS', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'UID      Present/Work  Base Salary   Bonus      Final Salary   Payment', 'size' => 9, 'font' => '/F2'];
    foreach ($salaryData as $row) {
        $uid = str_pad(substr((string)$row['uid'], 0, 8), 8);
        $attendance = str_pad((string)$row['present_days'] . '/' . (string)$row['total_working_days'], 13);
        $baseSalary = str_pad('LKR ' . number_format((float)$row['base_salary'], 2), 12);
        $bonusAmount = str_pad('LKR ' . number_format((float)$row['bonus_amount'], 2), 10);
        $finalSalary = str_pad('LKR ' . number_format((float)$row['final_salary_with_bonus'], 2), 13);
        $payment = ucfirst((string)($row['payment_status'] ?? 'pending'));
        $lines[] = ['text' => $uid . '  ' . $attendance . '  ' . $baseSalary . '  ' . $bonusAmount . '  ' . $finalSalary . '  ' . $payment, 'size' => 8, 'font' => '/F3'];
    }

    $lines[] = ['text' => ' ', 'size' => 8, 'font' => '/F1'];
    $lines[] = ['text' => 'SECTION C - MONTHLY SUMMARY', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'Total Employees             : ' . $totalEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'New Employees Joined        : ' . $newEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Employees Who Left          : ' . $employeesLeft, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Number of Paid Employees    : ' . $paidEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Number of Pending Payments  : ' . $pendingEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Total Salary Paid           : LKR ' . number_format($totalSalaryPaid, 2), 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Total Salary Pending        : LKR ' . number_format($totalSalaryPending, 2), 'size' => 9, 'font' => '/F1'];

    $linesPerPage = 52;
    $linePages = array_chunk($lines, $linesPerPage);
    if (empty($linePages)) {
        $linePages = [[]];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

    $pageRefs = [];
    $nextObj = 6;

    foreach ($linePages as $pageIndex => $pageLines) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageRefs[] = $pageObj . ' 0 R';

        $content = '';
        $y = 805;
        foreach ($pageLines as $line) {
            $content .= $drawText(40, $y, $line['text'], $line['size'], $line['font']);
            $y -= 13;
        }

        $content .= $drawText(40, 30, 'Page ' . ($pageIndex + 1) . ' of ' . count($linePages), 8, '/F1');

        $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
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
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $off) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    $filename = 'employee-monthly-payroll-report-' . $month . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================
    // Employee Action Handlers
    // ========================================
    // Handle JSON requests
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (is_array($data)) {
            $_POST = array_merge($_POST, $data);
        }
    }

    // Ensure performance/increment schema exists before handling POST actions.
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN base_salary DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN current_salary DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN last_increment_year INT NULL DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN total_stars INT NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN is_top_performer TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN increment_approved TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_monthly_performance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            month TINYINT NOT NULL,
            year SMALLINT NOT NULL,
            stars TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_employee_month_year (employee_id, month, year),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");
    } catch (Exception $e) {
        // Keep workflow unchanged if table creation fails.
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_yearly_performance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            year SMALLINT NOT NULL,
            total_stars INT NOT NULL DEFAULT 0,
            is_eligible TINYINT(1) NOT NULL DEFAULT 0,
            is_top_performer TINYINT(1) NOT NULL DEFAULT 0,
            increment_approved TINYINT(1) NOT NULL DEFAULT 0,
            increment_applied_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_employee_year (employee_id, year),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");
    } catch (Exception $e) {
        // Keep workflow unchanged if table creation fails.
    }
    try {
        $pdo->exec("ALTER TABLE employee_yearly_performance ADD COLUMN increment_applied_at TIMESTAMP NULL DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists or table not available yet.
    }
    
    if (isset($_POST['action'])) {
        $jsonActions = [
            'save_attendance',
            'generate_report',
            'load_salary_data',
            'load_employee_management_summary',
            'apply_salary_bonus',
            'set_payment_status',
            'update_single_attendance',
            'load_attendance',
            'load_attendance_summary',
            'update_employee_by_uid',
            'load_monthly_performance',
            'save_monthly_performance',
            'calculate_yearly_performance',
            'load_yearly_performance',
            'approve_yearly_increment',
        ];

        if (in_array($_POST['action'], $jsonActions, true)) {
            ini_set('display_errors', '0');
            header('Content-Type: application/json; charset=UTF-8');
        }

        // ----------------------------------------
        // Add / Edit Employee
        // ----------------------------------------
        if ($_POST['action'] === 'update_employee_by_uid') {
            $selectedUid = trim((string)($_POST['selected_uid'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $employee_type = trim((string)($_POST['employee_type'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $daily_wage = $_POST['daily_wage'] ?? 0;
            $monthly_salary = $_POST['monthly_salary'] ?? 0;

            $typeRaw = strtolower($employee_type);
            if (strpos($typeRaw, 'month') !== false) {
                $employee_type = 'monthly_paid';
            } elseif (strpos($typeRaw, 'day') !== false) {
                $employee_type = 'daily_paid';
            }

            $daily_wage = is_numeric($daily_wage) ? (float)$daily_wage : 0;
            $monthly_salary = is_numeric($monthly_salary) ? (float)$monthly_salary : 0;

            if (
                $selectedUid === '' ||
                $name === '' ||
                $phone === '' ||
                $address === '' ||
                !in_array($employee_type, ['daily_paid', 'monthly_paid'], true) ||
                ($employee_type === 'daily_paid' && $daily_wage <= 0) ||
                ($employee_type === 'monthly_paid' && $monthly_salary <= 0)
            ) {
                echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                exit;
            }

            if (!preg_match('/^[A-Za-z ]+$/', $name)) {
                echo json_encode(['success' => false, 'message' => 'Name is required and must contain only letters and spaces.']);
                exit;
            }

            if (!preg_match('/^\d{10}$/', $phone)) {
                echo json_encode(['success' => false, 'message' => 'Phone Number is required and must be exactly 10 digits.']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Debug: print UID before executing update query
                error_log('Edit Employee UID before query: ' . $selectedUid);

                $effectiveCurrentSalary = $employee_type === 'monthly_paid' ? (float)$monthly_salary : (float)$daily_wage;
                $stmt = $pdo->prepare("UPDATE employees SET name = ?, address = ?, employee_type = ?, phone = ?, daily_wage = ?, monthly_salary = ?, current_salary = ? WHERE uid = ? LIMIT 1");
                $stmt->execute([$name, $address, $employee_type, $phone, $daily_wage, $monthly_salary, $effectiveCurrentSalary, $selectedUid]);

                if ($stmt->rowCount() < 1) {
                    $existsStmt = $pdo->prepare("SELECT id FROM employees WHERE uid = ? LIMIT 1");
                    $existsStmt->execute([$selectedUid]);
                    if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Employee not found for selected UID']);
                        exit;
                    }
                }

                $pdo->commit();

                // Debug: print success message after update
                error_log('Employee update succeeded for UID: ' . $selectedUid);
                echo json_encode(['success' => true, 'message' => 'Employee updated successfully!', 'uid' => $selectedUid]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Error updating employee: ' . $e->getMessage()]);
            }
            exit;
        } elseif ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            // Add or Edit Employee
            $uid = $_POST['uid'] ?? '';
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $employee_type = trim((string)($_POST['employee_type'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $daily_wage = $_POST['daily_wage'] ?? 0;
            $monthly_salary = $_POST['monthly_salary'] ?? 0;
            
            // Normalize and validate type & wage values
            $typeRaw = strtolower($employee_type);
            if (strpos($typeRaw, 'month') !== false) {
                $employee_type = 'monthly_paid';
            } elseif (strpos($typeRaw, 'day') !== false) {
                $employee_type = 'daily_paid';
            }
            $daily_wage = is_numeric($daily_wage) ? floatval($daily_wage) : 0;
            $monthly_salary = is_numeric($monthly_salary) ? floatval($monthly_salary) : 0;

            $missingRequired = (
                $name === '' ||
                $phone === '' ||
                $address === '' ||
                !in_array($employee_type, ['daily_paid', 'monthly_paid'], true) ||
                ($employee_type === 'daily_paid' && $daily_wage <= 0) ||
                ($employee_type === 'monthly_paid' && $monthly_salary <= 0)
            );

            if ($missingRequired) {
                $error = "Please fill all required fields";
            } elseif (!preg_match('/^[A-Za-z ]+$/', $name)) {
                $error = "Name is required and must contain only letters and spaces.";
            } elseif (!preg_match('/^\d{10}$/', $phone)) {
                $error = "Phone Number is required and must be exactly 10 digits.";
            } elseif ($employee_type === 'daily_paid' && $daily_wage <= 0) {
                $error = "Please provide a valid Daily Rate / Amount.";
            } elseif ($employee_type === 'monthly_paid' && $monthly_salary <= 0) {
                $error = "Please provide a valid Monthly Salary.";
            } else {
                if ($_POST['action'] === 'add') {
                    // UID must be auto-generated and non-editable.
                    $uid = '';

                    // Generate UID if not provided
                    if (!$uid) {
                        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(uid, 5) AS UNSIGNED)) as max_id FROM employees");
                        $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
                        $uid = 'EMP-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
                    }

                    // Ensure UID is unique even when legacy data has collisions.
                    while (true) {
                        $uidCheck = $pdo->prepare("SELECT id FROM employees WHERE uid = ? LIMIT 1");
                        $uidCheck->execute([$uid]);
                        if (!$uidCheck->fetch()) {
                            break;
                        }
                        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(uid, 5) AS UNSIGNED)) as max_id FROM employees");
                        $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
                        $uid = 'EMP-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
                    }

                    $stmt = $pdo->prepare("INSERT INTO employees (uid, name, address, employee_type, phone, daily_wage, monthly_salary, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$uid, $name, $address, $employee_type, $phone, $daily_wage, $monthly_salary]);
                    $_SESSION['flash_success'] = "Employee added successfully!";
                } else {
                    // Edit Employee
                    $employee_id = $_POST['employee_id'] ?? '';
                    if (!is_numeric($employee_id) || (int)$employee_id <= 0) {
                        $error = "Employee not found";
                    } else {
                        $existsStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? LIMIT 1");
                        $existsStmt->execute([$employee_id]);
                        if (!$existsStmt->fetch()) {
                            $error = "Employee not found";
                        }
                    }

                    if (!$error) {
                        $effectiveCurrentSalary = $employee_type === 'monthly_paid' ? (float)$monthly_salary : (float)$daily_wage;
                        $stmt = $pdo->prepare("UPDATE employees SET name = ?, address = ?, employee_type = ?, phone = ?, daily_wage = ?, monthly_salary = ?, current_salary = ? WHERE id = ?");
                        $stmt->execute([$name, $address, $employee_type, $phone, $daily_wage, $monthly_salary, $effectiveCurrentSalary, $employee_id]);
                        $_SESSION['flash_success'] = "Employee updated successfully!";
                    }
                }

                // Redirect to prevent duplicate submission on page refresh
                if (!$error) {
                    header("Location: ?page=employees");
                    exit;
                }
            }
        // ----------------------------------------
        // Move to Past Employees
        // ----------------------------------------
        } elseif ($_POST['action'] === 'delete') {
            // Soft Delete Employee - Mark as inactive instead of permanent deletion
            $employee_id = $_POST['employee_id'] ?? '';
            $stmt = $pdo->prepare("UPDATE employees SET is_active = 0 WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            $_SESSION['flash_success'] = "Employee moved to past employees list!";
            header("Location: ?page=employees");
            exit;
        // ----------------------------------------
        // Reactivate Past Employee
        // ----------------------------------------
        } elseif ($_POST['action'] === 'reactivate') {
            // Reactivate Employee
            $employee_id = $_POST['employee_id'] ?? '';

            if (!is_numeric($employee_id) || (int)$employee_id <= 0) {
                $_SESSION['flash_error'] = "Employee not found";
                header("Location: ?page=employees");
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, uid, is_active FROM employees WHERE id = ? LIMIT 1");
            $stmt->execute([$employee_id]);
            $employeeRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employeeRow) {
                $_SESSION['flash_error'] = "Employee not found";
                header("Location: ?page=employees");
                exit;
            }

            if ((int)$employeeRow['is_active'] === 1) {
                $_SESSION['flash_error'] = "Employee already active";
                header("Location: ?page=employees");
                exit;
            }

            $uid = trim((string)($employeeRow['uid'] ?? ''));
            if ($uid !== '') {
                $dupStmt = $pdo->prepare("SELECT id FROM employees WHERE uid = ? AND is_active = 1 AND id <> ? LIMIT 1");
                $dupStmt->execute([$uid, $employee_id]);
                if ($dupStmt->fetch()) {
                    $_SESSION['flash_error'] = "Employee already active";
                    header("Location: ?page=employees");
                    exit;
                }
            }

            $stmt = $pdo->prepare("UPDATE employees SET is_active = 1 WHERE id = ? AND is_active = 0");
            $stmt->execute([$employee_id]);
            
            $_SESSION['flash_success'] = "Employee reactivated successfully!";
            header("Location: ?page=employees");
            exit;
        // ----------------------------------------
        // Permanent Employee Delete
        // ----------------------------------------
        } elseif ($_POST['action'] === 'permanent_delete') {
            // Permanently delete only past/inactive employees
            $employee_id = $_POST['employee_id'] ?? '';

            if (!is_numeric($employee_id) || (int)$employee_id <= 0) {
                $_SESSION['flash_error'] = "Invalid employee selected for permanent deletion.";
                header("Location: ?page=employees");
                exit;
            }

            try {
                $pdo->beginTransaction();

                $checkStmt = $pdo->prepare("SELECT id, uid, name, address, phone, employee_type, daily_wage, monthly_salary, is_active FROM employees WHERE id = ? LIMIT 1");
                $checkStmt->execute([$employee_id]);
                $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->rollBack();
                    $_SESSION['flash_error'] = "Employee already deleted";
                    header("Location: ?page=employees");
                    exit;
                }

                if ((int)$row['is_active'] === 1) {
                    $pdo->rollBack();
                    $_SESSION['flash_error'] = "Employee not found";
                    header("Location: ?page=employees");
                    exit;
                }

                $deletedMonth = date('Y-m');
                $archiveStmt = $pdo->prepare("INSERT INTO employee_deletion_history
                    (employee_id, uid, name, address, phone, employee_type, daily_wage, monthly_salary, deleted_month)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $archiveStmt->execute([
                    $row['id'],
                    $row['uid'] ?? null,
                    $row['name'] ?? null,
                    $row['address'] ?? null,
                    $row['phone'] ?? null,
                    $row['employee_type'] ?? null,
                    (float)($row['daily_wage'] ?? 0),
                    (float)($row['monthly_salary'] ?? 0),
                    $deletedMonth
                ]);

                // Remove dependent records before deleting the employee row.
                $stmt = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ?");
                $stmt->execute([$employee_id]);

                $stmt = $pdo->prepare("DELETE FROM salary_payments WHERE employee_id = ?");
                $stmt->execute([$employee_id]);

                $stmt = $pdo->prepare("DELETE FROM employee_salary_bonus WHERE employee_id = ?");
                $stmt->execute([$employee_id]);

                $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ? AND is_active = 0");
                $stmt->execute([$employee_id]);

                if ($stmt->rowCount() < 1) {
                    $pdo->rollBack();
                    $_SESSION['flash_error'] = "Employee already deleted";
                    header("Location: ?page=employees");
                    exit;
                }

                $pdo->commit();
                $_SESSION['flash_success'] = "Employee permanently deleted.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_error'] = "Error deleting employee permanently: " . $e->getMessage();
            }

            header("Location: ?page=employees");
            exit;
        // ----------------------------------------
        // Attendance Persistence
        // ----------------------------------------
        } elseif ($_POST['action'] === 'save_attendance') {
            // Save Attendance
            $attendance_data = $_POST['attendance'] ?? [];
            
            if (!is_array($attendance_data) || empty($attendance_data)) {
                echo json_encode(['success' => false, 'message' => 'No attendance data provided']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                foreach ($attendance_data as $record) {
                    if (!is_array($record)) {
                        throw new Exception('Invalid attendance payload');
                    }

                    $employeeId = isset($record['employee_id']) ? (int)$record['employee_id'] : 0;
                    $attendanceDate = isset($record['date']) ? (string)$record['date'] : '';
                    $status = isset($record['status']) ? (string)$record['status'] : '';

                    if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate) || !in_array($status, ['present', 'absent'], true)) {
                        throw new Exception('Invalid attendance data provided');
                    }

                    // Validate that employee exists
                    $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
                    $stmt->execute([$employeeId]);
                    if (!$stmt->fetch()) {
                        throw new Exception("Employee ID {$employeeId} does not exist");
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) 
                                          VALUES (?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE status = VALUES(status)");
                    $stmt->execute([$employeeId, $attendanceDate, $status]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error saving attendance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Manage Employees Report Data
        // ----------------------------------------
        } elseif ($_POST['action'] === 'generate_report') {
            // Generate Monthly Report (existing report logic)
            $month = $_POST['month'] ?? '';
            
            if (empty($month)) {
                echo json_encode(['success' => false, 'message' => 'Month not specified']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("SELECT employee_id, bonus_amount FROM employee_salary_bonus WHERE month = ?");
                $stmt->execute([$month]);
                $bonusAmounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // Parse month (YYYY-MM)
                $year = date('Y', strtotime($month . '-01'));
                $month_num = date('m', strtotime($month . '-01'));
                
                // Calculate total working days in the month (Mon-Fri)
                $first_day = strtotime($year . '-' . $month_num . '-01');
                $last_day = strtotime(date('Y-m-t', $first_day));
                
                $working_days = 0;
                for ($date = $first_day; $date <= $last_day; $date = strtotime('+1 day', $date)) {
                    $day_of_week = date('N', $date); // 1=Monday, 7=Sunday
                    if ($day_of_week <= 5) { // Monday to Friday
                        $working_days++;
                    }
                }
                
                // Get all employees (active and inactive)
                $stmt = $pdo->query("SELECT * FROM employees ORDER BY id ASC");
                $all_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($all_employees)) {
                    echo json_encode(['success' => false, 'message' => 'No data available to generate report']);
                    exit;
                }

                $report = [];
                
                foreach ($all_employees as $employee) {
                    // Determine correct employee type using the same logic as the UI
                    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                    if ($rawType === '') {
                        // Determine type from salary values when stored type is missing
                        if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                            $employee_type = 'monthly_paid';
                        } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                            $employee_type = 'daily_paid';
                        } else {
                            $employee_type = 'daily_paid';
                        }
                    } elseif (strpos($rawType, 'month') !== false) {
                        $employee_type = 'monthly_paid';
                    } elseif (strpos($rawType, 'day') !== false) {
                        $employee_type = 'daily_paid';
                    } else {
                        // fallback based on stored enum
                        $employee_type = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                    }
                    
                    // Count present days for the month
                    $stmt = $pdo->prepare("SELECT COUNT(*) as present_count FROM attendance 
                                          WHERE employee_id = ? AND attendance_date LIKE ? AND status = 'present'");
                    $stmt->execute([$employee['id'], $year . '-' . $month_num . '-%']);
                    $present_days = $stmt->fetch(PDO::FETCH_ASSOC)['present_count'];
                    
                    // Calculate salary based on correct type
                    $final_salary = 0;
                    $salary_info = '';
                    
                    if ($employee_type === 'daily_paid') {
                        $daily_rate = (float)($employee['daily_wage'] ?? 0);
                        $final_salary = $daily_rate * $present_days;
                        $salary_info = 'LKR ' . number_format($daily_rate, 2) . ' / day';
                    } else {
                        // Monthly paid: Monthly Salary / Working Days * Present Days
                        $monthly_salary = (float)($employee['monthly_salary'] ?? 0);
                        if ($monthly_salary > 0 && $working_days > 0) {
                            $per_day_salary = $monthly_salary / $working_days;
                            $final_salary = $per_day_salary * $present_days;
                        }
                        $salary_info = 'LKR ' . number_format($monthly_salary, 2) . ' / month';
                    }

                    $base_salary = $final_salary;
                    $bonus_amount = (float)($bonusAmounts[$employee['id']] ?? 0);
                    $final_salary_with_bonus = $base_salary + $bonus_amount;
                    
                    $report[] = [
                        'id' => $employee['id'],
                        'uid' => $employee['uid'],
                        'name' => $employee['name'],
                        'type' => $employee_type,
                        'salary_info' => $salary_info,
                        'base_salary' => number_format($base_salary, 2, '.', ''),
                        'bonus_amount' => number_format($bonus_amount, 2, '.', ''),
                        'present_days' => $present_days,
                        'total_working_days' => $working_days,
                        'final_salary' => number_format($final_salary_with_bonus, 2, '.', '')
                    ];
                }

                echo json_encode(['success' => true, 'report' => $report]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error generating report: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Salary View Data
        // ----------------------------------------
        } elseif ($_POST['action'] === 'load_salary_data') {
            // Load salary data for salary view modal
            $month = $_POST['month'] ?? '';
            if (empty($month)) {
                echo json_encode(['success' => false, 'message' => 'Month not specified']);
                exit;
            }

            try {
                $reportData = $buildMonthlySalaryData($month);

                if (empty($reportData['salaryData'])) {
                    echo json_encode(['success' => false, 'message' => 'No employee found']);
                    exit;
                }

                echo json_encode(['success' => true, 'salaryData' => $reportData['salaryData']]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading salary data: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Employee Summary Report Data
        // ----------------------------------------
        } elseif ($_POST['action'] === 'load_employee_management_summary') {
            $month = $_POST['month'] ?? date('Y-m');
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = date('Y-m');
            }

            try {
                $stmt = $pdo->query("SELECT * FROM employees ORDER BY id ASC");
                $allEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $activeEmployees = array_values(array_filter($allEmployees, function ($row) {
                    return (int)($row['is_active'] ?? 1) === 1;
                }));
                $pastEmployees = array_values(array_filter($allEmployees, function ($row) {
                    return (int)($row['is_active'] ?? 1) === 0;
                }));

                $year = (int)substr($month, 0, 4);
                $monthPattern = $month . '-%';

                $presentDaysStmt = $pdo->prepare("SELECT employee_id, COUNT(*) as present_days
                                                 FROM attendance
                                                 WHERE status = 'present' AND attendance_date LIKE ?
                                                 GROUP BY employee_id");
                $presentDaysStmt->execute([$monthPattern]);
                $presentDaysMap = [];
                foreach ($presentDaysStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $presentDaysMap[(int)$row['employee_id']] = (int)$row['present_days'];
                }

                $paymentStmt = $pdo->prepare("SELECT employee_id, status FROM salary_payments WHERE month = ?");
                $paymentStmt->execute([$month]);
                $paymentStatusMap = $paymentStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $eligibleApprovedMap = [];
                try {
                    $eligibilityStmt = $pdo->prepare("SELECT employee_id
                                                     FROM employee_yearly_performance
                                                     WHERE year = ? AND is_eligible = 1 AND increment_approved = 1");
                    $eligibilityStmt->execute([$year]);
                    foreach ($eligibilityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $eligibleApprovedMap[(int)$row['employee_id']] = true;
                    }
                } catch (Exception $e) {
                    // Keep report generation resilient if yearly performance table is unavailable.
                }

                $normalizeType = function ($employee) {
                    $rawType = strtolower(trim((string)($employee['employee_type'] ?? '')));
                    if ($rawType === '') {
                        if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                            return 'monthly_paid';
                        }
                        return 'daily_paid';
                    }
                    if (strpos($rawType, 'month') !== false) {
                        return 'monthly_paid';
                    }
                    if (strpos($rawType, 'day') !== false) {
                        return 'daily_paid';
                    }
                    return $rawType === 'monthly_paid' ? 'monthly_paid' : 'daily_paid';
                };

                $salaryData = [];
                foreach ($activeEmployees as $employee) {
                    $employeeId = (int)($employee['id'] ?? 0);
                    $type = $normalizeType($employee);
                    $presentDays = (int)($presentDaysMap[$employeeId] ?? 0);

                    if ($type === 'daily_paid') {
                        $dailyWage = (float)($employee['daily_wage'] ?? 0);
                        $baseSalary = $dailyWage * $presentDays;
                    } else {
                        $currentSalary = (float)($employee['current_salary'] ?? 0);
                        if ($currentSalary <= 0) {
                            $currentSalary = (float)($employee['monthly_salary'] ?? 0);
                        }
                        $baseSalary = $currentSalary;
                    }

                    $isEligibleApproved = !empty($eligibleApprovedMap[$employeeId]);
                    $bonusAmount = $isEligibleApproved ? ($baseSalary * 0.05) : 0.0;
                    $finalSalary = $baseSalary + $bonusAmount;

                    $salaryData[] = [
                        'id' => $employeeId,
                        'uid' => $employee['uid'] ?? 'N/A',
                        'name' => $employee['name'] ?? 'N/A',
                        'address' => $employee['address'] ?? 'N/A',
                        'phone' => $employee['phone'] ?? 'N/A',
                        'type' => $type,
                        'present_days' => $presentDays,
                        'base_salary' => number_format($baseSalary, 2, '.', ''),
                        'bonus_amount' => number_format($bonusAmount, 2, '.', ''),
                        'final_salary_with_bonus' => number_format($finalSalary, 2, '.', ''),
                        'payment_status' => $paymentStatusMap[$employeeId] ?? 'pending',
                    ];
                }

                $monthStart = $month . '-01';
                $monthEnd = date('Y-m-t', strtotime($monthStart));

                $newJoiners = [];
                $leftEmployees = [];
                foreach ($allEmployees as $employee) {
                    $createdAt = !empty($employee['created_at']) ? substr((string)$employee['created_at'], 0, 10) : '';
                    if ($createdAt >= $monthStart && $createdAt <= $monthEnd) {
                        $newJoiners[] = $employee;
                    }

                    $updatedAt = !empty($employee['updated_at']) ? substr((string)$employee['updated_at'], 0, 10) : '';
                    if ((int)($employee['is_active'] ?? 1) === 0 && $updatedAt >= $monthStart && $updatedAt <= $monthEnd) {
                        $leftEmployees[] = [
                            'uid' => $employee['uid'] ?? 'N/A',
                            'name' => $employee['name'] ?? 'N/A',
                            'address' => $employee['address'] ?? 'N/A',
                            'phone' => $employee['phone'] ?? 'N/A',
                            'employee_type' => $employee['employee_type'] ?? 'daily_paid',
                            'daily_wage' => $employee['daily_wage'] ?? 0,
                            'monthly_salary' => $employee['monthly_salary'] ?? 0,
                            'exit_type' => 'Inactive',
                            'exit_date' => $updatedAt,
                        ];
                    }
                }

                $historyStmt = $pdo->prepare("SELECT uid, name, address, phone, employee_type, daily_wage, monthly_salary, deleted_at
                                              FROM employee_deletion_history
                                              WHERE deleted_month = ?");
                $historyStmt->execute([$month]);
                $archivedLeftEmployees = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($archivedLeftEmployees as $archived) {
                    $leftEmployees[] = [
                        'uid' => $archived['uid'] ?? 'N/A',
                        'name' => $archived['name'] ?? 'N/A',
                        'address' => $archived['address'] ?? 'N/A',
                        'phone' => $archived['phone'] ?? 'N/A',
                        'employee_type' => $archived['employee_type'] ?? 'daily_paid',
                        'daily_wage' => $archived['daily_wage'] ?? 0,
                        'monthly_salary' => $archived['monthly_salary'] ?? 0,
                        'exit_type' => 'Deleted',
                        'exit_date' => !empty($archived['deleted_at']) ? substr((string)$archived['deleted_at'], 0, 10) : null,
                    ];
                }

                $leftEmployees = array_values(array_reduce($leftEmployees, function ($carry, $employee) {
                    $key = ($employee['uid'] ?? '') . '|' . ($employee['exit_date'] ?? '');
                    $carry[$key] = $employee;
                    return $carry;
                }, []));

                echo json_encode([
                    'success' => true,
                    'month' => $month,
                    'activeEmployees' => $activeEmployees,
                    'pastEmployees' => $pastEmployees,
                    'salaryData' => $salaryData,
                    'newJoiners' => $newJoiners,
                    'leftEmployees' => $leftEmployees
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading report data: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Bonus Allocation
        // ----------------------------------------
        } elseif ($_POST['action'] === 'apply_salary_bonus') {
            $month = $_POST['month'] ?? '';
            $scope = $_POST['scope'] ?? 'single';
            $identifierType = $_POST['identifier_type'] ?? 'uid';
            $identifierValue = trim((string)($_POST['identifier_value'] ?? ''));
            $bonusAmount = $_POST['bonus_amount'] ?? '';

            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                echo json_encode(['success' => false, 'message' => 'Invalid month format']);
                exit;
            }

            if (!in_array($scope, ['single', 'all'], true)) {
                echo json_encode(['success' => false, 'message' => 'Select an employee or choose all employees']);
                exit;
            }

            if (!in_array($identifierType, ['uid', 'name'], true)) {
                echo json_encode(['success' => false, 'message' => 'Select an employee or choose all employees']);
                exit;
            }

            if (!is_numeric($bonusAmount) || (float)$bonusAmount < 0) {
                echo json_encode(['success' => false, 'message' => 'Enter a valid bonus amount']);
                exit;
            }

            $bonusAmount = round((float)$bonusAmount, 2);

            try {
                $pdo->beginTransaction();

                if ($scope === 'all') {
                    $stmt = $pdo->query("SELECT id FROM employees WHERE is_active = 1");
                    $employeeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    if (empty($employeeIds)) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'No active employees found']);
                        exit;
                    }

                    $upsert = $pdo->prepare("INSERT INTO employee_salary_bonus (employee_id, month, bonus_amount) VALUES (?, ?, ?)
                                             ON DUPLICATE KEY UPDATE bonus_amount = VALUES(bonus_amount)");
                    foreach ($employeeIds as $employeeId) {
                        $upsert->execute([$employeeId, $month, $bonusAmount]);
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Bonus applied to all active employees']);
                    exit;
                }

                if (empty($identifierValue)) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Select an employee or choose all employees']);
                    exit;
                }

                if ($identifierType === 'name') {
                    $find = $pdo->prepare("SELECT id FROM employees WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1");
                } else {
                    $find = $pdo->prepare("SELECT id FROM employees WHERE LOWER(uid) = LOWER(?) AND is_active = 1 LIMIT 1");
                }

                $find->execute([$identifierValue]);
                $employeeId = $find->fetchColumn();

                if (!$employeeId) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Employee not found']);
                    exit;
                }

                $upsert = $pdo->prepare("INSERT INTO employee_salary_bonus (employee_id, month, bonus_amount) VALUES (?, ?, ?)
                                         ON DUPLICATE KEY UPDATE bonus_amount = VALUES(bonus_amount)");
                $upsert->execute([$employeeId, $month, $bonusAmount]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Bonus applied successfully']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Error applying bonus: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Payment Status Update
        // ----------------------------------------
        } elseif ($_POST['action'] === 'set_payment_status') {
            $employeeId = $_POST['employee_id'] ?? '';
            $month = $_POST['month'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (!$employeeId || !$month || !in_array($status, ['paid', 'pending'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("INSERT INTO salary_payments (employee_id, month, status) VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE status = VALUES(status)");
                $stmt->execute([$employeeId, $month, $status]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating payment status: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Single Attendance Update
        // ----------------------------------------
        } elseif ($_POST['action'] === 'update_single_attendance') {
            $employeeId = $_POST['employee_id'] ?? '';
            $attendanceDate = $_POST['attendance_date'] ?? '';
            $status = $_POST['status'] ?? '';

            if (!$employeeId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate) || !in_array($status, ['present', 'absent'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
                $stmt->execute([$employeeId]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Employee not found']);
                    exit;
                }

                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
                $stmt->execute([$employeeId, $attendanceDate, $status]);

                echo json_encode(['success' => true, 'message' => 'Attendance updated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating attendance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Attendance Lookup by Date
        // ----------------------------------------
        }elseif ($_POST['action'] === 'load_attendance') {
            // Load Attendance for Date
            $date = $_POST['date'] ?? '';
            
            if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['success' => false, 'message' => 'Date not specified']);
                exit;
            }
            
            try {
                // Only load attendance for existing employees
                $stmt = $pdo->prepare("SELECT a.employee_id, a.status FROM attendance a 
                                      INNER JOIN employees e ON a.employee_id = e.id 
                                      WHERE a.attendance_date = ?");
                $stmt->execute([$date]);
                $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'attendance' => $attendance_records]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading attendance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Attendance Summary (Present Days)
        // ----------------------------------------
        } elseif ($_POST['action'] === 'load_attendance_summary') {
            $month = (int)($_POST['month'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);

            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid month or year']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("SELECT a.employee_id, e.name as employee_name, COUNT(*) as total_present_days
                                      FROM attendance a
                                      INNER JOIN employees e ON e.id = a.employee_id
                                      WHERE a.status = 'present'
                                      AND MONTH(a.attendance_date) = ?
                                      AND YEAR(a.attendance_date) = ?
                                      GROUP BY a.employee_id, e.name
                                      ORDER BY a.employee_id ASC");
                $stmt->execute([$month, $year]);
                $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'summary' => $summary]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading attendance summary: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Monthly Performance Input Loader
        // ----------------------------------------
        } elseif ($_POST['action'] === 'load_monthly_performance') {
            $month = (int)($_POST['month'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);

            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid month or year']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("SELECT e.id as employee_id, e.uid, e.name, COALESCE(p.stars, 0) as stars
                                      FROM employees e
                                      LEFT JOIN employee_monthly_performance p
                                        ON p.employee_id = e.id AND p.month = ? AND p.year = ?
                                      WHERE e.is_active = 1
                                      ORDER BY e.id ASC");
                $stmt->execute([$month, $year]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'employees' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading monthly performance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Monthly Performance Save
        // ----------------------------------------
        } elseif ($_POST['action'] === 'save_monthly_performance') {
            $month = (int)($_POST['month'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);
            $ratings = $_POST['ratings'] ?? [];

            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100 || !is_array($ratings)) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO employee_monthly_performance (employee_id, month, year, stars)
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE stars = VALUES(stars)");

                foreach ($ratings as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $employeeId = (int)($row['employee_id'] ?? 0);
                    $stars = (int)($row['stars'] ?? -1);
                    if ($employeeId <= 0 || $stars < 0 || $stars > 5) {
                        throw new Exception('Invalid performance data');
                    }

                    $stmt->execute([$employeeId, $month, $year, $stars]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Monthly performance saved successfully']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Error saving monthly performance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Yearly Performance Calculation
        // ----------------------------------------
        } elseif ($_POST['action'] === 'calculate_yearly_performance') {
            $year = (int)($_POST['year'] ?? 0);
            if ($year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid year']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT employee_id, SUM(stars) as total_stars
                                      FROM employee_monthly_performance
                                      WHERE year = ?
                                      GROUP BY employee_id");
                $stmt->execute([$year]);
                $totalByEmployee = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $totalByEmployee[(int)$row['employee_id']] = (int)$row['total_stars'];
                }

                $stmt = $pdo->prepare("SELECT employee_id, increment_approved
                                      FROM employee_yearly_performance
                                      WHERE year = ?");
                $stmt->execute([$year]);
                $approvalMap = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $approvalMap[(int)$row['employee_id']] = [
                        'increment_approved' => (int)($row['increment_approved'] ?? 0),
                    ];
                }

                $stmt = $pdo->query("SELECT id, uid, name FROM employees WHERE is_active = 1 ORDER BY id ASC");
                $employeesForYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $eligible = [];
                foreach ($employeesForYear as $emp) {
                    $employeeId = (int)$emp['id'];
                    $totalStars = (int)($totalByEmployee[$employeeId] ?? 0);
                    if ($totalStars >= 50) {
                        $eligible[] = ['employee_id' => $employeeId, 'total_stars' => $totalStars];
                    }
                }

                usort($eligible, function ($a, $b) {
                    if ($a['total_stars'] === $b['total_stars']) {
                        return $a['employee_id'] <=> $b['employee_id'];
                    }
                    return $b['total_stars'] <=> $a['total_stars'];
                });

                $topPerformerId = !empty($eligible) ? (int)$eligible[0]['employee_id'] : 0;

                $upsertYearly = $pdo->prepare("INSERT INTO employee_yearly_performance
                    (employee_id, year, total_stars, is_eligible, is_top_performer, increment_approved, increment_applied_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        total_stars = VALUES(total_stars),
                        is_eligible = VALUES(is_eligible),
                        is_top_performer = VALUES(is_top_performer),
                        increment_approved = VALUES(increment_approved),
                        increment_applied_at = VALUES(increment_applied_at)");

                $updateEmployee = $pdo->prepare("UPDATE employees SET total_stars = ?, is_top_performer = ?, increment_approved = ? WHERE id = ?");

                foreach ($employeesForYear as $emp) {
                    $employeeId = (int)$emp['id'];
                    $totalStars = (int)($totalByEmployee[$employeeId] ?? 0);
                    $isEligible = $totalStars >= 50 ? 1 : 0;
                    $isTop = $topPerformerId === $employeeId ? 1 : 0;
                    $approved = (int)($approvalMap[$employeeId]['increment_approved'] ?? 0);

                    $upsertYearly->execute([$employeeId, $year, $totalStars, $isEligible, $isTop, $approved, null]);
                    $updateEmployee->execute([$totalStars, $isTop, $approved, $employeeId]);
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Yearly performance calculated successfully',
                    'top_performer_id' => $topPerformerId,
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Error calculating yearly performance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Yearly Performance Summary Loader
        // ----------------------------------------
        } elseif ($_POST['action'] === 'load_yearly_performance') {
            $year = (int)($_POST['year'] ?? 0);
            if ($year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid year']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("SELECT e.id as employee_id, e.uid, e.name,
                                      COALESCE(y.total_stars, 0) as total_stars,
                                      COALESCE(y.is_eligible, 0) as is_eligible,
                                      COALESCE(y.is_top_performer, 0) as is_top_performer,
                                      COALESCE(y.increment_approved, 0) as increment_approved
                                      FROM employees e
                                      LEFT JOIN employee_yearly_performance y
                                        ON y.employee_id = e.id AND y.year = ?
                                      WHERE e.is_active = 1
                                      ORDER BY COALESCE(y.total_stars, 0) DESC, e.id ASC");
                $stmt->execute([$year]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'summary' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading yearly performance: ' . $e->getMessage()]);
            }
            exit;
        // ----------------------------------------
        // Approve Yearly Increment
        // ----------------------------------------
        } elseif ($_POST['action'] === 'approve_yearly_increment') {
            $year = (int)($_POST['year'] ?? 0);
            if ($year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid year']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT y.employee_id, y.total_stars, e.employee_type, e.daily_wage, e.monthly_salary, e.current_salary,
                                      e.uid, e.name, e.is_top_performer, e.increment_approved, e.last_increment_year
                                      FROM employee_yearly_performance y
                                      INNER JOIN employees e ON e.id = y.employee_id
                                      WHERE y.year = ? AND y.is_top_performer = 1 AND y.total_stars >= 50
                                      LIMIT 1");
                $stmt->execute([$year]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No eligible top performer found for selected year']);
                    exit;
                }

                $employeeId = (int)$row['employee_id'];
                $type = strtolower(trim((string)($row['employee_type'] ?? '')));
                $lastIncrementYear = isset($row['last_increment_year']) && $row['last_increment_year'] !== null ? (int)$row['last_increment_year'] : 0;

                if ((int)($row['is_top_performer'] ?? 0) !== 1) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Selected employee is not marked as top performer']);
                    exit;
                }

                $markApproved = $pdo->prepare("UPDATE employees SET increment_approved = 1 WHERE id = ?");
                $markApproved->execute([$employeeId]);

                if ($lastIncrementYear === $year) {
                    $stmt = $pdo->prepare("UPDATE employee_yearly_performance
                                          SET increment_approved = 1
                                          WHERE employee_id = ? AND year = ?");
                    $stmt->execute([$employeeId, $year]);
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Increment already applied for this year']);
                    exit;
                }

                $currentSalary = (float)($row['current_salary'] ?? 0);
                if ($currentSalary <= 0) {
                    $currentSalary = (strpos($type, 'month') !== false || (float)($row['monthly_salary'] ?? 0) > 0)
                        ? (float)($row['monthly_salary'] ?? 0)
                        : (float)($row['daily_wage'] ?? 0);
                }

                $newSalary = $currentSalary * 1.05;

                if (strpos($type, 'month') !== false || (float)($row['monthly_salary'] ?? 0) > 0) {
                    $upd = $pdo->prepare("UPDATE employees SET monthly_salary = ?, current_salary = ?, increment_approved = 1, last_increment_year = ? WHERE id = ?");
                    $upd->execute([$newSalary, $newSalary, $year, $employeeId]);
                } else {
                    $upd = $pdo->prepare("UPDATE employees SET daily_wage = ?, current_salary = ?, increment_approved = 1, last_increment_year = ? WHERE id = ?");
                    $upd->execute([$newSalary, $newSalary, $year, $employeeId]);
                }

                $stmt = $pdo->prepare("UPDATE employee_yearly_performance
                                      SET increment_approved = 1
                                      WHERE employee_id = ? AND year = ?");
                $stmt->execute([$employeeId, $year]);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Increment approved and applied (5%) for ' . ($row['name'] ?? 'employee') . ' [' . ($row['uid'] ?? 'N/A') . ']'
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Error approving increment: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}

// Now include header AFTER POST processing
include 'header.php';

// Ensure is_active column exists
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN is_active TINYINT(1) DEFAULT 1");
} catch (Exception $e) {
    // Column already exists
}

// Ensure uid column exists and has values
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN uid VARCHAR(50) UNIQUE");
} catch (Exception $e) {
    // Column already exists
}

// Ensure employee_type, daily_wage, and monthly_salary columns exist
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN employee_type ENUM('daily_paid', 'monthly_paid') DEFAULT 'daily_paid'");
} catch (Exception $e) {
    // Column already exists
}
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN daily_wage DECIMAL(10,2) DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN total_stars INT NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN is_top_performer TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN increment_approved TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN base_salary DECIMAL(10,2) NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN current_salary DECIMAL(10,2) NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN last_increment_year INT NULL DEFAULT NULL");
} catch (Exception $e) {
    // Column already exists
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_monthly_performance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        month TINYINT NOT NULL,
        year SMALLINT NOT NULL,
        stars TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_employee_month_year (employee_id, month, year),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {
    // Keep workflow unchanged if table creation fails.
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_yearly_performance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year SMALLINT NOT NULL,
        total_stars INT NOT NULL DEFAULT 0,
        is_eligible TINYINT(1) NOT NULL DEFAULT 0,
        is_top_performer TINYINT(1) NOT NULL DEFAULT 0,
        increment_approved TINYINT(1) NOT NULL DEFAULT 0,
        increment_applied_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_employee_year (employee_id, year),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {
    // Keep workflow unchanged if table creation fails.
}

try {
    $pdo->exec("ALTER TABLE employee_yearly_performance ADD COLUMN increment_applied_at TIMESTAMP NULL DEFAULT NULL");
} catch (Exception $e) {
    // Column already exists or table not available yet.
}

// Normalize employee_type values and ensure default
try {
    // If the column is empty (legacy rows), infer from salary fields.
    $pdo->exec("UPDATE employees SET employee_type = 'monthly_paid' WHERE (employee_type = '' OR employee_type IS NULL) AND (monthly_salary > 0)");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE (employee_type = '' OR employee_type IS NULL) AND (daily_wage > 0)");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE employee_type IS NULL OR TRIM(employee_type) = ''");

    // Normalize string variants
    $pdo->exec("UPDATE employees SET employee_type = 'monthly_paid' WHERE LOWER(TRIM(employee_type)) LIKE '%month%'");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE LOWER(TRIM(employee_type)) LIKE '%day%'");
} catch (Exception $e) {
    // ignore
}

// Generate UIDs for employees that don't have one
$stmt = $pdo->query("SELECT id FROM employees WHERE uid IS NULL OR uid = ''");
$missingUids = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($missingUids as $row) {
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(uid, 5) AS UNSIGNED)) as max_id FROM employees WHERE uid IS NOT NULL");
    $stmt->execute();
    $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
    $newUid = 'EMP-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
    
    $updateStmt = $pdo->prepare("UPDATE employees SET uid = ? WHERE id = ?");
    $updateStmt->execute([$newUid, $row['id']]);
}

// Get all active employees
$stmt = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY id ASC");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fix missing monthly_salary values for existing monthly-paid employees (legacy data)
foreach ($employees as &$employee) {
    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
    $typeKey = (strpos($rawType, 'month') !== false) ? 'monthly_paid' : ((strpos($rawType, 'day') !== false) ? 'daily_paid' : $rawType);

    if ($typeKey === 'monthly_paid' && (float)($employee['monthly_salary'] ?? 0) <= 0 && (float)($employee['daily_wage'] ?? 0) > 0) {
        // If monthly salary is missing but daily_wage contains the intended value, migrate it.
        $employee['monthly_salary'] = $employee['daily_wage'];
        $stmt = $pdo->prepare("UPDATE employees SET monthly_salary = ? WHERE id = ?");
        $stmt->execute([$employee['monthly_salary'], $employee['id']]);
    }

    $effectiveSalary = ($typeKey === 'monthly_paid')
        ? (float)($employee['monthly_salary'] ?? 0)
        : (float)($employee['daily_wage'] ?? 0);
    $baseSalary = (float)($employee['base_salary'] ?? 0);
    $currentSalary = (float)($employee['current_salary'] ?? 0);

    if ($baseSalary <= 0) {
        $baseSalary = $effectiveSalary;
    }
    if ($currentSalary <= 0) {
        $currentSalary = $effectiveSalary;
    }

    $employee['base_salary'] = $baseSalary;
    $employee['current_salary'] = $currentSalary;

    $syncStmt = $pdo->prepare("UPDATE employees SET base_salary = ?, current_salary = ? WHERE id = ?");
    $syncStmt->execute([$baseSalary, $currentSalary, $employee['id']]);
}
unset($employee);

// Get all past/inactive employees
$stmt = $pdo->query("SELECT * FROM employees WHERE is_active = 0 ORDER BY id ASC");
$pastEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pastEmployees as &$employee) {
    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
    $typeKey = (strpos($rawType, 'month') !== false) ? 'monthly_paid' : ((strpos($rawType, 'day') !== false) ? 'daily_paid' : $rawType);

    if ($typeKey === 'monthly_paid' && (float)($employee['monthly_salary'] ?? 0) <= 0 && (float)($employee['daily_wage'] ?? 0) > 0) {
        $employee['monthly_salary'] = $employee['daily_wage'];
        $stmt = $pdo->prepare("UPDATE employees SET monthly_salary = ? WHERE id = ?");
        $stmt->execute([$employee['monthly_salary'], $employee['id']]);
    }

    $effectiveSalary = ($typeKey === 'monthly_paid')
        ? (float)($employee['monthly_salary'] ?? 0)
        : (float)($employee['daily_wage'] ?? 0);
    $baseSalary = (float)($employee['base_salary'] ?? 0);
    $currentSalary = (float)($employee['current_salary'] ?? 0);

    if ($baseSalary <= 0) {
        $baseSalary = $effectiveSalary;
    }
    if ($currentSalary <= 0) {
        $currentSalary = $effectiveSalary;
    }

    $employee['base_salary'] = $baseSalary;
    $employee['current_salary'] = $currentSalary;

    $syncStmt = $pdo->prepare("UPDATE employees SET base_salary = ?, current_salary = ? WHERE id = ?");
    $syncStmt->execute([$baseSalary, $currentSalary, $employee['id']]);
}
unset($employee);

// Combine all employees for search
$allEmployees = array_merge($employees, $pastEmployees);

// Get employee for edit (if editing)
$editEmployee = null;
$editEmployeeId = $_GET['edit_id'] ?? null;
if ($editEmployeeId) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$editEmployeeId]);
    $editEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<?php if (isset($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<style>
/* ========================================
    Employee Toolbar Styling
    ======================================== */
.employee-actions-sticky {
    position: sticky;
    top: 0;
    z-index: 1020;
    background: #fff;
    border-bottom: 1px solid #e9ecef;
    padding: 0.5rem 0.25rem;
}

.employee-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.employee-toolbar-title {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0;
    line-height: 1.2;
    white-space: nowrap;
}

.employee-toolbar-actions {
    display: flex;
    flex-wrap: nowrap;
    gap: 0.5rem;
    justify-content: center;
    align-items: center;
    overflow-x: auto;
    padding-bottom: 0.15rem;
    scrollbar-width: thin;
}

.employee-toolbar-actions .btn {
    white-space: nowrap;
    margin: 0;
}

@media (max-width: 992px) {
    .employee-toolbar {
        gap: 0.5rem;
    }

    .employee-toolbar-title {
        font-size: 1.05rem;
    }
}
</style>

<!-- ========================================
    Employee Management Toolbar
    ======================================== -->
<div class="employee-actions-sticky mb-4">
    <div class="employee-toolbar">
    <h4 class="employee-toolbar-title">Manage Employees</h4>
    <div class="employee-toolbar-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="resetForm()">
            <i class="bi bi-plus-lg me-2"></i>Add Employee
        </button>
        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#attendanceModal" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceModal')).show(); return false;">
            <i class="bi bi-calendar-check me-2"></i>Mark Attendance
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#salaryModal" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('salaryModal')).show(); return false;">
            <i class="bi bi-currency-dollar me-2"></i>View Employee Salary
        </button>
        <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
            <i class="bi bi-eye me-2"></i>View Employee Details
        </button>
        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#pastEmployeesModal">
            <i class="bi bi-archive me-2"></i>View Past Employees
        </button>
    </div>
    </div>
</div>

<!-- ========================================
    Active Employees Table
    ======================================== -->
<div class="card">
    <div class="card-body">
        <h5 class="mb-4 text-primary">Employee Details</h5>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                    <tr>
                        <th>UID</th>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Type</th>
                        <th>Phone Number</th>
                        <th>Daily Wage / Monthly Salary</th>
                        <th>Current Salary</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No employees found. <a href="#" onclick="document.querySelector('[data-bs-target=\"#employeeModal\"]').click()">Add one now</a></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></strong></td>
                            <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($employee['address'] ?? 'N/A') ?></td>
                            <?php
                                $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                                if ($rawType === '') {
                                    // Determine type from salary values when stored type is missing
                                    if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                        $typeKey = 'monthly_paid';
                                    } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                        $typeKey = 'daily_paid';
                                    } else {
                                        $typeKey = 'daily_paid';
                                    }
                                } elseif (strpos($rawType, 'month') !== false) {
                                    $typeKey = 'monthly_paid';
                                } elseif (strpos($rawType, 'day') !== false) {
                                    $typeKey = 'daily_paid';
                                } else {
                                    // fallback based on stored enum
                                    $typeKey = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                }
                                $typeLabel = $typeKey === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';

                                // Determine display amount based on type
                                if ($typeKey === 'daily_paid') {
                                    $displayAmount = number_format($employee['daily_wage'] ?? 0, 2);
                                    $displaySuffix = '/ day';
                                } else {
                                    // If monthly is missing, use daily_wage as backup (legacy data)
                                    $monthly = (float)($employee['monthly_salary'] ?? 0);
                                    if ($monthly <= 0) {
                                        $monthly = (float)($employee['daily_wage'] ?? 0);
                                    }
                                    $displayAmount = number_format($monthly, 2);
                                    $displaySuffix = '/ month';
                                }
                            ?>
                            <td>
                                <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                                <div class="mt-1">
                                    LKR <?= $displayAmount ?> <?= $displaySuffix ?>
                                </div>
                            </td>
                            <td>
                                <?php
                                    $currentSalaryDisplay = (float)($employee['current_salary'] ?? 0);
                                    if ($currentSalaryDisplay <= 0) {
                                        $currentSalaryDisplay = ($typeKey === 'daily_paid')
                                            ? (float)($employee['daily_wage'] ?? 0)
                                            : (float)($employee['monthly_salary'] ?? 0);
                                    }
                                ?>
                                <strong>LKR <?= number_format($currentSalaryDisplay, 2) ?></strong>
                            </td>
                            <td class="text-center text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="editEmployee(<?= $employee['id'] ?>)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['name'] ?? 'Employee') ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-4">
            <button class="btn btn-primary" onclick="downloadEmployeeManagementSummary()"><i class="bi bi-download me-2"></i>Download Report</button>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-body">
        <h5 class="mb-3 text-primary">Yearly Performance Bonus System</h5>

        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-3">
                <label for="performanceMonth" class="form-label">Month</label>
                <select id="performanceMonth" class="form-select">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="performanceYear" class="form-label">Year</label>
                <input type="number" id="performanceYear" class="form-control" min="2000" max="2100" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-primary w-100" onclick="saveMonthlyPerformance()">Save Monthly Performance</button>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-primary w-100" onclick="calculateYearlyPerformance()">Calculate Yearly Performance</button>
            </div>
        </div>

        <div class="table-responsive mb-4" style="max-height: 320px; overflow-y: auto;">
            <table class="table table-sm table-hover align-middle" id="monthlyPerformanceTable">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Monthly Stars (0-5)</th>
                    </tr>
                </thead>
                <tbody id="monthlyPerformanceBody">
                    <tr>
                        <td colspan="3" class="text-center text-muted">Select month and year to assign stars</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-success" onclick="approveYearlyIncrement()">Approve Increment</button>
        </div>

        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
            <table class="table table-striped align-middle" id="yearlyPerformanceTable">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Total Stars</th>
                        <th>Eligibility</th>
                        <th>Top Performer</th>
                        <th>Increment Approved</th>
                    </tr>
                </thead>
                <tbody id="yearlyPerformanceBody">
                    <tr>
                        <td colspan="6" class="text-center text-muted">Yearly performance data will appear here</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================
    Add / Edit Employee Modal
    ======================================== -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="employeeModalTitle">Add Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="employeeForm" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="employee_id" id="employeeId" value="">
                    
                    <div class="mb-3">
                        <label for="uid" class="form-label">Auto-Generated UID</label>
                        <input type="text" class="form-control" id="uid" name="uid" readonly>
                        <small class="text-muted">Will be auto-generated on add</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required title="Name can contain only letters and spaces">
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="employee_type" class="form-label">Employee Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="employee_type" name="employee_type" onchange="updateWageFields()" required>
                            <option value="daily_paid">Daily Paid</option>
                            <option value="monthly_paid">Monthly Paid</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="daily_wage_field">
                        <label for="daily_wage" class="form-label">Daily Rate / Amount (LKR) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="daily_wage" name="daily_wage" step="0.01" min="0.01">
                    </div>
                    
                    <div class="mb-3" id="monthly_salary_field" style="display: none;">
                        <label for="monthly_salary" class="form-label">Monthly Salary (LKR)</label>
                        <input type="number" class="form-control" id="monthly_salary" name="monthly_salary" step="0.01" min="0.01">
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" required minlength="10" maxlength="10" title="Phone number must be exactly 10 digits">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Add Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================
    Attendance Modal
    ======================================== -->
<div class="modal fade" id="attendanceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mark Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Date Selection -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="attendanceDate" class="form-label">Select Date</label>
                        <input type="date" class="form-control" id="attendanceDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="attendanceSearch" class="form-label">Search Employees</label>
                        <input type="text" class="form-control" id="attendanceSearch" placeholder="Search by name or UID...">
                    </div>
                </div>

                <!-- Attendance Table -->
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle" id="attendanceTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>UID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Present</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceBody">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No active employees found.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $employee): ?>
                                <tr data-employee-id="<?= $employee['id'] ?>" data-name="<?= htmlspecialchars(strtolower($employee['name'] ?? '')) ?>" data-uid="<?= htmlspecialchars(strtolower($employee['uid'] ?? '')) ?>">
                                    <td><strong><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></strong></td>
                                    <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php
                                            $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                                            if ($rawType === '') {
                                                // Determine type from salary values when stored type is missing
                                                if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                                    $typeKey = 'monthly_paid';
                                                } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                                    $typeKey = 'daily_paid';
                                                } else {
                                                    $typeKey = 'daily_paid';
                                                }
                                            } elseif (strpos($rawType, 'month') !== false) {
                                                $typeKey = 'monthly_paid';
                                            } elseif (strpos($rawType, 'day') !== false) {
                                                $typeKey = 'daily_paid';
                                            } else {
                                                // fallback based on stored enum
                                                $typeKey = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                            }
                                            $typeLabel = $typeKey === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';
                                        ?>
                                        <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                            <?= htmlspecialchars($typeLabel) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input attendance-checkbox" type="checkbox" 
                                                   id="present_<?= $employee['id'] ?>" 
                                                   data-employee-id="<?= $employee['id'] ?>" 
                                                   checked>
                                            <label class="form-check-label" for="present_<?= $employee['id'] ?>">
                                                Present
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 border-top pt-3">
                    <h6 class="mb-3">Attendance Summary</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="attendanceSummaryMonth" class="form-label">Month</label>
                            <select class="form-select" id="attendanceSummaryMonth">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="attendanceSummaryYear" class="form-label">Year</label>
                            <input type="number" class="form-control" id="attendanceSummaryYear" min="2000" max="2100" value="<?= date('Y') ?>">
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                        <table class="table table-sm table-striped align-middle" id="attendanceSummaryTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Employee Name</th>
                                    <th>Total Present Days</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceSummaryBody">
                                <tr>
                                    <td colspan="3" class="text-center text-muted">Select month and year to view attendance summary</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveAttendance()">Save Attendance</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================
    Salary / Bonus Modal
    ======================================== -->
<div class="modal fade" id="salaryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">View Employee Salary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label for="salaryMonth" class="form-label">Select Month</label>
                        <input type="month" class="form-control" id="salaryMonth" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="salarySearch" class="form-label">Search Employee</label>
                        <input type="text" class="form-control" id="salarySearch" placeholder="Search by UID or name...">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="button" class="btn btn-primary me-2" onclick="loadSalaryData()">Load Salaries</button>
                        <button type="button" class="btn btn-warning me-2" onclick="openBonusModal()">Calculate Bonus</button>
                        <button type="button" class="btn btn-success" onclick="downloadSalaryReport()">Download PDF</button>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="table table-hover align-middle" id="salaryTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Employee UID</th>
                                <th>Employee Name</th>
                                <th>Employee Type</th>
                                <th>Calculated Salary</th>
                                <th>Bonus</th>
                                <th>Final Salary</th>
                                <th>Action</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>
                        <tbody id="salaryTableBody">
                            <tr>
                                <td colspan="8" class="text-center text-muted">Select month and click Load Salaries</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Calculate Bonus Modal -->
<div class="modal fade" id="bonusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Calculate Bonus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Selected Month: <strong id="bonusSelectedMonth">-</strong></p>

                <div class="mb-3">
                    <label class="form-label">Apply Bonus To</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="bonusScope" id="bonusScopeSingle" value="single" checked onchange="toggleBonusScopeFields()">
                        <label class="form-check-label" for="bonusScopeSingle">Select Single Employee</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="bonusScope" id="bonusScopeAll" value="all" onchange="toggleBonusScopeFields()">
                        <label class="form-check-label" for="bonusScopeAll">Select All Employees</label>
                    </div>
                </div>

                <div id="singleEmployeeBonusFields">
                    <div class="mb-3">
                        <label for="bonusIdentifierType" class="form-label">Find Employee By</label>
                        <select id="bonusIdentifierType" class="form-select" onchange="refreshBonusIdentifierList()">
                            <option value="uid">Employee ID (UID)</option>
                            <option value="name">Employee Name</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="bonusIdentifierValue" class="form-label">Employee ID / Name</label>
                        <input type="text" id="bonusIdentifierValue" class="form-control" list="bonusIdentifierList" placeholder="Enter exact employee ID or name">
                        <datalist id="bonusIdentifierList"></datalist>
                    </div>
                </div>

                <div class="mb-2">
                    <label for="bonusAmount" class="form-label">Bonus Amount (LKR)</label>
                    <input type="number" class="form-control" id="bonusAmount" min="0" step="0.01" placeholder="Enter bonus amount">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="applySalaryBonus()">Apply Bonus</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Attendance (Single Employee) Modal -->
<div class="modal fade" id="attendanceEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attendanceEditEmployeeId">
                <div class="mb-3">
                    <label class="form-label">Employee</label>
                    <input type="text" id="attendanceEditEmployeeLabel" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label for="attendanceEditDate" class="form-label">Date</label>
                    <input type="date" id="attendanceEditDate" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label for="attendanceEditStatus" class="form-label">Status</label>
                    <select id="attendanceEditStatus" class="form-select">
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAttendanceEdit()">Update Attendance</button>
            </div>
        </div>
    </div>
</div>

<!-- View Employee Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Employee Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="employeeSearch" class="form-label">Search Employee</label>
                    <input type="text" class="form-control" id="employeeSearch" placeholder="Type employee name or UID..." autocomplete="off">
                    <div id="searchResults" class="list-group mt-2" style="display: none; max-height: 200px; overflow-y: auto;"></div>
                </div>
                
                <div id="employeeDetailsContainer" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>UID:</strong> <span id="detailUid">-</span></p>
                            <p><strong>Name:</strong> <span id="detailName">-</span></p>
                            <p><strong>Address:</strong> <span id="detailAddress">-</span></p>
                            <p><strong>Phone:</strong> <span id="detailPhone">-</span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Type:</strong> <span id="detailType">-</span></p>
                            <p><strong>Daily Wage:</strong> <span id="detailDailyWage">-</span></p>
                            <p><strong>Monthly Salary:</strong> <span id="detailMonthlySalary">-</span></p>
                            <p><strong>Status:</strong> <span id="detailStatus">-</span></p>
                            <p><strong>Joined:</strong> <span id="detailJoined">-</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Move to Past Employees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to move <strong id="deleteEmployeeName"></strong> to past employees?</p>
                <p class="text-muted small">This employee will be removed from the active list but their records will be preserved in the "Past Employees" section.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Move to Past Employees</button>
            </div>
        </div>
    </div>
</div>

<!-- Past Employees Modal -->
<div class="modal fade" id="pastEmployeesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Past Employees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($pastEmployees)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No past employees at the moment. All employees are currently active.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>UID</th>
                                <th>Name</th>
                                <th>Address</th>
                                <th>Type</th>
                                <th>Phone</th>
                                <th>Wage/Salary</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastEmployees as $employee): ?>
                            <tr>
                                <td><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($employee['address'] ?? 'N/A') ?></td>
                                <?php
                                    $rawPastType = strtolower(trim($employee['employee_type'] ?? ''));
                                    if ($rawPastType === '') {
                                        if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                            $pastType = 'monthly_paid';
                                        } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                            $pastType = 'daily_paid';
                                        } else {
                                            $pastType = 'daily_paid';
                                        }
                                    } elseif (strpos($rawPastType, 'month') !== false) {
                                        $pastType = 'monthly_paid';
                                    } elseif (strpos($rawPastType, 'day') !== false) {
                                        $pastType = 'daily_paid';
                                    } else {
                                        $pastType = ($rawPastType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                    }
                                    $pastLabel = $pastType === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';

                                    if ($pastType === 'daily_paid') {
                                        $pastAmount = number_format($employee['daily_wage'] ?? 0, 2);
                                        $pastSuffix = '/ day';
                                    } else {
                                        $pastAmt = (float)($employee['monthly_salary'] ?? 0);
                                        if ($pastAmt <= 0) {
                                            $pastAmt = (float)($employee['daily_wage'] ?? 0);
                                        }
                                        $pastAmount = number_format($pastAmt, 2);
                                        $pastSuffix = '/ month';
                                    }
                                ?>
                                <td>
                                    <span class="badge <?= $pastType === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                        <?= htmlspecialchars($pastLabel) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge <?= $pastType === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                        <?= htmlspecialchars($pastLabel) ?>
                                    </span>
                                    <div class="mt-1">
                                        LKR <?= $pastAmount ?> <?= $pastSuffix ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-success" onclick="reactivateEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['name'] ?? 'Employee') ?>')">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reactivate
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger ms-1" onclick="permanentlyDeleteEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['name'] ?? 'Employee') ?>')">
                                        <i class="bi bi-trash3"></i> Delete Permanently
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// ========================================
// Employee Management Client State
// ========================================
let deleteEmployeeId = null;
let employeeData = <?= json_encode($employees) ?>;
let allEmployeeData = <?= json_encode($allEmployees) ?>;
let salaryDataCache = [];
let selectedUID = '';
let monthlyPerformanceCache = [];
let yearlyPerformanceCache = [];

// ========================================
// Yearly Performance Workflow
// ========================================
function loadMonthlyPerformanceInput() {
    const month = parseInt(document.getElementById('performanceMonth').value || '0', 10);
    const year = parseInt(document.getElementById('performanceYear').value || '0', 10);
    const tbody = document.getElementById('monthlyPerformanceBody');

    if (!month || month < 1 || month > 12 || !year || year < 2000 || year > 2100) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Please select a valid month and year</td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Loading monthly performance...</td></tr>';

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_monthly_performance',
            month: month,
            year: year
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">' + (data.message || 'Failed to load monthly performance') + '</td></tr>';
            return;
        }

        monthlyPerformanceCache = Array.isArray(data.employees) ? data.employees : [];
        if (monthlyPerformanceCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No active employees found</td></tr>';
            return;
        }

        tbody.innerHTML = monthlyPerformanceCache.map(emp => {
            const selectedStars = Number(emp.stars || 0);
            const options = [0, 1, 2, 3, 4, 5].map(star => `<option value="${star}" ${selectedStars === star ? 'selected' : ''}>${star} Star${star === 1 ? '' : 's'}</option>`).join('');

            return `
                <tr>
                    <td><strong>${emp.uid || emp.employee_id}</strong></td>
                    <td>${emp.name || 'N/A'}</td>
                    <td>
                        <select class="form-select form-select-sm monthly-stars-select" data-employee-id="${emp.employee_id}">
                            ${options}
                        </select>
                    </td>
                </tr>
            `;
        }).join('');
    })
    .catch(error => {
        console.error('Error loading monthly performance:', error);
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error loading monthly performance</td></tr>';
    });
}

function saveMonthlyPerformance() {
    const month = parseInt(document.getElementById('performanceMonth').value || '0', 10);
    const year = parseInt(document.getElementById('performanceYear').value || '0', 10);

    if (!month || month < 1 || month > 12 || !year || year < 2000 || year > 2100) {
        alert('Please select a valid month and year');
        return;
    }

    const ratings = Array.from(document.querySelectorAll('.monthly-stars-select')).map(selectEl => ({
        employee_id: parseInt(selectEl.dataset.employeeId || '0', 10),
        stars: parseInt(selectEl.value || '0', 10)
    }));

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'save_monthly_performance',
            month: month,
            year: year,
            ratings: ratings
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to save monthly performance');
            return;
        }

        alert(data.message || 'Monthly performance saved successfully');
        loadYearlyPerformanceSummary();
    })
    .catch(error => {
        console.error('Error saving monthly performance:', error);
        alert('Error saving monthly performance');
    });
}

function calculateYearlyPerformance() {
    const year = parseInt(document.getElementById('performanceYear').value || '0', 10);
    if (!year || year < 2000 || year > 2100) {
        alert('Please select a valid year');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'calculate_yearly_performance',
            year: year
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to calculate yearly performance');
            return;
        }

        alert(data.message || 'Yearly performance calculated successfully');
        loadYearlyPerformanceSummary();
    })
    .catch(error => {
        console.error('Error calculating yearly performance:', error);
        alert('Error calculating yearly performance');
    });
}

function loadYearlyPerformanceSummary() {
    const year = parseInt(document.getElementById('performanceYear').value || '0', 10);
    const tbody = document.getElementById('yearlyPerformanceBody');

    if (!year || year < 2000 || year > 2100) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Please select a valid year</td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading yearly performance...</td></tr>';

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_yearly_performance',
            year: year
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">' + (data.message || 'Failed to load yearly performance') + '</td></tr>';
            return;
        }

        yearlyPerformanceCache = Array.isArray(data.summary) ? data.summary : [];
        if (yearlyPerformanceCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No yearly performance data available</td></tr>';
            return;
        }

        tbody.innerHTML = yearlyPerformanceCache.map(row => {
            const isEligible = Number(row.is_eligible || 0) === 1;
            const isTop = Number(row.is_top_performer || 0) === 1;
            const approved = Number(row.increment_approved || 0) === 1;

            return `
                <tr class="${isTop ? 'table-success' : ''}">
                    <td><strong>${row.uid || row.employee_id || 'N/A'}</strong></td>
                    <td>${row.name || 'N/A'}</td>
                    <td>${row.total_stars || 0}</td>
                    <td><span class="badge ${isEligible ? 'bg-success' : 'bg-secondary'}">${isEligible ? 'Eligible' : 'Not Eligible'}</span></td>
                    <td>${isTop ? '<span class="badge bg-warning text-dark">Top Performer</span>' : '-'}</td>
                    <td><span class="badge ${approved ? 'bg-success' : 'bg-secondary'}">${approved ? 'Approved' : 'Pending'}</span></td>
                </tr>
            `;
        }).join('');
    })
    .catch(error => {
        console.error('Error loading yearly performance:', error);
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading yearly performance</td></tr>';
    });
}

function approveYearlyIncrement() {
    const year = parseInt(document.getElementById('performanceYear').value || '0', 10);
    if (!year || year < 2000 || year > 2100) {
        alert('Please select a valid year');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'approve_yearly_increment',
            year: year
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to approve increment');
            return;
        }

        alert(data.message || 'Increment approved successfully');
        loadYearlyPerformanceSummary();
        window.location.reload();
    })
    .catch(error => {
        console.error('Error approving yearly increment:', error);
        alert('Error approving increment');
    });
}

// ========================================
// Bonus Workflow
// ========================================
function openBonusModal() {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month first');
        return;
    }

    document.getElementById('bonusSelectedMonth').textContent = month;
    document.getElementById('bonusScopeSingle').checked = true;
    document.getElementById('bonusIdentifierType').value = 'uid';
    document.getElementById('bonusIdentifierValue').value = '';
    document.getElementById('bonusAmount').value = '';

    toggleBonusScopeFields();
    refreshBonusIdentifierList();

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('bonusModal'));
    modal.show();
}

function resetBonusForm() {
    document.getElementById('bonusScopeSingle').checked = true;
    document.getElementById('bonusIdentifierType').value = 'uid';
    document.getElementById('bonusIdentifierValue').value = '';
    document.getElementById('bonusAmount').value = '';
    toggleBonusScopeFields();
    refreshBonusIdentifierList();
}

function toggleBonusScopeFields() {
    const scope = document.querySelector('input[name="bonusScope"]:checked')?.value || 'single';
    document.getElementById('singleEmployeeBonusFields').style.display = scope === 'single' ? 'block' : 'none';
}

function refreshBonusIdentifierList() {
    const type = document.getElementById('bonusIdentifierType').value;
    const datalist = document.getElementById('bonusIdentifierList');
    const source = salaryDataCache.length > 0 ? salaryDataCache : employeeData;

    const values = source
        .map(emp => type === 'name' ? String(emp.name || '').trim() : String(emp.uid || '').trim())
        .filter(v => v !== '');

    datalist.innerHTML = values
        .filter((value, index, arr) => arr.indexOf(value) === index)
        .slice(0, 200)
        .map(value => `<option value="${value.replace(/"/g, '&quot;')}"></option>`)
        .join('');
}

function applySalaryBonus() {
    const month = document.getElementById('salaryMonth').value;
    const scope = document.querySelector('input[name="bonusScope"]:checked')?.value || 'single';
    const bonusAmount = parseFloat(document.getElementById('bonusAmount').value || '');
    const identifierType = document.getElementById('bonusIdentifierType').value;
    const identifierValue = (document.getElementById('bonusIdentifierValue').value || '').trim();

    if (!month) {
        alert('Please select a month');
        return;
    }

    if (!['single', 'all'].includes(scope)) {
        alert('Select an employee or choose all employees');
        return;
    }

    if (!Number.isFinite(bonusAmount) || bonusAmount < 0) {
        alert('Enter a valid bonus amount');
        return;
    }

    if (scope === 'single' && !identifierValue) {
        alert('Select an employee or choose all employees');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'apply_salary_bonus',
            month: month,
            scope: scope,
            identifier_type: identifierType,
            identifier_value: identifierValue,
            bonus_amount: bonusAmount
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to apply bonus');
            return;
        }

        const modalEl = document.getElementById('bonusModal');
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.hide();
        resetBonusForm();

        alert(data.message || 'Bonus applied successfully');
        loadSalaryData();
    })
    .catch(error => {
        console.error('Error applying bonus:', error);
        alert('Error applying bonus');
    });
}

// ========================================
// Salary View Workflow
// ========================================
function loadSalaryData() {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    const tbody = document.getElementById('salaryTableBody');
    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Loading salary details...</td></tr>';

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: new URLSearchParams({
            action: 'load_salary_data',
            month: month
        }).toString()
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">' + (data.message || 'Failed to load salary data') + '</td></tr>';
            return;
        }

        salaryDataCache = data.salaryData || [];
        renderSalaryTable();
    })
    .catch(error => {
        console.error('Error loading salary data:', error);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading salary data</td></tr>';
    });
}

function renderSalaryTable() {
    const tbody = document.getElementById('salaryTableBody');
    const query = (document.getElementById('salarySearch').value || '').toLowerCase().trim();

    const filtered = salaryDataCache.filter(emp => {
        const uid = (emp.uid || '').toLowerCase();
        const name = (emp.name || '').toLowerCase();
        return uid.includes(query) || name.includes(query);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No employees found for selected month/filter</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map(emp => {
        const typeLabel = emp.type === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';
        const typeBadge = emp.type === 'daily_paid' ? 'bg-warning' : 'bg-info';
        const status = emp.payment_status === 'paid' ? 'paid' : 'pending';
        const baseSalary = parseFloat(emp.base_salary || emp.final_salary || 0).toFixed(2);
        const bonus = parseFloat(emp.bonus_amount || 0).toFixed(2);
        const finalWithBonus = parseFloat(emp.final_salary_with_bonus || (parseFloat(baseSalary) + parseFloat(bonus))).toFixed(2);
        return `
            <tr>
                <td><strong>${emp.uid || 'N/A'}</strong></td>
                <td>${emp.name || 'N/A'}</td>
                <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
                <td><strong>LKR ${baseSalary}</strong></td>
                <td><strong>LKR ${bonus}</strong></td>
                <td><strong>LKR ${finalWithBonus}</strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAttendanceEdit(${emp.id}, '${String(emp.uid || '').replace(/'/g, "\\'")}', '${String(emp.name || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-pencil-square me-1"></i>Edit Attendance
                    </button>
                </td>
                <td>
                    <select class="form-select form-select-sm" onchange="updateSalaryPaymentStatus(${emp.id}, this.value)">
                        <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="paid" ${status === 'paid' ? 'selected' : ''}>Paid</option>
                    </select>
                </td>
            </tr>
        `;
    }).join('');
}

function updateSalaryPaymentStatus(employeeId, status) {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'set_payment_status',
            employee_id: employeeId,
            month: month,
            status: status
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to update payment status');
            return;
        }

        const idx = salaryDataCache.findIndex(e => String(e.id) === String(employeeId));
        if (idx >= 0) {
            salaryDataCache[idx].payment_status = status;
        }
    })
    .catch(error => {
        console.error('Error updating payment status:', error);
        alert('Error updating payment status');
    });
}

function openAttendanceEdit(employeeId, uid, name) {
    document.getElementById('attendanceEditEmployeeId').value = employeeId;
    document.getElementById('attendanceEditEmployeeLabel').value = `${uid} - ${name}`;
    document.getElementById('attendanceEditDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('attendanceEditStatus').value = 'present';
    const modal = new bootstrap.Modal(document.getElementById('attendanceEditModal'));
    modal.show();
}

function saveAttendanceEdit() {
    const employeeId = document.getElementById('attendanceEditEmployeeId').value;
    const attendanceDate = document.getElementById('attendanceEditDate').value;
    const status = document.getElementById('attendanceEditStatus').value;

    if (!employeeId || !attendanceDate) {
        alert('Employee and date are required');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update_single_attendance',
            employee_id: employeeId,
            attendance_date: attendanceDate,
            status: status
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to update attendance');
            return;
        }

        const modalEl = document.getElementById('attendanceEditModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) {
            modalInstance.hide();
        }

        loadSalaryData();
    })
    .catch(error => {
        console.error('Error updating attendance:', error);
        alert('Error updating attendance');
    });
}

function downloadSalaryReport() {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    if (!Array.isArray(salaryDataCache) || salaryDataCache.length === 0) {
        alert('No data available to generate report');
        return;
    }

    if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
        window.open(`?page=employees&action=download_salary_report&month=${encodeURIComponent(month)}`, '_blank');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    const generatedAt = new Date().toLocaleString();
    const companyName = 'BluePeak Systems';

    doc.setFillColor(22, 91, 170);
    doc.rect(0, 0, 297, 26, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text(companyName, 14, 11);
    doc.setFontSize(14);
    doc.text('Employee Payroll Report', 14, 20);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text(`Date Generated: ${generatedAt}`, 210, 11);
    doc.text(`Payroll Month: ${month}`, 210, 20);

    const rows = salaryDataCache.map(emp => {
        const typeLabel = emp.type === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';
        const baseSalary = parseFloat(emp.base_salary || emp.final_salary || 0).toFixed(2);
        const bonus = parseFloat(emp.bonus_amount || 0).toFixed(2);
        const finalWithBonus = parseFloat(emp.final_salary_with_bonus || (parseFloat(baseSalary) + parseFloat(bonus))).toFixed(2);
        const paymentStatus = String(emp.payment_status || 'pending').toLowerCase() === 'paid' ? 'Paid' : 'Pending';
        return [
            emp.uid || 'N/A',
            emp.name || 'N/A',
            typeLabel,
            `LKR ${baseSalary}`,
            `LKR ${bonus}`,
            `LKR ${finalWithBonus}`,
            paymentStatus
        ];
    });

    const totalEmployees = salaryDataCache.length;
    const totalBasicSalary = salaryDataCache.reduce((sum, e) => sum + parseFloat(e.base_salary || e.final_salary || 0), 0);
    const totalBonus = salaryDataCache.reduce((sum, e) => sum + parseFloat(e.bonus_amount || 0), 0);
    const totalPaidSalary = salaryDataCache.reduce((sum, e) => {
        const status = String(e.payment_status || 'pending').toLowerCase();
        const finalSalary = parseFloat(e.final_salary_with_bonus || (parseFloat(e.base_salary || e.final_salary || 0) + parseFloat(e.bonus_amount || 0)));
        return status === 'paid' ? sum + finalSalary : sum;
    }, 0);
    const totalPendingSalary = salaryDataCache.reduce((sum, e) => {
        const status = String(e.payment_status || 'pending').toLowerCase();
        const finalSalary = parseFloat(e.final_salary_with_bonus || (parseFloat(e.base_salary || e.final_salary || 0) + parseFloat(e.bonus_amount || 0)));
        return status === 'pending' ? sum + finalSalary : sum;
    }, 0);

    doc.autoTable({
        startY: 32,
        head: [['Employee ID', 'Employee Name', 'Salary Type', 'Base Salary', 'Bonus', 'Final Salary', 'Payment Status']],
        body: rows,
        theme: 'grid',
        headStyles: { fillColor: [22, 91, 170], textColor: [255, 255, 255], fontStyle: 'bold' },
        bodyStyles: { textColor: [33, 37, 41], fontSize: 9 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        styles: { cellPadding: 2.5, lineColor: [220, 226, 232], lineWidth: 0.1 },
        tableWidth: 'auto',
        margin: { left: 8, right: 8 },
        horizontalPageBreak: true,
        horizontalPageBreakRepeat: 0
    });

    const summaryY = doc.lastAutoTable.finalY + 8;
    doc.setFillColor(240, 244, 248);
    doc.rect(14, summaryY, 269, 30, 'F');
    doc.setTextColor(35, 35, 35);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.text('Summary', 16, summaryY + 6);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text(`Total Employees: ${totalEmployees}`, 16, summaryY + 13);
    doc.text(`Total Basic Salary: LKR ${totalBasicSalary.toFixed(2)}`, 16, summaryY + 20);
    doc.text(`Total Bonus: LKR ${totalBonus.toFixed(2)}`, 110, summaryY + 13);
    doc.text(`Total Paid Salary: LKR ${totalPaidSalary.toFixed(2)}`, 110, summaryY + 20);
    doc.text(`Total Salary To Be Paid: LKR ${totalPendingSalary.toFixed(2)}`, 205, summaryY + 13);

    doc.save(`employee-payroll-report-${month}.pdf`);
}

function resetForm() {
    document.getElementById('employeeForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('employeeId').value = '';
    document.getElementById('uid').value = '';
    selectedUID = '';
    document.getElementById('employeeModalTitle').textContent = 'Add Employee';
    document.getElementById('submitBtn').textContent = 'Add Employee';
    document.getElementById('employee_type').value = 'daily_paid';
    updateWageFields();
}

function editEmployee(id) {
    const employee = employeeData.find(e => e.id == id);
    if (!employee) return;
    
    document.getElementById('formAction').value = 'edit';
    document.getElementById('employeeId').value = employee.id;
    document.getElementById('uid').value = employee.uid || '';
    selectedUID = employee.uid || '';
    document.getElementById('uid').setAttribute('readonly', 'readonly');
    document.getElementById('name').value = employee.name || '';
    document.getElementById('address').value = employee.address || '';
    const rawType = (employee.employee_type || 'daily_paid').toString().toLowerCase();
    const normalizedType = rawType.includes('month') ? 'monthly_paid' : 'daily_paid';
    document.getElementById('employee_type').value = normalizedType;
    document.getElementById('daily_wage').value = employee.daily_wage || '';
    document.getElementById('monthly_salary').value = employee.monthly_salary || '';
    document.getElementById('phone').value = employee.phone || '';
    
    document.getElementById('employeeModalTitle').textContent = 'Edit Employee';
    document.getElementById('submitBtn').textContent = 'Update Employee';
    
    updateWageFields();
}

function deleteEmployee(id, name) {
    deleteEmployeeId = id;
    document.getElementById('deleteEmployeeName').textContent = name;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

function confirmDelete() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="employee_id" value="' + deleteEmployeeId + '">';
    document.body.appendChild(form);
    form.submit();
}

function reactivateEmployee(id, name) {
    if (confirm('Are you sure you want to reactivate ' + name + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="reactivate"><input type="hidden" name="employee_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function permanentlyDeleteEmployee(id, name) {
    const message = 'Are you sure you want to permanently delete this employee?\n\nEmployee: ' + name;
    if (!confirm(message)) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="employee_id" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}

function updateWageFields() {
    const type = document.getElementById('employee_type').value || 'daily_paid';
    const dailyInput = document.getElementById('daily_wage');
    const monthlyInput = document.getElementById('monthly_salary');

    if (type === 'daily_paid') {
        document.getElementById('daily_wage_field').style.display = 'block';
        document.getElementById('monthly_salary_field').style.display = 'none';
        dailyInput.required = true;
        monthlyInput.required = false;
    } else {
        document.getElementById('daily_wage_field').style.display = 'none';
        document.getElementById('monthly_salary_field').style.display = 'block';
        dailyInput.required = false;
        monthlyInput.required = true;
    }
}

function handleUpdateEmployeeClick(event) {
    const action = document.getElementById('formAction').value;
    if (action !== 'edit') {
        return;
    }

    event.preventDefault();

    const uid = (selectedUID || document.getElementById('uid').value || '').trim();
    const name = (document.getElementById('name').value || '').trim();
    const phone = (document.getElementById('phone').value || '').trim();
    const address = (document.getElementById('address').value || '').trim();
    const employeeType = document.getElementById('employee_type').value;
    const dailyWage = parseFloat(document.getElementById('daily_wage').value || '0');
    const monthlySalary = parseFloat(document.getElementById('monthly_salary').value || '0');

    if (!uid || !name || !phone || !address || !['daily_paid', 'monthly_paid'].includes(employeeType)) {
        alert('Please fill all required fields');
        return;
    }

    if (!/^\d{10}$/.test(phone)) {
        alert('Phone Number is required and must be exactly 10 digits.');
        return;
    }

    if (employeeType === 'daily_paid' && (!Number.isFinite(dailyWage) || dailyWage <= 0)) {
        alert('Please provide a valid Daily Rate / Amount.');
        return;
    }

    if (employeeType === 'monthly_paid' && (!Number.isFinite(monthlySalary) || monthlySalary <= 0)) {
        alert('Please provide a valid Monthly Salary.');
        return;
    }

    // Debug: print UID before executing query
    console.log('Edit Employee UID before query:', uid);

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update_employee_by_uid',
            selected_uid: uid,
            name: name,
            phone: phone,
            address: address,
            employee_type: employeeType,
            daily_wage: employeeType === 'daily_paid' ? dailyWage : 0,
            monthly_salary: employeeType === 'monthly_paid' ? monthlySalary : 0
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to update employee');
            return;
        }

        // Debug: print success message after update
        console.log('Employee update success:', data.message || 'Employee updated successfully');

        const employeeModal = document.getElementById('employeeModal');
        const modalInstance = bootstrap.Modal.getOrCreateInstance(employeeModal);
        modalInstance.hide();

        window.location.reload();
    })
    .catch(error => {
        console.error('Error updating employee:', error);
        alert('Error updating employee');
    });
}

document.getElementById('submitBtn').addEventListener('click', handleUpdateEmployeeClick);

function loadEmployeeDetails() {
    const selectedId = document.getElementById('selectedEmployee').value;
    const employee = employeeData.find(e => e.id == selectedId);
    
    if (!employee) {
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    document.getElementById('detailUid').textContent = employee.uid || 'N/A';
    document.getElementById('detailName').textContent = employee.name || 'N/A';
    document.getElementById('detailAddress').textContent = employee.address || 'N/A';
    document.getElementById('detailPhone').textContent = employee.phone || 'N/A';
    document.getElementById('detailType').textContent = employee.employee_type === 'monthly_paid' ? 'Monthly Paid' : 'Daily Paid';
    document.getElementById('detailDailyWage').textContent = employee.daily_wage ? 'LKR ' + parseFloat(employee.daily_wage).toFixed(2) : 'N/A';
    document.getElementById('detailMonthlySalary').textContent = employee.monthly_salary ? 'LKR ' + parseFloat(employee.monthly_salary).toFixed(2) : 'N/A';
    document.getElementById('detailJoined').textContent = new Date(employee.created_at).toLocaleDateString();
    
    document.getElementById('employeeDetailsContainer').style.display = 'block';
}

// Search functionality for View Employee Details
document.getElementById('employeeSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const resultsDiv = document.getElementById('searchResults');
    
    if (query.length < 1) {
        resultsDiv.style.display = 'none';
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    const filtered = allEmployeeData.filter(emp => 
        (emp.name && emp.name.toLowerCase().includes(query)) || 
        (emp.uid && emp.uid.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="list-group-item text-muted">No employees found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = filtered.slice(0, 10).map(emp => 
        `<button type="button" class="list-group-item list-group-item-action" onclick="selectEmployee(${emp.id})">
            ${emp.name || 'N/A'} (${emp.uid || 'N/A'}) - ${emp.is_active == 1 ? 'Active' : 'Inactive'}
        </button>`
    ).join('');
    
    resultsDiv.style.display = 'block';
});

function selectEmployee(id) {
    const employee = allEmployeeData.find(e => e.id == id);
    if (!employee) return;
    
    document.getElementById('employeeSearch').value = `${employee.name || 'N/A'} (${employee.uid || 'N/A'})`;
    document.getElementById('searchResults').style.display = 'none';
    
    // Load details
    document.getElementById('detailUid').textContent = employee.uid || 'N/A';
    document.getElementById('detailName').textContent = employee.name || 'N/A';
    document.getElementById('detailAddress').textContent = employee.address || 'N/A';
    document.getElementById('detailPhone').textContent = employee.phone || 'N/A';
    document.getElementById('detailType').textContent = employee.employee_type === 'monthly_paid' ? 'Monthly Paid' : 'Daily Paid';
    document.getElementById('detailDailyWage').textContent = employee.daily_wage ? 'LKR ' + parseFloat(employee.daily_wage).toFixed(2) : 'N/A';
    document.getElementById('detailMonthlySalary').textContent = employee.monthly_salary ? 'LKR ' + parseFloat(employee.monthly_salary).toFixed(2) : 'N/A';
    document.getElementById('detailStatus').textContent = employee.is_active == 1 ? 'Active' : 'Inactive';
    document.getElementById('detailJoined').textContent = employee.created_at ? new Date(employee.created_at).toLocaleDateString() : 'N/A';
    
    document.getElementById('employeeDetailsContainer').style.display = 'block';
}

// Search functionality for attendance table
document.getElementById('attendanceSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#attendanceBody tr[data-employee-id]');
    
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const uid = row.dataset.uid || '';
        const visible = name.includes(query) || uid.includes(query);
        row.style.display = visible ? '' : 'none';
    });
});

function saveAttendance() {
    const date = document.getElementById('attendanceDate').value;
    if (!date) {
        alert('Please select a date');
        return;
    }

    const checkboxes = document.querySelectorAll('.attendance-checkbox');
    const attendanceData = [];

    checkboxes.forEach(checkbox => {
        const employeeId = checkbox.dataset.employeeId;
        const status = checkbox.checked ? 'present' : 'absent';
        attendanceData.push({
            employee_id: employeeId,
            date: date,
            status: status
        });
    });

    // Send data to server
    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'save_attendance',
            attendance: attendanceData
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (data.success) {
            alert('Attendance saved successfully!');
        } else {
            alert('Error saving attendance: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving attendance');
    });
}

// Load attendance data when date changes
document.getElementById('attendanceDate').addEventListener('change', function() {
    loadAttendanceForDate(this.value);
});

function loadAttendanceForDate(date) {
    if (!date) return;
    
    // Fetch existing attendance for the date
    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_attendance',
            date: date
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (data.success) {
            // Update checkboxes based on loaded data
            const checkboxes = document.querySelectorAll('.attendance-checkbox');
            checkboxes.forEach(checkbox => {
                const employeeId = checkbox.dataset.employeeId;
                const attendance = data.attendance.find(a => a.employee_id == employeeId);
                checkbox.checked = attendance ? attendance.status === 'present' : true; // Default to present
            });
        }
    })
    .catch(error => {
        console.error('Error loading attendance:', error);
    });
}

function loadAttendanceSummary() {
    const month = parseInt(document.getElementById('attendanceSummaryMonth').value, 10);
    const year = parseInt(document.getElementById('attendanceSummaryYear').value, 10);
    const tbody = document.getElementById('attendanceSummaryBody');

    if (!month || month < 1 || month > 12 || !year || year < 2000 || year > 2100) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Please select a valid month and year</td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Loading attendance summary...</td></tr>';

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_attendance_summary',
            month: month,
            year: year
        })
    })
    .then(readJsonResponseSafely)
    .then(data => {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">' + (data.message || 'Failed to load attendance summary') + '</td></tr>';
            return;
        }

        const rows = Array.isArray(data.summary) ? data.summary : [];
        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No present attendance records found for selected month/year</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(row => {
            const employeeId = row.employee_id ?? 'N/A';
            const employeeName = row.employee_name ?? 'N/A';
            const totalPresentDays = row.total_present_days ?? 0;
            return `
                <tr>
                    <td><strong>${employeeId}</strong></td>
                    <td>${employeeName}</td>
                    <td>${totalPresentDays}</td>
                </tr>
            `;
        }).join('');
    })
    .catch(error => {
        console.error('Error loading attendance summary:', error);
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error loading attendance summary</td></tr>';
    });
}

document.getElementById('attendanceSummaryMonth').value = String(new Date().getMonth() + 1);

document.getElementById('attendanceSummaryMonth').addEventListener('change', loadAttendanceSummary);
document.getElementById('attendanceSummaryYear').addEventListener('change', loadAttendanceSummary);
document.getElementById('attendanceModal').addEventListener('shown.bs.modal', loadAttendanceSummary);

document.getElementById('salarySearch').addEventListener('input', function() {
    renderSalaryTable();
});

document.getElementById('salaryMonth').addEventListener('change', function() {
    if (salaryDataCache.length > 0) {
        loadSalaryData();
    }
});

document.getElementById('salaryModal').addEventListener('shown.bs.modal', function() {
    loadSalaryData();
});

function readJsonResponseSafely(response) {
    return response.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (primaryError) {
            const firstBrace = text.indexOf('{');
            const lastBrace = text.lastIndexOf('}');

            if (firstBrace !== -1 && lastBrace > firstBrace) {
                const possibleJson = text.substring(firstBrace, lastBrace + 1);
                try {
                    return JSON.parse(possibleJson);
                } catch (secondaryError) {
                    // fall through to thrown error below
                }
            }

            throw new Error('Invalid server response');
        }
    });
}

// ========================================
// Full Management Summary PDF
// ========================================
function downloadEmployeeManagementSummary() {
    const month = document.getElementById('salaryMonth') ? document.getElementById('salaryMonth').value : new Date().toISOString().slice(0, 7);

    const normalizeEmployeeType = (rawType, monthlySalary, dailyWage) => {
        const type = String(rawType || '').toLowerCase().trim();
        if (type.includes('month')) {
            return 'monthly_paid';
        }
        if (type.includes('day')) {
            return 'daily_paid';
        }
        if (Number(monthlySalary || 0) > 0) {
            return 'monthly_paid';
        }
        return 'daily_paid';
    };

    const resolveDisplayedSalary = (row, preferredType = null) => {
        const resolvedType = preferredType || normalizeEmployeeType(row.employee_type, row.monthly_salary, row.daily_wage);

        const currentSalary = Number(row.current_salary || 0);
        const monthlySalary = Number(row.monthly_salary || 0);
        const dailyWage = Number(row.daily_wage || 0);
        const baseSalary = Number(row.base_salary || 0);
        const finalSalary = Number(row.final_salary || 0);

        if (currentSalary > 0) {
            return currentSalary;
        }
        if (resolvedType === 'monthly_paid' && monthlySalary > 0) {
            return monthlySalary;
        }
        if (resolvedType === 'daily_paid' && dailyWage > 0) {
            return dailyWage;
        }
        if (monthlySalary > 0) {
            return monthlySalary;
        }
        if (dailyWage > 0) {
            return dailyWage;
        }
        if (baseSalary > 0) {
            return baseSalary;
        }
        if (finalSalary > 0) {
            return finalSalary;
        }
        return 0;
    };

    const buildLocalSummaryData = () => {
        const sourceEmployees = Array.isArray(allEmployeeData) ? allEmployeeData : [];
        const activeEmployees = sourceEmployees.filter(emp => parseInt(emp.is_active ?? 1, 10) === 1);
        const pastEmployees = sourceEmployees.filter(emp => parseInt(emp.is_active ?? 1, 10) === 0);

        let localSalaryRows = Array.isArray(salaryDataCache) ? [...salaryDataCache] : [];
        if (localSalaryRows.length === 0) {
            localSalaryRows = activeEmployees.map(emp => {
                const type = normalizeEmployeeType(emp.employee_type, emp.monthly_salary, emp.daily_wage);
                const base = resolveDisplayedSalary(emp, type);
                return {
                    uid: emp.uid || 'N/A',
                    name: emp.name || 'N/A',
                    address: emp.address || 'N/A',
                    phone: emp.phone || 'N/A',
                    type,
                    base_salary: base,
                    bonus_amount: 0,
                    final_salary_with_bonus: base,
                    payment_status: 'pending'
                };
            });
        }

        return {
            success: true,
            month,
            activeEmployees,
            pastEmployees,
            salaryData: localSalaryRows,
            newJoiners: [],
            leftEmployees: []
        };
    };

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_employee_management_summary',
            month: month
        })
    })
    .then(async response => {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return {
                success: false,
                message: 'Invalid response format'
            };
        }
    })
    .then(data => {
        if (!data.success) {
            data = buildLocalSummaryData();
        }

        const activeEmployees = Array.isArray(data.activeEmployees) ? data.activeEmployees : [];
        const salaryRows = Array.isArray(data.salaryData) ? data.salaryData : [];
        const newJoiners = Array.isArray(data.newJoiners) ? data.newJoiners : [];
        const leftEmployees = Array.isArray(data.leftEmployees) ? data.leftEmployees : [];

        if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
            alert('PDF generation library is not available.');
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

        const companyName = 'BluePeak Systems';
        const reportMonth = data.month || month;
        const generatedAt = new Date().toLocaleString();
        const paymentStatusByUid = salaryRows.reduce((map, row) => {
            const uid = String(row.uid || '').trim();
            if (uid !== '') {
                map[uid] = String(row.payment_status || 'pending').toLowerCase() === 'paid' ? 'Paid' : 'Pending';
            }
            return map;
        }, {});

        doc.setFillColor(26, 82, 156);
        doc.rect(0, 0, 297, 28, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text(companyName, 14, 11);
        doc.setFontSize(14);
        doc.text('Employee Management Summary Report', 14, 21);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(`Date of Download: ${generatedAt}`, 210, 11);
        doc.text(`Month: ${reportMonth}`, 210, 21);

        let y = 34;

        const sectionTitle = (title) => {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12);
            doc.setTextColor(26, 82, 156);
            doc.text(title, 14, y);
            y += 2;
        };

        // Employee Details Section
        sectionTitle('Employee Details');
        const employeeDetailRows = activeEmployees.map(emp => {
            const resolvedType = normalizeEmployeeType(emp.employee_type, emp.monthly_salary, emp.daily_wage);
            const salaryType = resolvedType === 'monthly_paid' ? 'Monthly' : 'Daily';
            const baseSalary = resolveDisplayedSalary(emp, resolvedType);
            return [
                emp.uid || 'N/A',
                emp.name || 'N/A',
                emp.address || 'N/A',
                emp.phone || 'N/A',
                salaryType,
                `LKR ${baseSalary.toFixed(2)}`,
                (parseInt(emp.is_active, 10) === 1 ? 'Active' : 'Inactive'),
                paymentStatusByUid[String(emp.uid || '').trim()] || 'Pending'
            ];
        });

        doc.autoTable({
            startY: y + 2,
            head: [['Employee ID', 'Name', 'Address', 'Phone Number', 'Salary Type', 'Base Salary', 'Status', 'Payment Status']],
            body: employeeDetailRows,
            theme: 'grid',
            headStyles: { fillColor: [26, 82, 156], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [246, 248, 251] },
            styles: { fontSize: 8.1, cellPadding: 1.8, lineColor: [221, 227, 235], lineWidth: 0.1 },
            tableWidth: 'auto',
            margin: { left: 8, right: 8 }
        });
        y = doc.lastAutoTable.finalY + 8;

        // Salary Section
        sectionTitle('Employee Salary Details');
        const salarySectionRows = salaryRows.map(row => {
            const base = parseFloat(row.base_salary || row.final_salary || 0);
            const bonus = parseFloat(row.bonus_amount || 0);
            const finalSalary = parseFloat(row.final_salary_with_bonus || (base + bonus));
            const isPaid = String(row.payment_status || 'pending').toLowerCase() === 'paid';
            return [
                row.uid || 'N/A',
                row.name || 'N/A',
                row.address || 'N/A',
                row.phone || 'N/A',
                row.type === 'monthly_paid' ? 'Monthly' : 'Daily',
                `LKR ${base.toFixed(2)}`,
                `LKR ${bonus.toFixed(2)}`,
                isPaid ? `LKR ${finalSalary.toFixed(2)}` : 'LKR 0.00',
                isPaid ? 'LKR 0.00' : `LKR ${finalSalary.toFixed(2)}`,
                `LKR ${finalSalary.toFixed(2)}`,
                isPaid ? 'Paid' : 'Pending'
            ];
        });

        doc.autoTable({
            startY: y + 2,
            head: [['Employee ID', 'Name', 'Address', 'Phone Number', 'Salary Type', 'Base Salary', 'Bonus', 'Paid Salary', 'Pending Salary', 'Final Salary', 'Payment Status']],
            body: salarySectionRows,
            theme: 'grid',
            headStyles: { fillColor: [26, 82, 156], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [246, 248, 251] },
            styles: { fontSize: 7.2, cellPadding: 1.6, lineColor: [221, 227, 235], lineWidth: 0.1 },
            tableWidth: 'auto',
            margin: { left: 8, right: 8 },
            horizontalPageBreak: true,
            horizontalPageBreakRepeat: 0
        });
        y = doc.lastAutoTable.finalY + 8;

        // New Joiners Section
        sectionTitle('New Joiners This Month');
        const newJoinerRows = newJoiners.map(emp => {
            const resolvedType = normalizeEmployeeType(emp.employee_type, emp.monthly_salary, emp.daily_wage);
            const salary = resolveDisplayedSalary(emp, resolvedType);
            return [
                emp.uid || 'N/A',
                emp.name || 'N/A',
                emp.address || 'N/A',
                emp.phone || 'N/A',
                emp.created_at ? String(emp.created_at).slice(0, 10) : 'N/A',
                `LKR ${salary.toFixed(2)}`
            ];
        });

        doc.autoTable({
            startY: y + 2,
            head: [['Employee ID', 'Name', 'Address', 'Phone Number', 'Joining Date', 'Salary']],
            body: newJoinerRows.length ? newJoinerRows : [['-', 'No new joiners this month', '-', '-', '-', '-']],
            theme: 'grid',
            headStyles: { fillColor: [26, 82, 156], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [246, 248, 251] },
            styles: { fontSize: 8.8, cellPadding: 2.2, lineColor: [221, 227, 235], lineWidth: 0.1 }
        });
        y = doc.lastAutoTable.finalY + 8;

        // Left Employees Section
        sectionTitle('Employees Who Left This Month');
        const leftRows = leftEmployees.map(emp => {
            const resolvedType = normalizeEmployeeType(emp.employee_type, emp.monthly_salary, emp.daily_wage);
            const salary = resolveDisplayedSalary(emp, resolvedType);
            return [
                emp.uid || 'N/A',
                emp.name || 'N/A',
                emp.address || 'N/A',
                emp.phone || 'N/A',
                `LKR ${salary.toFixed(2)}`,
                emp.exit_type || 'Inactive',
                emp.exit_date || (emp.updated_at ? String(emp.updated_at).slice(0, 10) : 'N/A')
            ];
        });

        doc.autoTable({
            startY: y + 2,
            head: [['Employee ID', 'Name', 'Address', 'Phone Number', 'Salary', 'Exit Type', 'Exit Date']],
            body: leftRows.length ? leftRows : [['-', 'No employees left this month', '-', '-', '-', '-', '-']],
            theme: 'grid',
            headStyles: { fillColor: [26, 82, 156], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [246, 248, 251] },
            styles: { fontSize: 8.8, cellPadding: 2.2, lineColor: [221, 227, 235], lineWidth: 0.1 }
        });
        y = doc.lastAutoTable.finalY + 8;

        // Summary Section
        const totalBasicSalary = salaryRows.reduce((sum, row) => sum + parseFloat(row.base_salary || row.final_salary || 0), 0);
        const totalBonusGiven = salaryRows.reduce((sum, row) => sum + parseFloat(row.bonus_amount || 0), 0);
        const totalPaidSalary = salaryRows.reduce((sum, row) => {
            const base = parseFloat(row.base_salary || row.final_salary || 0);
            const bonus = parseFloat(row.bonus_amount || 0);
            const finalSalary = parseFloat(row.final_salary_with_bonus || (base + bonus));
            return String(row.payment_status || 'pending').toLowerCase() === 'paid' ? sum + finalSalary : sum;
        }, 0);
        const totalPendingSalary = salaryRows.reduce((sum, row) => {
            const base = parseFloat(row.base_salary || row.final_salary || 0);
            const bonus = parseFloat(row.bonus_amount || 0);
            const finalSalary = parseFloat(row.final_salary_with_bonus || (base + bonus));
            return String(row.payment_status || 'pending').toLowerCase() === 'pending' ? sum + finalSalary : sum;
        }, 0);

        sectionTitle('Summary');
        doc.setFillColor(240, 244, 249);
        doc.rect(14, y + 1, 269, 26, 'F');
        doc.setTextColor(35, 35, 35);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(`Total Employees: ${activeEmployees.length}`, 16, y + 8);
        doc.text(`New Joiners (this month): ${newJoiners.length}`, 16, y + 15);
        doc.text(`Total Basic Salary: LKR ${totalBasicSalary.toFixed(2)}`, 16, y + 22);

        doc.text(`Total Bonus Given: LKR ${totalBonusGiven.toFixed(2)}`, 110, y + 8);
        doc.text(`Total Paid Salary: LKR ${totalPaidSalary.toFixed(2)}`, 110, y + 15);
        doc.text(`Total Pending Salary: LKR ${totalPendingSalary.toFixed(2)}`, 110, y + 22);

        doc.save(`employee-management-summary-${reportMonth}.pdf`);
    })
    .catch(error => {
        console.error('Error generating summary report:', error);
        const data = buildLocalSummaryData();
        if (!Array.isArray(data.activeEmployees) || data.activeEmployees.length === 0) {
            alert('Unable to generate report right now. Please try again.');
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        doc.setFillColor(26, 82, 156);
        doc.rect(0, 0, 297, 28, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text('BluePeak Systems', 14, 11);
        doc.setFontSize(14);
        doc.text('Employee Management Summary Report', 14, 21);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(`Date of Download: ${new Date().toLocaleString()}`, 210, 11);
        doc.text(`Month: ${data.month || month}`, 210, 21);

        const rows = data.activeEmployees.map(emp => [
            emp.uid || 'N/A',
            emp.name || 'N/A',
            emp.address || 'N/A',
            emp.phone || 'N/A',
            String(emp.employee_type || '').toLowerCase().includes('month') ? 'Monthly' : 'Daily'
        ]);

        doc.autoTable({
            startY: 34,
            head: [['Employee ID', 'Name', 'Address', 'Phone Number', 'Salary Type']],
            body: rows.length ? rows : [['-', 'No employee records', '-', '-', '-']],
            theme: 'grid',
            headStyles: { fillColor: [26, 82, 156], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [246, 248, 251] },
            styles: { fontSize: 9, cellPadding: 2 }
        });

        doc.save(`employee-management-summary-${data.month || month}.pdf`);
    });
}

if (document.getElementById('performanceMonth')) {
    document.getElementById('performanceMonth').value = String(new Date().getMonth() + 1);
    document.getElementById('performanceMonth').addEventListener('change', function() {
        loadMonthlyPerformanceInput();
    });
}

if (document.getElementById('performanceYear')) {
    document.getElementById('performanceYear').addEventListener('change', function() {
        loadMonthlyPerformanceInput();
        loadYearlyPerformanceSummary();
    });
}

loadMonthlyPerformanceInput();
loadYearlyPerformanceSummary();

document.getElementById('employeeForm').addEventListener('submit', function(e) {
    const name = (document.getElementById('name').value || '').trim();
    const phone = (document.getElementById('phone').value || '').trim();
    const address = (document.getElementById('address').value || '').trim();
    const type = document.getElementById('employee_type').value;
    const daily = parseFloat(document.getElementById('daily_wage').value || '0');
    const monthly = parseFloat(document.getElementById('monthly_salary').value || '0');

    const requiredTargets = [
        document.getElementById('name'),
        document.getElementById('phone'),
        document.getElementById('address'),
        document.getElementById('employee_type')
    ];

    if (type === 'daily_paid') {
        requiredTargets.push(document.getElementById('daily_wage'));
    } else if (type === 'monthly_paid') {
        requiredTargets.push(document.getElementById('monthly_salary'));
    }

    requiredTargets.forEach(el => el.classList.remove('is-invalid'));
    let hasMissing = false;
    requiredTargets.forEach(el => {
        const value = (el.value || '').toString().trim();
        if (!value || ((el.id === 'daily_wage' || el.id === 'monthly_salary') && parseFloat(value) <= 0)) {
            el.classList.add('is-invalid');
            hasMissing = true;
        }
    });

    if (hasMissing) {
        e.preventDefault();
        alert('Please fill all required fields');
        return;
    }

    if (!/^[A-Za-z ]+$/.test(name)) {
        e.preventDefault();
        alert('Name is required and must contain only letters and spaces.');
        return;
    }

    if (!/^\d{10}$/.test(phone)) {
        e.preventDefault();
        alert('Phone Number is required and must be exactly 10 digits.');
        return;
    }

    if (!address) {
        e.preventDefault();
        alert('Address is required.');
        return;
    }

    if (!['daily_paid', 'monthly_paid'].includes(type)) {
        e.preventDefault();
        alert('Salary Type is required.');
        return;
    }

    if (type === 'daily_paid' && (!Number.isFinite(daily) || daily <= 0)) {
        e.preventDefault();
        alert('Please provide a valid Daily Rate / Amount.');
        return;
    }

    if (type === 'monthly_paid' && (!Number.isFinite(monthly) || monthly <= 0)) {
        e.preventDefault();
        alert('Please provide a valid Monthly Salary.');
        return;
    }
});
</script>

<?php include 'footer.php'; ?>
