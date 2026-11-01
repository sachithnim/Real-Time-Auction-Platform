/** Matches the server's rules, so most problems show before anything is uploaded. */
export const MAX_PHOTOS = 8
export const MAX_PHOTO_BYTES = 5 * 1024 * 1024
export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/** Why this file cannot be uploaded, or null when it looks fine. (Pixel size is checked by the server.) */
export function photoProblem(file: Pick<File, 'name' | 'type' | 'size'>): string | null {
  if (!PHOTO_TYPES.includes(file.type)) {
    return `${file.name}: use a JPG, PNG or WebP photo.`
  }
  if (file.size > MAX_PHOTO_BYTES) {
    return `${file.name}: photos must be 5 MB or smaller.`
  }
  return null
}

/** Splits picked files into ones to upload and messages for the rest, keeping within the photo limit. */
export function pickPhotos(files: Pick<File, 'name' | 'type' | 'size'>[], existing: number) {
  const problems: string[] = []
  const valid = files.filter((file) => {
    const problem = photoProblem(file)
    if (problem) {
      problems.push(problem)
    }
    return problem === null
  })

  const room = Math.max(0, MAX_PHOTOS - existing)
  if (valid.length > room) {
    problems.push(room === 0 ? `This auction already has ${MAX_PHOTOS} photos.` : `Only ${room} more ${room === 1 ? 'photo fits' : 'photos fit'} (${MAX_PHOTOS} at most); the rest were skipped.`)
  }
  return { accepted: valid.slice(0, room), problems }
}

/** Moves one item to a new index, e.g. to make a photo the cover (index 0). */
export function moveItem<T>(items: T[], from: number, to: number): T[] {
  const next = [...items]
  const [item] = next.splice(from, 1)
  next.splice(Math.max(0, Math.min(to, next.length)), 0, item)
  return next
}
