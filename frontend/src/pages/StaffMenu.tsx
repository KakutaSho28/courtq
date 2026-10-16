import { FormEvent, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { createMenuItem, deleteMenuItem, fetchStaffBooths, fetchStaffMenuItems, patchMenuItem } from '../api/courtq';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { Segmented } from '../components/Segmented';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatYen } from '../lib/time';
import type { MenuItemInput, MenuItemKind, StaffMenuItem } from '../types';

const KINDS: { value: MenuItemKind; label: string }[] = [
  { value: 'food', label: '飲食' },
  { value: 'merch', label: '物販' },
];

type FormState = { name: string; kind: MenuItemKind; price: string; description: string };

const emptyForm: FormState = { name: '', kind: 'food', price: '', description: '' };

function toInput(form: FormState, isActive: boolean): MenuItemInput {
  return { name: form.name.trim(), kind: form.kind, price: Number(form.price), description: form.description.trim() === '' ? null : form.description.trim(), is_active: isActive };
}

/** 入力の確認（サーバーも検査する。ここは送る前に気づけるようにするため） */
function validate(form: FormState): string | null {
  if (form.name.trim() === '') return 'メニュー名を入力してください';
  if (form.price.trim() === '' || !/^\d+$/.test(form.price.trim())) return '価格は 0 以上の整数（円）で入力してください';
  return null;
}

function MenuForm({
  initial,
  submitLabel,
  pending,
  offline,
  onSubmit,
  onCancel,
}: {
  initial: FormState;
  submitLabel: string;
  pending: boolean;
  offline: boolean;
  onSubmit: (form: FormState) => void;
  onCancel?: () => void;
}) {
  const [form, setForm] = useState(initial);
  const [problem, setProblem] = useState<string | null>(null);
  const idPrefix = `menu-form-${initial.name || 'new'}`;

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const invalid = validate(form);
    setProblem(invalid);
    if (!invalid) onSubmit(form);
  };

  return (
    <form onSubmit={submit} className="menu-form" noValidate>
      <div className="field">
        <label htmlFor={`${idPrefix}-name`}>メニュー名</label>
        <input id={`${idPrefix}-name`} value={form.name} maxLength={100} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </div>
      <div className="field">
        <span className="field-label">種類</span>
        <Segmented label="種類" options={KINDS} value={form.kind} onChange={(kind) => setForm({ ...form, kind })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-price`}>価格（円）</label>
        <input id={`${idPrefix}-price`} inputMode="numeric" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
      </div>
      <div className="field">
        <label htmlFor={`${idPrefix}-desc`}>説明（任意）</label>
        <input id={`${idPrefix}-desc`} value={form.description} maxLength={500} onChange={(e) => setForm({ ...form, description: e.target.value })} />
      </div>
      {problem && <p className="error-msg" role="alert">{problem}</p>}
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

function MenuRow({ boothId, item, offline, onChanged, onDeleted }: { boothId: string; item: StaffMenuItem; offline: boolean; onChanged: (item: StaffMenuItem) => void; onDeleted: (id: number) => void }) {
  const [mode, setMode] = useState<'view' | 'edit' | 'confirm-delete'>('view');
  const patch = useWriteAction((input: Partial<MenuItemInput>) => patchMenuItem(boothId, item.id, input));
  const remove = useWriteAction(() => deleteMenuItem(boothId, item.id));
  const [lastPatch, setLastPatch] = useState<Partial<MenuItemInput> | null>(null);
  const busy = patch.pending || remove.pending;

  const save = async (input: Partial<MenuItemInput>) => {
    setLastPatch(input);
    const updated = await patch.run(input);
    if (updated) {
      onChanged(updated);
      setMode('view');
    }
  };

  const doDelete = async () => {
    const result = await remove.run();
    if (result !== null) onDeleted(item.id);
  };

  return (
    <li className={`menu-manage${item.is_active ? '' : ' is-inactive'}`}>
      {mode === 'edit' ? (
        <MenuForm
          initial={{ name: item.name, kind: item.kind, price: String(item.price), description: item.description ?? '' }}
          submitLabel="保存する"
          pending={patch.pending}
          offline={offline}
          onSubmit={(form) => void save({ name: form.name.trim(), kind: form.kind, price: Number(form.price), description: form.description.trim() === '' ? null : form.description.trim() })}
          onCancel={() => { setMode('view'); patch.clearError(); }}
        />
      ) : (
        <>
          <div className="menu-main">
            <span className="menu-name">{item.name}</span>
            <span className="menu-price">{formatYen(item.price)}</span>
          </div>
          <div className="menu-manage-meta muted">
            {item.kind === 'food' ? '飲食' : '物販'}
            {item.description && `・${item.description}`}
            {!item.is_active && <span className="tag">販売終了</span>}
          </div>
          <div className="menu-manage-actions">
            <button
              type="button"
              className={`btn big-btn ${item.is_active ? 'btn-ghost' : 'btn-primary'}`}
              disabled={busy || offline}
              onClick={() => void save({ is_active: !item.is_active })}
            >
              {patch.pending ? '送信中...' : item.is_active ? '販売を終了する' : '販売を再開する'}
            </button>
            <button type="button" className="btn btn-ghost big-btn" disabled={busy} onClick={() => setMode('edit')}>
              編集
            </button>
            <button type="button" className="btn btn-danger big-btn" disabled={busy} onClick={() => setMode('confirm-delete')}>
              削除
            </button>
          </div>
          {mode === 'confirm-delete' && (
            <div className="notice notice-warning confirm-delete" role="alertdialog" aria-label={`${item.name}の削除の確認`}>
              <span>「{item.name}」を削除します。元に戻せません。</span>
              <span className="write-error-actions">
                <button type="button" className="btn btn-danger btn-sm" disabled={remove.pending || offline} onClick={() => void doDelete()}>
                  {remove.pending ? '送信中...' : '削除する'}
                </button>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setMode('view'); remove.clearError(); }}>
                  やめる
                </button>
              </span>
            </div>
          )}
        </>
      )}
      {patch.error && <WriteError error={patch.error} onRetry={lastPatch ? () => void save(lastPatch) : undefined} onDismiss={patch.clearError} />}
      {remove.error && (
        <>
          <WriteError error={remove.error} onRetry={remove.error.kind === 'invalid' ? undefined : () => void doDelete()} onDismiss={remove.clearError} />
          {remove.error.kind === 'invalid' && item.is_active && (
            <button type="button" className="btn btn-primary btn-block" disabled={busy || offline} onClick={() => { remove.clearError(); setMode('view'); void save({ is_active: false }); }}>
              削除せずに、販売を終了する
            </button>
          )}
        </>
      )}
    </li>
  );
}

/** S09 スタッフ: メニュー管理（追加・名前と価格の変更・販売終了と再開・削除）。結果はサーバーの応答を待ってから反映する */
export function StaffMenuPage() {
  const { boothId = '' } = useParams();
  const resource = useApiResource(async () => {
    const [items, booths] = await Promise.all([fetchStaffMenuItems(boothId), fetchStaffBooths()]);
    return { items, booth: booths.find((b) => String(b.booth_id) === boothId) ?? null };
  }, `staff-menu:${boothId}`);
  const [local, setLocal] = useState<{ items: StaffMenuItem[]; for: unknown } | null>(null);
  const create = useWriteAction((input: MenuItemInput) => createMenuItem(boothId, input));
  const [formKey, setFormKey] = useState(0);
  useDocumentTitle(resource.data?.booth ? `${resource.data.booth.name}のメニュー` : 'メニュー管理');

  const back = (
    <p className="breadcrumb">
      <Link to="/staff" className="link">
        ← 担当ブース
      </Link>
    </p>
  );

  if (resource.loading) return <>{back}<Loading what="メニュー" /></>;
  if (!resource.data) return <>{back}{resource.error && <LoadError what="メニュー" error={resource.error} onRetry={resource.reload} />}</>;

  // 画面で変更した結果は local に持つ。サーバーから取り直したら（resource.data が変わったら）そちらに戻す
  const items = local && local.for === resource.data ? local.items : resource.data.items;
  const setItems = (next: StaffMenuItem[]) => setLocal({ items: next, for: resource.data });
  const sorted = [...items].sort((a, b) => (a.kind === b.kind ? a.id - b.id : a.kind === 'food' ? -1 : 1));

  const add = async (form: FormState) => {
    const created = await create.run(toInput(form, true));
    if (created) {
      setItems([...items, created]);
      setFormKey((k) => k + 1); // 追加できたら、フォームを空に戻す
    }
  };

  return (
    <>
      {back}
      <h1 className="page-title">{resource.data.booth?.name ?? 'ブース'}のメニュー</h1>
      <p className="muted page-sub">販売をやめるメニューは「削除」ではなく「販売を終了する」にします（在庫の記録が残るため）。</p>
      {create.offline && <OfflineWriteNotice />}
      {resource.error && <RefreshError error={resource.error} onRetry={resource.reload} />}

      <section aria-labelledby="menu-add" className="update-form">
        <h2 id="menu-add" className="section-title">メニューを追加する</h2>
        <MenuForm key={formKey} initial={emptyForm} submitLabel="追加する" pending={create.pending} offline={create.offline} onSubmit={(form) => void add(form)} />
        {create.error && <WriteError error={create.error} onDismiss={create.clearError} />}
      </section>

      <section aria-labelledby="menu-list" className="update-form">
        <h2 id="menu-list" className="section-title">登録済みのメニュー（{sorted.length} 件）</h2>
        {sorted.length === 0 ? (
          <p className="muted">メニューはまだありません。上のフォームから追加してください。</p>
        ) : (
          <ul className="card-list">
            {sorted.map((item) => (
              <MenuRow
                key={item.id}
                boothId={boothId}
                item={item}
                offline={create.offline}
                onChanged={(updated) => setItems(items.map((i) => (i.id === updated.id ? updated : i)))}
                onDeleted={(id) => setItems(items.filter((i) => i.id !== id))}
              />
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
