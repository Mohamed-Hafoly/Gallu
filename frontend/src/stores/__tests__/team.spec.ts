import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useTeamStore } from "@/stores/team";

vi.mock("@/plugins/axios", () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

const mockedApi = vi.mocked(api);

const team = {
  id: 3,
  name: "Design",
  description: "The design team",
  creator: "Ada Lovelace",
  members_count: 2,
  deleted_at: null,
};

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("fetchAllTeams", () => {
  it("requests the admin listing and unwraps the data envelope", async () => {
    const teams = [team, { ...team, id: 4, deleted_at: "2026-08-15T10:00:00Z" }];
    mockedApi.get.mockResolvedValue({ data: { data: teams } });

    await expect(useTeamStore().fetchAllTeams()).resolves.toEqual(teams);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/teams");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.get.mockRejectedValue(new Error("boom"));

    await expect(useTeamStore().fetchAllTeams()).rejects.toThrow("boom");
  });
});

describe("fetchPickerTeams", () => {
  // Its own endpoint because the admin listing deliberately includes trashed
  // rows, which must never be offered as a team to join.
  it("requests the picker endpoint, not the listing", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: [team] } });

    await expect(useTeamStore().fetchPickerTeams()).resolves.toEqual([team]);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/teams/picker");
  });
});

describe("createTeam", () => {
  it("posts the name and description", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await expect(
      useTeamStore().createTeam({ name: "Design", description: "The design team" }),
    ).resolves.toEqual(team);

    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams", {
      name: "Design",
      description: "The design team",
    });
  });

  // The column is nullable, so an untouched description is null rather than "".
  it("carries a null description through", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await useTeamStore().createTeam({ name: "Design", description: null });

    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams", {
      name: "Design",
      description: null,
    });
  });
});

describe("updateTeam", () => {
  it("patches by id", async () => {
    mockedApi.patch.mockResolvedValue({ data: { data: team } });

    await expect(
      useTeamStore().updateTeam(3, { name: "Product", description: null }),
    ).resolves.toEqual(team);

    expect(mockedApi.patch).toHaveBeenCalledWith("/api/teams/3", {
      name: "Product",
      description: null,
    });
  });
});

describe("deleteTeam", () => {
  it("deletes by id", async () => {
    mockedApi.delete.mockResolvedValue({ status: 204 });

    await useTeamStore().deleteTeam(3);

    expect(mockedApi.delete).toHaveBeenCalledWith("/api/teams/3");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.delete.mockRejectedValue(new Error("boom"));

    await expect(useTeamStore().deleteTeam(3)).rejects.toThrow("boom");
  });
});

describe("restoreTeam", () => {
  it("posts to the team's restore endpoint", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await expect(useTeamStore().restoreTeam(3)).resolves.toEqual(team);
    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams/3/restore");
  });
});

const member = {
  id: 7,
  name: "Grace Hopper",
  email: "grace@example.com",
  role: "member",
  team: { id: 3, name: "Design" },
  is_super_admin: false,
};

describe("fetchMembers", () => {
  it("requests the team's members and unwraps the data envelope", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: [member] } });

    await expect(useTeamStore().fetchMembers(3)).resolves.toEqual([member]);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/teams/3/members");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.get.mockRejectedValue(new Error("boom"));

    await expect(useTeamStore().fetchMembers(3)).rejects.toThrow("boom");
  });
});

describe("syncMembers", () => {
  // One whole-state write replaced the per-member add/patch/delete calls, so a
  // save cannot half-apply.
  it("puts the whole desired membership in the API's casing", async () => {
    mockedApi.put.mockResolvedValue({ data: { data: [member] } });

    await expect(
      useTeamStore().syncMembers(3, [
        { userId: 7, role: "admin" },
        { userId: 9, role: "member" },
      ]),
    ).resolves.toEqual([member]);

    expect(mockedApi.put).toHaveBeenCalledWith("/api/teams/3/members", {
      members: [
        { user_id: 7, role: "admin" },
        { user_id: 9, role: "member" },
      ],
    });
  });

  // The key is `present`, not `required`, on the backend: an empty list is a
  // real instruction meaning "remove everyone".
  it("sends an empty list rather than omitting the key", async () => {
    mockedApi.put.mockResolvedValue({ data: { data: [] } });

    await expect(useTeamStore().syncMembers(3, [])).resolves.toEqual([]);

    expect(mockedApi.put).toHaveBeenCalledWith("/api/teams/3/members", {
      members: [],
    });
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.put.mockRejectedValue(new Error("boom"));

    await expect(useTeamStore().syncMembers(3, [])).rejects.toThrow("boom");
  });
});

describe("createTeam members", () => {
  it("serialises picked members into the API's casing", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await useTeamStore().createTeam({
      name: "Design",
      description: null,
      members: [
        { userId: 7, role: "admin" },
        { userId: 9, role: "member" },
      ],
    });

    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams", {
      name: "Design",
      description: null,
      members: [
        { user_id: 7, role: "admin" },
        { user_id: 9, role: "member" },
      ],
    });
  });

  // The backend rule is `sometimes`, so an empty pick list must not send the
  // key at all rather than an empty array.
  it("omits the key entirely when nothing was picked", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await useTeamStore().createTeam({
      name: "Design",
      description: null,
      members: [],
    });

    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams", {
      name: "Design",
      description: null,
    });
  });

  it("omits the key when members is absent", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: team } });

    await useTeamStore().createTeam({ name: "Design", description: null });

    expect(mockedApi.post).toHaveBeenCalledWith("/api/teams", {
      name: "Design",
      description: null,
    });
  });
});
