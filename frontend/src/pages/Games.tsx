import { fetchGames } from '../api/courtq';
import { GameCard } from '../components/GameCard';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useNow } from '../hooks/useNow';
import { isToday } from '../lib/time';

/** S03 試合一覧。今日の試合を先頭に大きく出す（来場者がまず開くのは今日の試合のため） */
export function GamesPage() {
  useDocumentTitle('試合を選ぶ');
  const now = useNow(60_000);
  const games = useApiResource(fetchGames, 'games');

  if (games.loading) return <Loading what="試合" />;
  if (!games.data) return games.error ? <LoadError what="試合" error={games.error} onRetry={games.reload} /> : null;

  const today = games.data.filter((g) => isToday(g.tipoff_at, now));
  const others = games.data.filter((g) => !isToday(g.tipoff_at, now));

  return (
    <>
      <h1 className="page-title">試合を選ぶ</h1>
      {games.error && <RefreshError error={games.error} onRetry={games.reload} />}

      {games.data.length === 0 && (
        <div className="empty">
          <p>予定されている試合はありません。</p>
        </div>
      )}

      {today.length > 0 && (
        <section aria-labelledby="today-heading">
          <h2 id="today-heading" className="section-title">本日の試合</h2>
          <ul className="card-list">
            {today.map((game) => (
              <GameCard key={game.id} game={game} highlight />
            ))}
          </ul>
        </section>
      )}

      {others.length > 0 && (
        <section aria-labelledby="upcoming-heading">
          <h2 id="upcoming-heading" className="section-title">{today.length > 0 ? 'これからの試合' : '試合の予定'}</h2>
          <ul className="card-list">
            {others.map((game) => (
              <GameCard key={game.id} game={game} />
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
