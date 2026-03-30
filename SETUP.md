# Sri Ram Fireworks Billing System - Setup Guide

## Prerequisites & Software Requirements

Before you can run this billing system, you need to download and install the following software and libraries.

---

## System Requirements

- **Operating System**: Windows 10 or higher (or Linux/Mac with XAMPP alternatives)
- **RAM**: Minimum 4GB (8GB recommended)
- **Disk Space**: Minimum 2GB free space
- **Internet Connection**: For downloading software and dependencies

---

## Software to Download & Install

### 1. **XAMPP** (Apache, PHP, MySQL Bundle)
   - **Download**: https://www.apachefriends.org/download.html
   - **What it includes**:
     - Apache Web Server
     - PHP 8.x
     - MariaDB (MySQL-compatible database)
     - phpMyAdmin (Database management)
   - **Installation Steps**:
     1. Download the latest XAMPP installer for Windows
     2. Run the installer and follow the setup wizard
     3. Install to default location (usually `C:\xampp`)
     4. Choose to install Apache and MySQL components
     5. Complete the installation
   - **Verify Installation**: Check that `C:\xampp` folder exists

### 2. **Git** (Version Control)
   - **Download**: https://git-scm.com/download/win
   - **Why**: To clone the project repository
   - **Installation Steps**:
     1. Download Git for Windows
     2. Run the installer with default settings
     3. Complete installation
   - **Verify Installation**: Open terminal and type `git --version`

### 3. **Visual Studio Code** (Optional - for development)
   - **Download**: https://code.visualstudio.com/
   - **Why**: Edit and manage project files
   - **Installation Steps**:
     1. Download VS Code
     2. Run installer
     3. Install extensions:
        - PHP Intelephense (bmewburn.vscode-intelephense-client)
        - MySQL (cweijan.vscode-mysql)
        - SQLTools + MySQL Driver (cweijan.vscode-sql-tools + cweijan.vscode-mysql)

---

## PHP Extensions & Libraries

The following PHP extensions are required (most come with XAMPP):

✅ **Already included in XAMPP**:
- `pdo` - Database abstraction layer
- `pdo_mysql` - MySQL driver for PDO
- `json` - JSON support
- `mbstring` - Multi-byte string functions
- `curl` - HTTP requests
- `gd` - Image processing

**To verify extensions are enabled**:
1. Start XAMPP
2. Create a file `info.php` in `C:\xampp\htdocs` with this content:
   ```php
   <?php phpinfo(); ?>
   ```
3. Open `http://localhost/info.php` in browser
4. Check that extensions listed above are present

---

## Database Setup

### MariaDB/MySQL
- **Included in**: XAMPP
- **Default Configuration**:
  - Host: `127.0.0.1` or `localhost`
  - Port: `3306`
  - User: `root`
  - Password: (empty/blank)
  - Database will be created automatically on first run

---

## Project Dependencies

This project uses **only PHP** (no Composer/NPM dependencies needed).

### Frontend Libraries (Included in project):
- **Bootstrap 5** - CSS framework (included as CDN link in templates)
- **jQuery** - JavaScript library (optional, included as CDN)

---

## Step-by-Step Installation Guide

### Step 1: Install Required Software
1. Install XAMPP (with Apache & MySQL)
2. Install Git
3. Install Visual Studio Code (optional)

### Step 2: Clone the Project
```bash
# Open terminal/PowerShell
cd C:\xampp\htdocs
git clone https://github.com/nej2004/BluePeak-Systems.git billing-system
cd billing-system
git checkout nejana
```

### Step 3: Start XAMPP Services
1. Open XAMPP Control Panel (`C:\xampp\xampp-control.exe`)
2. Click **Start** for:
   - Apache
   - MySQL

Wait for both to show "Running" status.

### Step 4: Start the Web Server
Open PowerShell/Terminal and run:
```bash
cd C:\xampp\htdocs\billing-system
C:\xampp\php\php.exe -S localhost:8000
```

### Step 5: Open in Browser
1. Open your web browser
2. Go to: `http://localhost:8000`
3. Login with default credentials:
   - Email: `admin@sriram.com`
   - Password: `admin123`

---

## Directory Structure After Setup

```
C:\xampp\htdocs\billing-system\
├── index.php                 (Main entry point)
├── templates/                (UI pages)
│   ├── dashboard.php
│   ├── products.php
│   ├── suppliers.php
│   ├── bills.php
│   ├── employees.php
│   └── ...
├── SETUP.md                  (This file)
└── README.md
```

---

## Troubleshooting

### Error: "PHP is not recognized"
- **Solution**: Use full path: `C:\xampp\php\php.exe -S localhost:8000`

### Error: "Port 8000 already in use"
- **Solution**: Use different port: `C:\xampp\php\php.exe -S localhost:9000`

### Error: "Cannot connect to MySQL"
- **Solution**: Start MySQL from XAMPP Control Panel first

### Error: "Database table not found"
- **Solution**: Tables are created automatically on first page load when connected to database

---

## Accessing via Apache (Alternative)

Instead of PHP built-in server, you can use Apache:

1. Copy project to: `C:\xampp\htdocs\billing-system`
2. Start Apache from XAMPP Control Panel
3. Open: `http://localhost/billing-system`

---

## System Features Available After Setup

✅ User Authentication & Login
✅ Dashboard with sales analytics
✅ Product inventory management
✅ Supplier order management
✅ Bills & invoicing
✅ Employee records
✅ Settings management
✅ Demo mode (fallback when database unavailable)

---

## Database Backup

To backup your database:

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select database `sri_ram_fireworks`
3. Click **Export**
4. Choose format: SQL
5. Save the `.sql` file

---

## Notes for Developers

- PHP version: 7.4+
- Database: MariaDB 10.4+ (MySQL 5.7+)
- No external dependencies (no Composer, no npm)
- Pure PHP with PDO for database operations
- Bootstrap 5 for frontend styling

---

## Quick Start Command

```bash
# One-liner to navigate and start (after XAMPP MySQL is running)
cd C:\xampp\htdocs\billing-system && C:\xampp\php\php.exe -S localhost:8000
```

Then open browser to: **http://localhost:8000**

---

## Support Files

- **README.md** - Project overview
- **SETUP.md** - This setup guide
- **.vscode/settings.json** - VS Code configuration for database tools

---

Last Updated: March 2026
