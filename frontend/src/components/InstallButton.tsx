import { useInstallPrompt } from '../hooks/useInstallPrompt';

/** 「ホーム画面に追加」。インストール可能なときだけ表示 */
export function InstallButton() {
  const { canInstall, install } = useInstallPrompt();
  if (!canInstall) return null;

  return (
    <button className="btn btn-ghost btn-sm" onClick={install}>
      ホーム画面に追加
    </button>
  );
}
