import { defineConfig, devices } from '@playwright/test';

const PORT = 5174;
/** Pusher のキーを設定した dev サーバー（リアルタイム機能のテスト用） */
const REALTIME_PORT = 5175;
/** PWA のテスト用: 本番ビルド（Service Worker 入り）を vite preview で配信する */
const PWA_PORT = 5176;
/** PWA のテスト用の API モック（Service Worker の通信は page.route で横取りできないため実サーバー） */
const MOCK_API_PORT = 5180;

/**
 * E2E テスト。API は各テストで page.route によりモックするため、バックエンド不要。
 * リアルタイムのテストは WebSocket も偽の Pusher サーバーに差し替える（実際のキー・通信は不要）。
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: `http://localhost:${PORT}`,
    ...devices['Desktop Chrome'],
    deviceScaleFactor: 1,
  },
  webServer: [
    {
      command: `npm run dev -- --port ${PORT} --strictPort`,
      url: `http://localhost:${PORT}`,
      reuseExistingServer: !process.env.CI,
      env: { VITE_API_BASE_URL: 'http://api.test/api' },
    },
    {
      command: `npm run dev -- --port ${REALTIME_PORT} --strictPort`,
      url: `http://localhost:${REALTIME_PORT}`,
      reuseExistingServer: !process.env.CI,
      env: {
        VITE_API_BASE_URL: 'http://api.test/api',
        VITE_PUSHER_APP_KEY: 'e2e-key',
        VITE_PUSHER_APP_CLUSTER: 'ap3',
        VITE_CACHE_DIR: 'node_modules/.vite-realtime',
      },
    },
    {
      command: 'node e2e/helpers/mockApiServer.mjs',
      url: `http://localhost:${MOCK_API_PORT}/api/health`,
      reuseExistingServer: !process.env.CI,
    },
    {
      command: `npx vite build --outDir dist-e2e --emptyOutDir && npx vite preview --outDir dist-e2e --port ${PWA_PORT} --strictPort`,
      url: `http://localhost:${PWA_PORT}`,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: { VITE_API_BASE_URL: `http://localhost:${MOCK_API_PORT}/api`, VITE_CACHE_DIR: 'node_modules/.vite-pwa' },
    },
  ],
});
