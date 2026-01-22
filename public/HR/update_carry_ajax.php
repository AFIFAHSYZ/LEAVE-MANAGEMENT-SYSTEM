<?php
require_once '../../config/conn1.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int)$_POST['user_id'];
    $leave_id = (int)$_POST['leave_id'];
    $carry_forward = (int)$_POST['carry_forward'];

    // Enforce max/min boundaries
    if ($carry_forward > 5) $carry_forward = 5;
    if ($carry_forward < 0) $carry_forward = 0;

    try {
        // Update both carry_forward and total_available together
        $sql = "
            UPDATE leave_balances
            SET 
                carry_forward = :carry_forward,
                total_available = (entitled_days + :carry_forward - used_days)
            WHERE user_id = :user_id AND leave_type_id = :leave_id
            RETURNING total_available;
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':carry_forward' => $carry_forward,
            ':user_id' => $user_id,
            ':leave_id' => $leave_id
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode([
                'success' => true,
                'carry_forward' => $carry_forward,
                'total_available' => $row['total_available']
            ]);
            exit;
        }
        echo json_encode(['success' => false]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
