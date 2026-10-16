import { expect, test, type Page } from '@playwright/test';
import { ALICE, NOW, SAKURA, mockCourtqApi } from './helpers/courtqApi';

/**
 * スタッフの画面 S07〜S10 と、役割による出し分け。API はモック、時刻は NOW に固定する。
 * 本当の防御はサーバー側（認可のテストは backend の AuthorizationTest）。ここでは画面の振る舞いを確かめる。
 */
test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(NOW);
});

const UPDATE_URL = '/staff/games/1/booths/2';
const tab = (page: Page, name: string) => page.getByRole('navigation', { name: 'メイン' }).getByRole('link', { name, exact: true });

test.describe('役割による出し分け', () => {
  test('来場者にはスタッフの画面が見えず、URL を直接開いても試合一覧に戻される', async ({ page }) => {
    const calls = await mockCourtqApi(page, { user: ALICE });
    await page.goto('/');

    await expect(tab(page, 'スタッフ登録')).toBeVisible();
    await expect(tab(page, 'スタッフ')).toHaveCount(0);

    for (const path of ['/staff', UPDATE_URL, '/staff/booths/2/menu']) {
      await page.goto(path);
      await expect(page).toHaveURL(/\/$/);
      await expect(page.getByRole('heading', { name: '試合を選ぶ' })).toBeVisible();
    }
    // スタッフ用の API も呼ばれない（画面に入る前に戻されるため）
    expect(calls.filter((c) => c.path.startsWith('/staff/') && c.path !== '/staff/invitations/accept')).toEqual([]);
  });

  test('スタッフにはスタッフのタブが出る', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/');

    await expect(tab(page, 'スタッフ')).toBeVisible();
    await expect(tab(page, 'スタッフ登録')).toHaveCount(0);
    await tab(page, 'スタッフ').click();
    await expect(page).toHaveURL(/\/staff$/);
  });

  test('役割が古い保存データ（role なし）でも、/me で確認できるまで待ってから判定する', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    await page.addInitScript(() => {
      localStorage.setItem('courtq_token', 'alice-token');
      localStorage.setItem('courtq_user', JSON.stringify({ id: 2, name: 'サクラ', email: 'sakura@example.com' })); // role なし
    });
    await page.goto('/staff');

    await expect(page.getByRole('heading', { name: '担当ブース' })).toBeVisible();
  });

  test('更新の途中で担当を外されたら、スタッフの画面を閉じたことを知らせ、以後は開けない', async ({ page }) => {
    let removed = false;
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path.endsWith('/congestion')) {
          removed = true; // この更新の時点で、担当が外されていた
          await route.fulfill({ status: 403, json: { message: 'この操作は許可されていません' } });
          return true;
        }
        if (path === '/me' && removed) {
          await route.fulfill({ json: { data: ALICE } }); // いまは来場者
          return true;
        }
        return false;
      },
    });
    await page.goto(UPDATE_URL);
    await page.getByRole('button', { name: '混雑', exact: true }).click();
    await page.getByRole('button', { name: /で更新する/ }).click();

    // 権限を取り直した時点で、試合一覧に戻され、理由が表示される
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('alert')).toContainText('担当ブースが変更されたため、スタッフの画面を閉じました');
    await expect(tab(page, 'スタッフ登録')).toBeVisible();
    await page.goto('/staff');
    await expect(page).toHaveURL(/\/$/);
  });
});

test.describe('S07 担当ブース', () => {
  test('担当ブースと今日の試合・いまの混雑を出し、更新画面とメニュー管理へ進める', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/staff');

    const booth = page.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'フリースロー・カレー' }) });
    await expect(booth).toContainText('2F コンコース東・飲食');
    await expect(booth).toContainText('本日');
    await expect(booth).toContainText('北浜ウィングス vs 南ヶ丘ブレイカーズ');
    await expect(booth).toContainText('やや混雑');
    await expect(page.getByRole('listitem')).toHaveCount(1); // 担当していないブースは出ない

    await expect(booth.getByRole('link', { name: 'メニューを管理する' })).toHaveAttribute('href', '/staff/booths/2/menu');
    await booth.getByRole('link', { name: '混雑・在庫を更新する' }).click();
    await expect(page).toHaveURL(new RegExp(`${UPDATE_URL}$`));
  });
});

test.describe('S08 混雑・在庫のクイック更新', () => {
  test('混雑は 2 タップ（レベルを選ぶ → 更新する）で更新でき、更新した時刻が表示される', async ({ page }) => {
    const calls = await mockCourtqApi(page, { user: SAKURA });
    await page.goto(UPDATE_URL);

    const panel = page.getByRole('region', { name: 'いまの表示' });
    await expect(panel).toContainText('やや混雑');
    await expect(panel).toContainText('最終更新 20分前');
    await expect(panel).toContainText('情報が古い可能性があります');

    // タップは 2 回だけ。待ち時間は現在の値（8 分）がそのまま入っている
    await page.getByRole('button', { name: '混雑', exact: true }).click();
    await page.getByRole('button', { name: '「混雑」で更新する' }).click();

    await expect(panel).toContainText('最終更新 たった今');
    await expect(panel).not.toContainText('情報が古い可能性があります');
    await expect(panel.locator('.congestion-badge')).toHaveText('混雑');
    await expect(page.getByText('✓ 更新しました')).toBeVisible();
    const put = calls.filter((c) => c.method === 'PUT' && c.path.endsWith('/congestion'));
    expect(put).toHaveLength(1);

    // 来場者の画面でも、更新した値が見える
    await page.goto('/games/1/booths/2');
    await expect(page.getByRole('region', { name: 'いまの混雑' })).toContainText('混雑');
    await expect(page.getByRole('region', { name: 'いまの混雑' })).toContainText('待ち 約8分');
  });

  test('待ち時間は ± で変えて送れる。0 より小さくならず、未入力にもできる', async ({ page }) => {
    const bodies: unknown[] = [];
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path.endsWith('/congestion')) bodies.push(route.request().postDataJSON());
        return false;
      },
    });
    await page.goto(UPDATE_URL);

    const stepper = page.getByRole('group', { name: '待ち時間（分）' });
    await expect(stepper).toContainText('8');
    await stepper.getByRole('button', { name: '待ち時間を5分増やす' }).click();
    await expect(stepper).toContainText('13');
    await stepper.getByRole('button', { name: '待ち時間を5分減らす' }).click();
    await stepper.getByRole('button', { name: '待ち時間を5分減らす' }).click();
    await expect(stepper).toContainText('3');
    await stepper.getByRole('button', { name: '待ち時間を5分減らす' }).click();
    await expect(stepper).toContainText('0');
    await expect(stepper.getByRole('button', { name: '待ち時間を5分減らす' })).toBeDisabled();

    await page.getByRole('button', { name: 'やや混雑', exact: true }).click();
    await page.getByRole('button', { name: /で更新する/ }).click();
    await expect.poll(() => bodies).toEqual([{ level: 2, wait_minutes: 0 }]);

    await stepper.getByRole('button', { name: '未入力にする' }).click();
    await expect(stepper).toContainText('未入力');
    await page.getByRole('button', { name: /で更新する/ }).click();
    await expect.poll(() => bodies).toEqual([{ level: 2, wait_minutes: 0 }, { level: 2, wait_minutes: null }]);
  });

  test('更新に失敗したら、何も変わっていないことを伝え、入力はそのまま残り、再試行できる', async ({ page }) => {
    let failing = true;
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path.endsWith('/congestion') && failing) {
          await route.fulfill({ status: 500, json: { message: 'サーバーでエラーが発生しました' } });
          return true;
        }
        return false;
      },
    });
    await page.goto(UPDATE_URL);
    const panel = page.getByRole('region', { name: 'いまの表示' });

    await page.getByRole('button', { name: '非常に混雑', exact: true }).click();
    await page.getByRole('button', { name: '「非常に混雑」で更新する' }).click();

    const alert = page.getByRole('alert');
    await expect(alert).toContainText('更新できませんでした');
    await expect(alert).toContainText('変更は反映されていません');
    // 来場者に見える値は変わっていない。成功したふりをしない
    await expect(panel.locator('.congestion-badge')).toHaveText('やや混雑');
    await expect(panel).toContainText('最終更新 20分前');
    await expect(page.getByText('✓ 更新しました')).toHaveCount(0);
    // 選んだレベルは残っている
    await expect(page.getByRole('button', { name: '非常に混雑', exact: true })).toHaveAttribute('aria-pressed', 'true');

    failing = false;
    await alert.getByRole('button', { name: 'もう一度送る' }).click();

    await expect(panel.locator('.congestion-badge')).toHaveText('非常に混雑');
    await expect(panel).toContainText('最終更新 たった今');
    await expect(page.getByRole('alert')).toHaveCount(0);
  });

  test('オフラインでは更新ボタンを押せず、理由を出す。送信もしない（成功したふりをしない）', async ({ page, context }) => {
    const calls = await mockCourtqApi(page, { user: SAKURA });
    await page.goto(UPDATE_URL);
    await page.getByRole('button', { name: '混雑', exact: true }).click();
    await expect(page.getByRole('button', { name: '「混雑」で更新する' })).toBeEnabled();

    await context.setOffline(true);

    await expect(page.getByText('オフラインのため、更新できません。')).toBeVisible();
    await expect(page.getByRole('button', { name: '「混雑」で更新する' })).toBeDisabled();
    await expect(page.getByRole('group', { name: 'ビーフカレーの在庫' }).getByRole('button', { name: '売り切れ' })).toBeDisabled();
    expect(calls.filter((c) => c.method === 'PUT')).toEqual([]);

    // 電波が戻れば、選んでいた内容のまま更新できる
    await context.setOffline(false);
    await expect(page.getByRole('button', { name: '「混雑」で更新する' })).toBeEnabled();
  });

  test('在庫は 1 タップで切り替わり、更新した時刻が出る。数量は任意', async ({ page }) => {
    const bodies: unknown[] = [];
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path.endsWith('/stock')) bodies.push(route.request().postDataJSON());
        return false;
      },
    });
    await page.goto(UPDATE_URL);
    const row = page.getByRole('listitem').filter({ hasText: 'カツカレー' });
    await expect(row).toContainText('残りわずか（残り4）');
    await expect(row).toContainText('情報が古い可能性があります');

    await row.getByRole('button', { name: '売り切れ' }).click();

    await expect(row).toContainText('売り切れ');
    await expect(row.getByRole('button', { name: '売り切れ' })).toHaveAttribute('aria-pressed', 'true');
    await expect(row).toContainText('最終更新 たった今');
    await expect(row).not.toContainText('情報が古い可能性があります');
    // 数量は入力欄の値（残り 4 が入っていた）がそのまま送られる
    expect(bodies).toEqual([{ status: 'sold_out', quantity: 4 }]);

    // 数量を空にして送ると null（未入力）
    await row.getByLabel(/^数量/).fill('');
    await row.getByRole('button', { name: '在庫あり' }).click();
    await expect.poll(() => bodies).toEqual([{ status: 'sold_out', quantity: 4 }, { status: 'in_stock', quantity: null }]);
  });

  test('在庫の更新に失敗したら、表示を元に戻して、再試行できる', async ({ page }) => {
    let failing = true;
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path.endsWith('/stock') && failing) {
          await route.fulfill({ status: 500, json: { message: 'サーバーでエラーが発生しました' } });
          return true;
        }
        return false;
      },
    });
    await page.goto(UPDATE_URL);
    const row = page.getByRole('listitem').filter({ hasText: 'ビーフカレー' });
    await expect(row).toContainText('在庫あり');

    await row.getByRole('button', { name: '売り切れ' }).click();

    await expect(row.getByRole('alert')).toContainText('更新できませんでした');
    await expect(row.getByRole('button', { name: '在庫あり' })).toHaveAttribute('aria-pressed', 'true'); // 元に戻っている
    await expect(row.getByRole('button', { name: '売り切れ' })).toHaveAttribute('aria-pressed', 'false');
    await expect(row.locator('.stock-badge')).toHaveText('在庫あり');

    failing = false;
    await row.getByRole('button', { name: 'もう一度送る' }).click();
    await expect(row.getByRole('button', { name: '売り切れ' })).toHaveAttribute('aria-pressed', 'true');
    await expect(row).toContainText('最終更新 たった今');
    await expect(row.getByRole('alert')).toHaveCount(0);
  });

  test('数量が正しくないときは送らない', async ({ page }) => {
    const calls = await mockCourtqApi(page, { user: SAKURA });
    await page.goto(UPDATE_URL);
    const row = page.getByRole('listitem').filter({ hasText: 'ビーフカレー' });

    await row.getByLabel(/^数量/).fill('-3');

    await expect(row).toContainText('数量は 0 以上の整数で入力してください');
    await expect(row.getByRole('button', { name: '売り切れ' })).toBeDisabled();
    expect(calls.filter((c) => c.method === 'PUT')).toEqual([]);
  });
});

test.describe('S09 メニュー管理', () => {
  test('販売終了のメニューも一覧に出て、販売を再開できる', async ({ page }) => {
    const calls = await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/staff/booths/2/menu');

    await expect(page.getByRole('heading', { name: 'フリースロー・カレーのメニュー' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /登録済みのメニュー（5 件）/ })).toBeVisible();
    const inactive = page.getByRole('listitem').filter({ hasText: 'ホットドッグ' });
    await expect(inactive).toContainText('販売終了');

    await inactive.getByRole('button', { name: '販売を再開する' }).click();

    await expect(inactive).not.toContainText('販売終了');
    await expect(inactive.getByRole('button', { name: '販売を終了する' })).toBeVisible();
    expect(calls).toContainEqual({ method: 'PUT', path: '/staff/booths/2/menu-items/25', query: '' });
  });

  test('メニューを追加できる。入力が正しくなければ送らず、追加できたらフォームが空になる', async ({ page }) => {
    const calls = await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/staff/booths/2/menu');
    const form = page.locator('form.menu-form').first();

    await form.getByRole('button', { name: '追加する' }).click();
    await expect(form).toContainText('メニュー名を入力してください');
    await form.getByLabel('メニュー名').fill('フライドポテト');
    await form.getByLabel('価格（円）').fill('abc');
    await form.getByRole('button', { name: '追加する' }).click();
    await expect(form).toContainText('価格は 0 以上の整数（円）で入力してください');
    expect(calls.filter((c) => c.method === 'POST')).toEqual([]);

    await form.getByLabel('価格（円）').fill('500');
    await form.getByLabel('説明（任意）').fill('塩味');
    await form.getByRole('button', { name: '追加する' }).click();

    const added = page.getByRole('listitem').filter({ hasText: 'フライドポテト' });
    await expect(added).toContainText('¥500');
    await expect(added).toContainText('塩味');
    await expect(page.getByRole('heading', { name: /登録済みのメニュー（6 件）/ })).toBeVisible();
    await expect(form.getByLabel('メニュー名')).toHaveValue('');
  });

  test('名前と価格を編集できる', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/staff/booths/2/menu');
    const row = page.locator('li.menu-manage').first(); // ビーフカレー（編集中は名前が入力欄の値になるので、位置で指す）

    await expect(row).toContainText('ビーフカレー');
    await row.getByRole('button', { name: '編集' }).click();
    await row.getByLabel('価格（円）').fill('950');
    await row.getByRole('button', { name: '保存する' }).click();

    await expect(row).toContainText('¥950');
    await expect(row.getByRole('button', { name: '編集' })).toBeVisible();
  });

  test('削除は確認してから。在庫の記録があるメニューは削除できず、販売終了を案内する', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    await page.goto('/staff/booths/2/menu');

    // 記録のないメニューは削除できる（確認あり）
    const towel = page.getByRole('listitem').filter({ hasText: 'マフラータオル' });
    await towel.getByRole('button', { name: '削除', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toContainText('元に戻せません');
    await page.getByRole('alertdialog').getByRole('button', { name: 'やめる' }).click();
    await expect(towel).toBeVisible();
    await towel.getByRole('button', { name: '削除', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: '削除する' }).click();
    await expect(towel).toHaveCount(0);

    // 在庫の記録があるメニューは 422。販売終了にする近道を出す
    const curry = page.getByRole('listitem').filter({ hasText: 'ビーフカレー' });
    await curry.getByRole('button', { name: '削除', exact: true }).click();
    await curry.getByRole('button', { name: '削除する' }).click();
    await expect(curry.getByRole('alert')).toContainText('在庫の記録があるメニューは削除できません');
    await curry.getByRole('button', { name: '削除せずに、販売を終了する' }).click();
    await expect(curry).toContainText('販売終了');
    await expect(curry.getByRole('alert')).toHaveCount(0);
  });

  test('追加に失敗したら、入力を残したまま理由を出す。オフラインでは追加できない', async ({ page, context }) => {
    await mockCourtqApi(page, {
      user: SAKURA,
      override: async (path, route) => {
        if (path === '/staff/booths/2/menu-items' && route.request().method() === 'POST') {
          await route.fulfill({ status: 500, json: { message: 'サーバーでエラーが発生しました' } });
          return true;
        }
        return false;
      },
    });
    await page.goto('/staff/booths/2/menu');
    const form = page.locator('form.menu-form').first();
    await form.getByLabel('メニュー名').fill('フライドポテト');
    await form.getByLabel('価格（円）').fill('500');

    await form.getByRole('button', { name: '追加する' }).click();

    await expect(page.getByRole('alert')).toContainText('更新できませんでした');
    await expect(form.getByLabel('メニュー名')).toHaveValue('フライドポテト'); // 入力は残る
    await expect(page.getByRole('listitem').filter({ hasText: 'フライドポテト' })).toHaveCount(0);

    await context.setOffline(true);
    await expect(form.getByRole('button', { name: '追加する' })).toBeDisabled();
    await expect(page.getByText('オフラインのため、更新できません。')).toBeVisible();
  });
});

test.describe('S10 招待コードの入力', () => {
  test('招待コードを入力すると担当になり、スタッフの画面が使えるようになる', async ({ page }) => {
    await mockCourtqApi(page, { user: ALICE });
    await page.goto('/');
    await tab(page, 'スタッフ登録').click();
    await expect(page).toHaveURL(/\/invite$/);

    // 全角・小文字でも整えられる
    await page.getByLabel('招待コード').fill('ｋ７ｑ２－ｍｘ９ｐ');
    await expect(page.getByLabel('招待コード')).toHaveValue('K7Q2-MX9P');
    await page.getByRole('button', { name: 'スタッフとして登録する' }).click();

    await expect(page.getByRole('heading', { name: '担当ブースに登録しました' })).toBeVisible();
    await expect(page.getByText('「ウィングス・グッズ東」の担当になりました。')).toBeVisible();
    await expect(tab(page, 'スタッフ')).toBeVisible();
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('courtq_user') ?? '{}').role)).toBe('staff');

    await page.getByRole('link', { name: '担当ブースを開く' }).click();
    await expect(page).toHaveURL(/\/staff$/);
    await expect(page.getByRole('heading', { name: 'ウィングス・グッズ東' })).toBeVisible();
  });

  test('正しくない・期限切れ・使用済みのコードは同じメッセージで、役割は変わらない', async ({ page }) => {
    await mockCourtqApi(page, { user: ALICE });
    await page.goto('/invite');

    await page.getByLabel('招待コード').fill('ZZZZ-ZZZZ');
    await page.getByRole('button', { name: 'スタッフとして登録する' }).click();

    await expect(page.getByRole('alert')).toContainText('招待コードが正しくないか、有効期限が切れています');
    await expect(tab(page, 'スタッフ登録')).toBeVisible();
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('courtq_user') ?? '{}').role)).toBe('user');
  });

  test('コードが空のうちは送れない。オフラインでは送れず、理由を出す', async ({ page, context }) => {
    const calls = await mockCourtqApi(page, { user: ALICE });
    await page.goto('/invite');
    const submit = page.getByRole('button', { name: 'スタッフとして登録する' });
    await expect(submit).toBeDisabled();

    await page.getByLabel('招待コード').fill('K7Q2-MX9P');
    await expect(submit).toBeEnabled();
    await context.setOffline(true);

    await expect(submit).toBeDisabled();
    await expect(page.getByText('オフラインのため、更新できません。')).toBeVisible();
    expect(calls.filter((c) => c.path === '/staff/invitations/accept')).toEqual([]);
  });
});

test.describe('スマホの画面幅（360px）', () => {
  test.use({ viewport: { width: 360, height: 740 }, hasTouch: true });

  test('スタッフの画面が横にはみ出さず、押すボタンは 48px 以上', async ({ page }) => {
    await mockCourtqApi(page, { user: SAKURA });
    for (const path of ['/staff', UPDATE_URL, '/staff/booths/2/menu', '/invite']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expect(page.locator('.spinner')).toHaveCount(0);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${path} が横にはみ出しています`).toBeLessThanOrEqual(0);
    }

    await page.goto(UPDATE_URL);
    await expect(page.getByRole('button', { name: '混雑', exact: true })).toBeVisible();
    const heights = await page.locator('.level-btn, .stepper-btn, .stock-btn, .send-btn').evaluateAll((els) => els.map((el) => el.getBoundingClientRect().height));
    expect(heights.length).toBe(4 + 2 + 4 * 3 + 1); // レベル 4・ステッパー 2・在庫（メニュー 4 件 × 3）・更新ボタン
    expect(Math.min(...heights)).toBeGreaterThanOrEqual(48);
  });
});
