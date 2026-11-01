import { describe, expect, it } from 'vitest'
import { MAX_PHOTO_BYTES, moveItem, photoProblem, pickPhotos } from '../photos'

const file = (name: string, type = 'image/jpeg', size = 1000) => ({ name, type, size })

describe('photoProblem', () => {
  it('accepts JPG, PNG and WebP up to 5 MB', () => {
    expect(photoProblem(file('a.jpg'))).toBeNull()
    expect(photoProblem(file('a.png', 'image/png'))).toBeNull()
    expect(photoProblem(file('a.webp', 'image/webp', MAX_PHOTO_BYTES))).toBeNull()
  })

  it('names the file and the reason when it is refused', () => {
    expect(photoProblem(file('logo.svg', 'image/svg+xml'))).toBe('logo.svg: use a JPG, PNG or WebP photo.')
    expect(photoProblem(file('anim.gif', 'image/gif'))).toContain('JPG, PNG or WebP')
    expect(photoProblem(file('big.jpg', 'image/jpeg', MAX_PHOTO_BYTES + 1))).toBe('big.jpg: photos must be 5 MB or smaller.')
  })
})

describe('pickPhotos', () => {
  it('keeps the good files and explains the rest', () => {
    const { accepted, problems } = pickPhotos([file('a.jpg'), file('b.pdf', 'application/pdf'), file('c.png', 'image/png')], 0)

    expect(accepted.map((f) => f.name)).toEqual(['a.jpg', 'c.png'])
    expect(problems).toEqual(['b.pdf: use a JPG, PNG or WebP photo.'])
  })

  it('stops at eight photos in total', () => {
    const { accepted, problems } = pickPhotos([file('a.jpg'), file('b.jpg'), file('c.jpg')], 6)

    expect(accepted.map((f) => f.name)).toEqual(['a.jpg', 'b.jpg'])
    expect(problems).toEqual(['Only 2 more photos fit (8 at most); the rest were skipped.'])
    expect(pickPhotos([file('a.jpg')], 8).problems).toEqual(['This auction already has 8 photos.'])
  })
})

describe('moveItem', () => {
  it('moves an item without changing the original list', () => {
    const list = ['a', 'b', 'c', 'd']

    expect(moveItem(list, 2, 0)).toEqual(['c', 'a', 'b', 'd'])
    expect(moveItem(list, 0, 1)).toEqual(['b', 'a', 'c', 'd'])
    expect(moveItem(list, 3, 9)).toEqual(['a', 'b', 'c', 'd'])
    expect(list).toEqual(['a', 'b', 'c', 'd'])
  })
})
