import { FormEvent, useState } from 'react';
import { createVenue } from '../api/courtq';
import { Segmented } from './Segmented';
import { WriteError } from './WriteError';
import { useWriteAction } from '../hooks/useWriteAction';
import { fromDatetimeLocal, toDatetimeLocal } from '../lib/time';
import type { AdminGame, GameInput, GameStatus, Venue } from '../types';

const STATUSES: { value: GameStatus; label: string }[] = [
  { value: 'scheduled', label: '開催前' },
  { value: 'live', label: '開催中' },
  { value: 'finished', label: '終了' },
];

export type GameFormState = { season: string; section: string; home_team: string; away_team: string; venue_id: string; tipoff_at: string; status: GameStatus };

export const emptyGameForm: GameFormState = { season: '', section: '', home_team: '', away_team: '', venue_id: '', tipoff_at: '', status: 'scheduled' };

export function gameToForm(game: AdminGame): GameFormState {
  return {
    season: game.season,
    section: game.section === null ? '' : String(game.section),
    home_team: game.home_team,
    away_team: game.away_team,
    venue_id: String(game.venue.id),
    tipoff_at: toDatetimeLocal(game.tipoff_at),
    status: game.status,
  };
}

/** 入力の確認（サーバーも検査する。ここは送る前に気づけるようにするため） */
function validate(form: GameFormState): string | null {
  if (form.season.trim() === '') return 'シーズンを入力してください（例: 2026-27）';
  if (form.section.trim() !== '' && !/^[1-9]\d{0,2}$/.test(form.section.trim())) return '節は 1 以上の整数で入力してください（空でも構いません）';
  if (form.home_team.trim() === '' || form.away_team.trim() === '') return 'ホームとアウェイのチーム名を入力してください';
  if (form.home_team.trim() === form.away_team.trim()) return 'ホームとアウェイは別のチームにしてください';
  if (form.venue_id === '') return '会場を選んでください';
  if (form.tipoff_at === '') return '開始日時を入力してください';
  return null;
}

export function toGameInput(form: GameFormState): GameInput {
  return {
    season: form.season.trim(),
    section: form.section.trim() === '' ? null : Number(form.section.trim()),
    home_team: form.home_team.trim(),
    away_team: form.away_team.trim(),
    venue_id: Number(form.venue_id),
    tipoff_at: fromDatetimeLocal(form.tipoff_at),
    status: form.status,
  };
}

type Props = {
  idPrefix: string;
  initial: GameFormState;
  venues: Venue[];
  submitLabel: string;
  pending: boolean;
  offline: boolean;
  onSubmit: (input: GameInput) => void;
  onCancel?: () => void;
  onVenueCreated: (venue: Venue) => void;
};

/** 試合の登録・編集フォーム（S11）。会場が無いときは、その場で会場を追加できる */
export function GameForm({ idPrefix, initial, venues, submitLabel, pending, offline, onSubmit, onCancel, onVenueCreated }: Props) {
  const [form, setForm] = useState(initial);
  const [problem, setProblem] = useState<string | null>(null);
  const [newVenue, setNewVenue] = useState('');
  const addVenue = useWriteAction((name: string) => createVenue(name));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const invalid = validate(form);
    setProblem(invalid);
    if (!invalid) onSubmit(toGameInput(form));
  };

  const submitVenue = async () => {
    if (newVenue.trim() === '') return;
    const venue = await addVenue.run(newVenue.trim());
    if (venue) {
      onVenueCreated(venue);
      setForm((f) => ({ ...f, venue_id: String(venue.id) }));
      setNewVenue('');
    }
  };

  return (
    <form onSubmit={submit} className="menu-form" noValidate>
      <div className="field">
        <label htmlFor={`${idPrefix}-season`}>シーズン</label>
        <input id={`${idPrefix}-season`} value={form.season} maxLength={20} placeholder="2026-27" onChange={(e) => setForm({ ...form, season: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-section`}>節（任意）</label>
        <input id={`${idPrefix}-section`} inputMode="numeric" value={form.section} onChange={(e) => setForm({ ...form, section: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-home`}>ホーム</label>
        <input id={`${idPrefix}-home`} value={form.home_team} maxLength={255} onChange={(e) => setForm({ ...form, home_team: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-away`}>アウェイ</label>
        <input id={`${idPrefix}-away`} value={form.away_team} maxLength={255} onChange={(e) => setForm({ ...form, away_team: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-venue`}>会場</label>
        <select id={`${idPrefix}-venue`} value={form.venue_id} onChange={(e) => setForm({ ...form, venue_id: e.target.value })}>
          <option value="">選んでください</option>
          {venues.map((venue) => (
            <option key={venue.id} value={venue.id}>
              {venue.name}
            </option>
          ))}
        </select>
      </div>
      <details className="venue-add">
        <summary>会場が見つからないとき（会場を追加する）</summary>
        <div className="field">
          <label htmlFor={`${idPrefix}-newvenue`}>新しい会場の名前</label>
          <input id={`${idPrefix}-newvenue`} value={newVenue} maxLength={255} onChange={(e) => setNewVenue(e.target.value)} />
        </div>
        <button type="button" className="btn btn-ghost" disabled={addVenue.pending || offline || newVenue.trim() === ''} onClick={() => void submitVenue()}>
          {addVenue.pending ? '送信中...' : '会場を追加する'}
        </button>
        {addVenue.error && <WriteError error={addVenue.error} onDismiss={addVenue.clearError} />}
      </details>
      <div className="field">
        <label htmlFor={`${idPrefix}-tipoff`}>開始日時（日本時間）</label>
        <input id={`${idPrefix}-tipoff`} type="datetime-local" value={form.tipoff_at} onChange={(e) => setForm({ ...form, tipoff_at: e.target.value })} />
      </div>
      <div className="field">
        <span className="field-label">状態</span>
        <Segmented label="状態" options={STATUSES} value={form.status} onChange={(status) => setForm({ ...form, status })} />
      </div>
      {problem && (
        <p className="error-msg" role="alert">
          {problem}
        </p>
      )}
      <div className="menu-form-actions">
        <button type="submit" className="btn btn-primary big-btn" disabled={pending || offline}>
          {pending ? '送信中...' : submitLabel}
        </button>
        {onCancel && (
          <button type="button" className="btn btn-ghost big-btn" onClick={onCancel} disabled={pending}>
            やめる
          </button>
        )}
      </div>
    </form>
  );
}
