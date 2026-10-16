import { useCallback, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { fetchBooths, fetchGame, type BoothFilters, type BoothSort } from '../api/courtq';
import { BoothCard } from '../components/BoothCard';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { Segmented } from '../components/Segmented';
import { DEFAULT_STALE_AFTER_MINUTES } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useFavoriteToggle } from '../hooks/useFavoriteToggle';
import { useGameRealtime } from '../hooks/useGameRealtime';
import { useNow } from '../hooks/useNow';
import { isNewer, sortBooths } from '../lib/boothSort';
import { formatTipoff } from '../lib/time';

type Category = 'all' | 'food' | 'merch';

const SORTS: { value: BoothSort; label: string }[] = [
  { value: 'congestion', label: '空いている順' },
  { value: 'wait', label: '待ち時間順' },
  { value: 'name', label: '名前順' },
];

const CATEGORIES: { value: Category; label: string }[] = [
  { value: 'all', label: 'すべて' },
  { value: 'food', label: '飲食' },
  { value: 'merch', label: '物販' },
];

/** URL のクエリ（?sort=wait&category=food&in_stock=1&favorites=1）から絞り込み条件を読む。戻るボタン・共有で同じ表示になる */
function readFilters(params: URLSearchParams): BoothFilters & { categoryValue: Category } {
  const sort = SORTS.some((s) => s.value === params.get('sort')) ? (params.get('sort') as BoothSort) : 'congestion';
  const category = params.get('category') === 'food' || params.get('category') === 'merch' ? (params.get('category') as 'food' | 'merch') : undefined;

  return {
    sort,
    category,
    categoryValue: category ?? 'all',
    inStock: params.get('in_stock') === '1',
    favorites: params.get('favorites') === '1',
  };
}

/** S04 試合のブース一覧: 混雑（文言 + 色）・待ち時間・最終更新、絞り込み・並び替え・お気に入り */
export function BoothListPage() {
  const { gameId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const filters = readFilters(params);
  const now = useNow();

  const game = useApiResource(() => fetchGame(gameId), `game:${gameId}`);
  const booths = useApiResource(() => fetchBooths(gameId, filters), `booths:${gameId}:${params.toString()}`);
  useDocumentTitle(game.data ? `${game.data.home_team} vs ${game.data.away_team} のブース` : 'ブース一覧');

  const reloadBooths = booths.reload;

  // リアルタイム: 混雑の更新はその場で差し替えて並べ替える。在庫の更新は「在庫のあるメニューなし」の判定が変わりうるので、
  // 短い間にまとめて 1 回だけ取り直す。再接続したときと、つながっていない間は定期的に取り直す
  const [flashed, setFlashed] = useState<ReadonlySet<number>>(new Set());
  const [announcement, setAnnouncement] = useState('');
  const reloadSoon = useDebouncedCallback(reloadBooths, 1000);
  useGameRealtime(gameId, {
    onCongestion: (event) => {
      const target = booths.data?.data.find((b) => b.booth_id === event.booth_id);
      if (!target || !isNewer(event.updated_at, target.congestion.updated_at)) return;
      booths.update((prev) => ({
        ...prev,
        data: sortBooths(
          prev.data.map((b) =>
            b.booth_id === event.booth_id
              ? { ...b, congestion: { level: event.level, label: event.label, wait_minutes: event.wait_minutes, updated_at: event.updated_at, is_stale: false } }
              : b,
          ),
          filters.sort,
        ),
      }));
      setAnnouncement(`${target.name}の混雑が「${event.label}」に更新されました`);
      setFlashed((ids) => new Set(ids).add(event.booth_id));
      window.setTimeout(() => setFlashed((ids) => new Set([...ids].filter((id) => id !== event.booth_id))), 2500);
    },
    onStock: reloadSoon,
    onResync: reloadBooths,
  });
  const onFavoriteChanged = useCallback(() => {
    // 「お気に入りのみ」の表示中に外したら、一覧から消えるよう取り直す
    if (filters.favorites) reloadBooths();
  }, [filters.favorites, reloadBooths]);
  const favorite = useFavoriteToggle(onFavoriteChanged);

  const update = (key: string, value: string | null) => {
    const next = new URLSearchParams(params);
    if (value === null) next.delete(key);
    else next.set(key, value);
    setParams(next, { replace: true });
  };

  if (game.loading) return <Loading what="試合" />;
  if (!game.data) return game.error ? <LoadError what="試合" error={game.error} onRetry={game.reload} /> : null;

  const staleAfter = booths.data?.meta.stale_after_minutes ?? DEFAULT_STALE_AFTER_MINUTES;

  return (
    <>
      <p className="breadcrumb">
        <Link to="/" className="link">
          ← 試合を選ぶ
        </Link>
      </p>
      <h1 className="page-title">
        {game.data.home_team} <span className="vs">vs</span> {game.data.away_team}
      </h1>
      <p className="muted page-sub">
        {formatTipoff(game.data.tipoff_at)} 試合開始・{game.data.venue.name}
      </p>

      <div className="filters">
        <Segmented label="並び順" options={SORTS} value={filters.sort} onChange={(v) => update('sort', v === 'congestion' ? null : v)} />
        <Segmented label="カテゴリ" options={CATEGORIES} value={filters.categoryValue} onChange={(v) => update('category', v === 'all' ? null : v)} />
        <div className="toggles" role="group" aria-label="絞り込み">
          <button type="button" className="toggle-btn" aria-pressed={filters.inStock} onClick={() => update('in_stock', filters.inStock ? null : '1')}>
            在庫ありのみ
          </button>
          <button type="button" className="toggle-btn" aria-pressed={filters.favorites} onClick={() => update('favorites', filters.favorites ? null : '1')}>
            <span aria-hidden="true">★ </span>お気に入りのみ
          </button>
        </div>
      </div>

      <div className="list-toolbar">
        <span className="muted" aria-live="polite">
          {booths.data ? `${booths.data.data.length} 件` : ''}
          {booths.refreshing ? '（更新中...）' : ''}
        </span>
        <button type="button" className="btn btn-ghost btn-sm" onClick={booths.reload} disabled={booths.loading || booths.refreshing}>
          最新にする
        </button>
      </div>

      <p className="sr-only" role="status" aria-live="polite">
        {announcement}
      </p>
      {favorite.error && (
        <div className="notice notice-error" role="alert">
          {favorite.error}
        </div>
      )}
      {booths.data && booths.error && <RefreshError error={booths.error} onRetry={booths.reload} />}

      {booths.loading && <Loading what="ブース" />}
      {!booths.data && booths.error && <LoadError what="ブース" error={booths.error} onRetry={booths.reload} />}

      {booths.data && booths.data.data.length === 0 && (
        <div className="empty">
          <p>{filters.favorites ? 'お気に入りのブースはまだありません。☆ を押すと登録できます。' : '条件に合うブースはありません。'}</p>
        </div>
      )}

      {booths.data && booths.data.data.length > 0 && (
        <ul className="card-list" aria-label="ブースの一覧">
          {booths.data.data.map((booth) => {
            const fav = favorite.isFavorite(booth.booth_id, booth.is_favorite);
            return (
              <BoothCard
                key={booth.booth_id}
                gameId={gameId}
                booth={booth}
                isFavorite={fav}
                flash={flashed.has(booth.booth_id)}
                favoriteBusy={favorite.busy[booth.booth_id] ?? false}
                onToggleFavorite={() => void favorite.toggle(booth.booth_id, fav)}
                staleAfterMinutes={staleAfter}
                now={now}
              />
            );
          })}
        </ul>
      )}
    </>
  );
}
