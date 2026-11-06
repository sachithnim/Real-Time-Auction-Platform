import type { User } from '../../types/api'
import { ResendVerification } from './ResendVerification'

/** Under the header until a bidder confirms their email: bidding waits for it. */
export function VerifyEmailBanner({ user }: { user: User }) {
  return (
    <div role="status" className="border-b border-amber-200 bg-amber-50">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm text-amber-900">
        <span>
          <span className="font-semibold">Confirm your email to start bidding.</span> We sent a link to {user.email}.
        </span>
        <ResendVerification className="text-amber-900" />
      </div>
    </div>
  )
}
