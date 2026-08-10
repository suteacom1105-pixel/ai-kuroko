export type Role = 'owner' | 'staff' | 'unknown';

function ownerUserId(): string {
  const id = process.env.GO_LINE_USER_ID;
  if (!id) throw new Error('GO_LINE_USER_ID is not set');
  return id;
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
