<?php
/**
 * ============================================================
 * ファイル名: api/events.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * 医師予定データ取得・検索 REST API
 * 電子カルテ、かわら版、外部グループウェア連携用
 *
 * 【GETパラメータ】
 * - start_date        : 開始日 (YYYY-MM-DD、デフォルト: 当月初日)
 * - end_date          : 終了日 (YYYY-MM-DD、デフォルト: 翌月末日)
 * - date              : 単一日指定 (YYYY-MM-DD または today)
 * - doctor_id         : 医師ID (数値)
 * - department_id     : 部門ID (数値)
 * - event_type        : 予定種別 (absence, clinic, meeting, business_trip)
 * - include_cancelled : 取消予定を含むか (1 または 0、デフォルト 0)
 * - include_draft     : 非公開予定を含むか (1 または 0、デフォルト 0)
 * - expand_period     : 期間予定を日別に分割展開するか (1 または 0、デフォルト 0)
 */

require_once __DIR__ . '/api_common.php';

try {
    $pdo = getDbConnection();

    // 日付パラメータ処理
    if (!empty($_GET['date'])) {
        $d = $_GET['date'] === 'today' ? date('Y-m-d') : $_GET['date'];
        $startDate = date('Y-m-d', strtotime($d));
        $endDate = $startDate;
    } else {
        $startDate = !empty($_GET['start_date']) ? date('Y-m-d', strtotime($_GET['start_date'])) : date('Y-m-01');
        $endDate = !empty($_GET['end_date']) ? date('Y-m-d', strtotime($_GET['end_date'])) : date('Y-m-t', strtotime('+1 month'));
    }

    $doctorId = !empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;
    $deptId = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
    $eventType = !empty($_GET['event_type']) ? trim($_GET['event_type']) : null;
    $includeCancelled = !empty($_GET['include_cancelled']);
    $includeDraft = !empty($_GET['include_draft']);
    $expandPeriod = !empty($_GET['expand_period']);

    // SQL組み立て
    $sql = "
        SELECT 
            e.id,
            e.title,
            e.note,
            e.start_date,
            e.end_date,
            e.start_time,
            e.end_time,
            CASE WHEN e.start_time IS NULL THEN true ELSE false END AS is_all_day,
            e.event_type,
            e.is_public,
            e.is_cancelled,
            e.cancelled_at,
            e.cancel_reason,
            e.created_at,
            e.updated_at,
            d.id AS doctor_id,
            d.short_name AS doctor_short_name,
            d.last_name || d.first_name AS doctor_full_name,
            d.title AS doctor_title,
            dep.id AS department_id,
            dep.display_name AS department_name,
            dep.color_code AS department_color
        FROM events e
        LEFT JOIN doctors d ON e.doctor_id = d.id
        LEFT JOIN departments dep ON COALESCE(e.department_id, d.department_id) = dep.id
        WHERE e.start_date <= :end_date
          AND COALESCE(e.end_date, e.start_date) >= :start_date
    ";
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];

    if ($doctorId) {
        $sql .= " AND e.doctor_id = :doctor_id";
        $params['doctor_id'] = $doctorId;
    }
    if ($deptId) {
        $sql .= " AND (COALESCE(e.department_id, d.department_id) = :dept_id OR dep.id = :dept_id)";
        $params['dept_id'] = $deptId;
    }
    if ($eventType) {
        $sql .= " AND e.event_type = :event_type";
        $params['event_type'] = $eventType;
    }
    if (!$includeCancelled) {
        $sql .= " AND e.is_cancelled = false";
    }
    if (!$includeDraft) {
        $sql .= " AND e.is_public = true";
    }

    $sql .= " ORDER BY e.start_date ASC, e.start_time ASC NULLS LAST, e.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($rawEvents as $ev) {
        $docShort = $ev['doctor_short_name'] ?? '担当';
        $docFull = $ev['doctor_full_name'] ?? $docShort;
        $isMulti = ($ev['start_date'] !== $ev['end_date'] && !empty($ev['end_date']));

        $sTime = $ev['start_time'] ? substr($ev['start_time'], 0, 5) : null;
        $eTime = $ev['end_time'] ? substr($ev['end_time'], 0, 5) : null;

        $baseItem = [
            'id' => (int)$ev['id'],
            'doctor' => [
                'id' => $ev['doctor_id'] ? (int)$ev['doctor_id'] : null,
                'name' => $docFull,
                'short_name' => $docShort,
                'title' => $ev['doctor_title'] ?? '',
                'department_id' => $ev['department_id'] ? (int)$ev['department_id'] : null,
                'department_name' => $ev['department_name'] ?? '',
                'department_color' => $ev['department_color'] ?? '#64748b',
            ],
            'title' => $ev['title'],
            'note' => $ev['note'] ?? '',
            'event_type' => $ev['event_type'],
            'event_type_label' => getApiEventLabel($ev['event_type']),
            'event_icon' => getApiEventIcon($ev['event_type']),
            'is_all_day' => (bool)$ev['is_all_day'],
            'start_date' => $ev['start_date'],
            'end_date' => $ev['end_date'] ?: $ev['start_date'],
            'start_time' => $sTime,
            'end_time' => $eTime,
            'is_multi_day' => $isMulti,
            'is_public' => (bool)$ev['is_public'],
            'is_cancelled' => (bool)$ev['is_cancelled'],
            'cancel_reason' => $ev['cancel_reason'] ?? null,
            'created_at' => $ev['created_at'],
            'updated_at' => $ev['updated_at'],
        ];

        if ($expandPeriod && $isMulti) {
            // 期間予定を日別に分割展開
            $cur = strtotime(max($ev['start_date'], $startDate));
            $last = strtotime(min($ev['end_date'], $endDate));
            $dayIdx = 1;
            $totalDays = (int)round((strtotime($ev['end_date']) - strtotime($ev['start_date'])) / 86400) + 1;

            while ($cur <= $last) {
                $curDate = date('Y-m-d', $cur);
                $expandedItem = $baseItem;
                $expandedItem['date'] = $curDate;
                $expandedItem['period_day_index'] = $dayIdx;
                $expandedItem['period_total_days'] = $totalDays;
                $results[] = $expandedItem;
                $cur = strtotime('+1 day', $cur);
                $dayIdx++;
            }
        } else {
            $results[] = $baseItem;
        }
    }

    $meta = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'filters' => [
            'doctor_id' => $doctorId,
            'department_id' => $deptId,
            'event_type' => $eventType,
            'include_cancelled' => $includeCancelled,
            'expand_period' => $expandPeriod,
        ]
    ];

    apiSuccess($results, $meta);

} catch (Exception $e) {
    apiError('予定データの取得に失敗しました: ' . $e->getMessage(), 500);
}
