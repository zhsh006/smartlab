import { db } from "@/server/db/client";

const dateFormatter = new Intl.DateTimeFormat("zh-CN", {
  dateStyle: "medium",
  timeStyle: "short",
});

export async function getSampleHub() {
  const [samples, sampleWorkflows, sampleApprovals, sampleLinks] = await Promise.all([
    db.sample.findMany({
      orderBy: [{ updatedAt: "desc" }, { createdAt: "desc" }],
      include: {
        project: true,
        events: {
          orderBy: { occurredAt: "desc" },
        },
      },
    }),
    db.workflowRun.findMany({
      where: {
        entityType: "SAMPLE",
      },
      orderBy: { updatedAt: "desc" },
      include: {
        project: true,
        tasks: {
          orderBy: { createdAt: "desc" },
        },
      },
    }),
    db.approval.findMany({
      where: {
        entityType: "SAMPLE",
      },
      orderBy: { createdAt: "desc" },
      include: {
        approver: true,
      },
    }),
    db.experimentLink.findMany({
      where: {
        entityType: "SAMPLE",
      },
      include: {
        experiment: {
          include: {
            project: true,
          },
        },
      },
      orderBy: { createdAt: "desc" },
    }),
  ]);

  const experimentCountBySample = new Map<string, number>();
  const latestExperimentBySample = new Map<
    string,
    {
      title: string;
      projectName: string;
      status: string;
    }
  >();

  for (const link of sampleLinks) {
    experimentCountBySample.set(
      link.entityId,
      (experimentCountBySample.get(link.entityId) ?? 0) + 1,
    );

    if (!latestExperimentBySample.has(link.entityId)) {
      latestExperimentBySample.set(link.entityId, {
        title: link.experiment.title,
        projectName: link.experiment.project.name,
        status: link.experiment.status,
      });
    }
  }

  const samplesWithContext = samples.map((sample) => {
    const latestEvent = sample.events[0] ?? null;
    const relatedExperiments = experimentCountBySample.get(sample.id) ?? 0;
    const latestExperiment = latestExperimentBySample.get(sample.id) ?? null;

    return {
      ...sample,
      latestEvent,
      relatedExperiments,
      latestExperiment,
    };
  });

  const recentEvents = samplesWithContext
    .flatMap((sample) =>
      sample.events.map((event) => ({
        id: event.id,
        sampleCode: sample.code,
        sampleName: sample.name,
        projectName: sample.project.name,
        eventType: event.eventType,
        note: event.note,
        occurredAt: event.occurredAt,
        occurredAtLabel: dateFormatter.format(event.occurredAt),
      })),
    )
    .sort((a, b) => b.occurredAt.getTime() - a.occurredAt.getTime())
    .slice(0, 6);

  const activeSamples = samplesWithContext.filter((sample) => sample.status === "ACTIVE");
  const sampleRiskCount = samplesWithContext.filter(
    (sample) => sample.relatedExperiments === 0 || sample.events.length === 0,
  ).length;
  const pendingWorkflowCount = sampleWorkflows.filter(
    (workflow) => workflow.status === "PENDING" || workflow.status === "IN_PROGRESS",
  ).length;

  return {
    summary: {
      totalSamples: samplesWithContext.length,
      activeSamples: activeSamples.length,
      pendingWorkflowCount,
      recentEventCount: recentEvents.length,
      sampleRiskCount,
    },
    samples: samplesWithContext,
    recentEvents,
    workflows: sampleWorkflows.map((workflow) => ({
      id: workflow.id,
      workflowType: workflow.workflowType,
      projectName: workflow.project.name,
      status: workflow.status,
      entityId: workflow.entityId,
      latestTask: workflow.tasks[0] ?? null,
    })),
    approvals: sampleApprovals.map((approval) => ({
      id: approval.id,
      decision: approval.decision,
      comment: approval.comment,
      approverName: approval.approver.name,
      entityId: approval.entityId,
      createdAtLabel: dateFormatter.format(approval.createdAt),
    })),
  };
}
