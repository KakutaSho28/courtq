import { expect, test, type Page } from '@playwright/test';
import { fakePusher } from './helpers/fakePusher';

/**
 * リアルタイム接続（偽の Pusher サーバー）。ゲームごとの公開チャンネルの購読とイベント受信は WP-B8 で追加する。
 * ここでは「接続状態のバッジ」「キー未設定なら無効」「切断→再接続」の土台を確認する。
 */
const API = 'http://api.test/api';
const ALICE = { id: 1, name: 'アリス', email: 'a@example.com' };

async function openHomeAsAlice(page: Page, baseURL: string) {
  await page.addInitScript(() => {
    localStorage.setItem('courtq_token', 'alice-token');
    localStorage.setItem('courtq_user', JSON.stringify({ id: 1, name: 'アリス', email: 'a@example.com' }));
  });
  const authRequests: string[] = [];
  await page.route(`${API}/**`, (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/broadcasting/auth')) authRequests.push(path);
    return route.fulfill({ json: { data: ALICE } });
  });
  await page.goto(`${baseURL}/`);
  return authRequests;
}

test.describe('リアルタイム（偽の Pusher サーバー）', () => {
  test.use({ baseURL: 'http://localhost:5175' });

  test('接続中と表示され、チャンネル認証 API は呼ばれない（公開チャンネルのみ使う）', async ({ page }) => {
    await fakePusher(page);
    const authRequests = await openHomeAsAlice(page, 'http://localhost:5175');

    await expect(page.getByRole('status')).toHaveText('リアルタイム接続中');
    expect(authRequests).toEqual([]);
  });

  test('切断すると表示が変わり、再接続すると元に戻る', async ({ page }) => {
    // バッジの表示状態の履歴を記録する（切断は一瞬なので、現在値ではなく履歴で確認する）
    await page.addInitScript(() => {
      const w = window as unknown as { __badge: string[] };
      w.__badge = [];
      new MutationObserver(() => {
        const el = document.querySelector('.realtime-badge');
        if (el) w.__badge.push(el.className.replace('realtime-badge ', ''));
      }).observe(document, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
    });
    const pusher = await fakePusher(page);
    await openHomeAsAlice(page, 'http://localhost:5175');
    await expect(page.getByRole('status')).toHaveText('リアルタイム接続中');

    pusher.drop();

    await expect
      .poll(() => page.evaluate(() => (window as unknown as { __badge: string[] }).__badge))
      .toContain('realtime-connecting');
    await expect(page.getByRole('status')).toHaveText('リアルタイム接続中');
  });
});

test('Pusher のキー未設定の環境ではバッジを出さず、通常どおり動作する', async ({ page }) => {
  await openHomeAsAlice(page, 'http://localhost:5174');

  await expect(page.getByRole('heading', { name: 'CourtQ' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveCount(0);
});
