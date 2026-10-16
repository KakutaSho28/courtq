import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { acceptInvitation } from '../api/courtq';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import { useAuthStore } from '../store/auth';
import type { InvitationAccepted } from '../types';

/** 入力のゆれを整える（サーバーも同じように受け付ける）: 全角→半角、小文字→大文字、空白とハイフンは見た目だけ */
function tidy(value: string): string {
  const half = value.replace(/[！-～]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0)).replace(/[‐−ー－]/g, '-');
  return half.toUpperCase().replace(/[^A-Z0-9-]/g, '');
}

/** S10 招待コードの入力（ログインしていれば誰でも）。使うとそのブースの担当（スタッフ）になる */
export function InvitePage() {
  useDocumentTitle('招待コードを入力');
  const setUser = useAuthStore((s) => s.setUser);
  const [code, setCode] = useState('');
  const [accepted, setAccepted] = useState<InvitationAccepted | null>(null);
  const write = useWriteAction((value: string) => acceptInvitation(value));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (code.replace(/-/g, '').length === 0) return;
    const result = await write.run(code);
    if (result) {
      // 役割と担当ブースが変わったので、保存してあるユーザー情報を更新する（スタッフの画面が使えるようになる）
      setUser(result.user);
      setAccepted(result);
      setCode('');
    }
  };

  if (accepted) {
    return (
      <>
        <h1 className="page-title">担当ブースに登録しました</h1>
        <div className="status-panel" role="status">
          <p className="status-wait">「{accepted.booth.name}」の担当になりました。</p>
          <p className="muted">混雑と在庫を更新できます。</p>
          <Link to="/staff" className="btn btn-primary btn-block big-btn">
            担当ブースを開く
          </Link>
        </div>
        <p className="staff-invite-link">
          <button type="button" className="btn btn-ghost" onClick={() => setAccepted(null)}>
            別の招待コードを入力する
          </button>
        </p>
      </>
    );
  }

  return (
    <>
      <h1 className="page-title">招待コードを入力</h1>
      <p className="muted page-sub">管理者から受け取った招待コード（8 文字）を入力すると、そのブースのスタッフとして混雑・在庫を更新できます。</p>
      {write.offline && <OfflineWriteNotice />}

      <form onSubmit={(e) => void submit(e)} className="invite-form" noValidate>
        <div className="field">
          <label htmlFor="invite-code">招待コード</label>
          <input
            id="invite-code"
            className="invite-input"
            value={code}
            onChange={(e) => { setCode(tidy(e.target.value)); write.clearError(); }}
            autoCapitalize="characters"
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            maxLength={12}
            placeholder="例: K7Q2-MX9P"
            aria-describedby="invite-help"
          />
          <span id="invite-help" className="muted">大文字・小文字、ハイフンは区別しません。</span>
        </div>
        {write.error && <WriteError error={write.error} onDismiss={write.clearError} />}
        <button type="submit" className="btn btn-primary btn-block big-btn" disabled={write.pending || write.offline || code.replace(/-/g, '').length === 0}>
          {write.pending ? '確認中...' : 'スタッフとして登録する'}
        </button>
      </form>
    </>
  );
}
