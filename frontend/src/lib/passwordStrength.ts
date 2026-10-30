export type Strength = 0 | 1 | 2 | 3 | 4

export const strengthLabels: Record<Strength, string> = { 0: 'Too short', 1: 'Weak', 2: 'Fair', 3: 'Good', 4: 'Strong' }

/**
 * A rough guide shown while typing. The server only requires 8 characters;
 * this just nudges people towards longer, mixed passwords.
 */
export function passwordStrength(password: string): Strength {
  if (password.length < 8) {
    return 0
  }
  let score = 1
  if (password.length >= 12) score++
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++
  if (/\d/.test(password) && /[^A-Za-z0-9]/.test(password)) score++
  return score as Strength
}
