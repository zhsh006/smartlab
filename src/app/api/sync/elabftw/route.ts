import { NextResponse } from "next/server";
import { syncElabftwExperiments } from "@/features/integrations/elabftw-sync";

export async function POST(request: Request) {
  try {
    const body = (await request.json().catch(() => ({}))) as {
      projectCode?: string;
    };
    const result = await syncElabftwExperiments(body.projectCode);
    return NextResponse.json({ ok: true, ...result });
  } catch (error) {
    return NextResponse.json(
      {
        ok: false,
        message: error instanceof Error ? error.message : "Unknown sync error",
      },
      { status: 500 },
    );
  }
}
