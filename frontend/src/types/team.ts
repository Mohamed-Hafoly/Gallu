import type { User } from "@/types/user";

/**
 * A role *within* a team. Deliberately not `User["role"]`, which also carries
 * `super-admin` — that one is global and can never belong to a team.
 */
export type TeamRole = "admin" | "member";

/**
 * A member as the picker holds it while editing — the user alongside the role
 * they are to have. Kept separate from `User.role`, which is the API's view of
 * where they stand *now*, not what is pending.
 */
export interface TeamMemberSelection {
  user: User;
  role: TeamRole;
}

export interface Team {
  id: number;
  name: string;
  description: string | null;
  /** Null once the creating user is deleted — the column nulls on delete. */
  creator?: string | null;
  /** Only present on the admin listing, not on the picker. */
  members_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
  deleted_at?: string | null;
}
