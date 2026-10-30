import { describe, expect, it } from 'vitest'
import { passwordStrength } from '../passwordStrength'

describe('passwordStrength', () => {
  it('calls anything under the 8-character minimum too short', () => {
    expect(passwordStrength('')).toBe(0)
    expect(passwordStrength('Ab1!xyz')).toBe(0)
  })

  it('rates a plain 8-character password weak', () => {
    expect(passwordStrength('abcdefgh')).toBe(1)
  })

  it('adds a point each for length, mixed case, and digits with symbols', () => {
    expect(passwordStrength('abcdefghijkl')).toBe(2)
    expect(passwordStrength('Abcdefghijkl')).toBe(3)
    expect(passwordStrength('Abcdefghij1!')).toBe(4)
  })
})
