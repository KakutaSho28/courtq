import assert from 'node:assert/strict';
import { test } from 'node:test';
import { checkDeployEnv, shouldCheck } from './check-deploy-env.mjs';

const good = { VITE_API_BASE_URL: 'https://courtq-api.up.railway.app/api', VITE_PUSHER_APP_KEY: 'key', VITE_PUSHER_APP_CLUSTER: 'ap3' };

test('正しい設定ならエラーも警告もない', () => {
  assert.deepEqual(checkDeployEnv(good), { errors: [], warnings: [] });
});

for (const [name, env, expected] of [
  ['VITE_API_BASE_URL が未設定', { ...good, VITE_API_BASE_URL: undefined }, '未設定'],
  ['空文字', { ...good, VITE_API_BASE_URL: '' }, '未設定'],
  ['localhost（開発用の既定値）', { ...good, VITE_API_BASE_URL: 'http://localhost/api' }, 'ローカルの値'],
  ['127.0.0.1', { ...good, VITE_API_BASE_URL: 'https://127.0.0.1/api' }, 'ローカルの値'],
  ['http', { ...good, VITE_API_BASE_URL: 'http://courtq-api.up.railway.app/api' }, 'https://'],
  ['末尾にスラッシュ', { ...good, VITE_API_BASE_URL: 'https://courtq-api.up.railway.app/api/' }, '末尾にスラッシュ'],
  ['/api が無い', { ...good, VITE_API_BASE_URL: 'https://courtq-api.up.railway.app' }, '/api ではありません'],
  ['Pusher のキーだけで cluster が無い', { ...good, VITE_PUSHER_APP_CLUSTER: undefined }, 'VITE_PUSHER_APP_CLUSTER'],
]) {
  test(`取り違えを検出する: ${name}`, () => {
    const { errors } = checkDeployEnv(env);
    assert.ok(errors.some((e) => e.includes(expected)), `期待した指摘が無い: ${expected}\n${errors.join('\n')}`);
  });
}

test('Pusher のキーが無いのはエラーではなく警告（リアルタイムなしで動く）', () => {
  const { errors, warnings } = checkDeployEnv({ ...good, VITE_PUSHER_APP_KEY: undefined, VITE_PUSHER_APP_CLUSTER: undefined });
  assert.deepEqual(errors, []);
  assert.equal(warnings.length, 1);
});

test('Vercel のビルド（VERCEL=1）と明示したときだけ確認する。ローカルの開発・e2e は対象外', () => {
  assert.equal(shouldCheck({ VERCEL: '1' }), true);
  assert.equal(shouldCheck({ CHECK_DEPLOY_ENV: '1' }), true);
  assert.equal(shouldCheck({}), false);
  assert.equal(shouldCheck({ VITE_API_BASE_URL: 'http://api.test/api' }), false);
});
