import { isStale, relativeTime } from '../lib/time';

type Props = {
  updatedAt: string | null;
  staleAfterMinutes: number;
  now: number;
  /** 一度も更新されていないときの文言 */
  emptyText?: string;
};

/**
 * 「最終更新 ○分前」と、しきい値を超えたら「情報が古い可能性があります」。
 * サーバーの is_stale ではなく、時刻から画面で計算する（オフラインで古いキャッシュを見ているときも正しく出すため）。
 */
export function Freshness({ updatedAt, staleAfterMinutes, now, emptyText = 'まだ更新されていません' }: Props) {
  if (updatedAt === null) {
    return <span className="freshness muted">{emptyText}</span>;
  }

  const stale = isStale(updatedAt, staleAfterMinutes, now);

  return (
    <span className="freshness">
      <span className="muted">
        最終更新 <time dateTime={updatedAt}>{relativeTime(updatedAt, now)}</time>
      </span>
      {stale && (
        <span className="stale-hint">
          <span aria-hidden="true">⚠ </span>情報が古い可能性があります
        </span>
      )}
    </span>
  );
}
