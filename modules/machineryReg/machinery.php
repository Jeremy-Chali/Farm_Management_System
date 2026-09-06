<?php
// Load database configuration from project root
require_once $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

$message = "";
$error = "";

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    
    // First verify if equipment is referenced in fuel_logs to prevent foreign key errors
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM fuel_logs WHERE equipment_id = ?");
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $res = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($res['count'] > 0) {
        $error = "Cannot delete equipment because it has associated fuel logs.";
    } else {
        $stmt = $conn->prepare("DELETE FROM equipment WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $message = "Machinery record deleted successfully.";
        } else {
            $error = "Failed to delete machinery record.";
        }
        $stmt->close();
    }
}

// Create or Update Record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $model = trim($_POST['model']);
    $status = trim($_POST['status']);
    $machinery_id = isset($_POST['machinery_id']) ? intval($_POST['machinery_id']) : null;

    if ($machinery_id) {
        // Update
        $stmt = $conn->prepare("UPDATE equipment SET name = ?, model = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $model, $status, $machinery_id);
        if ($stmt->execute()) {
            $message = "Machinery record updated successfully.";
        } else {
            $error = "Failed to update equipment details.";
        }
        $stmt->close();
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO equipment (name, model, status) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $model, $status);
        if ($stmt->execute()) {
            $message = "New machinery registered successfully.";
        } else {
            $error = "Failed to register machinery.";
        }
        $stmt->close();
    }
}

// Check for Edit Mode
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM equipment WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $edit_data = $res->fetch_assoc();
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 2. FETCH DATA & ANALYTICS
// ---------------------------------------------------------

// Search and Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(name LIKE ? OR model LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}
if ($status_filter !== '') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Machinery Records
$sql = "SELECT * FROM equipment WHERE $where_sql ORDER BY id DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$machinery_result = $stmt->get_result();
$machinery_list = $machinery_result ? $machinery_result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Compute Analytics
$total_fleet = count($machinery_list);
$operational_count = 0;
$maintenance_count = 0;

foreach ($machinery_list as $item) {
    if (strtolower($item['status']) === 'operational' || strtolower($item['status']) === 'active') {
        $operational_count++;
    } else {
        $maintenance_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Machinery Management - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1b4d3e;
            --primary-dark: #123529;
            --accent: #2e8b57;
            --light-bg: #f4f7f5;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --white: #ffffff;
            --border: #e2e8f0;
            --danger: #d9534f;
            --warning: #f0ad4e;
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            color: var(--text-dark);
            background-color: var(--light-bg);
            line-height: 1.6;
            padding: 30px 6%;
        }

        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .header-nav a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        /* Analytics Grid */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        .stat-card small {
            color: var(--text-light);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-card h3 {
            color: var(--primary-dark);
            font-size: 1.6rem;
            margin-top: 5px;
        }

        /* Main Workspace */
        .workspace {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 30px;
            align-items: start;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        h2, h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success { background: #e8f5e9; color: var(--primary-dark); border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: var(--danger); border-left: 4px solid var(--danger); }

        /* Form Controls */
        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        input, select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.95rem;
            outline: none;
        }

        input:focus, select:focus {
            border-color: var(--accent);
        }

        .btn {
            background-color: var(--primary);
            color: var(--white);
            border: none;
            padding: 10px 16px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #c9302c; }

        /* Filter Controls Bar */
        .filter-bar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 20px;
            background: var(--light-bg);
            padding: 15px;
            border-radius: 8px;
        }

        .filter-bar .form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 140px;
        }

        /* Status Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-active, .badge-operational { background: #e8f5e9; color: var(--primary-dark); }
        .badge-maintenance, .badge-inactive { background: #fff8e1; color: #b78103; }

        /* Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }

        th { background: var(--light-bg); color: var(--primary-dark); }
        tr:hover { background: #fafdfb; }

        .actions { display: flex; gap: 8px; }

        @media (max-width: 900px) {
            .workspace { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="header-nav">
        <a href="/Farm_Management_System/index.php">&larr; Back to Main Dashboard</a>
    </div>

    <!-- Analytics Dashboard Cards -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Fleet Units</small>
            <h3><?= $total_fleet ?></h3>
        </div>
        <div class="stat-card">
            <small>Operational Units</small>
            <h3><?= $operational_count ?></h3>
        </div>
        <div class="stat-card">
            <small>Maintenance / Inactive</small>
            <h3><?= $maintenance_count ?></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Machinery' : 'Register Machinery' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="machinery.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="machinery_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Machine Name / Title:</label>
                    <input type="text" name="name" placeholder="e.g. John Deere Tractor" value="<?= $edit_data ? htmlspecialchars($edit_data['name']) : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Model / Serial No:</label>
                    <input type="text" name="model" placeholder="e.g. 5075E / 2022" value="<?= $edit_data ? htmlspecialchars($edit_data['model']) : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Operational Status:</label>
                    <select name="status" required>
                        <option value="Operational" <?= ($edit_data && strtolower($edit_data['status']) === 'operational') ? 'selected' : '' ?>>Operational</option>
                        <option value="Maintenance" <?= ($edit_data && strtolower($edit_data['status']) === 'maintenance') ? 'selected' : '' ?>>In Maintenance</option>
                        <option value="Inactive" <?= ($edit_data && strtolower($edit_data['status']) === 'inactive') ? 'selected' : '' ?>>Inactive / Out of Service</option>
                    </select>
                </div>

                <button type="submit" class="btn" style="width: 100%;"><?= $edit_data ? 'Update Machinery' : 'Register Unit' ?></button>
                <?php if($edit_data): ?>
                    <a href="machinery.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Machinery Fleet List & Search -->
        <div class="card">
            <h2>Fleet Directory</h2>

            <!-- Filter Toolbar -->
            <form method="GET" action="machinery.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Unit:</label>
                    <input type="text" name="search" placeholder="Search by name or model..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>Filter Status:</label>
                    <select name="status_filter">
                        <option value="">All Statuses</option>
                        <option value="Operational" <?= $status_filter === 'Operational' ? 'selected' : '' ?>>Operational</option>
                        <option value="Maintenance" <?= $status_filter === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="machinery.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>

            <!-- Data Table -->
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Machine Name</th>
                        <th>Model / Spec</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($machinery_list)): ?>
                        <?php foreach($machinery_list as $item): ?>
                        <tr>
                            <td>#<?= $item['id'] ?></td>
                            <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                            <td><?= htmlspecialchars($item['model']) ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower(htmlspecialchars($item['status'])) ?>">
                                    <?= htmlspecialchars($item['status']) ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="machinery.php?action=edit&id=<?= $item['id'] ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Edit</a>
                                <a href="machinery.php?action=delete&id=<?= $item['id'] ?>" onclick="return confirm('Are you sure you want to delete this machinery record?');" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.8rem;">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-light);">No machinery registered matching your query.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>