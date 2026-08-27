import type { Image } from "@/types/image";
import type { User } from "@/types/user";
import { createTestingPinia } from "@pinia/testing";
import { setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useImagePermissions } from "@/composables/useImagePermissions";

// The auth store imports the real router module, whose HMR hook throws here.
vi.mock("@/plugins/router", () => ({ default: { replace: vi.fn() } }));

const ME = 7;

function makeUser(role: "super-admin" | "admin" | "member"): User {
  return {
    id: ME,
    name: "Grace Hopper",
    email: "grace@example.com",
    avatar_url: "/avatar.jpg",
    avatar_thumb_url: "/avatar.jpg",
    has_avatar: false,
    default_avatar_url: "/avatar.jpg",
    created_at: "2026-08-01T10:00:00Z",
    updated_at: "2026-08-15T10:00:00Z",
    is_super_admin: role === "super-admin",
    role,
    team: role === "super-admin" ? null : { id: 1, name: "Design" },
  };
}

function makeImage(userId: number): Image {
  return {
    id: 42,
    title: "Front cover",
    description: null,
    url: "/42.jpg",
    thumb_url: "/42-thumb.jpg",
    categories: [],
    document_id: 7,
    user_id: userId,
    creator: "Ada Lovelace",
    created_at: "2026-08-01T10:00:00.000000Z",
    updated_at: "2026-08-01T10:00:00.000000Z",
  };
}

/** Signs someone in, then hands back the composable bound to that session. */
function asUser(user: User | null) {
  setActivePinia(
    createTestingPinia({ createSpy: vi.fn, initialState: { auth: { user } } }),
  );

  return useImagePermissions();
}

const MINE = makeImage(ME);
const THEIRS = makeImage(99);

beforeEach(() => {
  vi.clearAllMocks();
});

/**
 * Mirrors ImagePolicy::update — the owner, or a team admin. Cosmetic: the
 * policy is what denies, and ImageController authorises every write path. These
 * exist so a button is not offered where the server will answer 403.
 */
describe("canEdit", () => {
  it("lets a member change their own image", () => {
    expect(asUser(makeUser("member")).canEdit(MINE)).toBe(true);
  });

  // The bug this composable exists to fix.
  it("does not let a member change a teammate's", () => {
    expect(asUser(makeUser("member")).canEdit(THEIRS)).toBe(false);
  });

  /**
   * No team comparison is needed: Image::scopeVisibleTo means an admin only
   * ever receives images from their own team, so anything reaching them here
   * has already passed the policy's team test.
   */
  it("lets an admin change anyone's image in reach", () => {
    const permissions = asUser(makeUser("admin"));

    expect(permissions.canEdit(THEIRS)).toBe(true);
    expect(permissions.canEdit(MINE)).toBe(true);
  });

  it("lets a super admin change anyone's", () => {
    expect(asUser(makeUser("super-admin")).canEdit(THEIRS)).toBe(true);
  });

  it("refuses when nobody is signed in", () => {
    expect(asUser(null).canEdit(MINE)).toBe(false);
  });
});

// ImagePolicy::delete delegates to ::update, so this is canEdit by definition.
describe("canDelete", () => {
  it("tracks canEdit exactly", () => {
    for (const role of ["super-admin", "admin", "member"] as const) {
      const permissions = asUser(makeUser(role));

      for (const image of [MINE, THEIRS]) {
        expect(permissions.canDelete(image)).toBe(permissions.canEdit(image));
      }
    }
  });

  it("does not let a member delete a teammate's image", () => {
    expect(asUser(makeUser("member")).canDelete(THEIRS)).toBe(false);
  });
});
