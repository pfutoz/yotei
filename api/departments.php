<?php
/**
 * ============================================================
 * ファイル名: api/departments.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * 部門マスタ一覧取得 API
 */

require_once __DIR__ . '/api_common.php';

try {
    $pdo = getDbConnection();

    $stmt = $pdo->query("
        SELECT id, name, display_name, color_code, sort_order
        FROM departments
        WHERE is_active = true
        ORDER BY sort_order ASC, id ASC
    ");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($dep) {
        return [
            'id' => (int)$dep['id'],
            'code' => $dep['name'],
            'name' => $dep['display_name'],
            'color' => $dep['color_code'],
            'sort_order' => (int)$dep['sort_order'],
        ];
    }, $departments);

    apiSuccess($formatted);

} catch (Exception $e) {
    apiError('部門マスタの取得に失敗しました: ' . $e->getMessage(), 500);
}
