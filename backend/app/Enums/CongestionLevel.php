<?php

namespace App\Enums;

/** 混雑の 4 段階（待ち時間は別に wait_minutes で持つ） */
enum CongestionLevel: int
{
    case Empty = 1;
    case Slightly = 2;
    case Crowded = 3;
    case VeryCrowded = 4;

    public function label(): string
    {
        return match ($this) {
            self::Empty => '空いている',
            self::Slightly => 'やや混雑',
            self::Crowded => '混雑',
            self::VeryCrowded => '非常に混雑',
        };
    }

    /** @return array<int, int> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
