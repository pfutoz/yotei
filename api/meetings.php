<?php
/**
 * ============================================================
 * ファイル名: api/meetings.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * 小野会全体会日程取得 API
 *
 * 【GETパラメータ】
 * - year  : 指定年 (4桁数値、デフォルトは当年)
 * - month : 指定月 (1-12、省略時は年間)
 */

require_once __DIR__ . '/api_common.php';

try {
    $pdo = getDbConnection();

    $year = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = !empty($_GET['month']) ? (int)$_GET['month'] : null;

    $sql = "
        SELECT meeting_date, title, start_time, location, note
        FROM all_meetings
        WHERE is_cancelled = false
    ";
    $params = [];

    if ($month !== null && $month >= 1 && $month <= 12) {
        $startDate = sprintf("%04d-%02d-01", $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        $sql .= " AND meeting_date >= :start AND meeting_date <= :end";
        $params['start'] = $startDate;
        $params['end'] = $endDate;
    } else {
        $sql .= " AND meeting_date >= :start AND meeting_date <= :end";
        $params['start'] = sprintf("%04d-01-01", $year);
        $params['end'] = sprintf("%04d-12-31", $year);
    }

    $sql .= " ORDER BY meeting_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dowMap = ['日', '月', '火', '水', '木', '金', '土'];
    $formatted = array_map(function($m) use ($dowMap) {
        $dow = (int)date('w', strtotime($m['meeting_date']));
        $w = $dowMap[$dow];
        return [
            'date' => $m['meeting_date'],
            'weekday' => $w,
            'date_display' => date('Y年n月j日', strtotime($m['meeting_date'])) . "（{$w}）",
            'time' => substr($m['start_time'], 0, 5) . '〜',
            'title' => $m['title'],
            'location' => $m['location'],
            'note' => $m['note'] ?? '',
            'is_future' => ($m['meeting_date'] >= date('Y-m-d')),
        ];
    }, $meetings);

    apiSuccess($formatted, ['year' => $year, 'month' => $month]);

} catch (Exception $e) {
    apiError('全体会日程の取得に失敗しました: ' . $e->getMessage(), 500);
}
