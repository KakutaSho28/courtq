import type { Stock } from '../types';

/** 在庫のバッジ。在庫あり / 残りわずか / 売り切れ（売り切れはグレー）。まだ登録されていなければ「在庫情報なし」 */
export function StockBadge({ stock }: { stock: Stock | null }) {
  if (stock === null) {
    return <span className="stock-badge stock-none">在庫情報なし</span>;
  }

  return (
    <span className={`stock-badge stock-${stock.status}`}>
      {stock.label}
      {stock.quantity !== null && stock.status !== 'sold_out' && <span className="stock-qty">（残り{stock.quantity}）</span>}
    </span>
  );
}
