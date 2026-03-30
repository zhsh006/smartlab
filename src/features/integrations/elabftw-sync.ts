import { db } from "@/server/db/client";
import { listElabftwExperiments } from "@/server/connectors/elabftw/service";

type SyncResult = {
  imported: number;
  skipped: number;
};

export async function syncElabftwExperiments(
  projectCode = "SLV2-001",
): Promise<SyncResult> {
  const project = await db.project.findUnique({
    where: { code: projectCode },
  });

  if (!project) {
    throw new Error(`Project ${projectCode} not found.`);
  }

  const externalExperiments = await listElabftwExperiments();

  let imported = 0;
  let skipped = 0;

  for (const item of externalExperiments) {
    const sourceRef = String(item.id);
    const existing = await db.experiment.findFirst({
      where: {
        sourceSystem: "ELABFTW",
        sourceRef,
      },
    });

    if (existing) {
      skipped += 1;
      continue;
    }

    const experiment = await db.experiment.create({
      data: {
        projectId: project.id,
        title: item.title || `eLabFTW Experiment #${item.id}`,
        summary: item.body?.slice(0, 280) || null,
        sourceSystem: "ELABFTW",
        sourceRef,
        authorName: "eLabFTW User",
        performedAt: item.createdAt
          ? new Date(item.createdAt)
          : item.created_at
            ? new Date(item.created_at)
            : null,
        status: "SUBMITTED",
      },
    });

    await db.externalRecord.create({
      data: {
        system: "ELABFTW",
        localType: "Experiment",
        localId: experiment.id,
        externalId: sourceRef,
        externalUrl: `${process.env.ELABFTW_BASE_URL?.replace("/api/v2", "")}/experiments.php?mode=view&id=${sourceRef}`,
        syncStatus: "IMPORTED",
        syncedAt: new Date(),
      },
    });

    imported += 1;
  }

  return { imported, skipped };
}
