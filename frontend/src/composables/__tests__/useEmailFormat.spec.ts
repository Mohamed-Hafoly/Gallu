import { describe, expect, it } from "vitest";
import { useEmailFormat } from "@/composables/useEmailFormat";

const { truncateEmail } = useEmailFormat();

describe("truncateEmail", () => {
  it("leaves an address that already fits untouched", () => {
    expect(truncateEmail("john@example.com", 20)).toBe("john@example.com");
  });

  it("leaves an address exactly at the budget alone", () => {
    const email = "john@example.com"; // 16 characters

    expect(truncateEmail(email, email.length)).toBe(email);
  });

  it("spends the budget on the domain, shortening the local part", () => {
    // "@example.com" is 12, leaving 5 for the local part plus the ellipsis.
    expect(truncateEmail("thisisrlylongaddress@example.com", 18)).toBe(
      "thisi…@example.com",
    );
  });

  it("truncates one character past the budget", () => {
    const email = `${"a".repeat(9)}@example.com`; // 21 characters

    expect(truncateEmail(email, 20)).toBe(`${"a".repeat(7)}…@example.com`);
  });

  // The branch the local-part-only version could not express: the domain on
  // its own is wider than the box, so its tail is what has to survive.
  it("keeps the domain's tail when the domain alone overruns the budget", () => {
    const result = truncateEmail("a@some.very.long.domain.co.uk", 14);

    // Exactly the budget: the ellipsis plus the last 13 characters.
    expect(result).toBe("….domain.co.uk");
    expect(result).toContain(".co.uk");
  });

  // The kept prefix can itself contain an "@" — that is the quoted local part,
  // not a second address.
  it("splits on the last @ when there are several", () => {
    expect(
      truncateEmail('"weird@inner"very-long-tail@example.com', 20),
    ).toBe('"weird@…@example.com');
  });

  it("keeps plus-addressing when it fits", () => {
    expect(truncateEmail("john+newsletter@example.com", 30)).toBe(
      "john+newsletter@example.com",
    );
  });

  // Was previously returned untouched; the budget is now a hard limit.
  it("caps a string with no @ rather than returning it whole", () => {
    expect(truncateEmail("not-an-email-but-quite-long", 10)).toBe(
      "not-an-em…",
    );
  });

  it.each([
    ["john@example.com", 20],
    ["thisisrlylongaddress@example.com", 18],
    ["a@some.very.long.domain.co.uk", 14],
    ["a@some.very.long.domain.co.uk", 6],
    ['"weird@inner"very-long-tail@example.com', 20],
    ["not-an-email-but-quite-long", 10],
    ["x@y.z", 5],
  ])("never exceeds the budget: %s at %i", (email, maxLength) => {
    expect(truncateEmail(email, maxLength).length).toBeLessThanOrEqual(
      maxLength,
    );
  });
});
