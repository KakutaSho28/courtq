import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchBoothDetail, fetchCongestionHistory } from '../api/courtq';
import { CongestionBadge } from '../components/CongestionBadge';
import { CongestionChart } from '../components/CongestionChart';
import { FavoriteButton } from '../components/FavoriteButton';
import { Freshness } from '../components/Freshness';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { StockBadge } from '../components/StockBadge';
import { CATEGORY_LABELS, DEFAULT_STALE_AFTER_MINUTES } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useFavoriteToggle } from '../hooks/useFavoriteToggle';
import { useGameRealtime } from '../hooks/useGameRealtime';
import { useNow } from '../hooks/useNow';
import { isNewer } from '../lib/boothSort';
import { formatYen } from '../lib/time';
import type { MenuItem } from '../types';

function MenuSection({ title, items, staleAfter, now }: { title: string; items: MenuItem[]; staleAfter: number; now: number }) {
  if (items.length === 0) return null;
  const headingId = `menu-${title}`;

  return (
    <section aria-labelledby={headingId} className="menu-section">
      <h2 id={headingId} className="section-title">
        {title}
      </h2>
      <ul className="menu-list">
        {items.map((item) => (
          <li key={item.id} className={`menu-item${item.stock?.status === 'sold_out' ? ' is-sold-out' : ''}`}>
            <span className="menu-main">
              <span className="menu-name">{item.name}</span>
              <span className="menu-price">{formatYen(item.price)}</span>
            </span>
            {item.description && <span className="muted menu-desc">{item.description}</span>}
            <span className="menu-stock">
              <StockBadge stock={item.stock} />
              {item.stock && <Freshness updatedAt={item.stock.updated_at} staleAfterMinutes={staleAfter} now={now} />}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** S05 ブース詳細: 混雑・待ち時間・最終更新、メニュー（飲食 / 物販）と在庫、混雑の推移 */
export function BoothDetailPage() {
  const { gameId = '', boothId = '' } = useParams();
  const now = useNow();
  const detail = useApiResource(() => fetchBoothDetail(gameId, boothId), `booth:${gameId}:${boothId}`);
  const history = useApiResource(() => fetchCongestionHistory(gameId, boothId), `history:${gameId}:${boothId}`);
  const favorite = useFavoriteToggle();
  useDocumentTitle(detail.data?.data.name ?? 'ブース');

  // リアルタイム: この試合のこのブースの更新だけを反映する（他のブースのイベントは無視）。
  // 再接続したときと、つながっていない間は定期的に取り直す
  const [flash, setFlash] = useState(false);
  const boothNumber = Number(boothId);
  useGameRealtime(gameId, {
    onCongestion: (event) => {
      if (event.booth_id !== boothNumber) return;
      const point = { level: event.level, label: event.label, wait_minutes: event.wait_minutes, recorded_at: event.updated_at };
      detail.update((prev) =>
        isNewer(event.updated_at, prev.data.congestion.updated_at)
          ? { ...prev, data: { ...prev.data, congestion: { level: event.level, label: event.label, wait_minutes: event.wait_minutes, updated_at: event.updated_at, is_stale: false } } }
          : prev,
      );
      history.update((points) => (points.length > 0 && !isNewer(event.updated_at, points[points.length - 1].recorded_at) ? points : [...points, point]));
      setFlash(true);
      window.setTimeout(() => setFlash(false), 2500);
    },
    onStock: (event) => {
      if (event.booth_id !== boothNumber) return;
      detail.update((prev) => ({
        ...prev,
        data: {
          ...prev.data,
          menu_items: prev.data.menu_items.map((item) =>
            item.id === event.menu_item_id && isNewer(event.updated_at, item.stock?.updated_at ?? null)
              ? { ...item, stock: { status: event.status, label: event.label, quantity: event.quantity, updated_at: event.updated_at, is_stale: false } }
              : item,
          ),
        },
      }));
    },
    onResync: () => {
      detail.reload();
      history.reload();
    },
  });

  const back = (
    <p className="breadcrumb">
      <Link to={`/games/${gameId}`} className="link">
        ← ブース一覧
      </Link>
    </p>
  );

  if (detail.loading) return <>{back}<Loading what="ブース" /></>;
  if (!detail.data) return <>{back}{detail.error && <LoadError what="ブース" error={detail.error} onRetry={detail.reload} />}</>;

  const booth = detail.data.data;
  const staleAfter = detail.data.meta.stale_after_minutes ?? DEFAULT_STALE_AFTER_MINUTES;
  const isFavorite = favorite.isFavorite(booth.booth_id, booth.is_favorite);
  const food = booth.menu_items.filter((item) => item.kind === 'food');
  const merch = booth.menu_items.filter((item) => item.kind === 'merch');

  return (
    <>
      {back}
      {detail.error && <RefreshError error={detail.error} onRetry={detail.reload} />}

      <h1 className="page-title">{booth.name}</h1>
      <p className="muted page-sub">
        {booth.location_label}・{CATEGORY_LABELS[booth.category]}
      </p>
      {booth.description && <p>{booth.description}</p>}

      <section className={`status-panel${flash ? ' is-updated' : ''}`} aria-label="いまの混雑">
        <CongestionBadge level={booth.congestion.level} isOpen={booth.is_open} size="lg" />
        {booth.is_open && (
          <p className="status-wait">
            {booth.congestion.wait_minutes === null
              ? '待ち時間の情報はありません'
              : booth.congestion.wait_minutes === 0
                ? '待ち時間なし'
                : `待ち 約${booth.congestion.wait_minutes}分`}
          </p>
        )}
        <Freshness updatedAt={booth.congestion.updated_at} staleAfterMinutes={staleAfter} now={now} />
      </section>

      <FavoriteButton
        variant="full"
        boothName={booth.name}
        isFavorite={isFavorite}
        busy={favorite.busy[booth.booth_id] ?? false}
        onToggle={() => void favorite.toggle(booth.booth_id, isFavorite)}
      />
      {favorite.error && (
        <div className="notice notice-error" role="alert">
          {favorite.error}
        </div>
      )}

      {booth.menu_items.length === 0 ? (
        <p className="muted">メニューはまだ登録されていません。</p>
      ) : (
        <>
          <MenuSection title="飲食" items={food} staleAfter={staleAfter} now={now} />
          <MenuSection title="物販" items={merch} staleAfter={staleAfter} now={now} />
        </>
      )}

      <section aria-labelledby="history-heading" className="history-section">
        <h2 id="history-heading" className="section-title">
          混雑の推移
        </h2>
        {history.loading && <Loading what="混雑の推移" />}
        {!history.data && history.error && <LoadError what="混雑の推移" error={history.error} onRetry={history.reload} />}
        {history.data && <CongestionChart points={history.data} now={now} />}
      </section>
    </>
  );
}
