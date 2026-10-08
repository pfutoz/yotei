<?php
/**
 * ============================================================
 * ファイル名: api/doctors.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * 医師マスタ一覧取得 API
 * 他システムでの医師選択セレクタ、マスタ同期用
 */

require_once __DIR__ . '/api_common.php';

try {
    $pdo = getDbConnection();

    $stmt = $pdo->query("
        SELECT 
            d.id,
            d.last_name,
            d.first_name,
            d.last_name || d.first_name AS full_name,
            d.short_name,
            d.title,
            d.department_id,
            dep.name AS department_code,
            dep.display_name AS department_name,
            dep.color_code AS department_color,
            d.sort_order,
            d.is_active
        FROM doctors d
        LEFT JOIN departments dep ON d.department_id = dep.id
        WHERE d.is_active = true
        ORDER BY d.sort_order ASC, d.id ASC
    ");
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($d) {
        return [
            'id' => (int)$d['id'],
            'name' => $d['full_name'],
            'short_name' => $d['short_name'],
            'title' => $d['title'] ?? '',
            'department_id' => $d['department_id'] ? (int)$d['department_id'] : null,
            'department_name' => $d['department_name'] ?? '',
            'department_color' => $d['department_color'] ?? '#64748b',
            'sort_order' => (int)$d['sort_order'],
        ];
    }, $doctors);

    apiSuccess($formatted);

} catch (Exception $e) {
    apiError('医師マスタの取得に失敗しました: ' . $e->getMessage(), 500);
}
