import { strengthLabels, type Strength } from '../../lib/passwordStrength'

const barColours: Record<Strength, string> = { 0: 'bg-red-400', 1: 'bg-red-400', 2: 'bg-amber-400', 3: 'bg-emerald-400', 4: 'bg-emerald-600' }

/** Four bars and a word under a new password, as a rough guide while typing. */
export function StrengthMeter({ strength }: { strength: Strength }) {
  return (
    <div className="flex items-center gap-3" aria-live="polite">
      <div className="flex flex-1 gap-1" aria-hidden>
        {[1, 2, 3, 4].map((step) => (
          <span key={step} className={`h-1.5 flex-1 rounded-full ${step <= Math.max(strength, 1) ? barColours[strength] : 'bg-slate-200'}`} />
        ))}
      </div>
      <span className="w-16 text-right text-xs text-slate-600">{strengthLabels[strength]}</span>
    </div>
  )
}
