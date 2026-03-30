import { db } from "@/server/db/client";

export type ProjectCard = {
  id: string;
  code: string;
  name: string;
  status: string;
  description: string | null;
  memberCount: number;
  experimentCount: number;
  sampleCount: number;
  materialCount: number;
  instrumentCount: number;
  workflowCount: number;
};

export type ProjectHubData = {
  id: string;
  code: string;
  name: string;
  description: string | null;
  status: string;
  memberCount: number;
  experimentCount: number;
  sampleCount: number;
  materialCount: number;
  instrumentCount: number;
  workflowCount: number;
  documentCount: number;
  pendingExperimentCount: number;
  lowStockLotCount: number;
  activeWorkflowCount: number;
  upcomingBookingCount: number;
  members: Array<{
    id: string;
    name: string;
    role: string;
    projectRole: string;
  }>;
  experiments: Array<{
    id: string;
    title: string;
    status: string;
    performedAt: Date | null;
    latestReview:
      | {
          status: string;
          comment: string | null;
          reviewerName: string;
        }
      | null;
    linkCount: number;
  }>;
  samples: Array<{
    id: string;
    code: string;
    name: string;
    status: string;
    sampleType: string;
    latestEvent:
      | {
          eventType: string;
          occurredAt: Date;
          note: string | null;
        }
      | null;
  }>;
  inventoryLots: Array<{
    id: string;
    materialName: string;
    lotNo: string;
    quantity: number | null;
    unit: string | null;
    location: string | null;
    latestTransaction:
      | {
          type: string;
          occurredAt: Date;
          note: string | null;
        }
      | null;
  }>;
  instruments: Array<{
    id: string;
    code: string;
    name: string;
    status: string;
    location: string | null;
    latestBooking:
      | {
          title: string;
          startAt: Date;
          endAt: Date;
          status: string;
        }
      | null;
  }>;
  workflows: Array<{
    id: string;
    workflowType: string;
    status: string;
    latestTask:
      | {
          stepKey: string;
          status: string;
          comment: string | null;
        }
      | null;
  }>;
  documents: Array<{
    id: string;
    title: string;
    docType: string;
    updatedAt: Date;
  }>;
  timeline: Array<{
    id: string;
    at: Date;
    label: string;
    detail: string;
  }>;
};

export async function listProjectCards(): Promise<ProjectCard[]> {
  const projects = await db.project.findMany({
    orderBy: { createdAt: "asc" },
    include: {
      _count: {
        select: {
          members: true,
          experiments: true,
          samples: true,
          materials: true,
          instruments: true,
          workflows: true,
        },
      },
    },
  });

  return projects.map((project) => ({
    id: project.id,
    code: project.code,
    name: project.name,
    status: project.status,
    description: project.description,
    memberCount: project._count.members,
    experimentCount: project._count.experiments,
    sampleCount: project._count.samples,
    materialCount: project._count.materials,
    instrumentCount: project._count.instruments,
    workflowCount: project._count.workflows,
  }));
}

export async function getProjectHub(
  projectId?: string,
): Promise<ProjectHubData | null> {
  const resolvedProjectId = await resolveProjectId(projectId);

  if (!resolvedProjectId) {
    return null;
  }

  const [project, pendingExperimentCount, lowStockLotCount, activeWorkflowCount, upcomingBookingCount] =
    await Promise.all([
      db.project.findUnique({
        where: { id: resolvedProjectId },
        include: {
          _count: {
            select: {
              members: true,
              experiments: true,
              samples: true,
              materials: true,
              instruments: true,
              workflows: true,
              documents: true,
            },
          },
          members: {
            orderBy: { joinedAt: "asc" },
            include: {
              user: true,
            },
          },
          experiments: {
            orderBy: [{ performedAt: "desc" }, { createdAt: "desc" }],
            take: 5,
            include: {
              reviews: {
                orderBy: { createdAt: "desc" },
                take: 1,
                include: {
                  reviewer: true,
                },
              },
              links: true,
            },
          },
          samples: {
            orderBy: { updatedAt: "desc" },
            take: 5,
            include: {
              events: {
                orderBy: { occurredAt: "desc" },
                take: 1,
              },
            },
          },
          materials: {
            orderBy: { updatedAt: "desc" },
            take: 6,
            include: {
              lots: {
                orderBy: { updatedAt: "desc" },
                include: {
                  transactions: {
                    orderBy: { occurredAt: "desc" },
                    take: 1,
                  },
                },
              },
            },
          },
          instruments: {
            orderBy: { updatedAt: "desc" },
            take: 5,
            include: {
              bookings: {
                orderBy: { startAt: "desc" },
                take: 1,
              },
            },
          },
          workflows: {
            orderBy: { updatedAt: "desc" },
            take: 5,
            include: {
              tasks: {
                orderBy: { updatedAt: "desc" },
              },
            },
          },
          documents: {
            orderBy: { updatedAt: "desc" },
            take: 5,
          },
        },
      }),
      db.experiment.count({
        where: {
          projectId: resolvedProjectId,
          status: {
            in: ["SUBMITTED", "NEEDS_REVISION"],
          },
        },
      }),
      db.materialLot.count({
        where: {
          material: {
            projectId: resolvedProjectId,
          },
          quantity: {
            lte: 2,
          },
        },
      }),
      db.workflowRun.count({
        where: {
          projectId: resolvedProjectId,
          status: {
            in: ["PENDING", "IN_PROGRESS"],
          },
        },
      }),
      db.instrumentBooking.count({
        where: {
          instrument: {
            projectId: resolvedProjectId,
          },
          startAt: {
            gte: new Date(),
          },
          status: {
            in: ["PENDING", "CONFIRMED"],
          },
        },
      }),
    ]);

  if (!project) {
    return null;
  }

  const inventoryLots = project.materials
    .flatMap((material) =>
      material.lots.map((lot) => ({
        id: lot.id,
        materialName: material.name,
        lotNo: lot.lotNo,
        quantity: lot.quantity,
        unit: material.unit,
        location: lot.location,
        latestTransaction: lot.transactions[0]
          ? {
              type: lot.transactions[0].type,
              occurredAt: lot.transactions[0].occurredAt,
              note: lot.transactions[0].note,
            }
          : null,
      })),
    )
    .sort((a, b) => {
      const left = a.quantity ?? Number.POSITIVE_INFINITY;
      const right = b.quantity ?? Number.POSITIVE_INFINITY;
      return left - right;
    })
    .slice(0, 6);

  const timeline = [
    ...project.experiments.map((experiment) => ({
      id: `experiment-${experiment.id}`,
      at: experiment.performedAt ?? experiment.updatedAt,
      label: `实验 ${experiment.title}`,
      detail: `${experiment.status}，关联 ${experiment.links.length} 个对象`,
    })),
    ...project.experiments
      .filter((experiment) => experiment.reviews[0])
      .map((experiment) => ({
        id: `review-${experiment.reviews[0]!.id}`,
        at: experiment.reviews[0]!.createdAt,
        label: `审核 ${experiment.reviews[0]!.reviewer.name}`,
        detail: `${experiment.title} · ${experiment.reviews[0]!.status}`,
      })),
    ...project.samples
      .filter((sample) => sample.events[0])
      .map((sample) => ({
        id: `sample-${sample.events[0]!.id}`,
        at: sample.events[0]!.occurredAt,
        label: `样本 ${sample.code}`,
        detail: `${sample.events[0]!.eventType} · ${sample.name}`,
      })),
    ...inventoryLots
      .filter((lot) => lot.latestTransaction)
      .map((lot) => ({
        id: `inventory-${lot.id}`,
        at: lot.latestTransaction!.occurredAt,
        label: `库存批次 ${lot.lotNo}`,
        detail: `${lot.latestTransaction!.type} · ${lot.materialName}`,
      })),
    ...project.instruments
      .filter((instrument) => instrument.bookings[0])
      .map((instrument) => ({
        id: `booking-${instrument.bookings[0]!.id}`,
        at: instrument.bookings[0]!.startAt,
        label: `仪器 ${instrument.name}`,
        detail: `${instrument.bookings[0]!.title} · ${instrument.bookings[0]!.status}`,
      })),
    ...project.documents.map((document) => ({
      id: `document-${document.id}`,
      at: document.updatedAt,
      label: `知识文档 ${document.docType}`,
      detail: document.title,
    })),
    ...project.workflows.map((workflow) => ({
      id: `workflow-${workflow.id}`,
      at: workflow.updatedAt,
      label: `流程 ${workflow.workflowType}`,
      detail: workflow.status,
    })),
  ]
    .sort((a, b) => b.at.getTime() - a.at.getTime())
    .slice(0, 10);

  return {
    id: project.id,
    code: project.code,
    name: project.name,
    description: project.description,
    status: project.status,
    memberCount: project._count.members,
    experimentCount: project._count.experiments,
    sampleCount: project._count.samples,
    materialCount: project._count.materials,
    instrumentCount: project._count.instruments,
    workflowCount: project._count.workflows,
    documentCount: project._count.documents,
    pendingExperimentCount,
    lowStockLotCount,
    activeWorkflowCount,
    upcomingBookingCount,
    members: project.members.map((member) => ({
      id: member.id,
      name: member.user.name,
      role: member.user.role,
      projectRole: member.projectRole,
    })),
    experiments: project.experiments.map((experiment) => ({
      id: experiment.id,
      title: experiment.title,
      status: experiment.status,
      performedAt: experiment.performedAt,
      latestReview: experiment.reviews[0]
        ? {
            status: experiment.reviews[0].status,
            comment: experiment.reviews[0].comment,
            reviewerName: experiment.reviews[0].reviewer.name,
          }
        : null,
      linkCount: experiment.links.length,
    })),
    samples: project.samples.map((sample) => ({
      id: sample.id,
      code: sample.code,
      name: sample.name,
      status: sample.status,
      sampleType: sample.sampleType,
      latestEvent: sample.events[0]
        ? {
            eventType: sample.events[0].eventType,
            occurredAt: sample.events[0].occurredAt,
            note: sample.events[0].note,
          }
        : null,
    })),
    inventoryLots,
    instruments: project.instruments.map((instrument) => ({
      id: instrument.id,
      code: instrument.code,
      name: instrument.name,
      status: instrument.status,
      location: instrument.location,
      latestBooking: instrument.bookings[0]
        ? {
            title: instrument.bookings[0].title,
            startAt: instrument.bookings[0].startAt,
            endAt: instrument.bookings[0].endAt,
            status: instrument.bookings[0].status,
          }
        : null,
    })),
    workflows: project.workflows.map((workflow) => ({
      id: workflow.id,
      workflowType: workflow.workflowType,
      status: workflow.status,
      latestTask: workflow.tasks[0]
        ? {
            stepKey: workflow.tasks[0].stepKey,
            status: workflow.tasks[0].status,
            comment: workflow.tasks[0].comment,
          }
        : null,
    })),
    documents: project.documents.map((document) => ({
      id: document.id,
      title: document.title,
      docType: document.docType,
      updatedAt: document.updatedAt,
    })),
    timeline,
  };
}

async function resolveProjectId(projectId?: string) {
  if (projectId) {
    return projectId;
  }

  const firstProject = await db.project.findFirst({
    orderBy: { createdAt: "asc" },
    select: { id: true },
  });

  return firstProject?.id ?? null;
}
