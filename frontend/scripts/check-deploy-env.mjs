// Vercel でのビルド時に、環境変数（VITE_*）の取り違えを見つけて、ビルドを止める。
// VITE_ の変数は「ビルド時に JS へ埋め込まれる」ので、未設定・取り違えのままデプロイすると、
// フロントが開発用の既定値（http://localhost/api）を向いたまま、気づきにくい不具合になる。
// 変更したあとは、Vercel で再デプロイが必要（変数を変えるだけでは、すでにビルドされた JS は変わらない）。

/**
 * @param {Record<string, string | undefined>} env
 * @returns {{ errors: string[], warnings: string[] }}
 */
export function checkDeployEnv(env) {
  const errors = [];
  const warnings = [];

  const api = env.VITE_API_BASE_URL ?? '';
  if (api === '') {
    errors.push('VITE_API_BASE_URL が未設定です。Railway の web サービスの URL + /api（例: https://courtq-api.up.railway.app/api）を設定して、再デプロイしてください。');
  } else {
    if (!api.startsWith('https://')) errors.push(`VITE_API_BASE_URL が https:// で始まっていません（${api}）。`);
    if (/\/\/(localhost|127\.0\.0\.1)/.test(api)) errors.push(`VITE_API_BASE_URL がローカルの値です（${api}）。Railway の URL を設定してください。`);
    if (api.endsWith('/')) errors.push(`VITE_API_BASE_URL の末尾にスラッシュがあります（${api}）。外してください（末尾は /api）。`);
    else if (!api.endsWith('/api')) errors.push(`VITE_API_BASE_URL の末尾が /api ではありません（${api}）。Railway の URL + /api にしてください。`);
  }

  const key = env.VITE_PUSHER_APP_KEY ?? '';
  if (key !== '' && (env.VITE_PUSHER_APP_CLUSTER ?? '') === '') {
    errors.push('VITE_PUSHER_APP_KEY を設定していますが、VITE_PUSHER_APP_CLUSTER が未設定です（Pusher の App Keys の cluster。例: ap3）。');
  }
  if (key === '') {
    warnings.push('VITE_PUSHER_APP_KEY が未設定です。リアルタイム更新は無効になります（30 秒ごとの取り直しで動きます）。');
  }

  return { errors, warnings };
}

/** Vercel のビルド（VERCEL=1）、または CHECK_DEPLOY_ENV=1 のときだけ確認する（ローカルの開発・e2e は対象外） */
export function shouldCheck(env) {
  return env.VERCEL === '1' || env.CHECK_DEPLOY_ENV === '1';
}
