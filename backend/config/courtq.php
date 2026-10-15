<?php

/*
| CourtQ の業務ルールで使う定数。env() はここでだけ読む（config:cache 後にアプリコードから env() は使えない）。
*/
return [

    // 混雑・在庫の最終更新からこの分数を超えたら「情報が古い可能性があります」を出す（フロントにも API で渡す）
    'stale_after_minutes' => (int) env('COURTQ_STALE_AFTER_MINUTES', 15),

    // 待ち時間（分）として受け付ける範囲
    'wait_minutes_max' => 180,

    // 一覧 API のサーバーキャッシュ秒数（10〜30 秒。更新時に破棄される）
    'list_cache_seconds' => (int) env('COURTQ_LIST_CACHE_SECONDS', 20),

    // スタッフ招待コードの有効日数と長さ
    'invitation_ttl_days' => (int) env('COURTQ_INVITATION_TTL_DAYS', 7),
    'invitation_code_length' => 10,

];
