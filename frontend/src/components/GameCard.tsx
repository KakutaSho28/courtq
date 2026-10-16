import { Link } from 'react-router-dom';
import { formatTipoff } from '../lib/time';
import type { Game } from '../types';

const STATUS_LABELS = { scheduled: '試合前', live: '開催中', finished: '終了' } as const;

/** 試合の 1 件。カード全体がそのブース一覧へのリンク */
export function GameCard({ game, highlight = false }: { game: Game; highlight?: boolean }) {
  return (
    <li>
      <Link to={`/games/${game.id}`} className={`game-card${highlight ? ' is-today' : ''}`}>
        <span className="game-card-top">
          <span className="muted">
            {game.season} シーズン{game.section !== null ? ` 第${game.section}節` : ''}
          </span>
          <span className={`game-status game-status-${game.status}`}>{STATUS_LABELS[game.status]}</span>
        </span>
        <span className="game-teams">
          {game.home_team} <span className="vs">vs</span> {game.away_team}
        </span>
        <span className="game-when">
          <time dateTime={game.tipoff_at}>{formatTipoff(game.tipoff_at)}</time> 試合開始・{game.venue.name}
        </span>
        <span className="game-cta" aria-hidden="true">
          ブースの混雑を見る →
        </span>
      </Link>
    </li>
  );
}
