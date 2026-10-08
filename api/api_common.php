<?php
/**
 * ============================================================
 * ファイル名: api/api_common.php
 * システム名: 医師予定表管理システム（yotei）API
 * ============================================================
 *
 * 【概要】
 * 外部・他システム連携用 REST API 共通ライブラリ
 * - CORS対応（院内システム・別ドメインからのAjax通信許可）
 * - JSONレスポンス共通フォーマット
 * - エラーハンドリング
 */

// CORSヘッダー（院内システムからの連携を許可）
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Tab-ID");
}

// OPTIONSプリフライトリクエストの即時終了
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// データベース接続ライブラリ
require_once __DIR__ . '/../LIB/db.php';

/**
 * 成功レスポンス（JSON出力）
 * @param mixed $data
 * @param array $meta
 * @param int $httpCode
 */
function apiSuccess($data, $meta = [], $httpCode = 200) {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $response = [
        'status' => 'success',
        'code' => $httpCode,
        'timestamp' => date('c'),
        'count' => is_countable($data) ? count($data) : 1,
    ];

    if (!empty($meta)) {
        $response['meta'] = $meta;
    }

    $response['data'] = $data;

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * エラーレスポンス（JSON出力）
 * @param string $message
 * @param int $httpCode
 * @param array $details
 */
function apiError($message, $httpCode = 400, $details = []) {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $response = [
        'status' => 'error',
        'code' => $httpCode,
        'message' => $message,
        'timestamp' => date('c'),
    ];

    if (!empty($details)) {
        $response['details'] = $details;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * イベント種別の日本語ラベル
 */
function getApiEventLabel($type) {
    $labels = [
        'absence' => '休診・不在',
        'clinic' => '診察・外来',
        'meeting' => '会議・委員会',
        'business_trip' => '出張・学会',
        'other' => 'その他',
    ];
    return $labels[$type] ?? ($type ?: 'その他');
}

/**
 * イベント種別のアイコン絵文字
 */
function getApiEventIcon($type) {
    $icons = [
        'absence' => '🔴',
        'clinic' => '🟢',
        'meeting' => '🟡',
        'business_trip' => '✈️',
        'other' => '📌',
    ];
    return $icons[$type] ?? '📌';
}
