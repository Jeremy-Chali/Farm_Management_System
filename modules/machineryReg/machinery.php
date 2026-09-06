<?php
// Relative path resolution to reach db_config.php anywhere in project structure
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) {
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';
}
require_once $db_path;

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// ---------------------------------------------------------
// AUTO-SCHEMA REPAIR (PREVENTS "UNKNOWN COLUMN" ERRORS)
// ---------------------------------------------------------
$columns_to_check = [
    'serial_number'         => "VARCHAR(100) DEFAULT NULL",
    'purchase_date'         => "DATE DEFAULT NULL",
    'purchase_cost'         => "DECIMAL(10,2) DEFAULT 0.00",
    'lifespan_years'        => "INT DEFAULT 5",
    'salvage_value'         => "DECIMAL(10,2) DEFAULT 0.00",
    'total_operating_hours' => "DECIMAL(10,2) DEFAULT 0.00",
    'status'                => "VARCHAR(50) NOT NULL DEFAULT 'Operational'",
    'assigned_operator'     => "VARCHAR(100) DEFAULT NULL"
];

foreach ($columns_to_check as $col => $definition) {
    $chk = $conn->query("SHOW COLUMNS FROM equipment LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE equipment ADD COLUMN $col $definition");
    }
}

$message = "";
$error = "";

// ---------------------------------------------------------
// 1. HANDLE POST ACTIONS (CREATE, EDIT, DELETE)
// ---------------------------------------------------------

// Delete Record
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM equipment WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Machinery unit deleted successfully.";
    } else {
        $error = "Failed to delete machinery unit.";
    }
    $stmt->close();
}

// Create or Update Record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name                  = trim($_POST['name']);
    $model                 = trim($_POST['model']);
    $status                = trim($_POST['status']);
    $assigned_operator     = trim($_POST['assigned_operator']);
    $serial_number         = !empty($_POST['serial_number']) ? trim($_POST['serial_number']) : null;
    $purchase_date         = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d');
    $purchase_cost         = floatval($_POST['purchase_cost'] ?? 0);
    $lifespan_years        = intval($_POST['lifespan_years'] ?? 0);
    $salvage_value         = floatval($_POST['salvage_value'] ?? 0);
    $total_operating_hours = floatval($_POST['total_operating_hours'] ?? 0);
    
    $machinery_id = isset($_POST['machinery_id']) ? intval($_POST['machinery_id']) : null;

    if ($machinery_id) {
        // Update Record
        $stmt = $conn->prepare("UPDATE equipment SET name = ?, model = ?, status = ?, assigned_operator = ?, serial_number = ?, purchase_date = ?, purchase_cost = ?, lifespan_years = ?, salvage_value = ?, total_operating_hours = ? WHERE id = ?");
        $stmt->bind_param("ssssssdiddi", $name, $model, $status, $assigned_operator, $serial_number, $purchase_date, $purchase_cost, $lifespan_years, $salvage_value, $total_operating_hours, $machinery_id);
        if ($stmt->execute()) {
            $message = "Machinery record updated successfully.";
        } else {
            $error = "Failed to update equipment record: " . $stmt->error;
        }
        $stmt->close();
    } else {
        // Insert Record
        $stmt = $conn->prepare("INSERT INTO equipment (name, model, status, assigned_operator, serial_number, purchase_date, purchase_cost, lifespan_years, salvage_value, total_operating_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssdidd", $name, $model, $status, $assigned_operator, $serial_number, $purchase_date, $purchase_cost, $lifespan_years, $salvage_value, $total_operating_hours);
        if ($stmt->execute()) {
            $message = "Machinery asset registered successfully.";
        } else {
            $error = "Failed to register machinery asset: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Fetch Edit Data
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
// 2. FETCH EQUIPMENT & ANALYTICS
// ---------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(name LIKE ? OR model LIKE ? OR assigned_operator LIKE ? OR serial_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if ($status_filter !== '') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);
$sql = "SELECT * FROM equipment WHERE $where_sql ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$equipment_res = $stmt->get_result();
$equipment_list = $equipment_res ? $equipment_res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Analytics Counters
$total_units = count($equipment_list);
$operational_units = 0;
$maintenance_units = 0;
$total_fleet_value = 0;

foreach ($equipment_list as $eq) {
    $st = strtolower($eq['status'] ?? '');
    if ($st === 'operational') $operational_units++;
    if ($st === 'maintenance') $maintenance_units++;
    $total_fleet_value += floatval($eq['purchase_cost'] ?? 0);
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
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --border: #cbd5e1;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; line-height: 1.5; }

        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .header-nav h1 { font-size: 1.6rem; color: var(--primary-dark); font-weight: 700; letter-spacing: -0.5px; }

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

        .btn-back svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.2s ease-in-out; }
        .btn-back:hover { background-color: var(--primary); color: var(--white); border-color: var(--primary); transform: translateY(-1px); }
        .btn-back:hover svg { transform: translateX(-3px); }

        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }

        .workspace { display: grid; grid-template-columns: 380px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff; }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }

        .btn { background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; color: #ffffff; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border); }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .badge { padding: 3px 8px; font-size: 0.75rem; font-weight: 700; border-radius: 12px; display: inline-block; }
        .badge-op { background: #dcfce7; color: #15803d; }
        .badge-maint { background: #fef3c7; color: #b45309; }
        .badge-inact { background: #fee2e2; color: #b91c1c; }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) { .workspace { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <div class="header-nav">
        <h1>Machinery & Equipment Fleet</h1>
        <a href="/Farm_Management_System/index.php" class="btn-back">
            <svg viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Return to Dashboard
        </a>
    </div>

    <!-- Fleet Analytics KPI Cards -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Assets Registered</small>
            <h3><?= $total_units ?></h3>
        </div>
        <div class="stat-card">
            <small>Operational Units</small>
            <h3><?= $operational_units ?></h3>
        </div>
        <div class="stat-card">
            <small>In Maintenance</small>
            <h3><?= $maintenance_units ?></h3>
        </div>
        <div class="stat-card">
            <small>Total Capital Asset Value</small>
            <h3><?= number_format($total_fleet_value, 2) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">ZMW</span></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Asset Entry & Edit Form Card -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Machinery Unit' : 'Register New Asset' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="machinery.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="machinery_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Machine Name / Designation *</label>
                    <input type="text" name="name" placeholder="e.g. John Deere 5075E" value="<?= $edit_data ? htmlspecialchars($edit_data['name']) : '' ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Model Spec *</label>
                        <input type="text" name="model" placeholder="e.g. Utility Tractor" value="<?= $edit_data ? htmlspecialchars($edit_data['model']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Serial Number</label>
                        <input type="text" name="serial_number" placeholder="e.g. SN-98214" value="<?= $edit_data ? htmlspecialchars($edit_data['serial_number'] ?? '') : '' ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Assigned Operator</label>
                        <input type="text" name="assigned_operator" placeholder="Enter name..." value="<?= $edit_data ? htmlspecialchars($edit_data['assigned_operator'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Operational Status *</label>
                        <select name="status" required>
                            <option value="Operational" <?= ($edit_data && strtolower($edit_data['status'] ?? '') === 'operational') ? 'selected' : '' ?>>Operational</option>
                            <option value="Maintenance" <?= ($edit_data && strtolower($edit_data['status'] ?? '') === 'maintenance') ? 'selected' : '' ?>>In Maintenance</option>
                            <option value="Inactive" <?= ($edit_data && strtolower($edit_data['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Purchase Date *</label>
                        <input type="date" name="purchase_date" value="<?= $edit_data ? $edit_data['purchase_date'] : date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Purchase Cost (ZMW) *</label>
                        <input type="number" step="0.01" name="purchase_cost" placeholder="0.00" value="<?= $edit_data ? $edit_data['purchase_cost'] : '' ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Lifespan (Years) *</label>
                        <input type="number" name="lifespan_years" placeholder="e.g. 10" value="<?= $edit_data ? $edit_data['lifespan_years'] : '10' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Salvage Value (ZMW) *</label>
                        <input type="number" step="0.01" name="salvage_value" placeholder="0.00" value="<?= $edit_data ? $edit_data['salvage_value'] : '0.00' ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Total Operating Hours Logged</label>
                    <input type="number" step="0.01" name="total_operating_hours" placeholder="0.00" value="<?= $edit_data ? $edit_data['total_operating_hours'] : '0.00' ?>">
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Machinery Record' : 'Register Asset' ?></button>
                <?php if($edit_data): ?>
                    <a href="machinery.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Fleet Roster Log Grid -->
        <div class="card">
            <h2>Registered Fleet Roster</h2>

            <!-- Search and Filter Bar -->
            <form method="GET" action="machinery.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Asset / Operator / SN:</label>
                    <input type="text" name="search" placeholder="Search fleet..." value="<?= htmlspecialchars($search) ?>">
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

            <!-- Machinery Fleet Data Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Asset / S/N</th>
                            <th>Model Spec</th>
                            <th>Cost & Date</th>
                            <th>Hours Logged</th>
                            <th>Operator</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($equipment_list)): ?>
                            <?php foreach($equipment_list as $eq): ?>
                            <?php 
                                $st = strtolower($eq['status'] ?? 'operational');
                                $badge_class = 'badge-op';
                                if ($st === 'maintenance') $badge_class = 'badge-maint';
                                if ($st === 'inactive') $badge_class = 'badge-inact';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($eq['name']) ?></strong><br>
                                    <small style="color:var(--text-light);"><?= !empty($eq['serial_number']) ? 'SN: ' . htmlspecialchars($eq['serial_number']) : 'No S/N' ?></small>
                                </td>
                                <td><?= htmlspecialchars($eq['model']) ?></td>
                                <td>
                                    <strong><?= number_format($eq['purchase_cost'], 2) ?> ZMW</strong><br>
                                    <small style="color:var(--text-light);"><?= htmlspecialchars($eq['purchase_date']) ?></small>
                                </td>
                                <td><strong><?= number_format($eq['total_operating_hours'], 2) ?> hrs</strong></td>
                                <td><?= !empty($eq['assigned_operator']) ? htmlspecialchars($eq['assigned_operator']) : '<em style="color:var(--text-light);">Unassigned</em>' ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?= ucfirst(htmlspecialchars($eq['status'] ?? 'Operational')) ?></span></td>
                                <td class="actions">
                                    <a href="machinery.php?action=edit&id=<?= $eq['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="machinery.php?action=delete&id=<?= $eq['id'] ?>" onclick="return confirm('Are you sure you want to delete this asset?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-light); padding: 25px;">No machinery assets found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>