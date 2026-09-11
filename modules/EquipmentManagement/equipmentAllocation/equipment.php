<?php
// Define relative and root path lookups for database connection
$config_files = [
    __DIR__ . '/../../db_connect.php',
    __DIR__ . '/../../db_config.php',
    __DIR__ . '/../db_connect.php',
    __DIR__ . '/../db_config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_connect.php',
    $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php'
];

$connected = false;

foreach ($config_files as $file) {
    if (file_exists($file)) {
        include_once $file;
        if (isset($pdo) && $pdo instanceof PDO) {
            $connected = true;
            break;
        }
    }
}

// Fallback: Direct connection if configuration file path fails
if (!$connected) {
    $db_host = 'localhost';
    $db_name = 'ifms_db';
    $db_user = 'root';
    $db_pass = '';

    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die("<div style='padding:20px; font-family:sans-serif; color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; border-radius:6px; margin:40px auto; max-width:600px;'>
                <h3 style='margin-bottom:10px;'>Database Connection Error</h3>
                <p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                <p style='margin-top:10px; font-size:0.9rem;'>Ensure MySQL is running in XAMPP and <code>ifms_db</code> is initialized.</p>
             </div>");
    }
}

$message = "";
$message_type = "";

// ---------------------------------------------------------
// 1. ACTION HANDLERS (DELETE & EDIT / CREATE POST)
// ---------------------------------------------------------

// DELETE ACTION
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    try {
        $pdo->beginTransaction();

        // Fetch log details to deduct hours from equipment table
        $stmt = $pdo->prepare("SELECT equipment_id, hours_logged FROM equipment_allocations WHERE id = ?");
        $stmt->execute([$delete_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Subtract hours from total_operating_hours
            $deduct = $pdo->prepare("UPDATE equipment SET total_operating_hours = GREATEST(0, total_operating_hours - ?) WHERE id = ?");
            $deduct->execute([$existing['hours_logged'], $existing['equipment_id']]);

            // Delete record from equipment_allocations
            $del_stmt = $pdo->prepare("DELETE FROM equipment_allocations WHERE id = ?");
            $del_stmt->execute([$delete_id]);

            $pdo->commit();
            $message = "Allocation log deleted and equipment total hours adjusted successfully.";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Failed to delete record: " . $e->getMessage();
        $message_type = "danger";
    }
}

// SAVE ACTION (CREATE OR UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allocation_id = !empty($_POST['allocation_id']) ? (int)$_POST['allocation_id'] : null;
    $equipment_id = (int)($_POST['equipment_id'] ?? 0);
    $task_name = trim($_POST['task_name'] ?? '');
    $operator = trim($_POST['operator_name'] ?? '');
    $hours_logged = (float)($_POST['hours_logged'] ?? 0);
    $allocation_date = $_POST['allocation_date'] ?? '';

    if (!empty($equipment_id) && !empty($task_name) && !empty($operator) && $hours_logged > 0 && !empty($allocation_date)) {
        try {
            $pdo->beginTransaction();

            if ($allocation_id) {
                // Fetch old log data to re-adjust operating hours
                $old_stmt = $pdo->prepare("SELECT equipment_id, hours_logged FROM equipment_allocations WHERE id = ?");
                $old_stmt->execute([$allocation_id]);
                $old_data = $old_stmt->fetch();

                if ($old_data) {
                    // Reverse previous hours from original machine
                    $rev_stmt = $pdo->prepare("UPDATE equipment SET total_operating_hours = GREATEST(0, total_operating_hours - ?) WHERE id = ?");
                    $rev_stmt->execute([$old_data['hours_logged'], $old_data['equipment_id']]);
                }

                // Update allocation record
                $stmt = $pdo->prepare("UPDATE equipment_allocations SET equipment_id = ?, task_name = ?, operator_name = ?, hours_logged = ?, allocation_date = ? WHERE id = ?");
                $stmt->execute([$equipment_id, $task_name, $operator, $hours_logged, $allocation_date, $allocation_id]);

                // Apply updated hours to equipment
                $add_stmt = $pdo->prepare("UPDATE equipment SET total_operating_hours = total_operating_hours + ? WHERE id = ?");
                $add_stmt->execute([$hours_logged, $equipment_id]);

                $message = "Allocation record updated successfully.";
                $message_type = "success";
            } else {
                // Insert new allocation record
                $stmt = $pdo->prepare("INSERT INTO equipment_allocations (equipment_id, task_name, operator_name, hours_logged, allocation_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$equipment_id, $task_name, $operator, $hours_logged, $allocation_date]);

                // Update equipment operating hours
                $update = $pdo->prepare("UPDATE equipment SET total_operating_hours = total_operating_hours + ? WHERE id = ?");
                $update->execute([$hours_logged, $equipment_id]);

                $message = "Allocation record created and equipment operating hours updated successfully.";
                $message_type = "success";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please complete all required fields correctly.";
        $message_type = "warning";
    }
}

// Fetch record for Edit mode
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM equipment_allocations WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// ---------------------------------------------------------
// 2. FETCH DATA & METRICS FOR DISPLAY
// ---------------------------------------------------------

$total_hours = $pdo->query("SELECT SUM(hours_logged) AS grand_total FROM equipment_allocations")->fetch()['grand_total'] ?? 0;
$total_allocations = $pdo->query("SELECT COUNT(id) AS total_count FROM equipment_allocations")->fetch()['total_count'] ?? 0;
$total_machines = $pdo->query("SELECT COUNT(id) AS machine_count FROM equipment")->fetch()['machine_count'] ?? 0;

$equipment = $pdo->query("SELECT id, name, model, serial_number FROM equipment ORDER BY name ASC")->fetchAll();

$sql = "SELECT a.*, e.name AS eq_name, e.model AS eq_model, e.serial_number AS eq_serial 
        FROM equipment_allocations a 
        JOIN equipment e ON a.equipment_id = e.id 
        ORDER BY a.allocation_date DESC, a.id DESC";
$allocations = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Operations & Equipment Allocation - Lima Digital</title>
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
            --border: #cbd5e1;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            --transition: all 0.2s ease-in-out;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-bg); color: var(--text-dark); line-height: 1.6; }

        .top-header {
            padding: 16px 5%; background: var(--white); box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex; justify-content: flex-end; align-items: center; border-bottom: 1px solid var(--border);
        }

        .btn-back {
            display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px;
            background-color: var(--white); color: var(--primary-dark); font-weight: 600;
            font-size: 0.85rem; border-radius: 8px; border: 1px solid var(--border);
            text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: var(--transition);
        }

        .btn-back svg {
            width: 16px; height: 16px; fill: none; stroke: currentColor;
            stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.2s ease-in-out;
        }

        .btn-back:hover {
            background-color: var(--primary); color: var(--white); border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(27, 77, 62, 0.15); transform: translateY(-1px);
        }

        .btn-back:hover svg { transform: translateX(-3px); }

        .dashboard-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h1 { font-size: 1.8rem; color: var(--primary-dark); font-weight: 700; }
        .page-header p { color: var(--text-light); font-size: 0.95rem; }

        .alert { padding: 14px 20px; border-radius: 8px; margin-bottom: 25px; font-size: 0.95rem; display: flex; align-items: center; gap: 10px; }
        .alert-success { background-color: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-warning { background-color: #fff8e1; color: #f57f17; border: 1px solid #ffe082; }
        .alert-danger  { background-color: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: var(--white); padding: 22px 25px; border-radius: 12px; box-shadow: var(--card-shadow); border: 1px solid var(--border); display: flex; flex-direction: column; }
        .kpi-card .kpi-label { font-size: 0.85rem; color: var(--text-light); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .kpi-card .kpi-value { font-size: 1.9rem; font-weight: 700; color: var(--primary-dark); }

        .dashboard-grid { display: grid; grid-template-columns: 360px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); border-radius: 12px; padding: 25px; box-shadow: var(--card-shadow); border: 1px solid var(--border); }
        .card-title { font-size: 1.15rem; font-weight: 600; color: var(--primary-dark); margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.92rem; transition: var(--transition); background-color: #fafafa; }
        .form-control:focus { outline: none; border-color: var(--accent); background-color: var(--white); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1); }

        .btn-primary { width: 100%; background-color: var(--primary); color: var(--white); padding: 12px; border: none; border-radius: 6px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: var(--transition); display: inline-block; text-align: center; text-decoration: none; }
        .btn-primary:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); margin-top: 10px; }
        .btn-secondary:hover { background: #cbd5e1; }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .btn-sm-edit { background: #e0f2fe; color: #0369a1; }
        .btn-sm-edit:hover { background: #bae6fd; }
        .btn-sm-danger { background: #fee2e2; color: #dc2626; }
        .btn-sm-danger:hover { background: #fca5a5; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { background-color: #f8faf9; color: var(--text-light); font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 16px; text-align: left; border-bottom: 2px solid var(--border); }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 0.92rem; color: var(--text-dark); vertical-align: middle; }
        tr:hover td { background-color: #fbfdfc; }

        .badge-model { background: #e0f2fe; color: #0369a1; font-size: 0.78rem; padding: 3px 8px; border-radius: 4px; font-weight: 500; }
        .hours-tag { color: var(--primary); font-weight: 600; }
        .actions-cell { display: flex; gap: 6px; }

        @media (max-width: 992px) { .dashboard-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <header class="top-header">
        <a href="/Farm_Management_System/index.php" class="btn-back">
            <svg viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Return to Dashboard
        </a>
    </header>

    <div class="dashboard-container">
        
        <div class="page-header">
            <div>
                <h1>Field Operations & Equipment Allocation</h1>
                <p>Assign heavy machinery to field tasks and log total operating hours live.</p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?>">
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <div class="kpi-grid">
            <div class="kpi-card">
                <span class="kpi-label">Total Operating Hours Logged</span>
                <span class="kpi-value"><?= number_format($total_hours, 1) ?> <small style="font-size:1rem; font-weight:400;">hrs</small></span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Total Allocations</span>
                <span class="kpi-value"><?= number_format($total_allocations) ?></span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Registered Machinery Assets</span>
                <span class="kpi-value"><?= number_format($total_machines) ?></span>
            </div>
        </div>

        <div class="dashboard-grid">
            
            <!-- Left Panel: Allocation Form -->
            <div class="card">
                <h2 class="card-title"><?= $edit_data ? 'Edit Task Allocation' : 'New Task Allocation' ?></h2>
                <form action="equipment.php" method="POST">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="allocation_id" value="<?= $edit_data['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Select Equipment Asset</label>
                        <select name="equipment_id" class="form-control" required>
                            <option value="">-- Select Machine --</option>
                            <?php foreach ($equipment as $eq): ?>
                                <option value="<?= htmlspecialchars($eq['id']) ?>" <?= ($edit_data && $edit_data['equipment_id'] == $eq['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($eq['name']) ?> (<?= htmlspecialchars($eq['model']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Task / Field Operation</label>
                        <input type="text" name="task_name" class="form-control" placeholder="e.g. Block C Harrowing" value="<?= $edit_data ? htmlspecialchars($edit_data['task_name']) : '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Operator Name</label>
                        <input type="text" name="operator_name" class="form-control" placeholder="e.g. John Mwansa" value="<?= $edit_data ? htmlspecialchars($edit_data['operator_name']) : '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Operating Hours Logged</label>
                        <input type="number" step="0.1" min="0.1" name="hours_logged" class="form-control" placeholder="e.g. 6.5" value="<?= $edit_data ? htmlspecialchars($edit_data['hours_logged']) : '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Allocation Date</label>
                        <input type="date" name="allocation_date" class="form-control" value="<?= $edit_data ? htmlspecialchars($edit_data['allocation_date']) : date('Y-m-d') ?>" required>
                    </div>

                    <button type="submit" class="btn-primary"><?= $edit_data ? 'Update Allocation' : 'Record Allocation' ?></button>
                    
                    <?php if ($edit_data): ?>
                        <a href="equipment.php" class="btn-primary btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Right Panel: Allocation Table with Edit & Delete Actions -->
            <div class="card">
                <h2 class="card-title">Recent Field Allocation Logs</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Equipment</th>
                                <th>Model / S/N</th>
                                <th>Task / Field</th>
                                <th>Operator</th>
                                <th>Hours</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allocations) > 0): ?>
                                <?php foreach ($allocations as $alloc): ?>
                                <tr>
                                    <td><?= htmlspecialchars($alloc['allocation_date']) ?></td>
                                    <td><strong><?= htmlspecialchars($alloc['eq_name']) ?></strong></td>
                                    <td>
                                        <span class="badge-model"><?= htmlspecialchars($alloc['eq_model']) ?></span><br>
                                        <small style="color:var(--text-light);"><?= htmlspecialchars($alloc['eq_serial']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($alloc['task_name']) ?></td>
                                    <td><?= htmlspecialchars($alloc['operator_name']) ?></td>
                                    <td class="hours-tag"><?= htmlspecialchars($alloc['hours_logged']) ?> hrs</td>
                                    <td class="actions-cell">
                                        <a href="equipment.php?action=edit&id=<?= $alloc['id'] ?>" class="btn-sm btn-sm-edit">Edit</a>
                                        <a href="equipment.php?action=delete&id=<?= $alloc['id'] ?>" onclick="return confirm('Are you sure you want to delete this log? Equipment total hours will be updated.');" class="btn-sm btn-sm-danger">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-light); padding: 30px;">
                                        No allocation records found in system.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</body>
</html>