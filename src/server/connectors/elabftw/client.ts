const baseUrl = process.env.ELABFTW_BASE_URL;
const apiKey = process.env.ELABFTW_API_KEY;
const verifyTls = process.env.ELABFTW_VERIFY_TLS !== "false";

export async function elabftwRequest(path: string) {
  if (!baseUrl || !apiKey) {
    throw new Error("Missing eLabFTW configuration.");
  }

  if (!verifyTls) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = "0";
  }

  const response = await fetch(`${baseUrl}${path}`, {
    headers: {
      Authorization: apiKey,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error(`eLabFTW request failed: ${response.status}`);
  }

  return response.json();
}
