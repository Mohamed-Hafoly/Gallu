import type { User } from "@/types/user";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

/** What the admin table's `@update:options` maps onto. */
export interface UserListParams {
  page: number;
  per_page: number;
  sort_by?: string;
  sort_order?: "asc" | "desc";
  search?: string;
  /** Which side of the soft delete to serve. Absent means live only. */
  trashed?: "with" | "only";
}

export interface UserPage {
  items: User[];
  total: number;
}

export interface CreateUserPayload {
  name: string;
  email: string;
  password: string;
  passwordConfirmation: string;
  isSuperAdmin: boolean;
  /** Optional, exactly as on registration. */
  avatar?: File | null;
  /** Membership, same split as the update: null means no team. */
  teamId?: number | null;
  teamRole?: "admin" | "member";
}

export interface UpdateUserPayload {
  name: string;
  email: string;
  avatar?: File | null;
  removeAvatar?: boolean;
  /** Promote or demote. Left undefined when editing yourself — the backend
   * refuses to let a super-admin change their own flag. */
  isSuperAdmin?: boolean;
  /** Membership. Null clears it; undefined leaves it untouched. Ignored by the
   * backend for a super-admin, who sits above teams. */
  teamId?: number | null;
  teamRole?: "admin" | "member";
}

export const useUserStore = defineStore("user", () => {
  /**
   * One page of the admin listing. Unlike the categories store this does not
   * fetch everything — paging, sorting and searching all happen server-side, so
   * the total has to come back alongside the rows for the table's footer.
   */
  async function fetchUsers(params: UserListParams): Promise<UserPage> {
    const { data } = await api.get("/api/users", { params });

    return { items: data.data as User[], total: data.meta.total as number };
  }

  /**
   * Multipart so the optional avatar can ride along — but with no `_method`
   * spoofing, unlike updateUser: this route is already POST.
   */
  async function createUser(payload: CreateUserPayload) {
    const formData = new FormData();
    formData.append("name", payload.name);
    formData.append("email", payload.email);
    formData.append("password", payload.password);
    formData.append("password_confirmation", payload.passwordConfirmation);
    formData.append("is_super_admin", payload.isSuperAdmin ? "1" : "0");
    if (payload.teamId != null) {
      formData.append("team_id", String(payload.teamId));
      if (payload.teamRole) formData.append("team_role", payload.teamRole);
    }
    if (payload.avatar) formData.append("avatar", payload.avatar);

    const { data } = await api.post("/api/users", formData);
    return data.data as User;
  }

  /**
   * Name, email, avatar and the super-admin flag are editable; the id and both
   * timestamps are rendered disabled and the backend ignores them.
   *
   * Sent as multipart so the avatar can ride along, hence the `_method`
   * spoofing over a POST — same trick as stores/image.ts.
   */
  async function updateUser(id: number, payload: UpdateUserPayload) {
    const formData = new FormData();
    formData.append("_method", "PATCH");
    formData.append("name", payload.name);
    formData.append("email", payload.email);
    if (payload.avatar) formData.append("avatar", payload.avatar);
    if (payload.removeAvatar) formData.append("remove_avatar", "1");
    // Only when defined: an update that omits it leaves the flag untouched.
    if (payload.isSuperAdmin !== undefined) {
      formData.append("is_super_admin", payload.isSuperAdmin ? "1" : "0");
    }
    // Same rule for the team. Empty string rather than "null": multipart has no
    // null, and the backend reads 0 — what integer() gives for "" — as "clear".
    if (payload.teamId !== undefined) {
      formData.append("team_id", payload.teamId === null ? "" : String(payload.teamId));
      if (payload.teamRole) formData.append("team_role", payload.teamRole);
    }

    const { data } = await api.post(`/api/users/${id}`, formData);
    return data.data as User;
  }

  /** Soft delete — the row moves to the pending-deletion table. */
  async function deleteUser(id: number) {
    await api.delete(`/api/users/${id}`);
  }

  async function restoreUser(id: number): Promise<User> {
    const { data } = await api.post(`/api/users/${id}/restore`);
    return data.data as User;
  }

  return { fetchUsers, createUser, updateUser, deleteUser, restoreUser };
});
