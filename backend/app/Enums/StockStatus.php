<?php

namespace App\Enums;

/** 在庫の 3 状態 */
enum StockStatus: string
{
    case InStock = 'in_stock';
    case Low = 'low';
    case SoldOut = 'sold_out';

    public function label(): string
    {
        return match ($this) {
            self::InStock => '在庫あり',
            self::Low => '残りわずか',
            self::SoldOut => '売り切れ',
        };
    }
}
