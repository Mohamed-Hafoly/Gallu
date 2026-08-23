import type { Team, TeamRole } from "@/types/team";
import type { User } from "@/types/user";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

export interface TeamPayload {
  name: string;
  description: string | null;
}

export interface TeamMemberPayload {
  userId: number;
  role: TeamRole;
}

/**
 * Create also carries the optional starting membership. Update deliberately
 * does not — the API accepts members only on the way in.
 */
export interface CreateTeamPayload extends TeamPayload {
  members?: TeamMemberPayload[];
}

export const useTeamStore = defineStore("team", () => {
  /**
   * Every team including soft-deleted ones, for the admin screen. It splits
   * live from trashed on `deleted_at` and pages, sorts and filters client-side,
   * so this is a single unpaginated request — same shape as the categories
   * store, and unlike users, which pages server-side.
   */
  async function fetchAllTeams() {
    const { data } = await api.get("/api/teams");
    return data.data as Team[];
  }

  /**
   * Live teams only, for the user dialog's team select. Its own endpoint
   * because the admin listing deliberately includes trashed rows.
   */
  async function fetchPickerTeams() {
    const { data } = await api.get("/api/teams/picker");
    return data.data as Team[];
  }

  /** The backend assigns the id and takes the creator from the session. */
  async function createTeam(payload: CreateTeamPayload) {
    const { members, ...team } = payload;

    const { data } = await api.post("/api/teams", {
      ...team,
      // Renamed on the way out: the store speaks camelCase, the API snake_case.
      // Omitted entirely when nothing was picked, so the rules stay `sometimes`.
      ...(members?.length
        ? {
            members: members.map((member) => ({
              user_id: member.userId,
              role: member.role,
            })),
          }
        : {}),
    });

    return data.data as Team;
  }

  async function updateTeam(id: number, payload: TeamPayload) {
    const { data } = await api.patch(`/api/teams/${id}`, payload);
    return data.data as Team;
  }

  /** Soft delete — the row moves to the pending-deletion table. */
  async function deleteTeam(id: number) {
    await api.delete(`/api/teams/${id}`);
  }

  async function restoreTeam(id: number) {
    const { data } = await api.post(`/api/teams/${id}/restore`);
    return data.data as Team;
  }

  /**
   * The team's members, each carrying their in-team role. Members are users, so
   * these come back as the same `User` shape the admin users table renders.
   */
  async function fetchMembers(teamId: number) {
    const { data } = await api.get(`/api/teams/${teamId}/members`);
    return data.data as User[];
  }

  /**
   * Replace the team's membership with the list the dialog holds.
   *
   * One whole-state write rather than a request per add, removal and role
   * change: the dialog saves once, so a partial apply would leave the team in a
   * state nobody chose. Anyone absent is removed, and anyone who belonged to
   * another team is moved here. Returns the membership as the server now sees
   * it, so the dialog reseeds rather than trusting its own optimism.
   */
  async function syncMembers(teamId: number, members: TeamMemberPayload[]) {
    const { data } = await api.put(`/api/teams/${teamId}/members`, {
      // Renamed on the way out: the store speaks camelCase, the API snake_case.
      members: members.map((member) => ({
        user_id: member.userId,
        role: member.role,
      })),
    });

    return data.data as User[];
  }

  return {
    fetchAllTeams,
    fetchPickerTeams,
    createTeam,
    updateTeam,
    deleteTeam,
    restoreTeam,
    fetchMembers,
    syncMembers,
  };
});
