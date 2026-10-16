import { expect, test, type Page } from '@playwright/test';
import { fakePusher } from './helpers/fakePusher';
import { NOW, NOW_ISO, booths, mockCourtqApi, type Recorded } from './helpers/courtqApi';

/**
 * ゲームごとの公開チャンネル game.{gameId} のリアルタイム更新（偽の Pusher サーバー）。
 * Pusher のキーを設定した dev サーバー（ポート 5175）を使う。API はモック、時刻は NOW に固定する。
 */
test.use({ baseURL: 'http://localhost:5175' });

test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(NOW);
});

const card = (page: Page, name: string) => page.getByRole('listitem').filter({ has: page.getByText(name, { exact: true }) });
const boothCalls = (calls: Recorded[]) => calls.filter((c) => c.path === '/games/1/booths').length;

/** ブース 4（ハーフタイム・ドリンク）の混雑の更新イベント */
const congestionEvent = (overrides: Record<string, unknown> = {}) => ({
  game_id: 1,
  booth_id: 4,
  game_booth_id: 14,
  level: 1,
  label: '空いている',
  wait_minutes: 0,
  updated_at: NOW_ISO,
  ...overrides,
});

const stockEvent = (overrides: Record<string, unknown> = {}) => ({
  game_id: 1,
  booth_id: 2,
  menu_item_id: 21,
  status: 'sold_out',
  label: '売り切れ',
  quantity: 0,
  updated_at: NOW_ISO,
  ...overrides,
});

async function open(page: Page, path: string, options: Parameters<typeof mockCourtqApi>[1] = {}) {
  const pusher = await fakePusher(page);
  const calls = await mockCourtqApi(page, options);
  await page.goto(path);
  return { pusher, calls };
}

test.describe('S04 ブース一覧', () => {
  test('game.{id} の公開チャンネルを購読し（認証なし）、混雑の更新を取り直さずに反映して並べ替える', async ({ page }) => {
    const authCalls: string[] = [];
    await page.route('**/broadcasting/auth', (route) => {
      authCalls.push(route.request().url());
      return route.abort();
    });
    const { pusher, calls } = await open(page, '/games/1');
    await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('非常に混雑');
    await expect.poll(() => pusher.subscribed).toContain('game.1');
    expect(pusher.subscribed.filter((c) => c !== 'game.1')).toEqual([]); // 購読するのはこの試合のチャンネルだけ
    expect(pusher.subscribed.every((c) => !c.startsWith('private-') && !c.startsWith('presence-'))).toBe(true);
    const before = boothCalls(calls);
    // 空いている順: ダンク(空)・フリースロー(やや)・バーガー(混雑)・ドリンク(非常に混雑)
    await expect(page.locator('.booth-card .booth-name')).toHaveText(['ダンク・ポップコーン', 'フリースロー・カレー', 'ファーストブレイク・バーガー', 'ハーフタイム・ドリンク', 'ウィングス・グッズ東', 'ウィングス・グッズ西']);

    pusher.push('game.1', 'booth.congestion.updated', congestionEvent());

    const drink = card(page, 'ハーフタイム・ドリンク');
    await expect(drink).toContainText('空いている');
    await expect(drink).toContainText('待ち時間なし');
    await expect(drink).toContainText('最終更新 たった今');
    // 空いている順に並び替わる（ダンク → ドリンク → フリースロー…）。API は取り直していない
    await expect(page.locator('.booth-card .booth-name')).toHaveText(['ダンク・ポップコーン', 'ハーフタイム・ドリンク', 'フリースロー・カレー', 'ファーストブレイク・バーガー', 'ウィングス・グッズ東', 'ウィングス・グッズ西']);
    expect(boothCalls(calls)).toBe(before);
    expect(authCalls).toEqual([]);
    // 気づけるよう短く強調し、読み上げにも伝える
    await expect(drink).toHaveClass(/is-updated/);
    await expect(page.getByRole('status').filter({ hasText: 'ハーフタイム・ドリンクの混雑が「空いている」に更新されました' })).toHaveCount(1);
    await expect(drink).not.toHaveClass(/is-updated/, { timeout: 6000 });
  });

  test('古い更新・別の試合の更新・並べ替えの対象外は無視する', async ({ page }) => {
    const { pusher } = await open(page, '/games/1');
    const drink = card(page, 'ハーフタイム・ドリンク');
    await expect(drink).toContainText('非常に混雑');
    await expect.poll(() => pusher.subscribed).toContain('game.1');

    // 現在値（1 分前）より古い更新（順序が前後した）
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ updated_at: new Date(NOW.getTime() - 60 * 60_000).toISOString() }));
    // 別の試合の更新（game_id が違う）
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ game_id: 2 }));
    // この一覧にないブース
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ booth_id: 999 }));
    // 最後に、有効な更新（ここまでの無視が済んだことの目印）
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ booth_id: 3, level: 1, label: '空いている', wait_minutes: 0 }));

    await expect(card(page, 'ファーストブレイク・バーガー')).toContainText('空いている');
    await expect(drink).toContainText('非常に混雑');
    await expect(drink).toContainText('待ち 約30分');
  });

  test('在庫の更新は、まとめて 1 回だけ取り直して反映する', async ({ page }) => {
    let changed = false;
    const { pusher, calls } = await open(page, '/games/1', {
      override: async (path, route) => {
        if (path === '/games/1/booths' && changed) {
          const list = booths().map((b) => (b.booth_id === 4 ? { ...b, has_stock: true } : b));
          await route.fulfill({ json: { data: list, meta: { stale_after_minutes: 15 } } });
          return true;
        }
        return false;
      },
    });
    await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('在庫のあるメニューなし');
    await expect.poll(() => pusher.subscribed).toContain('game.1');
    const before = boothCalls(calls);

    changed = true;
    for (let i = 0; i < 3; i++) pusher.push('game.1', 'item.stock.updated', stockEvent({ booth_id: 4, menu_item_id: 40 + i, status: 'in_stock', label: '在庫あり' }));

    await expect(card(page, 'ハーフタイム・ドリンク')).not.toContainText('在庫のあるメニューなし');
    expect(boothCalls(calls)).toBe(before + 1); // 3 回のイベントを 1 回の取り直しにまとめた
  });

  test('切断して再接続すると、一覧を取り直して、切断中に取りこぼした更新を補う', async ({ page }) => {
    let missed = false;
    const { pusher, calls } = await open(page, '/games/1', {
      override: async (path, route) => {
        if (path === '/games/1/booths' && missed) {
          // 切断中にサーバーで起きた更新（イベントは届かなかった）
          const list = booths().map((b) => (b.booth_id === 4 ? { ...b, congestion: { level: 2, label: 'やや混雑', wait_minutes: 7, updated_at: NOW_ISO, is_stale: false } } : b));
          await route.fulfill({ json: { data: list, meta: { stale_after_minutes: 15 } } });
          return true;
        }
        return false;
      },
    });
    await expect(page.locator('.realtime-badge')).toHaveText('リアルタイム接続中');
    await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('非常に混雑');
    const before = boothCalls(calls);
    const connectionsBefore = pusher.connections();

    missed = true;
    pusher.drop();

    await expect.poll(() => pusher.connections(), { timeout: 20_000 }).toBeGreaterThan(connectionsBefore);
    await expect(page.locator('.realtime-badge')).toHaveText('リアルタイム接続中');
    await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('やや混雑');
    await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('待ち 約7分');
    expect(boothCalls(calls)).toBeGreaterThan(before);
  });

  test('画面を離れると購読をやめ、別の試合を開くとその試合のチャンネルを購読する', async ({ page }) => {
    const { pusher } = await open(page, '/games/1');
    await expect.poll(() => pusher.subscribed).toContain('game.1');

    await page.getByRole('link', { name: '← 試合を選ぶ' }).click(); // 画面を読み込み直さない移動
    await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
    await expect.poll(() => pusher.unsubscribed).toContain('game.1');

    await page.getByRole('region', { name: 'これからの試合' }).getByRole('link').click();
    await expect(page).toHaveURL(/\/games\/2$/);
    await expect.poll(() => pusher.subscribed).toContain('game.2');
    expect(pusher.subscribed.filter((c) => c === 'game.1')).toHaveLength(1); // 前の試合は購読し直していない
  });
});

test.describe('S05 ブース詳細', () => {
  test('このブースの混雑・在庫の更新を反映し、推移グラフにも点を足す。他のブースの更新は無視する', async ({ page }) => {
    const { pusher } = await open(page, '/games/1/booths/2');
    const panel = page.getByRole('region', { name: 'いまの混雑' });
    await expect(panel).toContainText('やや混雑');
    const chart = page.getByRole('img', { name: /混雑の推移/ });
    await expect(chart.locator('circle')).toHaveCount(3);
    await expect.poll(() => pusher.subscribed).toContain('game.1');

    // 他のブースの更新は無視する
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ booth_id: 4 }));
    pusher.push('game.1', 'item.stock.updated', stockEvent({ booth_id: 4 }));
    // このブースの更新
    pusher.push('game.1', 'booth.congestion.updated', congestionEvent({ booth_id: 2, level: 4, label: '非常に混雑', wait_minutes: 40 }));

    await expect(panel).toContainText('非常に混雑');
    await expect(panel).toContainText('待ち 約40分');
    await expect(panel).toContainText('最終更新 たった今');
    await expect(panel).not.toContainText('情報が古い可能性があります');
    await expect(panel).toHaveClass(/is-updated/);
    await expect(chart.locator('circle')).toHaveCount(4);

    // 在庫: ビーフカレー（21）が売り切れになる
    const curry = page.getByRole('listitem').filter({ hasText: 'ビーフカレー' });
    await expect(curry).toContainText('在庫あり');
    pusher.push('game.1', 'item.stock.updated', stockEvent());
    await expect(curry).toContainText('売り切れ');
    await expect(curry).toHaveClass(/is-sold-out/);
    await expect(curry).toContainText('最終更新 たった今');
    // 同じ画面の別のメニュー（カツカレー）はそのまま
    await expect(page.getByRole('listitem').filter({ hasText: 'カツカレー' })).toContainText('残りわずか（残り4）');
  });

  test('再接続すると、詳細と推移を取り直す', async ({ page }) => {
    const { pusher, calls } = await open(page, '/games/1/booths/2');
    await expect(page.getByRole('region', { name: 'いまの混雑' })).toContainText('やや混雑');
    await expect(page.locator('.realtime-badge')).toHaveText('リアルタイム接続中');
    const detailBefore = calls.filter((c) => c.path === '/games/1/booths/2').length;
    const historyBefore = calls.filter((c) => c.path.endsWith('/congestion-history')).length;
    const connectionsBefore = pusher.connections();

    pusher.drop();

    await expect.poll(() => pusher.connections(), { timeout: 20_000 }).toBeGreaterThan(connectionsBefore);
    await expect.poll(() => calls.filter((c) => c.path === '/games/1/booths/2').length).toBeGreaterThan(detailBefore);
    await expect.poll(() => calls.filter((c) => c.path.endsWith('/congestion-history')).length).toBeGreaterThan(historyBefore);
  });
});
