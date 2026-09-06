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
    <title>Fleet Operations Console - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1b4d3e;
            --primary-dark: #123529;
            --accent: #2e8b57;
            --light-bg: #f4f7f5;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --border: #cbd5e1;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #0284c7;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; line-height: 1.5; }

        /* Top Bar & Header Navigation */
        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .header-nav h1 {
            font-size: 1.6rem;
            color: var(--primary-dark);
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background-color: var(--white);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s ease-in-out;
        }

        .btn-back svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 0.2s ease-in-out;
        }

        .btn-back:hover {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(27, 77, 62, 0.15);
            transform: translateY(-1px);
        }

        .btn-back:hover svg {
            transform: translateX(-3px);
        }

        /* Operational Mode Navigation Tabs */
        .nav-tabs { display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 20px; overflow-x: auto; }
        .tab-item {
            padding: 10px 18px; font-weight: 600; color: var(--text-light); border: none;
            background: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px;
            white-space: nowrap; font-size: 0.88rem;
        }
        .tab-item.active { color: var(--primary); border-color: var(--primary); }

        /* KPI Operational Summary Ribbon */
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card {
            background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }

        /* Main Workspace Split Screen */
        .workspace { display: grid; grid-template-columns: 340px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        /* Form Controls & Inputs */
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select {
            width: 100%; padding: 8px 12px; border: 1px solid var(--border);
            border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff;
        }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }

        /* Buttons & Actions */
        .btn {
            background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px;
            font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #dc2626; }

        /* Dispatch Filter Bar */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border);
        }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 150px; }

        /* Badges */
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .badge-active, .badge-operational { background: #dcfce7; color: #166534; }
        .badge-maintenance { background: #fef3c7; color: #92400e; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }

        /* Operations Data Table */
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        /* Inline Table Controls */
        .inline-input { width: 85px; padding: 4px 6px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--border); }
        .inline-select { width: 125px; padding: 4px 6px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--border); }
        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .btn-action { background: var(--info); color: #fff; }
        .btn-warn { background: var(--warning); color: #000; }

        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) {
            .workspace { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation with Enhanced Back Button -->
    <div class="header-nav">
        <h1>Fleet Control Console</h1>
        <a href="/Farm_Management_System/index.php" class="btn-back">
            <svg viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Return to Dashboard
        </a>
    </div>

    <!-- Navigation Tabs for Fleet Context Switch -->
    <div class="nav-tabs">
        <button class="tab-item active">Live Directory & Dispatch</button>
        <button class="tab-item">Maintenance Schedule</button>
        <button class="tab-item">Fuel & Meter Logs</button>
        <button class="tab-item">Operator Assignments</button>
    </div>

    <!-- Analytics Operational Bar -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Fleet Size</small>
            <h3><?= $total_fleet ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Units</span></h3>
        </div>
        <div class="stat-card">
            <small>Ready / Operational</small>
            <h3><?= $operational_count ?></h3>
        </div>
        <div class="stat-card">
            <small>Under Maintenance / Inactive</small>
            <h3 style="color: <?= $maintenance_count > 0 ? 'var(--warning)' : 'var(--primary-dark)'; ?>"><?= $maintenance_count ?></h3>
        </div>
        <div class="stat-card">
            <small>Fleet Availability Rate</small>
            <h3><?= $total_fleet > 0 ? round(($operational_count / $total_fleet) * 100, 1) : 0 ?>%</h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form Sidebar -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Machinery Unit' : 'Register New Asset' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="machinery.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="machinery_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Machine Title / Designation *</label>
                    <input type="text" name="name" placeholder="e.g. John Deere 5075E Tractor" value="<?= $edit_data ? htmlspecialchars($edit_data['name']) : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Model / Class Specification *</label>
                    <input type="text" name="model" placeholder="e.g. Utility Tractor / 75 HP" value="<?= $edit_data ? htmlspecialchars($edit_data['model']) : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Assigned Operator (Field Roster)</label>
                    <select disabled>
                        <option>John Doe (Tractor Operator)</option>
                        <option>Unassigned (In Depot)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Operational Status *</label>
                    <select name="status" required>
                        <option value="Operational" <?= ($edit_data && strtolower($edit_data['status']) === 'operational') ? 'selected' : '' ?>>Operational</option>
                        <option value="Maintenance" <?= ($edit_data && strtolower($edit_data['status']) === 'maintenance') ? 'selected' : '' ?>>In Maintenance</option>
                        <option value="Inactive" <?= ($edit_data && strtolower($edit_data['status']) === 'inactive') ? 'selected' : '' ?>>Inactive / Out of Service</option>
                    </select>
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Machinery Record' : 'Register Asset' ?></button>
                <?php if($edit_data): ?>
                    <a href="machinery.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Directory & Interactive Control Console -->
        <div class="card">
            <h2>Active Directory & Dispatch Grid</h2>

            <!-- Filter Toolbar -->
            <form method="GET" action="machinery.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Asset:</label>
                    <input type="text" name="search" placeholder="Search by name or model..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>Status Filter:</label>
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

            <!-- Operational Control Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Unit Tag</th>
                            <th>Machine Specification</th>
                            <th>Hour Meter</th>
                            <th>Assigned Driver</th>
                            <th>Status</th>
                            <th>Quick Operations & Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($machinery_list)): ?>
                            <?php foreach($machinery_list as $item): ?>
                            <tr>
                                <td><strong>#EQ-<?= sprintf('%03d', $item['id']) ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                                    <small style="color: var(--text-light);"><?= htmlspecialchars($item['model']) ?></small>
                                </td>
                                <td>
                                    <!-- Interactive Meter Reading Control Mock -->
                                    <div style="display: flex; gap: 4px; align-items: center;">
                                        <input type="number" class="inline-input" value="1240" step="1">
                                        <button class="btn-sm btn-action" title="Log current meter reading">Log</button>
                                    </div>
                                </td>
                                <td>
                                    <!-- Interactive Operator Control Mock -->
                                    <select class="inline-select">
                                        <option value="">Unassigned</option>
                                        <option value="1" selected>John Doe</option>
                                        <option value="2">Jane Smith</option>
                                    </select>
                                </td>
                                <td>
                                    <span class="badge badge-<?= strtolower(htmlspecialchars($item['status'])) ?>">
                                        <?= htmlspecialchars($item['status']) ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <button class="btn-sm btn-warn" title="Flag machine for maintenance service">Service</button>
                                    <button class="btn-sm btn-action" title="Log fuel dispensing">Fuel</button>
                                    <a href="machinery.php?action=edit&id=<?= $item['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="machinery.php?action=delete&id=<?= $item['id'] ?>" onclick="return confirm('Are you sure you want to delete this machinery record?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-light); padding: 25px;">No machinery records registered matching your query.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>