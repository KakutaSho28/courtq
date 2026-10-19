import { expect, test, type Page } from '@playwright/test';

/**
 * PWA の検証。本番ビルド（Service Worker 入り）を vite preview で配信し、実際の HTTP モック API につなぐ。
 * オフラインは context.setOffline() で再現する。
 */
test.use({ baseURL: 'http://localhost:5176' });

const MOCK_API = 'http://localhost:5180';

// モック API は 1 つで、/__down による切断を全テストで共有する。同じファイルのテストを別々のワーカーで並列に動かすと
// 互いの切断に巻き込まれるため、必ず 1 ワーカーで直列に動かす（--repeat-each を使うときも --workers=1）
test.describe.configure({ mode: 'serial' });

// 失敗してもモック API を落としたままにしない
test.afterEach(async ({ request }) => {
  await request.get(`${MOCK_API}/__down?on=0`);
});

/** ログイン済みの状態にする */
async function loginAsAlice(page: Page) {
  await page.addInitScript(() => {
    if (!localStorage.getItem('courtq_token')) {
      localStorage.setItem('courtq_token', 'alice-token');
      localStorage.setItem('courtq_user', JSON.stringify({ id: 1, name: 'アリス', email: 'a@example.com' }));
    }
  });
}

/** Service Worker が有効化され、このページを制御している状態にする */
async function waitForServiceWorker(page: Page) {
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  await expect
    .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
    .toBe(true);
}

async function cachedUrls(page: Page): Promise<Record<string, string[]>> {
  return page.evaluate(async () => {
    const result: Record<string, string[]> = {};
    for (const name of await caches.keys()) {
      const cache = await caches.open(name);
      result[name] = (await cache.keys()).map((request) => request.url);
    }
    return result;
  });
}

test('マニフェストとアイコンがインストール要件を満たす', async ({ page, request }) => {
  await page.goto('/login');

  await expect(page.locator('link[rel="manifest"]')).toHaveCount(1);
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#14213D');
  await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', '/icons/apple-touch-icon.png');

  const manifest = await (await request.get('/manifest.webmanifest')).json();
  expect(manifest).toMatchObject({
    name: 'CourtQ',
    short_name: 'CourtQ',
    display: 'standalone',
    start_url: '/',
    theme_color: '#14213D',
    background_color: '#14213D',
  });

  const sizes = manifest.icons.map((icon: { sizes: string; purpose: string }) => `${icon.sizes}:${icon.purpose}`);
  expect(sizes).toEqual(expect.arrayContaining(['192x192:any', '512x512:any', '512x512:maskable']));
  for (const icon of manifest.icons as { src: string; type: string }[]) {
    const res = await request.get(icon.src);
    expect(res.status(), icon.src).toBe(200);
    expect(res.headers()['content-type']).toContain(icon.type);
  }
  expect((await request.get('/icons/apple-touch-icon.png')).status()).toBe(200);
});

test('Service Worker が登録・有効化され、ページを制御する', async ({ page }) => {
  await page.goto('/login');
  await waitForServiceWorker(page);

  const scope = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope);
  expect(scope).toBe('http://localhost:5176/');
});

test('オフラインでもアプリが起動し、前回取得した内容がキャッシュから表示され、バナーが出る', async ({ page, context, request }) => {
  await loginAsAlice(page);
  await page.goto('/');
  await waitForServiceWorker(page);
  await page.reload(); // Service Worker の制御下で API を取得し、キャッシュさせる
  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
  await expect(page.getByText('北浜ウィングス')).toBeVisible();
  await expect.poll(async () => (await cachedUrls(page))['courtq-api']?.some((u) => u.includes('/api/games'))).toBe(true);
  await expect(page.getByRole('alert')).toHaveCount(0);

  // ネットワーク断を再現する。ページ側は setOffline、Service Worker の通信はサーバーを落として切る
  await request.get(`${MOCK_API}/__down?on=1`);
  await context.setOffline(true);
  await page.reload();

  // アプリシェルが開き、前回の内容が表示される。オフラインであることが分かる
  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
  await expect(page.getByText('北浜ウィングス')).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('オフラインです');
  await expect(page.getByRole('alert')).toContainText('最後に取得した内容');

  // 接続が戻るとバナーが消える
  await request.get(`${MOCK_API}/__down?on=0`);
  await context.setOffline(false);
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('API の GET だけがキャッシュされ、POST（ログアウト）はキャッシュされない', async ({ page }) => {
  await loginAsAlice(page);
  await page.goto('/');
  await waitForServiceWorker(page);
  await page.reload();
  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();

  await page.evaluate(async (api) => {
    await fetch(`${api}/api/auth/logout`, { method: 'POST' });
  }, MOCK_API);

  const all = Object.values(await cachedUrls(page)).flat();
  expect(all.some((u) => u.includes('/api/me'))).toBe(true);
  expect(all.filter((u) => u.includes('/api/auth/logout'))).toEqual([]);

  // API キャッシュは短期（5分）の NetworkFirst 用の専用キャッシュに入る
  expect(Object.keys(await cachedUrls(page))).toContain('courtq-api');
});

test('ログアウトすると API キャッシュが消える（別ユーザーのデータを残さない）', async ({ page }) => {
  await loginAsAlice(page);
  await page.goto('/');
  await waitForServiceWorker(page);
  await page.reload();
  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
  await expect.poll(async () => (await cachedUrls(page))['courtq-api']?.length ?? 0).toBeGreaterThan(0);

  await page.getByRole('button', { name: 'ログアウト' }).click();
  await expect(page).toHaveURL(/\/login$/);

  await expect.poll(async () => (await cachedUrls(page))['courtq-api'] ?? []).toEqual([]);
});

test('「ホーム画面に追加」ボタンは、インストール可能になったときだけ表示され、押すとプロンプトが出る', async ({ page }) => {
  await loginAsAlice(page);
  await page.goto('/');
  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'ホーム画面に追加' })).toHaveCount(0);

  // ブラウザが発火する beforeinstallprompt を再現する
  await page.evaluate(() => {
    const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
      prompt: () => Promise<void>;
      userChoice: Promise<{ outcome: string }>;
    };
    event.prompt = async () => {
      (window as unknown as { __prompted: boolean }).__prompted = true;
    };
    event.userChoice = Promise.resolve({ outcome: 'accepted' });
    window.dispatchEvent(event);
  });

  const button = page.getByRole('button', { name: 'ホーム画面に追加' });
  await expect(button).toBeVisible();
  await button.click();

  await expect.poll(() => page.evaluate(() => (window as unknown as { __prompted?: boolean }).__prompted)).toBe(true);
  await expect(button).toHaveCount(0);
});

/** 試合 → ブース一覧 → ブース詳細を開いて、API のレスポンスをキャッシュさせる */
async function visitBoothScreens(page: Page) {
  await loginAsAlice(page);
  await page.goto('/');
  await waitForServiceWorker(page);
  await page.goto('/games/1');
  await expect(page.getByRole('heading', { level: 1, name: /北浜ウィングス/ })).toBeVisible();
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();
  await page.goto('/games/1/booths/2');
  await expect(page.getByRole('heading', { level: 1, name: 'フリースロー・カレー' })).toBeVisible();
  await expect(page.getByText('ビーフカレー')).toBeVisible();
  await expect
    .poll(async () => {
      const urls = (await cachedUrls(page))['courtq-api'] ?? [];
      return ['/api/games/1', '/api/games/1/booths?', '/api/games/1/booths/2', '/congestion-history'].every((p) => urls.some((u) => u.includes(p)));
    })
    .toBe(true);
}

test('オフラインでも、前に開いたブース一覧と詳細が表示される（バナーあり・混雑の文言つき）', async ({ page, context, request }) => {
  await visitBoothScreens(page);

  await request.get(`${MOCK_API}/__down?on=1`);
  await context.setOffline(true);

  // ブース一覧（デフォルトの並び順で開いたもの）
  await page.goto('/games/1');
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();
  await expect(page.getByText('空いている').first()).toBeVisible();
  await expect(page.getByText('混雑', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'オフラインです' })).toBeVisible();

  // ブース詳細（メニューの在庫も）
  await page.goto('/games/1/booths/2');
  await expect(page.getByRole('heading', { level: 1, name: 'フリースロー・カレー' })).toBeVisible();
  await expect(page.getByText('ビーフカレー')).toBeVisible();
  await expect(page.getByText('売り切れ').first()).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'オフラインです' })).toBeVisible();
});

test('オフラインで時間がたつと、キャッシュ表示でも「情報が古い可能性があります」が出る', async ({ page, context, request }) => {
  await visitBoothScreens(page);
  await page.goto('/games/1');
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();
  await expect(page.getByText('情報が古い可能性があります')).toHaveCount(0);

  await request.get(`${MOCK_API}/__down?on=1`);
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();

  // 画面を開いたまま 20 分たったことにする（しきい値は 15 分。表示は画面側で計算し直される）
  await page.clock.install({ time: new Date(Date.now()) });
  await page.reload();
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();
  await expect(page.getByText('情報が古い可能性があります')).toHaveCount(0);
  await page.clock.fastForward('20:00');
  await expect(page.getByText('情報が古い可能性があります').first()).toBeVisible();
});

test('オフラインで、まだ開いたことのない絞り込み・ブースは、オフラインの案内を出す（真っ白にならない）', async ({ page, context, request }) => {
  await visitBoothScreens(page);

  await request.get(`${MOCK_API}/__down?on=1`);
  await context.setOffline(true);

  await page.goto('/games/1/booths/1');
  await expect(page.getByText('オフラインのため')).toBeVisible();
  await expect(page.getByRole('button', { name: '再読み込み' })).toBeVisible();
});

test('接続が戻ると、ブース一覧が自動で取り直される', async ({ page, context, request }) => {
  await visitBoothScreens(page);
  await page.goto('/games/1');
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();

  await request.get(`${MOCK_API}/__down?on=1`);
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByRole('alert').filter({ hasText: 'オフラインです' })).toBeVisible();

  await request.get(`${MOCK_API}/__down?on=0`);
  await context.setOffline(false);
  await expect(page.getByRole('alert').filter({ hasText: 'オフラインです' })).toHaveCount(0);
  await expect(page.getByText('ダンク・ポップコーン')).toBeVisible();
});
