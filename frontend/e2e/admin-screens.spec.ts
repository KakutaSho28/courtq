import { expect, test, type Page } from '@playwright/test';
import { ADMIN, ALICE, NOW, SAKURA, mockCourtqApi } from './helpers/courtqApi';
import { createAdminMock } from './helpers/adminApi';

/**
 * 管理画面 S11〜S14 と、役割による出し分け。API はモック、時刻は NOW に固定する。
 * 本当の防御はサーバー側（認可のテストは backend の AuthorizationTest）。ここでは画面の振る舞いを確かめる。
 */
test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(NOW);
});

const mainTab = (page: Page, name: string) => page.getByRole('navigation', { name: 'メイン' }).getByRole('link', { name, exact: true });
const adminTab = (page: Page, name: string) => page.getByRole('navigation', { name: '管理' }).getByRole('link', { name, exact: true });

async function openAsAdmin(page: Page, path: string) {
  const admin = createAdminMock();
  const calls = await mockCourtqApi(page, { user: ADMIN, override: admin.override });
  await page.goto(path);
  return { admin, calls };
}

test.describe('役割による出し分け', () => {
  test('来場者とスタッフには管理が見えず、URL を直接開いても試合一覧に戻される', async ({ page }) => {
    for (const user of [ALICE, SAKURA]) {
      const calls = await mockCourtqApi(page, { user });
      await page.goto('/');
      await expect(mainTab(page, '管理')).toHaveCount(0);

      for (const path of ['/admin', '/admin/games', '/admin/booths', '/admin/games/1/booths', '/admin/booths/2/staff']) {
        await page.goto(path);
        await expect(page).toHaveURL(/\/$/);
        await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
      }
      // 管理用の API も呼ばれない（画面に入る前に戻されるため）
      expect(calls.filter((c) => c.path.startsWith('/admin/'))).toEqual([]);
      await page.unroute('http://api.test/api/**');
    }
  });

  test('管理者には「管理」のタブが出て、開ける', async ({ page }) => {
    await openAsAdmin(page, '/');

    await mainTab(page, '管理').click();
    await expect(page).toHaveURL(/\/admin\/games$/);
    await expect(page.getByRole('heading', { name: '管理' })).toBeVisible();
    await adminTab(page, 'ブース').click();
    await expect(page).toHaveURL(/\/admin\/booths$/);
  });

  test('/admin は試合の管理に移動する', async ({ page }) => {
    await openAsAdmin(page, '/admin');
    await expect(page).toHaveURL(/\/admin\/games$/);
  });
});

test.describe('S11 試合の管理', () => {
  test('試合の一覧に、開始日時・会場・出店ブースの数が出る', async ({ page }) => {
    await openAsAdmin(page, '/admin/games');

    const list = page.getByRole('region', { name: /登録済みの試合/ });
    await expect(list.getByText('北浜ウィングス')).toHaveCount(2);
    await expect(list.getByText(/出店 2 ブース/)).toBeVisible();
    await expect(list.getByText(/出店 0 ブース/)).toBeVisible();
    await expect(list.getByText('開催中')).toBeVisible();
  });

  test('試合を追加できる。入力が足りないと送らずに理由を出し、日時は日本時間（+09:00）で送る', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games');
    const form = page.locator('form').filter({ has: page.locator('#game-new-season') });

    await form.getByRole('button', { name: '追加する' }).click();
    await expect(form.getByRole('alert')).toContainText('シーズンを入力してください');
    expect(admin.requests.filter((r) => r.method === 'POST')).toEqual([]);

    await form.getByLabel('シーズン').fill('2026-27');
    await form.getByLabel('ホーム', { exact: true }).fill('東町ホークス');
    await form.getByLabel('アウェイ').fill('西浦サンダース');
    await form.getByRole('button', { name: '追加する' }).click();
    await expect(form.getByRole('alert')).toContainText('会場を選んでください');

    await form.getByLabel('会場', { exact: true }).selectOption({ label: 'サンプルアリーナ（架空）' });
    await form.getByLabel('開始日時（日本時間）').fill('2026-11-08T14:05');
    await form.getByRole('button', { name: '追加する' }).click();

    const list = page.getByRole('region', { name: /登録済みの試合/ });
    await expect(list.getByText('東町ホークス')).toBeVisible();
    const post = admin.requests.find((r) => r.method === 'POST' && r.path === '/admin/games');
    expect(post?.body).toMatchObject({ season: '2026-27', section: null, home_team: '東町ホークス', away_team: '西浦サンダース', venue_id: 1, tipoff_at: '2026-11-08T14:05:00+09:00', status: 'scheduled' });
    // 追加できたらフォームは空に戻る
    await expect(form.getByLabel('ホーム', { exact: true })).toHaveValue('');
  });

  test('同じ試合を登録しようとすると、サーバーの理由を出し、入力は残る', async ({ page }) => {
    await openAsAdmin(page, '/admin/games');
    const form = page.locator('form').filter({ has: page.locator('#game-new-season') });

    await form.getByLabel('シーズン').fill('2026-27');
    await form.getByLabel('節（任意）').fill('5');
    await form.getByLabel('ホーム', { exact: true }).fill('北浜ウィングス');
    await form.getByLabel('アウェイ').fill('南ヶ丘ブレイカーズ');
    await form.getByLabel('会場', { exact: true }).selectOption({ index: 1 });
    await form.getByLabel('開始日時（日本時間）').fill('2026-10-16T19:05');
    await form.getByRole('button', { name: '追加する' }).click();

    await expect(page.getByRole('alert').filter({ hasText: 'すでに登録されています' })).toBeVisible();
    await expect(form.getByLabel('アウェイ')).toHaveValue('南ヶ丘ブレイカーズ');
  });

  test('試合を編集して保存できる（状態を終了にする）', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games');
    await page.getByRole('listitem').filter({ hasText: '南ヶ丘ブレイカーズ vs 北浜ウィングス' }).getByRole('button', { name: '編集' }).click();
    // 編集中は行の文字が入力欄に変わるので、フォームで探す
    const form = page.locator('form').filter({ has: page.locator('#game-2-season') });
    await form.getByRole('button', { name: '終了', exact: true }).click();
    await form.getByRole('button', { name: '保存する' }).click();

    await expect(page.getByRole('listitem').filter({ hasText: '南ヶ丘ブレイカーズ vs 北浜ウィングス' }).getByText('終了')).toBeVisible();
    const put = admin.requests.find((r) => r.method === 'PUT' && r.path === '/admin/games/2');
    expect(put?.body).toMatchObject({ status: 'finished', tipoff_at: '2026-10-23T14:05:00+09:00' });
  });

  test('削除は確認してから。記録のある試合は削除できない理由を出し、残る', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games');

    // 記録のある試合 1
    const live = page.getByRole('listitem').filter({ hasText: '北浜ウィングス vs 南ヶ丘ブレイカーズ' });
    await live.getByRole('button', { name: '削除' }).click();
    expect(admin.requests.filter((r) => r.method === 'DELETE')).toEqual([]); // 1 回目では実行しない
    await live.getByRole('button', { name: '削除する' }).click();
    await expect(page.getByRole('alert').filter({ hasText: '記録がある試合は削除できません' })).toBeVisible();
    await expect(live).toBeVisible();

    // 記録のない試合 2 は消える
    const next = page.getByRole('listitem').filter({ hasText: '南ヶ丘ブレイカーズ vs 北浜ウィングス' });
    await next.getByRole('button', { name: '削除' }).click();
    await next.getByRole('button', { name: '削除する' }).click();
    await expect(page.getByRole('listitem').filter({ hasText: '南ヶ丘ブレイカーズ vs 北浜ウィングス' })).toHaveCount(0);
  });

  test('会場が無いときは、その場で会場を追加して選べる', async ({ page }) => {
    await openAsAdmin(page, '/admin/games');
    const form = page.locator('form').filter({ has: page.locator('#game-new-season') });

    await form.getByText('会場が見つからないとき').click();
    await form.getByLabel('新しい会場の名前').fill('みなと第二アリーナ（架空）');
    await form.getByRole('button', { name: '会場を追加する' }).click();

    await expect(form.getByLabel('会場', { exact: true })).toHaveValue('2');
    await expect(form.getByRole('option', { name: 'みなと第二アリーナ（架空）' })).toHaveCount(1);
  });

  test('出店ブースの設定へ移動できる', async ({ page }) => {
    await openAsAdmin(page, '/admin/games');
    await page.getByRole('listitem').filter({ hasText: '北浜ウィングス vs 南ヶ丘ブレイカーズ' }).getByRole('link', { name: '出店ブースを設定する' }).click();
    await expect(page).toHaveURL(/\/admin\/games\/1\/booths$/);
  });
});

test.describe('CSV 取り込み', () => {
  const csv = (content: string) => ({ name: 'games.csv', mimeType: 'text/csv', buffer: Buffer.from(content) });

  test('誤りのある行は全部表示し、取り込みボタンは出さない（何も保存しない）', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games');

    await page.getByLabel(/CSV ファイル/).setInputFiles(csv('season,section,date\nBAD'));
    await page.getByRole('button', { name: '内容を確認する' }).click();

    const errors = page.getByRole('alert').filter({ hasText: '2 件の誤りがあります' });
    await expect(errors).toBeVisible();
    await expect(errors.getByRole('listitem')).toHaveText([/^3 行目:/, /^4 行目: 会場「みなと第二」が登録されていません/]);
    await expect(page.getByRole('button', { name: /取り込む/ })).toHaveCount(0);
    expect(admin.requests.filter((r) => r.path === '/admin/games/import').length).toBe(1);
  });

  test('確認 → 取り込みの 2 段階。確認では保存せず、取り込むと一覧に増える', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games');
    const list = page.getByRole('region', { name: /登録済みの試合/ });
    await expect(list.getByText(/東町ホークス/)).toHaveCount(0);

    await page.getByLabel(/CSV ファイル/).setInputFiles(csv('season,section,date,tipoff_time,home_team,away_team,venue\nok'));
    await page.getByRole('button', { name: '内容を確認する' }).click();

    const preview = page.getByRole('region', { name: '取り込み内容の確認' });
    await expect(preview).toContainText('1 件を登録します');
    await expect(preview).toContainText('1 件は取り込まずに飛ばします');
    await expect(preview.getByText('登録', { exact: true })).toBeVisible();
    await expect(preview.getByText('飛ばす')).toBeVisible();
    await expect(preview).toContainText('すでに登録されています');
    await expect(list.getByText(/東町ホークス/)).toHaveCount(0); // まだ保存していない

    await preview.getByRole('button', { name: 'この内容で 1 件を取り込む' }).click();

    await expect(page.getByText('1 件を取り込みました')).toBeVisible();
    await expect(list.getByText('北浜ウィングス vs 東町ホークス')).toHaveCount(1);
    const imports = admin.requests.filter((r) => r.path === '/admin/games/import');
    expect(imports).toHaveLength(2);
    expect(String(imports[0].body)).toMatch(/name="dry_run"\r?\n\r?\n1/);
    expect(String(imports[1].body)).toMatch(/name="dry_run"\r?\n\r?\n0/);
  });
});

test.describe('S12 ブースの管理', () => {
  test('ブースを追加・編集できる', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/booths');
    const form = page.locator('form').filter({ has: page.locator('#booth-new-name') });

    await form.getByRole('button', { name: '追加する' }).click();
    await expect(form.getByRole('alert')).toContainText('ブース名を入力してください');

    await form.getByLabel('ブース名').fill('ハーフタイム・ドリンク');
    await form.getByRole('button', { name: '飲食・物販' }).click();
    await form.getByLabel('場所').fill('1F コンコース東');
    await form.getByRole('button', { name: '追加する' }).click();

    const row = page.getByRole('listitem').filter({ hasText: 'ハーフタイム・ドリンク' });
    await expect(row).toContainText('飲食・物販');
    expect(admin.requests.find((r) => r.method === 'POST' && r.path === '/admin/booths')?.body).toMatchObject({ name: 'ハーフタイム・ドリンク', category: 'both', location_label: '1F コンコース東', description: null });

    await row.getByRole('button', { name: '編集' }).click();
    const editForm = page.locator('form').filter({ has: page.locator('#booth-10-name') });
    await editForm.getByLabel('ブース名').fill('ハーフタイム・ドリンク東');
    await editForm.getByRole('button', { name: '保存する' }).click();
    await expect(page.getByRole('listitem').filter({ hasText: 'ハーフタイム・ドリンク東' })).toBeVisible();
  });

  test('試合に出店しているブースは削除できず、出店していないブースは確認のうえ削除できる', async ({ page }) => {
    await openAsAdmin(page, '/admin/booths');

    const assigned = page.getByRole('listitem').filter({ hasText: 'フリースロー・カレー' });
    await assigned.getByRole('button', { name: '削除' }).click();
    await assigned.getByRole('button', { name: '削除する' }).click();
    await expect(page.getByRole('alert').filter({ hasText: '試合に割り当て済みのブースは削除できません' })).toBeVisible();
    await expect(assigned).toBeVisible();

    const free = page.getByRole('listitem').filter({ hasText: 'ウィングス・グッズ東' });
    await free.getByRole('button', { name: '削除' }).click();
    await expect(free).toContainText('元に戻せません');
    await free.getByRole('button', { name: '削除する' }).click();
    await expect(page.getByRole('listitem').filter({ hasText: 'ウィングス・グッズ東' })).toHaveCount(0);
  });

  test('担当スタッフと招待コードの画面へ移動できる', async ({ page }) => {
    await openAsAdmin(page, '/admin/booths');
    await page.getByRole('listitem').filter({ hasText: 'フリースロー・カレー' }).getByRole('link', { name: '担当スタッフと招待コード' }).click();
    await expect(page).toHaveURL(/\/admin\/booths\/2\/staff$/);
  });
});

test.describe('S13 試合の出店ブース', () => {
  test('出店の追加と営業中 / 閉店中を切り替えて保存できる。変更が無いうちは保存できない', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/games/1/booths');
    const save = page.getByRole('button', { name: /変更/ });
    await expect(save).toBeDisabled();
    await expect(save).toHaveText('変更はありません');

    const popcorn = page.getByRole('listitem').filter({ hasText: 'ダンク・ポップコーン' });
    await popcorn.getByRole('checkbox', { name: '営業中' }).uncheck();
    await expect(popcorn.getByText('閉店中')).toBeVisible();
    const merch = page.getByRole('listitem').filter({ hasText: 'ウィングス・グッズ東' });
    await merch.getByRole('checkbox', { name: 'ウィングス・グッズ東' }).check();
    await expect(save).toHaveText('変更を保存する（出店 3 ブース）');
    await save.click();

    await expect(page.getByText('保存しました')).toBeVisible();
    const put = admin.requests.find((r) => r.method === 'PUT' && r.path === '/admin/games/1/booths');
    const sent = (put?.body as { booths: { booth_id: number; is_open: boolean }[] }).booths;
    expect([...sent].sort((a, b) => a.booth_id - b.booth_id)).toEqual([
      { booth_id: 1, is_open: false },
      { booth_id: 2, is_open: true },
      { booth_id: 3, is_open: true },
    ]);
    await expect(save).toBeDisabled();
  });

  test('記録のあるブースは試合から外せない（チェックが無効で理由が出る）', async ({ page }) => {
    await openAsAdmin(page, '/admin/games/1/booths');
    const curry = page.getByRole('listitem').filter({ hasText: 'フリースロー・カレー' });

    await expect(curry.getByRole('checkbox', { name: 'フリースロー・カレー' })).toBeDisabled();
    await expect(curry).toContainText('記録あり・外せません');
    // 閉店にはできる
    await expect(curry.getByRole('checkbox', { name: '営業中' })).toBeEnabled();
  });

  test('出店を外して保存できる（記録が無いブース）', async ({ page }) => {
    await openAsAdmin(page, '/admin/games/1/booths');
    await page.getByRole('listitem').filter({ hasText: 'ダンク・ポップコーン' }).getByRole('checkbox', { name: 'ダンク・ポップコーン' }).uncheck();
    await page.getByRole('button', { name: /変更を保存する/ }).click();
    await expect(page.getByText('保存しました')).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: 'ダンク・ポップコーン' }).getByRole('checkbox', { name: 'ダンク・ポップコーン' })).not.toBeChecked();
  });

  test('オフラインでは保存できず、理由を出す（成功したふりをしない）', async ({ page, context }) => {
    const { admin } = await openAsAdmin(page, '/admin/games/1/booths');
    await page.getByRole('listitem').filter({ hasText: 'ダンク・ポップコーン' }).getByRole('checkbox', { name: '営業中' }).uncheck();

    await context.setOffline(true);
    await expect(page.getByRole('status').filter({ hasText: 'オフラインのため、更新できません' })).toBeVisible();
    await expect(page.getByRole('button', { name: /変更を保存する/ })).toBeDisabled();
    expect(admin.requests.filter((r) => r.method === 'PUT')).toEqual([]);

    // 接続が戻ると保存できる
    await context.setOffline(false);
    await expect(page.getByRole('button', { name: /変更を保存する/ })).toBeEnabled();
  });
});

test.describe('S14 招待コードと担当スタッフ', () => {
  test('招待コードを発行して表示し、担当スタッフの一覧が出る', async ({ page }) => {
    await openAsAdmin(page, '/admin/booths/2/staff');

    await expect(page.getByRole('region', { name: /担当スタッフ/ })).toContainText('サクラ');
    await expect(page.getByRole('region', { name: /担当スタッフ/ })).toContainText('sakura@example.com');

    await page.getByRole('button', { name: '招待コードを発行する' }).click();
    await expect(page.getByText('K7Q2-MX9P')).toBeVisible();
    await expect(page.getByText(/まで有効・1 回だけ使えます/)).toBeVisible();
  });

  test('担当から外すときは確認してから。外すと一覧から消える', async ({ page }) => {
    const { admin } = await openAsAdmin(page, '/admin/booths/2/staff');
    const row = page.getByRole('listitem').filter({ hasText: 'サクラ' });

    await row.getByRole('button', { name: '担当から外す' }).click();
    expect(admin.requests.filter((r) => r.method === 'DELETE')).toEqual([]);
    await row.getByRole('button', { name: '外す', exact: true }).click();

    await expect(page.getByText('担当スタッフはまだいません')).toBeVisible();
    expect(admin.requests.find((r) => r.method === 'DELETE')?.path).toBe('/admin/booths/2/staff/2');
  });
});

test.describe('スマホの画面幅（360px）', () => {
  test.use({ viewport: { width: 360, height: 740 } });

  for (const path of ['/admin/games', '/admin/booths', '/admin/games/1/booths', '/admin/booths/2/staff']) {
    test(`${path} が横にはみ出さず、押すボタンは 44px 以上`, async ({ page }) => {
      await openAsAdmin(page, path);
      await expect(page.getByRole('heading', { name: '管理' })).toBeVisible();
      await expect(page.getByRole('button').first()).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow).toBeLessThanOrEqual(0);

      const small = await page.evaluate(() =>
        [...document.querySelectorAll<HTMLElement>('main button:not(.btn-sm), main a.btn, main .admin-nav-link, main input[type="checkbox"]')]
          .filter((el) => el.offsetParent !== null)
          .map((el) => ({ text: el.textContent?.trim().slice(0, 20) ?? '', height: Math.max(el.getBoundingClientRect().height, el.closest('label')?.getBoundingClientRect().height ?? 0) }))
          .filter((x) => x.height < 44),
      );
      expect(small).toEqual([]);
    });
  }
});
