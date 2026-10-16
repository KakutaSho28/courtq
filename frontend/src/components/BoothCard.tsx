import { Link } from 'react-router-dom';
import { CATEGORY_LABELS } from '../constants';
import type { BoothListItem } from '../types';
import { CongestionBadge } from './CongestionBadge';
import { FavoriteButton } from './FavoriteButton';
import { Freshness } from './Freshness';

type Props = {
  gameId: number | string;
  booth: BoothListItem;
  isFavorite: boolean;
  favoriteBusy: boolean;
  /** リアルタイムで更新されたばかり（短い間、枠を強調して気づけるようにする） */
  flash?: boolean;
  onToggleFavorite: () => void;
  staleAfterMinutes: number;
  now: number;
};

/**
 * ブース一覧の 1 件。カード全体（お気に入りボタン以外）が詳細へのリンクで、タップしやすい。
 * 混雑は色 + メーター + 文言、待ち時間、最終更新を並べる。
 */
export function BoothCard({ gameId, booth, isFavorite, favoriteBusy, flash = false, onToggleFavorite, staleAfterMinutes, now }: Props) {
  const { congestion } = booth;

  return (
    <li className={`booth-card${booth.is_open ? '' : ' is-closed'}${flash ? ' is-updated' : ''}`}>
      <Link to={`/games/${gameId}/booths/${booth.booth_id}`} className="booth-card-link">
        <span className="booth-card-head">
          <span className="booth-name">{booth.name}</span>
          <CongestionBadge level={congestion.level} isOpen={booth.is_open} />
        </span>
        <span className="booth-meta muted">
          {booth.location_label}・{CATEGORY_LABELS[booth.category]}
          {!booth.has_stock && booth.is_open && <span className="tag">在庫のあるメニューなし</span>}
        </span>
        {booth.is_open && (
          <span className="booth-status">
            <span className="wait">
              {congestion.wait_minutes === null ? '待ち時間 —' : congestion.wait_minutes === 0 ? '待ち時間なし' : `待ち 約${congestion.wait_minutes}分`}
            </span>
            <Freshness updatedAt={congestion.updated_at} staleAfterMinutes={staleAfterMinutes} now={now} />
          </span>
        )}
      </Link>
      <FavoriteButton boothName={booth.name} isFavorite={isFavorite} busy={favoriteBusy} onToggle={onToggleFavorite} />
    </li>
  );
}
