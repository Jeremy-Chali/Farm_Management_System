<?php
// Load database configuration from project root
require_once $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

$message = "";
$error = "";

// ---------------------------------------------------------
// 1. HANDLE POST ACTIONS (CREATE, EDIT, DELETE)
// ---------------------------------------------------------

// Delete Record
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM fuel_logs WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Fuel record deleted successfully.";
    } else {
        $error = "Failed to delete fuel record.";
    }
    $stmt->close();
}

// Create or Update Record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = intval($_POST['equipment_id']);
    $litres_consumed = floatval($_POST['litres_consumed']);
    $total_cost = floatval($_POST['total_cost']);
    $log_date = $_POST['log_date'];
    $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : null;

    if ($log_id) {
        // Update
        $stmt = $conn->prepare("UPDATE fuel_logs SET equipment_id = ?, litres_consumed = ?, total_cost = ?, log_date = ? WHERE id = ?");
        $stmt->bind_param("iddsi", $equipment_id, $litres_consumed, $total_cost, $log_date, $log_id);
        if ($stmt->execute()) {
            $message = "Fuel log updated successfully.";
        } else {
            $error = "Failed to update record.";
        }
        $stmt->close();
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO fuel_logs (equipment_id, litres_consumed, total_cost, log_date) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("idds", $equipment_id, $litres_consumed, $total_cost, $log_date);
        if ($stmt->execute()) {
            $message = "Fuel consumption recorded successfully.";
        } else {
            $error = "Failed to record fuel consumption.";
        }
        $stmt->close();
    }
}

// Check for Edit Mode
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM fuel_logs WHERE id = ?");
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

// Fetch Equipment Dropdown
$equipment_result = $conn->query("SELECT id, name FROM equipment ORDER BY name ASC");
$equipment = $equipment_result ? $equipment_result->fetch_all(MYSQLI_ASSOC) : [];

// Search and Date Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "e.name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}
if ($start_date !== '') {
    $where_clauses[] = "f.log_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date !== '') {
    $where_clauses[] = "f.log_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Fuel Logs with Filters
$sql = "SELECT f.*, e.name as eq_name 
        FROM fuel_logs f 
        JOIN equipment e ON f.equipment_id = e.id 
        WHERE $where_sql 
        ORDER BY f.log_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$fuel_logs_result = $stmt->get_result();
$fuel_logs = $fuel_logs_result ? $fuel_logs_result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Compute Analytics
$total_spent = 0;
$total_litres = 0;
foreach ($fuel_logs as $log) {
    $total_spent += $log['total_cost'];
    $total_litres += $log['litres_consumed'];
}
$avg_cost_per_litre = $total_litres > 0 ? ($total_spent / $total_litres) : 0;
$log_count = count($fuel_logs);

// CSV Export Action
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fuel_logs_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Equipment', 'Litres Consumed', 'Total Cost (kwacha)']);
    foreach ($fuel_logs as $row) {
        fputcsv($output, [$row['id'], $row['log_date'], $row['eq_name'], $row['litres_consumed'], $row['total_cost']]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Operations Hub - Lima Digital</title>
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

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
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

        /* KPI Analytics Ribbon */
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

        /* Filter Toolbar */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border);
        }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }

        /* Table Styling */
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) {
            .workspace { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation with Styled Back & Export Buttons -->
    <div class="header-nav">
        <h1>Fuel Operations & Dispensary</h1>
        <div class="header-actions">
            <a href="fuel.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-secondary">Export CSV</a>
            <a href="/Farm_Management_System/index.php" class="btn-back">
                <svg viewBox="0 0 24 24">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Return to Dashboard
            </a>
        </div>
    </div>

    <!-- Navigation Context Tabs -->
    <div class="nav-tabs">
        <button class="tab-item active">Dispensary Audit Log</button>
        <button class="tab-item">Fuel Tank Inventory</button>
        <button class="tab-item">Burn Rates & Efficiency</button>
    </div>

    <!-- Analytics Operational Bar -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Expenditure</small>
            <h3><?= number_format($total_spent, 2) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">ZMW</span></h3>
        </div>
        <div class="stat-card">
            <small>Total Fuel Volume</small>
            <h3><?= number_format($total_litres, 2) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">L</span></h3>
        </div>
        <div class="stat-card">
            <small>Average Price / Litre</small>
            <h3><?= number_format($avg_cost_per_litre, 2) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">ZMW/L</span></h3>
        </div>
        <div class="stat-card">
            <small>Dispensary Records</small>
            <h3><?= $log_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Entries</span></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form Sidebar -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Fuel Record' : 'Record Fuel Usage' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="fuel.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="log_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Target Equipment *</label>
                    <select name="equipment_id" required>
                        <?php foreach($equipment as $eq): ?>
                            <option value="<?= $eq['id'] ?>" <?= ($edit_data && $edit_data['equipment_id'] == $eq['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($eq['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Volume Consumed (Litres) *</label>
                    <input type="number" step="0.01" name="litres_consumed" placeholder="e.g. 55.00" value="<?= $edit_data ? $edit_data['litres_consumed'] : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Total Cost (kwacha) *</label>
                    <input type="number" step="0.01" name="total_cost" placeholder="e.g. 1200.00" value="<?= $edit_data ? $edit_data['total_cost'] : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Dispense Date *</label>
                    <input type="date" name="log_date" value="<?= $edit_data ? $edit_data['log_date'] : date('Y-m-d') ?>" required>
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Entry' : 'Save Fuel Log' ?></button>
                <?php if($edit_data): ?>
                    <a href="fuel.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Audit Log Grid -->
        <div class="card">
            <h2>Fuel Audit & Dispensing Log</h2>

            <!-- Search and Date Filter Toolbar -->
            <form method="GET" action="fuel.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Machine:</label>
                    <input type="text" name="search" placeholder="Search equipment..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>From Date:</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="form-group">
                    <label>To Date:</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="fuel.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>

            <!-- Fuel Log Data Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Log Date</th>
                            <th>Equipment Asset</th>
                            <th>Volume (L)</th>
                            <th>Total Cost</th>
                            <th>Unit Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($fuel_logs)): ?>
                            <?php foreach($fuel_logs as $fuel): ?>
                            <?php $unit_rate = $fuel['litres_consumed'] > 0 ? ($fuel['total_cost'] / $fuel['litres_consumed']) : 0; ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($fuel['log_date']) ?></strong></td>
                                <td><?= htmlspecialchars($fuel['eq_name']) ?></td>
                                <td><strong><?= number_format($fuel['litres_consumed'], 2) ?> L</strong></td>
                                <td><?= number_format($fuel['total_cost'], 2) ?> kwacha</td>
                                <td><small style="color:var(--text-light);"><?= number_format($unit_rate, 2) ?> ZMW/L</small></td>
                                <td class="actions">
                                    <a href="fuel.php?action=edit&id=<?= $fuel['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="fuel.php?action=delete&id=<?= $fuel['id'] ?>" onclick="return confirm('Are you sure you want to delete this fuel record?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-light); padding: 25px;">No fuel logs found matching your criteria.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>