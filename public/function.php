<?php
function calculateEntitledDays($pdo, $user_id, $leave_type_id) {
    // Get user join date
    $stmt = $pdo->prepare("SELECT date_joined FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    $join_date = $stmt->fetchColumn();

    if (!$join_date) return 0;

    // Calculate full years of service
    $years = floor((time() - strtotime($join_date)) / (365 * 24 * 60 * 60));

    // Find the matching tenure policy
    $stmt = $pdo->prepare("
        SELECT days_per_year
        FROM leave_tenure_policy
        WHERE leave_type_id = :leave_type_id
          AND min_years <= :years
          AND (max_years IS NULL OR max_years >= :years)
        ORDER BY min_years DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':leave_type_id' => $leave_type_id,
        ':years' => $years
    ]);
    $days = $stmt->fetchColumn();

    // Fallback to default limit if no policy found
    if ($days === false) {
        $stmt = $pdo->prepare("SELECT default_limit FROM leave_types WHERE id = :leave_type_id");
        $stmt->execute([':leave_type_id' => $leave_type_id]);
        $days = $stmt->fetchColumn() ?? 0;
    }

    return (int)$days;
}
?>

