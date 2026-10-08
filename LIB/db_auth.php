<?php
/**
 * ============================================================
 * ファイル名: db_auth.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v1.0
 * 生成日時: 2026-08-24 12:15
 * 最終更新: 2026-08-24 12:15
 * ============================================================
 *
 * 【概要】
 * auth_db 専用データベース接続設定
 * 接続情報を一元管理し、各ファイルから呼び出す
 *
 * 【設計意図】
 * - auth_db の接続情報をハードコードせず、共通化する
 * - 本番環境への切り替え時はこのファイルのみ変更すればよい
 * - 静的変数による接続の再利用（パフォーマンス向上）
 *
 * 【変更履歴】
 * 2026-08-24 12:15 v1.0 新規作成（事務長）
 * ============================================================
 */

define('AUTH_DB_HOST', 'localhost');
define('AUTH_DB_PORT', '5432');
define('AUTH_DB_NAME', 'auth_db');
define('AUTH_DB_USER', 'postgres');
define('AUTH_DB_PASS', 'postgres');

/**
 * auth_db へのPDO接続を取得
 *
 * @return PDO
 * @throws PDOException
 */
function getAuthDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            AUTH_DB_HOST,
            AUTH_DB_PORT,
            AUTH_DB_NAME
        );
        $pdo = new PDO($dsn, AUTH_DB_USER, AUTH_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET search_path TO auth, public');
    }
    return $pdo;
}