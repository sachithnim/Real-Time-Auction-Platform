import { Link } from 'react-router'

export function NotFoundPage({ message = 'This page does not exist.' }: { message?: string }) {
  return (
    <div className="py-16 text-center">
      <p className="text-lg font-semibold">{message}</p>
      <Link to="/" className="mt-4 inline-block text-indigo-600 hover:underline">
        Back to auctions
      </Link>
    </div>
  )
}
