import { db } from "@/server/db/client";

export async function listExperiments() {
  return db.experiment.findMany({
    orderBy: [{ performedAt: "desc" }, { createdAt: "desc" }],
    include: {
      project: true,
      reviews: {
        orderBy: { createdAt: "desc" },
        take: 1,
      },
      links: true,
    },
  });
}

export async function getWorkspaceSummary() {
  const [projects, experiments, reviews, lowStockLots, bookings] =
    await Promise.all([
      db.project.count(),
      db.experiment.count({
        where: {
          status: {
            in: ["SUBMITTED", "NEEDS_REVISION"],
          },
        },
      }),
      db.experimentReview.count({
        where: { status: "NEEDS_REVISION" },
      }),
      db.materialLot.count({
        where: {
          quantity: {
            lte: 2,
          },
        },
      }),
      db.instrumentBooking.count({
        where: {
          startAt: {
            gte: new Date("2026-03-27T00:00:00+08:00"),
            lte: new Date("2026-03-27T23:59:59+08:00"),
          },
        },
      }),
    ]);

  return {
    projects,
    experiments,
    reviews,
    lowStockLots,
    bookings,
  };
}

export async function getExperimentDetail(id: string) {
  return db.experiment.findUnique({
    where: { id },
    include: {
      project: true,
      reviews: {
        orderBy: { createdAt: "desc" },
        include: {
          reviewer: true,
        },
      },
      revisions: {
        orderBy: { createdAt: "desc" },
      },
      links: {
        orderBy: { createdAt: "asc" },
      },
      analysisRuns: {
        orderBy: { createdAt: "desc" },
      },
    },
  });
}

export async function getAssayPackContext() {
  const [projects, samples, materialLots, instruments] = await Promise.all([
    db.project.findMany({
      orderBy: { name: "asc" },
      select: {
        id: true,
        code: true,
        name: true,
      },
    }),
    db.sample.findMany({
      orderBy: [{ updatedAt: "desc" }, { createdAt: "desc" }],
      take: 20,
      select: {
        id: true,
        code: true,
        name: true,
        project: {
          select: {
            name: true,
          },
        },
      },
    }),
    db.materialLot.findMany({
      orderBy: [{ updatedAt: "desc" }, { createdAt: "desc" }],
      take: 20,
      select: {
        id: true,
        lotNo: true,
        material: {
          select: {
            name: true,
            code: true,
          },
        },
      },
    }),
    db.instrument.findMany({
      orderBy: { name: "asc" },
      select: {
        id: true,
        code: true,
        name: true,
      },
    }),
  ]);

  return {
    projects,
    samples: samples.map((sample) => ({
      id: sample.id,
      label: `${sample.code} · ${sample.name}`,
      projectName: sample.project.name,
    })),
    materialLots: materialLots.map((lot) => ({
      id: lot.id,
      label: `${lot.material.code} · ${lot.material.name} · Lot ${lot.lotNo}`,
    })),
    instruments: instruments.map((instrument) => ({
      id: instrument.id,
      label: `${instrument.code} · ${instrument.name}`,
    })),
  };
}
