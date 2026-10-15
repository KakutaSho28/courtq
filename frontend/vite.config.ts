import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

/** API レスポンスのランタイムキャッシュ名（ログイン/ログアウト時に破棄する。src/lib/pwa.ts と合わせる） */
const API_CACHE = 'courtq-api';

export default defineConfig({
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
          // 一覧などの GET API: ネットワーク優先（3秒で諦めて最後に取得した内容を表示）、最大5分。鮮度の扱いは WP-B9 で詳細化する
          {
            urlPattern: ({ request, url }) => request.method === 'GET' && url.pathname.startsWith('/api/'),
            handler: 'NetworkFirst',
            options: {
              cacheName: API_CACHE,
              networkTimeoutSeconds: 3,
              expiration: { maxAgeSeconds: 5 * 60, maxEntries: 50 },
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
