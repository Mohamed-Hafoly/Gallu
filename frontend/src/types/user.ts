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
}
