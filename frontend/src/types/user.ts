export interface User {
  id: number;
  name: string;
  email: string;
  /** Always populated — the backend falls back to the default avatar. */
  avatar_url: string;
  avatar_thumb_url: string;
  /** False when the user is on the default avatar, so it can't be removed. */
  has_avatar: boolean;
  /** What the avatar reverts to — previewed while a removal is pending. */
  default_avatar_url: string;
  created_at: string;
  updated_at: string;
  /** Gates the admin nav entry and the /admin routes. Backed by a real policy
   * on the server — this only keeps the UI honest. */
  is_super_admin: boolean;
  /** Presentation of the same fact, labelled through admin.users.roles.*.
   * Binary until teams exist, when a team admin resolves to "admin". */
  role: "super-admin" | "admin" | "member";
  /** The team this user belongs to. Null for a team-less user, and for one
   * whose team is soft-deleted. */
  team: { id: number; name: string } | null;
  /** Set only on the pending-deletion table's rows; null on every live user. */
  deleted_at: string | null;
}
