<?php
// 1. DYNAMIC DB ROUTING & SETUP (Matches Fuel Module Routing)
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) {
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';
}
require_once $db_path;

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// Auto-create table matching exact ifms_db.sql schema
$conn->query("CREATE TABLE IF NOT EXISTS maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    service_type ENUM('Routine', 'Repair') NOT NULL,
    description TEXT NOT NULL,
    cost DECIMAL(10, 2) NOT NULL,
    service_date DATE NOT NULL,
    next_due_hours DECIMAL(10, 2) DEFAULT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
)");

$message = "";
$error = "";

// Delete Maintenance Record
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM maintenance_logs WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Maintenance record deleted successfully.";
    } else {
        $error = "Failed to delete maintenance record.";
    }
    $stmt->close();
}

// Create or Update Maintenance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = intval($_POST['equipment_id']);
    $service_type = $_POST['service_type'];
    $description = trim($_POST['description']);
    $cost = floatval($_POST['cost']);
    $service_date = $_POST['service_date'];
    $next_due_hours = ($_POST['next_due_hours'] !== '') ? floatval($_POST['next_due_hours']) : null;
    $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : null;

    if ($log_id) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE maintenance_logs SET equipment_id = ?, service_type = ?, description = ?, cost = ?, service_date = ?, next_due_hours = ? WHERE id = ?");
        $stmt->bind_param("issdsdi", $equipment_id, $service_type, $description, $cost, $service_date, $next_due_hours, $log_id);
        if ($stmt->execute()) {
            $message = "Maintenance record updated successfully.";
        } else {
            $error = "Failed to update record: " . $stmt->error;
        }
        $stmt->close();
    } else {
        // Insert new record
        $stmt = $conn->prepare("INSERT INTO maintenance_logs (equipment_id, service_type, description, cost, service_date, next_due_hours) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdsd", $equipment_id, $service_type, $description, $cost, $service_date, $next_due_hours);
        if ($stmt->execute()) {
            $message = "Maintenance record saved successfully.";
        } else {
            $error = "Failed to record maintenance event: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Check for Edit Mode
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM maintenance_logs WHERE id = ?");
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

// Fetch Equipment Dropdown & Reminders Data
$equipment_result = $conn->query("SELECT id, name, total_operating_hours FROM equipment ORDER BY name ASC");
$equipment = $equipment_result ? $equipment_result->fetch_all(MYSQLI_ASSOC) : [];

// Search and Date Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(e.name LIKE ? OR m.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}
if ($start_date !== '') {
    $where_clauses[] = "m.service_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date !== '') {
    $where_clauses[] = "m.service_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Maintenance Logs
$sql = "SELECT m.*, e.name as eq_name 
        FROM maintenance_logs m 
        LEFT JOIN equipment e ON m.equipment_id = e.id 
        WHERE $where_sql 
        ORDER BY m.service_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs_result = $stmt->get_result();
$logs = $logs_result ? $logs_result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Compute Analytics
$total_maint_cost = 0;
$routine_count = 0;
$repair_count = 0;

foreach ($logs as $log) {
    $total_maint_cost += $log['cost'];
    if ($log['service_type'] === 'Routine') {
        $routine_count++;
    } else {
        $repair_count++;
    }
}
$log_count = count($logs);

// CSV Export Action
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=maintenance_logs_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Equipment', 'Service Type', 'Description', 'Cost (ZMW)', 'Next Due Hours']);
    foreach ($logs as $row) {
        fputcsv($output, [$row['id'], $row['service_date'], $row['eq_name'] ?? 'Unknown Machine', $row['service_type'], $row['description'], $row['cost'], $row['next_due_hours'] ?? 'N/A']);
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
    <title>Maintenance & Repairs - Lima Digital</title>
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

        /* Navigation Header */
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

        .btn-back:hover svg { transform: translateX(-3px); }

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

        /* Alerts & Reminders Box */
        .reminders-card {
            background: #fffbe2; border: 1px solid #ffe58f; border-radius: 8px;
            padding: 16px; margin-bottom: 25px;
        }
        .reminders-card h3 { color: #856404; font-size: 0.95rem; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .reminders-list { list-style: none; padding-left: 0; }
        .reminders-list li { color: #856404; font-size: 0.85rem; padding: 4px 0; font-weight: 500; }

        /* Main Workspace Split Screen */
        .workspace { display: grid; grid-template-columns: 340px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        /* Status Alerts */
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        /* Form Controls */
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select, textarea {
            width: 100%; padding: 8px 12px; border: 1px solid var(--border);
            border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff;
        }
        textarea { resize: vertical; min-height: 75px; }
        input:focus, select:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }

        /* Buttons */
        .btn {
            background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px;
            font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; color: #ffffff; }

        /* Filter Toolbar */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border);
        }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }

        /* Table Design */
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;
        }
        .badge-routine { background: #e0f2fe; color: #0369a1; }
        .badge-repair { background: #fef3c7; color: #b45309; }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) {
            .workspace { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <div class="header-nav">
        <h1>Maintenance & Repairs</h1>
        <div class="header-actions">
            <a href="maintenance.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-secondary">Export CSV</a>
            <a href="/Farm_Management_System/index.php" class="btn-back">
                <svg viewBox="0 0 24 24">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Return to Dashboard
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <button class="tab-item active">Service Log</button>
        <button class="tab-item">Preventive Schedule</button>
        <button class="tab-item">Parts & Inventories</button>
    </div>

    <!-- Active Reminders Ribbon -->
    <?php 
    $active_alerts = [];
    foreach($equipment as $eq) {
        $stmt_due = $conn->prepare("SELECT next_due_hours FROM maintenance_logs WHERE equipment_id = ? AND next_due_hours IS NOT NULL ORDER BY service_date DESC LIMIT 1");
        $stmt_due->bind_param("i", $eq['id']);
        $stmt_due->execute();
        $res_due = $stmt_due->get_result()->fetch_assoc();
        $stmt_due->close();

        if ($res_due && !empty($res_due['next_due_hours'])) {
            $due = $res_due['next_due_hours'];
            if ($eq['total_operating_hours'] >= ($due - 50)) {
                $active_alerts[] = [
                    'name' => $eq['name'],
                    'current' => $eq['total_operating_hours'],
                    'due' => $due
                ];
            }
        }
    }
    if (!empty($active_alerts)):
    ?>
    <div class="reminders-card">
        <h3>⚠️ Active Maintenance Alerts</h3>
        <ul class="reminders-list">
            <?php foreach($active_alerts as $alert): ?>
                <li><strong><?= htmlspecialchars($alert['name']) ?></strong> is approaching service threshold. Current: <strong><?= number_format($alert['current'], 2) ?> hrs</strong> (Due at: <strong><?= number_format($alert['due'], 2) ?> hrs</strong>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Analytics Operational Bar -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Spend</small>
            <h3><?= number_format($total_maint_cost, 2) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">ZMW</span></h3>
        </div>
        <div class="stat-card">
            <small>Total Service Logs</small>
            <h3><?= $log_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Entries</span></h3>
        </div>
        <div class="stat-card">
            <small>Routine Maintenance</small>
            <h3><?= $routine_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Events</span></h3>
        </div>
        <div class="stat-card">
            <small>Unplanned Repairs</small>
            <h3><?= $repair_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Events</span></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form Sidebar -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Maintenance Log' : 'Record Service Log' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="log_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Target Machine *</label>
                    <select name="equipment_id" required>
                        <option value="">-- Select Machine --</option>
                        <?php foreach($equipment as $eq): ?>
                            <option value="<?= $eq['id'] ?>" <?= ($edit_data && $edit_data['equipment_id'] == $eq['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($eq['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Service Type *</label>
                    <select name="service_type" required>
                        <option value="Routine" <?= ($edit_data && $edit_data['service_type'] === 'Routine') ? 'selected' : '' ?>>Routine Maintenance</option>
                        <option value="Repair" <?= ($edit_data && $edit_data['service_type'] === 'Repair') ? 'selected' : '' ?>>Unplanned Repair</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Description / Parts Replaced *</label>
                    <textarea name="description" placeholder="e.g. Changed oil filter, replaced hydraulic hose..." required><?= $edit_data ? htmlspecialchars($edit_data['description']) : '' ?></textarea>
                </div>

                <div class="form-group">
                    <label>Total Cost (ZMW) *</label>
                    <input type="number" step="0.01" name="cost" placeholder="e.g. 850.00" value="<?= $edit_data ? $edit_data['cost'] : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Service Date *</label>
                    <input type="date" name="service_date" value="<?= $edit_data ? $edit_data['service_date'] : date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Next Service Due (Operating Hours)</label>
                    <input type="number" step="0.01" name="next_due_hours" placeholder="e.g. 1200.00" value="<?= $edit_data ? $edit_data['next_due_hours'] : '' ?>">
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Entry' : 'Save Maintenance Log' ?></button>
                <?php if($edit_data): ?>
                    <a href="maintenance.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Audit Log Grid -->
        <div class="card">
            <h2>Maintenance & Repair History</h2>

            <!-- Search and Date Filter Toolbar -->
            <form method="GET" action="maintenance.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Asset/Work:</label>
                    <input type="text" name="search" placeholder="Search equipment or description..." value="<?= htmlspecialchars($search) ?>">
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
                    <a href="maintenance.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>

            <!-- Maintenance Log Data Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Equipment Asset</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Cost</th>
                            <th>Next Due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach($logs as $log): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($log['service_date']) ?></strong></td>
                                <td><?= htmlspecialchars($log['eq_name'] ?? 'Unlinked Asset') ?></td>
                                <td>
                                    <span class="badge <?= $log['service_type'] === 'Routine' ? 'badge-routine' : 'badge-repair' ?>">
                                        <?= htmlspecialchars($log['service_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($log['description']) ?></td>
                                <td><strong><?= number_format($log['cost'], 2) ?> ZMW</strong></td>
                                <td><?= $log['next_due_hours'] !== null ? number_format($log['next_due_hours'], 2) . ' hrs' : '-' ?></td>
                                <td class="actions">
                                    <a href="maintenance.php?action=edit&id=<?= $log['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="maintenance.php?action=delete&id=<?= $log['id'] ?>" onclick="return confirm('Are you sure you want to delete this log entry?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-light); padding: 25px;">No maintenance records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>