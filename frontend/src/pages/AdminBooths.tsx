import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { createBooth, deleteBooth, fetchAdminBooths, updateBooth } from '../api/courtq';
import { AdminNav } from '../components/AdminNav';
import { ConfirmBar } from '../components/ConfirmBar';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { Segmented } from '../components/Segmented';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { CATEGORY_LABELS } from '../constants';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import type { AdminBooth, BoothCategory, BoothInput } from '../types';

const CATEGORIES: { value: BoothCategory; label: string }[] = [
  { value: 'food', label: CATEGORY_LABELS.food },
  { value: 'merch', label: CATEGORY_LABELS.merch },
  { value: 'both', label: CATEGORY_LABELS.both },
];

type FormState = { name: string; category: BoothCategory; location_label: string; description: string };
const emptyForm: FormState = { name: '', category: 'food', location_label: '', description: '' };

function validate(form: FormState): string | null {
  if (form.name.trim() === '') return 'ブース名を入力してください';
  if (form.location_label.trim() === '') return '場所を入力してください（例: 2F コンコース東）';
  return null;
}

function toInput(form: FormState): BoothInput {
  return { name: form.name.trim(), category: form.category, location_label: form.location_label.trim(), description: form.description.trim() === '' ? null : form.description.trim() };
}

function BoothForm({ idPrefix, initial, submitLabel, pending, offline, onSubmit, onCancel }: { idPrefix: string; initial: FormState; submitLabel: string; pending: boolean; offline: boolean; onSubmit: (input: BoothInput) => void; onCancel?: () => void }) {
  const [form, setForm] = useState(initial);
  const [problem, setProblem] = useState<string | null>(null);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const invalid = validate(form);
    setProblem(invalid);
    if (!invalid) onSubmit(toInput(form));
  };

  return (
    <form onSubmit={submit} className="menu-form" noValidate>
      <div className="field">
        <label htmlFor={`${idPrefix}-name`}>ブース名</label>
        <input id={`${idPrefix}-name`} value={form.name} maxLength={100} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </div>
      <div className="field">
        <span className="field-label">カテゴリ</span>
        <Segmented label="カテゴリ" options={CATEGORIES} value={form.category} onChange={(category) => setForm({ ...form, category })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-loc`}>場所</label>
        <input id={`${idPrefix}-loc`} value={form.location_label} maxLength={100} placeholder="2F コンコース東" onChange={(e) => setForm({ ...form, location_label: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-desc`}>説明（任意）</label>
        <input id={`${idPrefix}-desc`} value={form.description} maxLength={2000} onChange={(e) => setForm({ ...form, description: e.target.value })} />
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

function BoothRow({ booth, offline, onChanged }: { booth: AdminBooth; offline: boolean; onChanged: () => void }) {
  const [mode, setMode] = useState<'view' | 'edit' | 'confirm-delete'>('view');
  const update = useWriteAction((input: BoothInput) => updateBooth(booth.booth_id, input));
  const remove = useWriteAction(() => deleteBooth(booth.booth_id));

  const save = async (input: BoothInput) => {
    if (await update.run(input)) {
      setMode('view');
      onChanged();
    }
  };
  const doDelete = async () => {
    if ((await remove.run()) !== null) onChanged();
  };

  return (
    <li className="menu-manage">
      {mode === 'edit' ? (
        <BoothForm
          idPrefix={`booth-${booth.booth_id}`}
          initial={{ name: booth.name, category: booth.category, location_label: booth.location_label, description: booth.description ?? '' }}
          submitLabel="保存する"
          pending={update.pending}
          offline={offline}
          onSubmit={(input) => void save(input)}
          onCancel={() => { setMode('view'); update.clearError(); }}
        />
      ) : (
        <>
          <div className="menu-main">
            <span className="menu-name">{booth.name}</span>
            <span className="tag">{CATEGORY_LABELS[booth.category]}</span>
          </div>
          <div className="menu-manage-meta muted">
            {booth.location_label}・担当スタッフ {booth.staff_count} 人・出店 {booth.games_count} 試合
            {booth.description && `・${booth.description}`}
          </div>
          <div className="menu-manage-actions">
            <Link to={`/admin/booths/${booth.booth_id}/staff`} className="btn btn-primary big-btn" style={{ gridColumn: '1 / -1' }}>
              担当スタッフと招待コード
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
              message={`「${booth.name}」を削除します。メニューと担当スタッフの登録も消え、元に戻せません。`}
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

/** S12 管理者: ブースの登録・編集・削除 */
export function AdminBoothsPage() {
  useDocumentTitle('ブースの管理');
  const booths = useApiResource(fetchAdminBooths, 'admin-booths');
  const create = useWriteAction((input: BoothInput) => createBooth(input));
  const [formKey, setFormKey] = useState(0);

  const header = (
    <>
      <h1 className="page-title">管理</h1>
      <AdminNav />
    </>
  );

  if (booths.loading) return <>{header}<Loading what="ブース" /></>;
  if (!booths.data) return <>{header}{booths.error && <LoadError what="ブース" error={booths.error} onRetry={booths.reload} />}</>;

  const add = async (input: BoothInput) => {
    if (await create.run(input)) {
      setFormKey((k) => k + 1);
      booths.reload();
    }
  };

  return (
    <>
      {header}
      {create.offline && <OfflineWriteNotice />}
      {booths.error && <RefreshError error={booths.error} onRetry={booths.reload} />}

      <section aria-labelledby="booth-add" className="update-form">
        <h2 id="booth-add" className="section-title">ブースを追加する</h2>
        <BoothForm key={formKey} idPrefix="booth-new" initial={emptyForm} submitLabel="追加する" pending={create.pending} offline={create.offline} onSubmit={(input) => void add(input)} />
        {create.error && <WriteError error={create.error} onDismiss={create.clearError} />}
      </section>

      <section aria-labelledby="booth-list" className="update-form">
        <h2 id="booth-list" className="section-title">登録済みのブース（{booths.data.length} 件）</h2>
        <p className="muted">試合に出店させるには、「試合」の画面で試合ごとに設定します。試合に出店しているブースは削除できません。</p>
        {booths.data.length === 0 ? (
          <p className="muted">ブースはまだありません。上のフォームから追加してください。</p>
        ) : (
          <ul className="card-list">
            {booths.data.map((booth) => (
              <BoothRow key={booth.booth_id} booth={booth} offline={create.offline} onChanged={booths.reload} />
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
