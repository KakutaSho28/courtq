import { useState } from 'react';
import { updateStock } from '../api/courtq';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatYen } from '../lib/time';
import type { MenuItem, Stock, StockStatus } from '../types';
import { Freshness } from './Freshness';
import { StockBadge } from './StockBadge';
import { WriteError } from './WriteError';

const STATUSES: { value: StockStatus; label: string }[] = [
  { value: 'in_stock', label: '在庫あり' },
  { value: 'low', label: '残りわずか' },
  { value: 'sold_out', label: '売り切れ' },
];

type Props = {
  gameId: number | string;
  item: MenuItem;
  staleAfterMinutes: number;
  now: number;
  /** 画面全体が更新できない状態（オフライン）のとき */
  offline: boolean;
};

/**
 * メニュー 1 件の在庫の切り替え。状態のボタンを 1 回押すだけで送る（1 タップ）。
 * 押した瞬間に表示を変え、失敗したら元に戻して再試行できるようにする。
 * 数量は任意（空なら送らない）。
 */
export function StockRow({ gameId, item, staleAfterMinutes, now, offline }: Props) {
  const [stock, setStock] = useState<Stock | null>(item.stock);
  const [quantity, setQuantity] = useState(item.stock?.quantity?.toString() ?? '');
  /** 失敗した操作（再試行用） */
  const [failed, setFailed] = useState<StockStatus | null>(null);
  const write = useWriteAction((status: StockStatus, qty: number | null) => updateStock(gameId, item.id, status, qty));

  const parsed = quantity.trim() === '' ? null : Number(quantity);
  const quantityInvalid = parsed !== null && (!Number.isInteger(parsed) || parsed < 0 || parsed > 99999);

  const choose = async (status: StockStatus) => {
    if (quantityInvalid) return;
    const before = stock;
    setFailed(null);
    // 押した瞬間に表示を変える（会場の回線が遅くても反応が分かるように）。失敗したら元に戻す
    setStock({ status, label: STATUSES.find((s) => s.value === status)?.label ?? '', quantity: parsed, updated_at: before?.updated_at ?? new Date(now).toISOString(), is_stale: false });
    const result = await write.run(status, parsed);
    if (result) {
      setStock(result.stock);
      setQuantity(result.stock.quantity?.toString() ?? '');
    } else {
      setStock(before);
      setFailed(status);
    }
  };

  const current = stock?.status ?? null;

  return (
    <li className={`stock-row${current === 'sold_out' ? ' is-sold-out' : ''}`}>
      <div className="stock-row-head">
        <span className="menu-name">{item.name}</span>
        <span className="menu-price">{formatYen(item.price)}</span>
      </div>
      <div className="menu-stock">
        <StockBadge stock={stock} />
        {write.pending ? (
          <span className="muted" role="status">送信中...</span>
        ) : (
          stock && <Freshness updatedAt={stock.updated_at} staleAfterMinutes={staleAfterMinutes} now={now} />
        )}
      </div>
      <div className="stock-buttons" role="group" aria-label={`${item.name}の在庫`}>
        {STATUSES.map((s) => (
          <button
            key={s.value}
            type="button"
            className={`stock-btn stock-btn-${s.value}`}
            aria-pressed={current === s.value}
            disabled={write.pending || offline || quantityInvalid}
            onClick={() => void choose(s.value)}
          >
            {s.label}
          </button>
        ))}
      </div>
      <div className="field stock-qty-field">
        <label htmlFor={`qty-${item.id}`}>数量（任意。押すボタンと一緒に送られます）</label>
        <input id={`qty-${item.id}`} type="number" inputMode="numeric" min={0} max={99999} value={quantity} onChange={(e) => setQuantity(e.target.value)} />
        {quantityInvalid && <span className="error-msg">数量は 0 以上の整数で入力してください</span>}
      </div>
      {write.error && <WriteError error={write.error} onRetry={failed ? () => void choose(failed) : undefined} onDismiss={write.clearError} />}
    </li>
  );
}
