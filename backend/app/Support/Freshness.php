<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 混雑・在庫の「情報が古い可能性があります」の判定。しきい値は config('courtq.stale_after_minutes') の 1 か所だけ。
 */
class Freshness
{
    public static function thresholdMinutes(): int
    {
        return (int) config('courtq.stale_after_minutes');
    }

    /** 最終更新からしきい値を超えていれば true。まだ一度も更新されていない（null）なら、古くなる対象が無いので false */
    public static function isStale(?CarbonInterface $updatedAt): bool
    {
        return $updatedAt !== null && $updatedAt->lt(now()->subMinutes(self::thresholdMinutes()));
    }

    /**
     * レスポンスの meta。オフライン（PWA のキャッシュ）で画面側が鮮度を計算し直せるよう、しきい値も返す。
     *
     * @return array{meta: array{stale_after_minutes: int}}
     */
    public static function meta(): array
    {
        return ['meta' => ['stale_after_minutes' => self::thresholdMinutes()]];
    }
}
