import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import { checkDeployEnv, shouldCheck } from './scripts/check-deploy-env.mjs';

/** API レスポンスのランタイムキャッシュ名（ログイン/ログアウト時に破棄する。src/lib/pwa.ts と合わせる） */
const API_CACHE = 'courtq-api';

const config = defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons/apple-touch-icon.png'],
      manifest: {
        name: 'CourtQ',
        short_name: 'CourtQ',
        description: 'アリーナのブースの混雑と在庫を、試合当日にリアルタイムで確認できる Web アプリ',
        lang: 'ja',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        theme_color: '#14213D',
        background_color: '#14213D',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // アプリシェル（HTML / JS / CSS / アイコン）をプリキャッシュ。SPA なのでどの画面の遷移もシェルで開く
        globPatterns: ['**/*.{js,css,html,svg,png,ico,webmanifest}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        cleanupOutdatedCaches: true,
        clientsClaim: true,
        skipWaiting: true,
        // 上から順に評価され、最初に一致したものが使われる。キャッシュしないものを先に NetworkOnly で除外する
        runtimeCaching: [
          // GET API: ネットワーク優先（3秒で諦めて最後に取得した内容を表示）。
          // 試合当日の会場は電波が不安定なため、最後に取得した内容を 1 時間は表示できるようにする。
          // 古い内容かどうかは画面側が updated_at から計算して「情報が古い可能性があります」を出すので、長く残しても誤解させない
          {
            urlPattern: ({ request, url }) => request.method === 'GET' && url.pathname.startsWith('/api/'),
            handler: 'NetworkFirst',
            options: {
              cacheName: API_CACHE,
              networkTimeoutSeconds: 3,
              expiration: { maxAgeSeconds: 60 * 60, maxEntries: 50 },
              cacheableResponse: { statuses: [200] },
            },
          },
        ],
      },
    }),
  ],
  // E2E で複数の dev サーバーを同時に起動してもキャッシュが衝突しないようにする
  cacheDir: process.env.VITE_CACHE_DIR ?? 'node_modules/.vite',
  server: {
    host: true,
    port: 5173,
  },
});

export default defineConfig(({ command, mode }) => {
  // Vercel のビルドでは、VITE_ の変数の取り違え（未設定・localhost・末尾スラッシュなど）をここで止める
  const env = { ...loadEnv(mode, process.cwd(), 'VITE_'), ...process.env };
  if (command === 'build' && shouldCheck(env)) {
    const { errors, warnings } = checkDeployEnv(env);
    for (const warning of warnings) console.warn(`[警告] ${warning}`);
    if (errors.length > 0) {
      throw new Error(`環境変数の確認でエラーがあります。ビルドを中止します。\n${errors.map((e) => `- ${e}`).join('\n')}\n（docs/deployment.md の環境変数の表を確認してください）`);
    }
  }

  return config;
});
