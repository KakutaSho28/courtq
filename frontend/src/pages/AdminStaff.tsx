import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchAdminBooth, fetchBoothStaff, issueInvitation, removeBoothStaff } from '../api/courtq';
import { AdminNav } from '../components/AdminNav';
import { ConfirmBar } from '../components/ConfirmBar';
import { LoadError, Loading, RefreshError } from '../components/LoadState';
import { OfflineWriteNotice, WriteError } from '../components/WriteError';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatTipoff } from '../lib/time';
import type { BoothStaffMember, IssuedInvitation } from '../types';

function StaffRow({ boothId, member, offline, onRemoved }: { boothId: string; member: BoothStaffMember; offline: boolean; onRemoved: () => void }) {
  const [confirm, setConfirm] = useState(false);
  const remove = useWriteAction(() => removeBoothStaff(boothId, member.user_id));

  const doRemove = async () => {
    if ((await remove.run()) !== null) onRemoved();
  };

  return (
    <li className="menu-manage">
      <div className="menu-main">
        <span className="menu-name">{member.name}</span>
        <span className="tag">{member.role === 'admin' ? '管理者' : 'スタッフ'}</span>
      </div>
      <div className="menu-manage-meta muted">{member.email}</div>
      <button type="button" className="btn btn-danger big-btn" disabled={remove.pending} onClick={() => setConfirm(true)}>
        担当から外す
      </button>
      {confirm && (
        <ConfirmBar
          message={`${member.name}さんを、このブースの担当から外します。他に担当ブースがなければ、スタッフの画面は使えなくなります。`}
          confirmLabel="外す"
          pending={remove.pending}
          disabled={offline}
          onConfirm={() => void doRemove()}
          onCancel={() => { setConfirm(false); remove.clearError(); }}
        />
      )}
      {remove.error && <WriteError error={remove.error} onRetry={() => void doRemove()} onDismiss={remove.clearError} />}
    </li>
  );
}

/** S14 管理者: ブースの招待コードの発行と、担当スタッフの一覧・解除 */
export function AdminStaffPage() {
  const { boothId = '' } = useParams();
  const resource = useApiResource(async () => {
    const [booth, staff] = await Promise.all([fetchAdminBooth(boothId), fetchBoothStaff(boothId)]);
    return { booth, staff };
  }, `admin-staff:${boothId}`);
  const [invitation, setInvitation] = useState<IssuedInvitation | null>(null);
  const [copied, setCopied] = useState(false);
  const issue = useWriteAction(() => issueInvitation(boothId));
  useDocumentTitle(resource.data ? `${resource.data.booth.name}の担当スタッフ` : '担当スタッフ');

  const top = (
    <>
      <h1 className="page-title">管理</h1>
      <AdminNav />
      <p className="breadcrumb">
        <Link to="/admin/booths" className="link">
          ← ブースの管理
        </Link>
      </p>
    </>
  );

  if (resource.loading) return <>{top}<Loading what="担当スタッフ" /></>;
  if (!resource.data) return <>{top}{resource.error && <LoadError what="担当スタッフ" error={resource.error} onRetry={resource.reload} />}</>;

  const { booth, staff } = resource.data;

  const doIssue = async () => {
    setCopied(false);
    const result = await issue.run();
    if (result) setInvitation(result);
  };

  const copy = async () => {
    if (!invitation) return;
    try {
      await navigator.clipboard.writeText(invitation.code);
      setCopied(true);
    } catch {
      setCopied(false); // コピーできない環境では、表示されたコードを手で書き写してもらう
    }
  };

  return (
    <>
      {top}
      <h2 className="section-title">{booth.name}</h2>
      <p className="muted page-sub">{booth.location_label}</p>
      {issue.offline && <OfflineWriteNotice />}
      {resource.error && <RefreshError error={resource.error} onRetry={resource.reload} />}

      <section aria-labelledby="invite-title" className="update-form">
        <h2 id="invite-title" className="section-title">招待コードを発行する</h2>
        <p className="muted">コードを受け取った人が「スタッフ登録」の画面に入力すると、このブースの担当になります。1 回だけ使えて、7 日で期限が切れます。</p>
        <button type="button" className="btn btn-primary big-btn btn-block" disabled={issue.pending || issue.offline} onClick={() => void doIssue()}>
          {issue.pending ? '発行中...' : '招待コードを発行する'}
        </button>
        {issue.error && <WriteError error={issue.error} onRetry={() => void doIssue()} onDismiss={issue.clearError} />}
        {invitation && (
          <div className="status-panel invite-issued" role="status">
            <p className="muted">招待コード（{invitation.booth.name}）</p>
            <p className="invite-code" aria-label={`招待コード ${invitation.code.split('').join(' ')}`}>{invitation.code}</p>
            <p className="muted">{formatTipoff(invitation.expires_at)} まで有効・1 回だけ使えます。このコードは、この画面を閉じると再表示できません。</p>
            <button type="button" className="btn btn-ghost" onClick={() => void copy()}>
              {copied ? 'コピーしました' : 'コードをコピーする'}
            </button>
          </div>
        )}
      </section>

      <section aria-labelledby="staff-title" className="update-form">
        <h2 id="staff-title" className="section-title">担当スタッフ（{staff.length} 人）</h2>
        {staff.length === 0 ? (
          <p className="muted">担当スタッフはまだいません。</p>
        ) : (
          <ul className="card-list">
            {staff.map((member) => (
              <StaffRow key={member.user_id} boothId={boothId} member={member} offline={issue.offline} onRemoved={resource.reload} />
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
