import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchGame, fetchGameBooths, syncGameBooths } from '../api/courtq';
import { AdminNav } from '../components/AdminNav';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { CATEGORY_LABELS } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatTipoff } from '../lib/time';
import type { GameBoothAssignment } from '../types';

type Draft = Record<number, { assigned: boolean; is_open: boolean }>;

function toDraft(rows: GameBoothAssignment[]): Draft {
  return Object.fromEntries(rows.map((r) => [r.booth_id, { assigned: r.assigned, is_open: r.is_open }]));
}

/** S13 管理者: 試合に出店するブースの設定（出店するか・営業中か閉店中か） */
export function AdminGameBoothsPage() {
  const { gameId = '' } = useParams();
  const resource = useApiResource(async () => {
    const [game, rows] = await Promise.all([fetchGame(gameId), fetchGameBooths(gameId)]);
    return { game, rows };
  }, `admin-game-booths:${gameId}`);
  // 画面で変更中の内容。サーバーの内容（resource.data.rows）が変わったら、そちらに戻す
  const [local, setLocal] = useState<{ draft: Draft; base: unknown } | null>(null);
  const [saved, setSaved] = useState(false);
  const save = useWriteAction((booths: { booth_id: number; is_open: boolean }[]) => syncGameBooths(gameId, booths));
  useDocumentTitle(resource.data ? `${resource.data.game.home_team} vs ${resource.data.game.away_team} の出店ブース` : '出店ブースの設定');

  const back = (
    <>
      <h1 className="page-title">管理</h1>
      <AdminNav />
      <p className="breadcrumb">
        <Link to="/admin/games" className="link">
          ← 試合の管理
        </Link>
      </p>
    </>
  );

  if (resource.loading) return <>{back}<Loading what="出店ブース" /></>;
  if (!resource.data) return <>{back}{resource.error && <LoadError what="出店ブース" error={resource.error} onRetry={resource.reload} />}</>;

  const { game, rows } = resource.data;
  const server = toDraft(rows);
  const draft = local && local.base === resource.data ? local.draft : server;
  const setDraft = (next: Draft) => {
    setSaved(false);
    setLocal({ draft: next, base: resource.data });
  };
  const changed = rows.filter((r) => draft[r.booth_id].assigned !== server[r.booth_id].assigned || (draft[r.booth_id].assigned && draft[r.booth_id].is_open !== server[r.booth_id].is_open));
  const assignedCount = rows.filter((r) => draft[r.booth_id].assigned).length;

  const submit = async () => {
    const payload = rows.filter((r) => draft[r.booth_id].assigned).map((r) => ({ booth_id: r.booth_id, is_open: draft[r.booth_id].is_open }));
    const result = await save.run(payload);
    if (result) {
      setLocal(null);
      setSaved(true);
      resource.reload();
    }
  };

  return (
    <>
      {back}
      <h2 className="section-title">
        {game.home_team} <span className="vs">vs</span> {game.away_team}
      </h2>
      <p className="muted page-sub">
        {formatTipoff(game.tipoff_at)} 試合開始・{game.venue.name}
      </p>
      <p className="muted">出店するブースにチェックを付け、閉店しているブースは「営業中」をオフにします。混雑・在庫の記録があるブースは、試合から外せません（閉店にしてください）。</p>
      {save.offline && <OfflineWriteNotice />}
      {resource.error && <RefreshError error={resource.error} onRetry={resource.reload} />}

      {rows.length === 0 ? (
        <div className="empty">
          <p>ブースがまだありません。</p>
          <Link to="/admin/booths" className="btn btn-primary">
            ブースを追加する
          </Link>
        </div>
      ) : (
        <ul className="card-list">
          {rows.map((row) => {
            const state = draft[row.booth_id];
            return (
              <li key={row.booth_id} className="menu-manage assign-row">
                <label className="assign-check">
                  <input
                    type="checkbox"
                    checked={state.assigned}
                    disabled={row.has_records || save.pending}
                    onChange={(e) => setDraft({ ...draft, [row.booth_id]: { ...state, assigned: e.target.checked } })}
                  />
                  <span className="menu-name">{row.name}</span>
                </label>
                <div className="menu-manage-meta muted">
                  {row.location_label}・{CATEGORY_LABELS[row.category]}
                  {row.has_records && <span className="tag">記録あり・外せません</span>}
                </div>
                {state.assigned && (
                  <label className="assign-open">
                    <input type="checkbox" checked={state.is_open} disabled={save.pending} onChange={(e) => setDraft({ ...draft, [row.booth_id]: { ...state, is_open: e.target.checked } })} />
                    営業中{!state.is_open && <span className="tag">閉店中</span>}
                  </label>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {save.error && <WriteError error={save.error} onRetry={() => void submit()} onDismiss={save.clearError} />}
      {saved && changed.length === 0 && (
        <p className="notice notice-success" role="status">
          保存しました。来場者の画面には、次の読み込みから反映されます。
        </p>
      )}

      <div className="assign-actions">
        <button type="button" className="btn btn-primary big-btn btn-block" disabled={changed.length === 0 || save.pending || save.offline} onClick={() => void submit()}>
          {save.pending ? '送信中...' : changed.length === 0 ? '変更はありません' : `変更を保存する（出店 ${assignedCount} ブース）`}
        </button>
      </div>
    </>
  );
}
