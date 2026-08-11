export type Role = 'owner' | 'staff' | 'unknown';

function ownerUserId(): string | undefined {
  return process.env.GO_LINE_USER_ID;
}

function staffUserIds(): string[] {
  return (process.env.STAFF_LINE_USER_IDS ?? '')
    .split(',')
    .map((id) => id.trim())
    .filter(Boolean);
}

export function resolveRole(userId: string | undefined): Role {
  if (!userId) return 'unknown';
  if (userId === ownerUserId()) return 'owner';
  if (staffUserIds().includes(userId)) return 'staff';
  return 'unknown';
}
