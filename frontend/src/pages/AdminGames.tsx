import { useState } from 'react';
import { Link } from 'react-router-dom';
import { createGame, deleteGame, fetchAdminGames, fetchVenues, updateGame } from '../api/courtq';
import { AdminNav } from '../components/AdminNav';
import { ConfirmBar } from '../components/ConfirmBar';
import { CsvImport } from '../components/CsvImport';
import { emptyGameForm, GameForm, gameToForm } from '../components/GameForm';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatTipoff } from '../lib/time';
import type { AdminGame, GameInput, GameStatus, Venue } from '../types';

const STATUS_LABELS: Record<GameStatus, string> = { scheduled: '開催前', live: '開催中', finished: '終了' };

function GameRow({ game, venues, offline, onVenueCreated, onChanged, onDeleted }: { game: AdminGame; venues: Venue[]; offline: boolean; onVenueCreated: (v: Venue) => void; onChanged: () => void; onDeleted: () => void }) {
  const [mode, setMode] = useState<'view' | 'edit' | 'confirm-delete'>('view');
  const update = useWriteAction((input: GameInput) => updateGame(game.id, input));
  const remove = useWriteAction(() => deleteGame(game.id));

  const save = async (input: GameInput) => {
    if (await update.run(input)) {
      setMode('view');
      onChanged();
    }
  };

  const doDelete = async () => {
    if ((await remove.run()) !== null) onDeleted();
  };

  return (
    <li className="menu-manage">
      {mode === 'edit' ? (
        <GameForm
          idPrefix={`game-${game.id}`}
          initial={gameToForm(game)}
          venues={venues}
          submitLabel="保存する"
          pending={update.pending}
          offline={offline}
          onSubmit={(input) => void save(input)}
          onCancel={() => { setMode('view'); update.clearError(); }}
          onVenueCreated={onVenueCreated}
        />
      ) : (
        <>
          <div className="menu-main">
            <span className="menu-name">
              {game.home_team} <span className="vs">vs</span> {game.away_team}
            </span>
            <span className={`game-status${game.status === 'live' ? ' game-status-live' : ''}`}>{STATUS_LABELS[game.status]}</span>
          </div>
          <div className="menu-manage-meta muted">
            {formatTipoff(game.tipoff_at)} 試合開始・{game.venue.name}・{game.season}
            {game.section !== null && ` 第${game.section}節`}・出店 {game.booths_count} ブース
          </div>
          <div className="menu-manage-actions">
            <Link to={`/admin/games/${game.id}/booths`} className="btn btn-primary big-btn" style={{ gridColumn: '1 / -1' }}>
              出店ブースを設定する
            </Link>
            <button type="button" className="btn btn-ghost big-btn" onClick={() => setMode('edit')}>
              編集
            </button>
            <button type="button" className="btn btn-danger big-btn" onClick={() => setMode('confirm-delete')}>
              削除
            </button>
          </div>
          {mode === 'confirm-delete' && (
            <ConfirmBar
              message={`「${game.home_team} vs ${game.away_team}」を削除します。元に戻せません。`}
              confirmLabel="削除する"
              pending={remove.pending}
              disabled={offline}
              onConfirm={() => void doDelete()}
              onCancel={() => { setMode('view'); remove.clearError(); }}
            />
          )}
        </>
      )}
      {update.error && <WriteError error={update.error} onDismiss={update.clearError} />}
      {remove.error && <WriteError error={remove.error} onRetry={remove.error.kind === 'invalid' ? undefined : () => void doDelete()} onDismiss={remove.clearError} />}
    </li>
  );
}

/** S11 管理者: 試合の登録・編集・削除と CSV 取り込み */
export function AdminGamesPage() {
  useDocumentTitle('試合の管理');
  const games = useApiResource(fetchAdminGames, 'admin-games');
  const venueResource = useApiResource(fetchVenues, 'admin-venues');
  const [addedVenues, setAddedVenues] = useState<Venue[]>([]);
  const create = useWriteAction((input: GameInput) => createGame(input));
  const [formKey, setFormKey] = useState(0);

  const header = (
    <>
      <h1 className="page-title">管理</h1>
      <AdminNav />
    </>
  );

  if (games.loading || venueResource.loading) return <>{header}<Loading what="試合" /></>;
  if (!games.data || !venueResource.data) {
    const error = games.error ?? venueResource.error;
    return <>{header}{error && <LoadError what="試合" error={error} onRetry={() => { games.reload(); venueResource.reload(); }} />}</>;
  }

  const venues = [...venueResource.data, ...addedVenues.filter((v) => !venueResource.data?.some((x) => x.id === v.id))];

  const add = async (input: GameInput) => {
    if (await create.run(input)) {
      setFormKey((k) => k + 1);
      games.reload();
    }
  };

  return (
    <>
      {header}
      {create.offline && <OfflineWriteNotice />}
      {games.error && <RefreshError error={games.error} onRetry={games.reload} />}

      <section aria-labelledby="csv-title" className="update-form">
        <h2 id="csv-title" className="section-title">CSV で取り込む</h2>
        <CsvImport onImported={games.reload} />
      </section>

      <section aria-labelledby="game-add" className="update-form">
        <h2 id="game-add" className="section-title">試合を 1 件ずつ追加する</h2>
        <GameForm
          key={formKey}
          idPrefix="game-new"
          initial={emptyGameForm}
          venues={venues}
          submitLabel="追加する"
          pending={create.pending}
          offline={create.offline}
          onSubmit={(input) => void add(input)}
          onVenueCreated={(v) => setAddedVenues((list) => [...list, v])}
        />
        {create.error && <WriteError error={create.error} onDismiss={create.clearError} />}
      </section>

      <section aria-labelledby="game-list" className="update-form">
        <h2 id="game-list" className="section-title">登録済みの試合（{games.data.length} 件）</h2>
        {games.data.length === 0 ? (
          <p className="muted">試合はまだありません。CSV で取り込むか、上のフォームから追加してください。</p>
        ) : (
          <ul className="card-list">
            {games.data.map((game) => (
              <GameRow
                key={game.id}
                game={game}
                venues={venues}
                offline={create.offline}
                onVenueCreated={(v) => setAddedVenues((list) => [...list, v])}
                onChanged={games.reload}
                onDeleted={games.reload}
              />
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
