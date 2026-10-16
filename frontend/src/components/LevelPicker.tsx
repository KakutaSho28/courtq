import { CONGESTION_LABELS } from '../constants';
import type { CongestionLevel } from '../types';

type Props = {
  value: CongestionLevel | null;
  onChange: (level: CongestionLevel) => void;
  disabled?: boolean;
};

const LEVELS: CongestionLevel[] = [1, 2, 3, 4];

/**
 * 混雑レベルを選ぶ大きな 4 つのボタン（2 × 2）。色 + 文言 + 選択中の ✓ で、色だけに頼らない。
 * 選択は aria-pressed でも伝える。
 */
export function LevelPicker({ value, onChange, disabled = false }: Props) {
  return (
    <div className="level-picker" role="group" aria-label="混雑レベル">
      {LEVELS.map((level) => (
        <button
          key={level}
          type="button"
          className={`level-btn level-${level}`}
          aria-pressed={value === level}
          disabled={disabled}
          onClick={() => onChange(level)}
        >
          <span className="level-check" aria-hidden="true">
            {value === level ? '✓' : ''}
          </span>
          {CONGESTION_LABELS[level]}
        </button>
      ))}
    </div>
  );
}
