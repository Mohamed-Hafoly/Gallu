export function useEmailFormat() {
  /**
   * Shortens an address to fit `maxLength` characters, spending the budget on
   * the domain first: `averylongaddre…@example.com`.
   *
   * The domain is the more identifying half, and a plain CSS ellipsis would eat
   * it. `maxLength` is a hard limit on the *result*, so the caller can match it
   * to the width of the box the address has to fit.
   */
  const truncateEmail = (email: string, maxLength = 30): string => {
    if (email.length <= maxLength) return email;

    // The local part may legally contain a quoted "@", so the domain is
    // whatever follows the *last* one.
    const at = email.lastIndexOf("@");

    // Not an address — fall back to a plain end-truncation.
    if (at === -1) return `${email.slice(0, maxLength - 1)}…`;

    const domain = email.slice(at); // includes the "@"
    const localBudget = maxLength - domain.length - 1; // -1 for the ellipsis

    // Room to keep the domain whole and still show some of the local part.
    if (localBudget >= 1) return `${email.slice(0, localBudget)}…${domain}`;

    // The domain alone overruns the budget, so keep its tail — the registrable
    // part is what identifies the address.
    return `…${email.slice(-(maxLength - 1))}`;
  };

  return { truncateEmail };
}
