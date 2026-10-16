import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import { fetchBooths, fetchFavorites, fetchGames } from '../api/courtq';
import { BoothCard } from '../components/BoothCard';
import { FavoriteButton } from '../components/FavoriteButton';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { CATEGORY_LABELS, DEFAULT_STALE_AFTER_MINUTES } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useFavoriteToggle } from '../hooks/useFavoriteToggle';
import { useNow } from '../hooks/useNow';
import { isToday } from '../lib/time';

/** お気に入りと、今日の試合でのその混雑（今日の試合が無ければ一覧だけ） */
async function loadFavorites() {
  const [favorites, games] = await Promise.all([fetchFavorites(), fetchGames()]);
  const todayGame = games.find((g) => isToday(g.tipoff_at)) ?? null;
  const todayBooths = todayGame && favorites.length > 0 ? await fetchBooths(String(todayGame.id), { sort: 'congestion', favorites: true }) : null;

  return { favorites, todayGame, todayBooths };
}

/** S06 お気に入り */
export function FavoritesPage() {
  useDocumentTitle('お気に入り');
  const now = useNow();
  const resource = useApiResource(loadFavorites, 'favorites');
  const reload = resource.reload;
  const onChanged = useCallback(() => reload(), [reload]);
  const favorite = useFavoriteToggle(onChanged);

  if (resource.loading) return <Loading what="お気に入り" />;
  if (!resource.data) return resource.error ? <LoadError what="お気に入り" error={resource.error} onRetry={resource.reload} /> : null;

  const { favorites, todayGame, todayBooths } = resource.data;
  const inToday = new Set(todayBooths?.data.map((b) => b.booth_id) ?? []);
  const notInToday = favorites.filter((f) => !inToday.has(f.booth_id));
  const staleAfter = todayBooths?.meta.stale_after_minutes ?? DEFAULT_STALE_AFTER_MINUTES;

  return (
    <>
      <h1 className="page-title">お気に入り</h1>
      {resource.error && <RefreshError error={resource.error} onRetry={resource.reload} />}
      {favorite.error && (
        <div className="notice notice-error" role="alert">
          {favorite.error}
        </div>
      )}

      {favorites.length === 0 && (
        <div className="empty">
          <p>お気に入りのブースはまだありません。</p>
          <p className="muted">ブース一覧やブースの画面で ☆ を押すと、ここにまとめて表示されます。</p>
          <Link to="/" className="btn btn-primary">
            試合を選ぶ
          </Link>
        </div>
      )}

      {todayGame && todayBooths && todayBooths.data.length > 0 && (
        <section aria-labelledby="fav-today">
          <h2 id="fav-today" className="section-title">
            本日の試合（{todayGame.home_team} vs {todayGame.away_team}）
          </h2>
          <ul className="card-list">
            {todayBooths.data.map((booth) => {
              const fav = favorite.isFavorite(booth.booth_id, true);
              return (
                <BoothCard
                  key={booth.booth_id}
                  gameId={todayGame.id}
                  booth={booth}
                  isFavorite={fav}
                  favoriteBusy={favorite.busy[booth.booth_id] ?? false}
                  onToggleFavorite={() => void favorite.toggle(booth.booth_id, fav)}
                  staleAfterMinutes={staleAfter}
                  now={now}
                />
              );
            })}
          </ul>
        </section>
      )}

      {notInToday.length > 0 && (
        <section aria-labelledby="fav-others">
          <h2 id="fav-others" className="section-title">
            {todayGame ? '本日は出店していないお気に入り' : 'お気に入りのブース'}
          </h2>
          {!todayGame && <p className="muted">本日の試合はありません。試合の日に、ここで混雑が見られます。</p>}
          <ul className="card-list">
            {notInToday.map((booth) => {
              const fav = favorite.isFavorite(booth.booth_id, true);
              return (
                <li key={booth.booth_id} className="booth-card is-plain">
                  <span className="booth-card-link">
                    <span className="booth-name">{booth.name}</span>
                    <span className="booth-meta muted">
                      {booth.location_label}・{CATEGORY_LABELS[booth.category]}
                    </span>
                  </span>
                  <FavoriteButton
                    boothName={booth.name}
                    isFavorite={fav}
                    busy={favorite.busy[booth.booth_id] ?? false}
                    onToggle={() => void favorite.toggle(booth.booth_id, fav)}
                  />
                </li>
              );
            })}
          </ul>
        </section>
      )}
    </>
  );
}
