import { useRef, useState } from 'react';
import { importGamesCsv } from '../api/courtq';
import { useWriteAction } from '../hooks/useWriteAction';
import { formatTipoff } from '../lib/time';
import type { CsvImportResult } from '../types';
import { OfflineWriteNotice, WriteError } from './WriteError';

const SAMPLE = `season,section,date,tipoff_time,home_team,away_team,venue
2026-27,5,2026-11-01,19:05,ベイサイド・ダイナモ,シティ・ラインズ,みなと第一アリーナ`;

type Props = {
  /** 取り込みが済んだとき（試合の一覧を取り直す） */
  onImported: () => void;
};

/**
 * 試合の CSV 取り込み（ADMIN-02）。必ず「確認」（保存しない）→「取り込む」の 2 段階にする。
 * 1 行でも誤りがあると何も保存されないので、誤りは行ごとに全部表示する。
 */
export function CsvImport({ onImported }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<CsvImportResult | null>(null);
  const [done, setDone] = useState<CsvImportResult | null>(null);
  const check = useWriteAction((f: File) => importGamesCsv(f, true));
  const save = useWriteAction((f: File) => importGamesCsv(f, false));

  const rowErrors = (check.error ?? save.error)?.fieldErrors?.csv ?? [];
  const error = check.error ?? save.error;

  const reset = () => {
    setFile(null);
    setPreview(null);
    check.clearError();
    save.clearError();
    if (inputRef.current) inputRef.current.value = '';
  };

  const runCheck = async () => {
    if (!file) return;
    setDone(null);
    setPreview(null);
    save.clearError();
    const result = await check.run(file);
    if (result) setPreview(result);
  };

  const runSave = async () => {
    if (!file) return;
    const result = await save.run(file);
    if (result) {
      setDone(result);
      reset();
      onImported();
    }
  };

  return (
    <div className="csv-import">
      <details>
        <summary>CSV の書き方</summary>
        <p className="muted">
          1 行目は見出しです。Excel の「CSV」でも、UTF-8 の CSV でも読み込めます。会場は登録済みの名前と<strong>完全に同じ</strong>にしてください。
          すでに登録済みの試合（シーズン・節・ホーム・アウェイが同じ）は取り込まずに飛ばすので、同じファイルを取り込み直しても重複しません。
        </p>
        <pre className="csv-sample">{SAMPLE}</pre>
      </details>

      {save.offline && <OfflineWriteNotice />}

      <div className="field">
        <label htmlFor="csv-file">CSV ファイル（1MB・500 行まで）</label>
        <input
          id="csv-file"
          ref={inputRef}
          type="file"
          accept=".csv,text/csv"
          onChange={(e) => {
            setFile(e.target.files?.[0] ?? null);
            setPreview(null);
            setDone(null);
            check.clearError();
            save.clearError();
          }}
        />
      </div>
      <button type="button" className="btn btn-ghost big-btn" disabled={!file || check.pending || save.pending || check.offline} onClick={() => void runCheck()}>
        {check.pending ? '確認中...' : '内容を確認する（まだ保存しません）'}
      </button>

      {error && rowErrors.length === 0 && <WriteError error={error} onDismiss={() => { check.clearError(); save.clearError(); }} />}
      {rowErrors.length > 0 && (
        <div className="notice notice-error csv-errors" role="alert">
          <div>
            <p className="csv-errors-title">{rowErrors.length} 件の誤りがあります。直してから、もう一度確認してください（何も保存していません）。</p>
            <ul>
              {rowErrors.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          </div>
        </div>
      )}

      {preview && (
        <section className="csv-preview" aria-label="取り込み内容の確認">
          <p role="status">
            <strong>{preview.created_count} 件</strong>を登録します
            {preview.skipped_count > 0 && <>（{preview.skipped_count} 件は取り込まずに飛ばします）</>}。
          </p>
          <ul className="csv-rows">
            {preview.rows.map((row) => (
              <li key={row.row} className={row.action === 'skip' ? 'is-skip' : undefined}>
                <span className="tag">{row.action === 'create' ? '登録' : '飛ばす'}</span>{' '}
                {row.home_team} <span className="vs">vs</span> {row.away_team}・{formatTipoff(row.tipoff_at)}・{row.venue}
                {row.reason && <span className="muted">（{row.row} 行目: {row.reason}）</span>}
              </li>
            ))}
          </ul>
          <button type="button" className="btn btn-primary big-btn btn-block" disabled={preview.created_count === 0 || save.pending || save.offline} onClick={() => void runSave()}>
            {save.pending ? '取り込み中...' : preview.created_count === 0 ? '取り込む試合がありません' : `この内容で ${preview.created_count} 件を取り込む`}
          </button>
        </section>
      )}

      {done && (
        <p className="notice notice-success" role="status">
          {done.created_count} 件を取り込みました{done.skipped_count > 0 && `（${done.skipped_count} 件は登録済みなどで飛ばしました）`}。
        </p>
      )}
    </div>
  );
}
