const baseUrl = process.env.INVENTREE_BASE_URL;
const token = process.env.INVENTREE_TOKEN;

export async function inventreeRequest(path: string) {
  if (!baseUrl || !token) {
    throw new Error("Missing InvenTree configuration.");
  }

  const response = await fetch(`${baseUrl}${path}`, {
    headers: {
      Authorization: `Token ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error(`InvenTree request failed: ${response.status}`);
  }

  return response.json();
}
