import { expect, test, type Page } from '@playwright/test';
import { NOW, mockCourtqApi } from './helpers/courtqApi';

/**
 * 利用者の画面 S03〜S06（試合一覧・ブース一覧・ブース詳細・お気に入り）。API はモック、時刻は NOW に固定する。
 */
test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(NOW);
});

const card = (page: Page, name: string) => page.getByRole('listitem').filter({ has: page.getByText(name, { exact: true }) });

test('試合一覧: 本日の試合が先頭に出て、選ぶとその試合のブース一覧が開く', async ({ page }) => {
  await mockCourtqApi(page);
  await page.goto('/');

  await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
  const today = page.getByRole('region', { name: '本日の試合' });
  await expect(today).toContainText('北浜ウィングス');
  await expect(today).toContainText('10/16(金) 19:05');
  await expect(today).toContainText('開催中');
  await expect(page.getByRole('region', { name: 'これからの試合' })).toContainText('10/23(金) 14:05');

  await today.getByRole('link').click();
  await expect(page).toHaveURL(/\/games\/1$/);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('北浜ウィングス');
  await expect(page).toHaveTitle('北浜ウィングス vs 南ヶ丘ブレイカーズ のブース | CourtQ');
});

test('ブース一覧: 混雑は色だけでなく文言で出し、待ち時間・最終更新・古い情報の注意を表示する', async ({ page }) => {
  const calls = await mockCourtqApi(page);
  await page.goto('/games/1');

  // 既定は空いている順
  await expect.poll(() => calls.find((c) => c.path === '/games/1/booths')?.query).toBe('?sort=congestion');

  await expect(card(page, 'ダンク・ポップコーン')).toContainText('空いている');
  await expect(card(page, 'ダンク・ポップコーン')).toContainText('待ち時間なし');
  await expect(card(page, 'ダンク・ポップコーン')).toContainText('最終更新 2分前');
  await expect(card(page, 'フリースロー・カレー')).toContainText('やや混雑');
  await expect(card(page, 'フリースロー・カレー')).toContainText('待ち 約8分');
  await expect(card(page, 'ファーストブレイク・バーガー')).toContainText('混雑');
  await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('非常に混雑');
  await expect(card(page, 'ハーフタイム・ドリンク')).toContainText('在庫のあるメニューなし');
  await expect(card(page, 'ウィングス・グッズ東')).toContainText('未更新');
  await expect(card(page, 'ウィングス・グッズ東')).toContainText('まだ更新されていません');
  await expect(card(page, 'ウィングス・グッズ西')).toContainText('営業していません');

  // 20 分前の更新だけが「情報が古い可能性があります」（しきい値 15 分）。閉店中のブースには出さない
  await expect(page.getByText('情報が古い可能性があります')).toHaveCount(1);
  await expect(card(page, 'フリースロー・カレー')).toContainText('情報が古い可能性があります');
});

test('混雑の色: 「混雑」の橙はブランドのオレンジと別の色で、段階ごとに違う色', async ({ page }) => {
  await mockCourtqApi(page);
  await page.goto('/games/1');

  const bg = (level: number) =>
    page.locator(`.congestion-badge[data-level="${level}"]`).first().evaluate((el) => getComputedStyle(el).backgroundColor);
  const brand = await page.locator('.tab.is-active').evaluate((el) => getComputedStyle(el).borderBottomColor);

  expect(brand).toBe('rgb(255, 122, 26)');
  expect(await bg(3)).toBe('rgb(194, 65, 12)');
  expect(new Set([await bg(1), await bg(2), await bg(3), await bg(4), brand]).size).toBe(5);
});

test('ブース一覧: 並び順・カテゴリ・在庫ありの絞り込みが API と URL に反映される', async ({ page }) => {
  const calls = await mockCourtqApi(page);
  await page.goto('/games/1');
  await expect(card(page, 'ダンク・ポップコーン')).toBeVisible();

  await page.getByRole('group', { name: 'カテゴリ' }).getByRole('button', { name: '物販' }).click();
  await expect(page).toHaveURL(/category=merch/);
  await expect(card(page, 'ダンク・ポップコーン')).toHaveCount(0);
  await expect(card(page, 'ウィングス・グッズ東')).toBeVisible();
  await expect(page.getByRole('group', { name: 'カテゴリ' }).getByRole('button', { name: '物販' })).toHaveAttribute('aria-pressed', 'true');

  await page.getByRole('group', { name: '並び順' }).getByRole('button', { name: '待ち時間順' }).click();
  await page.getByRole('button', { name: '在庫ありのみ' }).click();

  await expect(page).toHaveURL(/sort=wait/);
  await expect(page).toHaveURL(/in_stock=1/);
  await expect
    .poll(() => calls.filter((c) => c.path === '/games/1/booths').at(-1)?.query)
    .toBe('?sort=wait&category=merch&in_stock=1');

  // URL を開き直しても同じ条件で表示される（戻る・共有に強い）
  await page.reload();
  await expect(page.getByRole('button', { name: '在庫ありのみ' })).toHaveAttribute('aria-pressed', 'true');
});

test('お気に入り: 一覧の ☆ で追加し、お気に入りの画面に今日の混雑と一緒に出る。外すと消える', async ({ page }) => {
  const calls = await mockCourtqApi(page);
  await page.goto('/games/1');

  const star = page.getByRole('button', { name: 'ダンク・ポップコーンをお気に入りに追加' });
  await star.click();
  await expect(page.getByRole('button', { name: 'ダンク・ポップコーンをお気に入りから外す' })).toHaveAttribute('aria-pressed', 'true');
  expect(calls).toContainEqual({ method: 'PUT', path: '/booths/1/favorite', query: '' });

  await page.getByRole('link', { name: 'お気に入り' }).click();
  await expect(page.getByRole('heading', { name: 'お気に入り', level: 1 })).toBeVisible();
  const today = page.getByRole('region', { name: /本日の試合/ });
  await expect(card(page, 'ダンク・ポップコーン')).toContainText('空いている');
  await expect(today).toContainText('フリースロー・カレー');

  await page.getByRole('button', { name: 'ダンク・ポップコーンをお気に入りから外す' }).click();
  await expect(card(page, 'ダンク・ポップコーン')).toHaveCount(0);
  expect(calls).toContainEqual({ method: 'DELETE', path: '/booths/1/favorite', query: '' });
});

test('お気に入り: 通信に失敗したら元に戻してメッセージを出す', async ({ page }) => {
  await mockCourtqApi(page, {
    override: async (path, route) => {
      if (path === '/booths/1/favorite') {
        await route.fulfill({ status: 500, json: { message: 'エラー' } });
        return true;
      }
      return false;
    },
  });
  await page.goto('/games/1');

  await page.getByRole('button', { name: 'ダンク・ポップコーンをお気に入りに追加' }).click();

  await expect(page.getByRole('alert')).toContainText('お気に入りを変更できませんでした');
  await expect(page.getByRole('button', { name: 'ダンク・ポップコーンをお気に入りに追加' })).toHaveAttribute('aria-pressed', 'false');
});

test('ブース詳細: メニューを飲食・物販に分け、在庫（売り切れはグレー）と最終更新、混雑の推移を出す', async ({ page }) => {
  await mockCourtqApi(page);
  await page.goto('/games/1');
  await card(page, 'フリースロー・カレー').getByRole('link').click();

  await expect(page).toHaveURL(/\/games\/1\/booths\/2$/);
  await expect(page.getByRole('heading', { name: 'フリースロー・カレー', level: 1 })).toBeVisible();
  const status = page.getByRole('region', { name: 'いまの混雑' });
  await expect(status).toContainText('やや混雑');
  await expect(status).toContainText('待ち 約8分');
  await expect(status).toContainText('最終更新 20分前');
  await expect(status).toContainText('情報が古い可能性があります');

  const food = page.getByRole('region', { name: '飲食' });
  const merch = page.getByRole('region', { name: '物販' });
  await expect(food.getByRole('listitem')).toHaveCount(3);
  await expect(merch.getByRole('listitem')).toHaveCount(1);
  await expect(food.getByRole('listitem').filter({ hasText: 'ビーフカレー' })).toContainText('在庫あり');
  await expect(food.getByRole('listitem').filter({ hasText: 'カツカレー' })).toContainText('残りわずか（残り4）');
  await expect(food.getByRole('listitem').filter({ hasText: 'カツカレー' })).toContainText('情報が古い可能性があります');
  const soldOut = food.getByRole('listitem').filter({ hasText: 'ナン' });
  await expect(soldOut).toContainText('売り切れ');
  await expect(soldOut).toHaveClass(/is-sold-out/);
  await expect(merch).toContainText('在庫情報なし');
  await expect(page.getByText('¥1,800')).toBeVisible();

  // 混雑の推移: 3 点を描き、表でも同じ内容が読める
  const chart = page.getByRole('img', { name: /混雑の推移/ });
  await expect(chart).toBeVisible();
  await expect(chart.locator('circle')).toHaveCount(3);
  await chart.locator('.chart-hit').hover({ position: { x: 20, y: 60 } });
  await expect(page.locator('.chart-tooltip')).toContainText('空いている');
  await page.getByText('表で見る').click();
  await expect(page.locator('.chart-table tbody tr')).toHaveCount(3);
  await expect(page.locator('.chart-table tbody tr').first()).toContainText('やや混雑');

  // 詳細からもお気に入りを切り替えられる（フリースロー・カレーは登録済み）
  await expect(page.getByRole('button', { name: 'お気に入り登録済み' })).toHaveAttribute('aria-pressed', 'true');
});

test('時間がたつと、サーバーの値によらず画面側で「情報が古い可能性があります」を出す', async ({ page }) => {
  await mockCourtqApi(page);
  await page.goto('/games/1');
  await expect(card(page, 'ダンク・ポップコーン')).toContainText('最終更新 2分前');
  await expect(card(page, 'ダンク・ポップコーン')).not.toContainText('情報が古い可能性があります');

  // 20 分後に開き直す（モックは同じ応答のまま。サーバーの is_stale は false）
  await page.clock.setFixedTime(new Date(NOW.getTime() + 20 * 60_000));
  await page.reload();

  await expect(card(page, 'ダンク・ポップコーン')).toContainText('最終更新 22分前');
  await expect(card(page, 'ダンク・ポップコーン')).toContainText('情報が古い可能性があります');
});

test('読み込み中の表示が出てから、一覧に切り替わる', async ({ page }) => {
  let release: () => void = () => undefined;
  const gate = new Promise<void>((resolve) => (release = resolve));
  await mockCourtqApi(page, {
    override: async (path) => {
      // 返すのを待たせてから、いつもの応答（モックの既定）に任せる
      if (path === '/games/1/booths') await gate;
      return false;
    },
  });
  await page.goto('/games/1');

  await expect(page.getByText('ブースを読み込み中...')).toBeVisible();
  release();
  await expect(card(page, 'ダンク・ポップコーン')).toBeVisible();
  await expect(page.getByText('ブースを読み込み中...')).toHaveCount(0);
});

test('読み込みに失敗したらメッセージと再読み込みボタンを出し、押すと読み直す', async ({ page }) => {
  let fail = true;
  await mockCourtqApi(page, {
    override: async (path, route) => {
      if (path === '/games/1/booths' && fail) {
        await route.fulfill({ status: 500, json: { message: 'サーバーでエラーが発生しました' } });
        return true;
      }
      return false;
    },
  });
  await page.goto('/games/1');

  await expect(page.getByRole('alert')).toContainText('ブースを読み込めませんでした');
  await expect(page.getByRole('alert')).toContainText('サーバーでエラーが発生しました');
  fail = false;
  await page.getByRole('button', { name: 'もう一度読み込む' }).click();
  await expect(card(page, 'ダンク・ポップコーン')).toBeVisible();
});

test('存在しない試合は「見つかりません」を出す', async ({ page }) => {
  await mockCourtqApi(page);
  await page.goto('/games/999');

  await expect(page.getByRole('alert')).toContainText('試合が見つかりません。');
});

test('オフラインで開くと、オフラインの案内とバナーを出す', async ({ page, context }) => {
  await mockCourtqApi(page, {
    override: async (path, route) => {
      if (path.startsWith('/games')) {
        await route.abort('internetdisconnected');
        return true;
      }
      return false;
    },
  });
  await page.goto('/login'); // アプリを読み込んでからオフラインにする
  await context.setOffline(true);
  await page.evaluate(() => {
    history.pushState({}, '', '/games/1');
    dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.getByText('オフラインのため試合を表示できません。')).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'オフラインです' })).toBeVisible();
  await context.setOffline(false);
});

test.describe('スマホの画面幅（360px）', () => {
  test.use({ viewport: { width: 360, height: 740 }, hasTouch: true });

  test('横にはみ出さず、ボタンは 44px 以上の高さがある', async ({ page }) => {
    await mockCourtqApi(page);
    for (const path of ['/', '/games/1', '/games/1/booths/2', '/favorites']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expect(page.locator('.spinner')).toHaveCount(0);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${path} が横にはみ出しています`).toBeLessThanOrEqual(0);
    }

    await page.goto('/games/1');
    await expect(card(page, 'ダンク・ポップコーン')).toBeVisible();
    const heights = await page
      .locator('.segmented-btn, .toggle-btn, .favorite-btn, .tab')
      .evaluateAll((els) => els.map((el) => el.getBoundingClientRect().height));
    expect(heights.length).toBeGreaterThan(5);
    expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);
  });
});
