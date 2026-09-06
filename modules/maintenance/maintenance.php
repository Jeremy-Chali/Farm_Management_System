<?php
include 'db_connect.php';

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = $_POST['equipment_id'];
    $service_type = $_POST['service_type']; // 'Routine', 'Repair'
    $description = $_POST['description'];
    $cost = $_POST['cost'];
    $service_date = $_POST['service_date'];
    $next_due_hours = $_POST['next_due_hours'];

    $stmt = $pdo->prepare("INSERT INTO maintenance_logs (equipment_id, service_type, description, cost, service_date, next_due_hours) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$equipment_id, $service_type, $description, $cost, $service_date, $next_due_hours])) {
        $message = "Maintenance record saved.";
    }
}

$equipment = $pdo->query("SELECT e.id, e.name, e.total_operating_hours FROM equipment e")->fetchAll(PDO::FETCH_ASSOC);
$logs = $pdo->query("SELECT m.*, e.name as eq_name FROM maintenance_logs m JOIN equipment e ON m.equipment_id = e.id ORDER BY m.service_date DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance & Repairs - IFMS</title>
</head>
<body>
    <div class="container">
        <h2>Maintenance Schedules & Repair History</h2>
        
        <!-- Automated Maintenance Reminders Section -->
        <div class="reminders-box">
            <h3>Active Maintenance Reminders</h3>
            <ul>
                <?php foreach($equipment as $eq): 
                    $last_maint = $pdo->prepare("SELECT next_due_hours FROM maintenance_logs WHERE equipment_id = ? ORDER BY service_date DESC LIMIT 1");
                    $last_maint->execute([$eq['id']]);
                    $due = $last_maint->fetchColumn();
                    if ($due && $eq['total_operating_hours'] >= ($due - 50)):
                ?>
                    <li style="color: red;">⚠️ Warning: <?= htmlspecialchars($eq['name']) ?> is approaching its maintenance threshold (Current Hours: <?= $eq['total_operating_hours'] ?>, Due at: <?= $due ?>).</li>
                <?php endif; endforeach; ?>
            </ul>
        </div>

        <?php if($message): ?><p class="alert"><?= $message ?></p><?php endif; ?>

        <form action="maintenance_repairs.php" method="POST">
            <label>Equipment:</label>
            <select name="equipment_id" required>
                <?php foreach($equipment as $eq): ?>
                    <option value="<?= $eq['id'] ?>"><?= htmlspecialchars($eq['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Service Type:</label>
            <select name="service_type">
                <option value="Routine">Routine Maintenance Schedule</option>
                <option value="Repair">Unplanned Repair</option>
            </select>

            <label>Description / Parts Replaced:</label>
            <textarea name="description" required></textarea>

            <label>Cost ($):</label>
            <input type="number" step="0.01" name="cost" required>

            <label>Service Date:</label>
            <input type="date" name="service_date" required>

            <label>Next Due at Operating Hours (Optional):</label>
            <input type="number" name="next_due_hours">

            <button type="submit">Log Maintenance/Repair</button>
        </form>

        <h3>Repair & Maintenance History Log</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Equipment</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td><?= $log['service_date'] ?></td>
                    <td><?= htmlspecialchars($log['eq_name']) ?></td>
                    <td><?= $log['service_type'] ?></td>
                    <td><?= htmlspecialchars($log['description']) ?></td>
                    <td>$<?= number_format($log['cost'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>