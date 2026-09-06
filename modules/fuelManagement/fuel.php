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
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --white: #ffffff;
            --border: #e2e8f0;
            --danger: #d9534f;
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
        <a href="fuel.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-secondary">Export to CSV</a>
    </div>

    <!-- Analytics Dashboard Cards -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Spent</small>
            <h3><?= number_format($total_spent, 2) ?> kwacha</h3>
        </div>
        <div class="stat-card">
            <small>Total Fuel Consumed</small>
            <h3><?= number_format($total_litres, 2) ?> L</h3>
        </div>
        <div class="stat-card">
            <small>Average Cost / Litre</small>
            <h3><?= number_format($avg_cost_per_litre, 2) ?> kwacha</h3>
        </div>
        <div class="stat-card">
            <small>Total Logs Recorded</small>
            <h3><?= $log_count ?></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Fuel Log' : 'Record Fuel Usage' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="fuel.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="log_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Equipment:</label>
                    <select name="equipment_id" required>
                        <?php foreach($equipment as $eq): ?>
                            <option value="<?= $eq['id'] ?>" <?= ($edit_data && $edit_data['equipment_id'] == $eq['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($eq['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Litres Consumed (L):</label>
                    <input type="number" step="0.01" name="litres_consumed" value="<?= $edit_data ? $edit_data['litres_consumed'] : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Total Cost (kwacha):</label>
                    <input type="number" step="0.01" name="total_cost" value="<?= $edit_data ? $edit_data['total_cost'] : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Log Date:</label>
                    <input type="date" name="log_date" value="<?= $edit_data ? $edit_data['log_date'] : date('Y-m-d') ?>" required>
                </div>

                <button type="submit" class="btn" style="width: 100%;"><?= $edit_data ? 'Update Entry' : 'Save Entry' ?></button>
                <?php if($edit_data): ?>
                    <a href="fuel.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- History & Search View -->
        <div class="card">
            <h2>Fuel Audit Log</h2>

            <!-- Filter Toolbar -->
            <form method="GET" action="fuel.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Machine:</label>
                    <input type="text" name="search" placeholder="e.g. Tractor" value="<?= htmlspecialchars($search) ?>">
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

            <!-- Data Table -->
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Equipment</th>
                        <th>Litres</th>
                        <th>Cost</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($fuel_logs)): ?>
                        <?php foreach($fuel_logs as $fuel): ?>
                        <tr>
                            <td><?= htmlspecialchars($fuel['log_date']) ?></td>
                            <td><?= htmlspecialchars($fuel['eq_name']) ?></td>
                            <td><?= number_format($fuel['litres_consumed'], 2) ?> L</td>
                            <td><?= number_format($fuel['total_cost'], 2) ?> kwacha</td>
                            <td class="actions">
                                <a href="fuel.php?action=edit&id=<?= $fuel['id'] ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Edit</a>
                                <a href="fuel.php?action=delete&id=<?= $fuel['id'] ?>" onclick="return confirm('Are you sure you want to delete this record?');" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.8rem;">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-light);">No records found matching your criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>