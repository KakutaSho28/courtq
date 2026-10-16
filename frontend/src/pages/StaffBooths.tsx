import { Link } from 'react-router-dom';
import { fetchStaffBooths } from '../api/courtq';
import { CongestionBadge } from '../components/CongestionBadge';
import { Freshness } from '../components/Freshness';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { CATEGORY_LABELS, DEFAULT_STALE_AFTER_MINUTES } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useNow } from '../hooks/useNow';
import { formatTipoff, isToday } from '../lib/time';
import { useAuthStore } from '../store/auth';

/** S07 スタッフ: 担当ブース。今日の試合があれば、そのブースの更新画面をすぐ開ける（admin は全ブース） */
export function StaffBoothsPage() {
  useDocumentTitle('担当ブース');
  const now = useNow();
  const role = useAuthStore((s) => s.user?.role);
  const booths = useApiResource(fetchStaffBooths, 'staff-booths');

  if (booths.loading) return <Loading what="担当ブース" />;
  if (!booths.data) return booths.error ? <LoadError what="担当ブース" error={booths.error} onRetry={booths.reload} /> : null;

  return (
    <>
      <h1 className="page-title">担当ブース</h1>
      <p className="muted page-sub">{role === 'admin' ? '管理者は、すべてのブースを更新できます。' : '混雑と在庫を更新するブースを選びます。'}</p>
      {booths.error && <RefreshError error={booths.error} onRetry={booths.reload} />}

      {booths.data.length === 0 && (
        <div className="empty">
          <p>担当しているブースがありません。</p>
          <p className="muted">管理者から受け取った招待コードを入力すると、そのブースの担当になります。</p>
        </div>
      )}

      <ul className="card-list">
        {booths.data.map((booth) => (
          <li key={booth.booth_id} className="staff-booth">
            <div className="staff-booth-head">
              <h2 className="booth-name">{booth.name}</h2>
              <span className="muted">
                {booth.location_label}・{CATEGORY_LABELS[booth.category]}
              </span>
            </div>

            {booth.upcoming_games.length === 0 && <p className="muted">出店予定の試合はありません。</p>}
            {booth.upcoming_games.map((game) => {
              const today = isToday(game.tipoff_at, now);
              return (
                <div key={game.game_id} className={`staff-game${today ? ' is-today' : ''}`}>
                  <div className="staff-game-info">
                    <span className="staff-game-title">
                      {today && <span className="game-status game-status-live">本日</span>} {game.home_team} <span className="vs">vs</span> {game.away_team}
                    </span>
                    <span className="muted">{formatTipoff(game.tipoff_at)} 試合開始</span>
                    <span className="staff-game-status">
                      <CongestionBadge level={game.congestion.level} isOpen={game.is_open} />
                      <Freshness updatedAt={game.congestion.updated_at} staleAfterMinutes={DEFAULT_STALE_AFTER_MINUTES} now={now} />
                    </span>
                  </div>
                  <Link
                    to={`/staff/games/${game.game_id}/booths/${booth.booth_id}`}
                    className={`btn ${today ? 'btn-primary' : 'btn-ghost'} btn-block big-btn`}
                    aria-label={`${game.home_team} vs ${game.away_team}（${formatTipoff(game.tipoff_at)}）の混雑・在庫を更新する`}
                  >
                    混雑・在庫を更新する
                  </Link>
                </div>
              );
            })}

            <Link to={`/staff/booths/${booth.booth_id}/menu`} className="btn btn-ghost btn-block">
              メニューを管理する
            </Link>
          </li>
        ))}
      </ul>

      <p className="staff-invite-link">
        別のブースの担当になる場合は{' '}
        <Link to="/invite" className="link">
          招待コードを入力する
        </Link>
      </p>
    </>
  );
}
