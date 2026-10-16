type Props = {
  boothName: string;
  isFavorite: boolean;
  busy?: boolean;
  onToggle: () => void;
  /** icon: 一覧の星だけのボタン / full: 詳細画面の文言付きボタン */
  variant?: 'icon' | 'full';
};

/** お気に入りの切り替え。状態は aria-pressed でも伝える（読み上げで分かるように） */
export function FavoriteButton({ boothName, isFavorite, busy = false, onToggle, variant = 'icon' }: Props) {
  const label = isFavorite ? `${boothName}をお気に入りから外す` : `${boothName}をお気に入りに追加`;

  return (
    <button
      type="button"
      className={`favorite-btn favorite-${variant}${isFavorite ? ' is-favorite' : ''}`}
      aria-pressed={isFavorite}
      aria-label={variant === 'icon' ? label : undefined}
      disabled={busy}
      onClick={onToggle}
    >
      <span aria-hidden="true">{isFavorite ? '★' : '☆'}</span>
      {variant === 'full' && <span>{isFavorite ? 'お気に入り登録済み' : 'お気に入りに追加'}</span>}
    </button>
  );
}
