import { FormEvent, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { Logo } from '../components/Logo';
import { useAuthStore } from '../store/auth';

export function RegisterPage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');

    if (password !== passwordConfirm) {
      setError('パスワードが一致しません');
      return;
    }

    setLoading(true);
    try {
      const res = await apiClient.post('/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirm,
      });
      setAuth(res.data.data.token, res.data.data.user);
      navigate('/');
    } catch (err: unknown) {
      const message =
        err && typeof err === 'object' && 'response' in err
          ? ((err as { response?: { data?: { message?: string } } }).response?.data
              ?.message ?? '登録に失敗しました')
          : '登録に失敗しました';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-card">
      <h1 className="auth-logo" style={{ marginBottom: 8 }}>
        <Logo />
      </h1>
      <p className="muted" style={{ textAlign: 'center', marginBottom: 24 }}>
        アカウント登録
      </p>
      <form onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="name">表示名</label>
          <input id="name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} />
        </div>
        <div className="field">
          <label htmlFor="email">メールアドレス</label>
          <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="password">パスワード（8文字以上）</label>
          <input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
        </div>
        <div className="field">
          <label htmlFor="password-confirm">パスワード（確認）</label>
          <input id="password-confirm" type="password" value={passwordConfirm} onChange={(e) => setPasswordConfirm(e.target.value)} required minLength={8} />
        </div>
        {error && <p className="error-msg">{error}</p>}
        <button type="submit" className="btn btn-primary btn-block" disabled={loading}>
          {loading ? '登録中...' : '登録する'}
        </button>
      </form>
      <p className="muted" style={{ textAlign: 'center', marginTop: 18 }}>
        すでにアカウントをお持ちの方は{' '}
        <Link to="/login" className="link">
          ログイン
        </Link>
      </p>
    </div>
  );
}
