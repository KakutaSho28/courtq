import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchBoothDetail, updateCongestion } from '../api/courtq';
import { CongestionBadge } from '../components/CongestionBadge';
import { Freshness } from '../components/Freshness';
import { LevelPicker } from '../components/LevelPicker';
import { LoadError, Loading } from '../components/LoadState';
import { StockRow } from '../components/StockRow';
import { WaitStepper } from '../components/WaitStepper';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { CONGESTION_LABELS, DEFAULT_STALE_AFTER_MINUTES } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useNow } from '../hooks/useNow';
import { useWriteAction } from '../hooks/useWriteAction';
import type { Congestion, CongestionLevel } from '../types';

/**
 * S08 スタッフ: 混雑と在庫のクイック更新。
 * - 混雑: 4 つの大きなボタンで選び、「更新する」を押す（2 タップ）。待ち時間は現在の値が入っていて、変えるときだけ ± を押す
 * - 在庫: メニューごとの 3 つのボタンを 1 回押すだけ（1 タップ）
 * - 更新したら、サーバーが返した最終更新の時刻を表示する。失敗したら何も変わっていないことを伝えて、再試行できる
 */
export function StaffUpdatePage() {
  const { gameId = '', boothId = '' } = useParams();
  const now = useNow(15_000);
  const detail = useApiResource(() => fetchBoothDetail(gameId, boothId), `staff-update:${gameId}:${boothId}`);
  useDocumentTitle(detail.data ? `${detail.data.data.name}を更新` : '更新');

  /** サーバーが確定した混雑（更新が成功するたびに入れ替える。失敗したら変えない） */
  const [current, setCurrent] = useState<Congestion | null>(null);
  const [level, setLevel] = useState<CongestionLevel | null>(null);
  const [wait, setWait] = useState<number | null>(null);
  /** 直近の送信が成功したか（失敗・再送中は消す） */
  const [saved, setSaved] = useState(false);
  const write = useWriteAction((lv: CongestionLevel, w: number | null) => updateCongestion(gameId, boothId, lv, w));

  // 最初に読み込めたとき、現在値を下書きの初期値にする（待ち時間はそのまま使える）
  const loaded = detail.data?.data;
  useEffect(() => {
    if (loaded && current === null) {
      setCurrent(loaded.congestion);
      setLevel(loaded.congestion.level);
      setWait(loaded.congestion.wait_minutes);
    }
  }, [loaded, current]);

  const back = (
    <p className="breadcrumb">
      <Link to="/staff" className="link">
        ← 担当ブース
      </Link>
    </p>
  );

  if (detail.loading) return <>{back}<Loading what="ブース" /></>;
  if (!loaded) return <>{back}{detail.error && <LoadError what="ブース" error={detail.error} onRetry={detail.reload} />}</>;

  const staleAfter = detail.data?.meta.stale_after_minutes ?? DEFAULT_STALE_AFTER_MINUTES;
  const congestion = current ?? loaded.congestion;
  const sendCongestion = async () => {
    if (level === null) return;
    setSaved(false);
    const result = await write.run(level, wait);
    if (result) {
      setCurrent(result.congestion);
      setSaved(true);
    }
  };

  return (
    <>
      {back}
      <h1 className="page-title">{loaded.name}</h1>
      <p className="muted page-sub">{loaded.location_label}</p>
      {write.offline && <OfflineWriteNotice />}

      <section className="status-panel" aria-label="いまの表示">
        <h2 className="section-title panel-title">いま来場者に見えている混雑</h2>
        <CongestionBadge level={congestion.level} isOpen={loaded.is_open} size="lg" />
        <p className="status-wait">
          {congestion.wait_minutes === null ? '待ち時間は未入力' : congestion.wait_minutes === 0 ? '待ち時間なし' : `待ち 約${congestion.wait_minutes}分`}
        </p>
        <Freshness updatedAt={congestion.updated_at} staleAfterMinutes={staleAfter} now={now} />
        {saved && !write.pending && (
          <p className="save-note" role="status">
            ✓ 更新しました。来場者の画面にも反映されます。
          </p>
        )}
      </section>

      <section aria-labelledby="congestion-form" className="update-form">
        <h2 id="congestion-form" className="section-title">混雑を更新する</h2>
        <LevelPicker value={level} onChange={setLevel} disabled={write.pending} />
        <h3 className="field-title">待ち時間</h3>
        <WaitStepper value={wait} onChange={setWait} disabled={write.pending} />
        <button
          type="button"
          className="btn btn-primary btn-block big-btn send-btn"
          disabled={level === null || write.pending || write.offline}
          onClick={() => void sendCongestion()}
        >
          {write.pending ? '送信中...' : level === null ? '混雑レベルを選んでください' : `「${CONGESTION_LABELS[level]}」で更新する`}
        </button>
        {write.error && <WriteError error={write.error} onRetry={level !== null ? () => void sendCongestion() : undefined} onDismiss={write.clearError} />}
      </section>

      <section aria-labelledby="stock-heading" className="update-form">
        <h2 id="stock-heading" className="section-title">在庫を更新する</h2>
        {loaded.menu_items.length === 0 ? (
          <p className="muted">
            販売中のメニューがありません。<Link to={`/staff/booths/${boothId}/menu`} className="link">メニューを管理する</Link>
          </p>
        ) : (
          <ul className="stock-list">
            {loaded.menu_items.map((item) => (
              <StockRow key={item.id} gameId={gameId} item={item} staleAfterMinutes={staleAfter} now={now} offline={write.offline} />
            ))}
          </ul>
        )}
      </section>

      <p className="staff-invite-link">
        <Link to={`/staff/booths/${boothId}/menu`} className="link">
          メニューを管理する（追加・価格・販売終了）
        </Link>
      </p>
    </>
  );
}
