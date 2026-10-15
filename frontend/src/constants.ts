/** アプリ名。画面・マニフェスト・タイトルで使う名前はここだけで定義する（ビルド時に VITE_APP_NAME で上書き可） */
export const APP_NAME: string = import.meta.env.VITE_APP_NAME || 'CourtQ';

/** localStorage のキー */
export const TOKEN_KEY = 'courtq_token';
export const USER_KEY = 'courtq_user';

/** API レスポンスのランタイムキャッシュ名（vite.config.ts の API_CACHE と同じ値） */
export const API_CACHE_NAME = 'courtq-api';
