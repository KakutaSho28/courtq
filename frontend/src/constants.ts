/** アプリ名。画面・マニフェスト・タイトルで使う名前はここだけで定義する（ビルド時に VITE_APP_NAME で上書き可） */
export const APP_NAME: string = import.meta.env.VITE_APP_NAME || 'CourtQ';

/** localStorage のキー */
export const TOKEN_KEY = 'courtq_token';
export const USER_KEY = 'courtq_user';

/** API レスポンスのランタイムキャッシュ名（vite.config.ts の API_CACHE と同じ値） */
export const API_CACHE_NAME = 'courtq-api';

/**
 * 「情報が古い可能性があります」を出すまでの分数の既定値。サーバーの config('courtq.stale_after_minutes') と同じ値。
 * 画面ではサーバーが返す meta.stale_after_minutes を優先し、取れないとき（古いキャッシュなど）だけこれを使う。
 */
export const DEFAULT_STALE_AFTER_MINUTES = 15;

/** 日時の表示に使うタイムゾーン（会場は日本。端末の設定に関係なく日本時間で表示する） */
export const DISPLAY_TIME_ZONE = 'Asia/Tokyo';

/** 混雑レベルの文言（サーバーの CongestionLevel::label() と同じ） */
export const CONGESTION_LABELS = { 1: '空いている', 2: 'やや混雑', 3: '混雑', 4: '非常に混雑' } as const;

/** カテゴリの文言 */
export const CATEGORY_LABELS = { food: '飲食', merch: '物販', both: '飲食・物販' } as const;
