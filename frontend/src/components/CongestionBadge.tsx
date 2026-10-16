import { CONGESTION_LABELS } from '../constants';
import type { CongestionLevel } from '../types';

type Props = {
  level: CongestionLevel | null;
  /** 閉店中は混雑ではなく「営業していません」を出す */
  isOpen?: boolean;
  size?: 'md' | 'lg';
};

/**
 * 混雑のバッジ。色だけで伝えないよう、必ず文言（空いている〜非常に混雑）と 4 段階のメーターを一緒に出す。
 * 色は混雑の状態色（--status-N）で、ブランドのオレンジとは別の色。
 */
export function CongestionBadge({ level, isOpen = true, size = 'md' }: Props) {
  if (!isOpen) {
    return <span className={`congestion-badge congestion-closed congestion-${size}`}>営業していません</span>;
  }
  if (level === null) {
    return <span className={`congestion-badge congestion-unknown congestion-${size}`}>未更新</span>;
  }

  return (
    <span className={`congestion-badge congestion-${level} congestion-${size}`} data-level={level}>
      <span className="congestion-meter" aria-hidden="true">
        {[1, 2, 3, 4].map((step) => (
          <span key={step} className={step <= level ? 'on' : ''} />
        ))}
      </span>
      {CONGESTION_LABELS[level]}
    </span>
  );
}
