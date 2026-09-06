<?php
include 'db_connect.php';

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = $_POST['equipment_id'];
    $task_name = $_POST['task_name'];
    $operator = $_POST['operator_name'];
    $hours_logged = $_POST['hours_logged'];
    $allocation_date = $_POST['allocation_date'];

    $stmt = $pdo->prepare("INSERT INTO equipment_allocations (equipment_id, task_name, operator_name, hours_logged, allocation_date) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$equipment_id, $task_name, $operator, $hours_logged, $allocation_date])) {
        // Update cumulative operating hours
        $update = $pdo->prepare("UPDATE equipment SET total_operating_hours = total_operating_hours + ? WHERE id = ?");
        $update->execute([$hours_logged, $equipment_id]);
        $message = "Allocation and operating hours updated.";
    }
}

$equipment = $pdo->query("SELECT id, name FROM equipment")->fetchAll(PDO::FETCH_ASSOC);
$allocations = $pdo->query("SELECT a.*, e.name as eq_name FROM equipment_allocations a JOIN equipment e ON a.equipment_id = e.id ORDER BY a.allocation_date DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Equipment Allocation & Operating Hours - IFMS</title>
</head>
<body>
    <div class="container">
        <h2>Equipment Allocation & Operating Hours Tracker</h2>
        <?php if($message): ?><p class="alert"><?= $message ?></p><?php endif; ?>

        <form action="equipment_allocation.php" method="POST">
            <label>Select Equipment:</label>
            <select name="equipment_id" required>
                <?php foreach($equipment as $eq): ?>
                    <option value="<?= $eq['id'] ?>"><?= htmlspecialchars($eq['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Task / Field Name:</label>
            <input type="text" name="task_name" required>

            <label>Operator Name:</label>
            <input type="text" name="operator_name" required>

            <label>Operating Hours Logged:</label>
            <input type="number" step="0.1" name="hours_logged" required>

            <label>Date:</label>
            <input type="date" name="allocation_date" required>

            <button type="submit">Submit Allocation</button>
        </form>

        <h3>Allocation History</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Equipment</th>
                    <th>Task</th>
                    <th>Operator</th>
                    <th>Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($allocations as $alloc): ?>
                <tr>
                    <td><?= $alloc['allocation_date'] ?></td>
                    <td><?= htmlspecialchars($alloc['eq_name']) ?></td>
                    <td><?= htmlspecialchars($alloc['task_name']) ?></td>
                    <td><?= htmlspecialchars($alloc['operator_name']) ?></td>
                    <td><?= $alloc['hours_logged'] ?> hrs</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>