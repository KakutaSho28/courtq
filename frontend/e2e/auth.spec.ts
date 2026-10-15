import { expect, test, type Page } from '@playwright/test';

const API = 'http://api.test/api';
const ALICE = { id: 1, name: 'アリス', email: 'a@example.com' };

/** 認証系 API をモックする。呼ばれたパスを記録して返す */
async function mockAuthApi(page: Page, options: { loginStatus?: number } = {}) {
  const calls: string[] = [];
  await page.route(`${API}/**`, async (route) => {
    const req = route.request();
    const path = new URL(req.url()).pathname.replace('/api', '');
    calls.push(`${req.method()} ${path}`);

    if (path === '/auth/register') return route.fulfill({ status: 201, json: { data: { user: ALICE, token: 'alice-token' } } });
    if (path === '/auth/login') {
      return options.loginStatus === 401
        ? route.fulfill({ status: 401, json: { message: 'メールアドレスまたはパスワードが正しくありません' } })
        : route.fulfill({ json: { data: { user: ALICE, token: 'alice-token' } } });
    }
    if (path === '/me') return route.fulfill({ json: { data: ALICE } });
    if (path === '/auth/logout') return route.fulfill({ json: { message: 'ログアウトしました' } });
    return route.fulfill({ status: 404, json: { message: '見つかりません' } });
  });
  return calls;
}

test('未ログインでホームを開くと、ログイン画面に移動する', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('img', { name: 'CourtQ' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'ログイン' })).toBeVisible();
});

test('新規登録するとホームが開き、ログアウトするとログイン画面に戻る', async ({ page }) => {
  const calls = await mockAuthApi(page);
  await page.goto('/register');

  await page.getByLabel('表示名').fill('アリス');
  await page.getByLabel('メールアドレス').fill('a@example.com');
  await page.getByLabel('パスワード（8文字以上）').fill('password123');
  await page.getByLabel('パスワード（確認）').fill('password123');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('heading', { name: 'CourtQ' })).toBeVisible();
  await expect(page.getByText('アリスさん、ようこそ。')).toBeVisible();
  await expect.poll(() => calls).toContain('GET /me');

  await page.getByRole('button', { name: 'ログアウト' }).click();
  await expect(page).toHaveURL(/\/login$/);
  expect(await page.evaluate(() => localStorage.getItem('courtq_token'))).toBeNull();
});

test('ログインに失敗すると、サーバーのメッセージを表示してログイン画面に留まる', async ({ page }) => {
  await mockAuthApi(page, { loginStatus: 401 });
  await page.goto('/login');

  await page.getByLabel('メールアドレス').fill('a@example.com');
  await page.getByLabel('パスワード').fill('wrong-password');
  await page.getByRole('button', { name: 'ログイン' }).click();

  await expect(page.getByText('メールアドレスまたはパスワードが正しくありません')).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});

test('トークンが無効（401）になると自動でログアウトする', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('courtq_token', 'expired');
    localStorage.setItem('courtq_user', JSON.stringify({ id: 1, name: 'アリス', email: 'a@example.com' }));
  });
  await page.route(`${API}/**`, (route) => route.fulfill({ status: 401, json: { message: 'ログインが必要です' } }));

  await page.goto('/');

  await expect(page).toHaveURL(/\/login$/);
});
