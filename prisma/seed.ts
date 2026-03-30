import {
  ApprovalDecision,
  BookingStatus,
  ExperimentStatus,
  InstrumentStatus,
  InventoryTxnType,
  PrismaClient,
  ProjectStatus,
  ReviewStatus,
  SampleStatus,
  UserRole,
  WorkflowStatus,
} from "@prisma/client";

const db = new PrismaClient();

async function main() {
  await db.auditEvent.deleteMany();
  await db.externalRecord.deleteMany();
  await db.approval.deleteMany();
  await db.workflowTask.deleteMany();
  await db.workflowRun.deleteMany();
  await db.instrumentBooking.deleteMany();
  await db.instrument.deleteMany();
  await db.inventoryTransaction.deleteMany();
  await db.materialLot.deleteMany();
  await db.material.deleteMany();
  await db.sampleEvent.deleteMany();
  await db.sample.deleteMany();
  await db.experimentRevision.deleteMany();
  await db.experimentReview.deleteMany();
  await db.experimentLink.deleteMany();
  await db.analysisRun.deleteMany();
  await db.experiment.deleteMany();
  await db.knowledgeDocument.deleteMany();
  await db.projectMember.deleteMany();
  await db.project.deleteMany();
  await db.user.deleteMany();

  const mentor = await db.user.create({
    data: {
      username: "mentor_liu",
      name: "刘导师",
      email: "mentor@example.com",
      role: UserRole.MENTOR,
    },
  });

  const student = await db.user.create({
    data: {
      username: "student_lin",
      name: "林同学",
      email: "student@example.com",
      role: UserRole.STUDENT,
    },
  });

  const project = await db.project.create({
    data: {
      code: "SLV2-001",
      name: "脑损伤后炎症因子动态观察",
      description: "聚合动物建模、ELISA、qPCR 与周报审核的统一试点项目。",
      status: ProjectStatus.ACTIVE,
      members: {
        create: [
          { userId: mentor.id, projectRole: "PI" },
          { userId: student.id, projectRole: "研究生" },
        ],
      },
    },
  });

  const experiment = await db.experiment.create({
    data: {
      projectId: project.id,
      title: "ELISA 复测 03-27",
      summary: "复测 TNF-a / IL-6 两组孔板，确认前日异常高值。",
      status: ExperimentStatus.SUBMITTED,
      authorName: student.name,
      performedAt: new Date("2026-03-27T09:30:00+08:00"),
      sourceSystem: "ELABFTW",
      sourceRef: "123",
      revisions: {
        create: [
          {
            action: "CREATED",
            payload: JSON.stringify({ source: "seed" }),
          },
          {
            action: "SUBMITTED",
            payload: JSON.stringify({ reviewer: mentor.name }),
          },
        ],
      },
      reviews: {
        create: {
          reviewerId: mentor.id,
          status: ReviewStatus.NEEDS_REVISION,
          comment: "补充标准曲线截图，并解释 2 个异常值的处理方式。",
        },
      },
      analysisRuns: {
        create: {
          assayType: "ELISA",
          status: "SUCCESS",
          inputFileRef: "storage/mock/elisa-20260327.xlsx",
          outputJson: JSON.stringify({
            charts: ["standard-curve", "group-comparison"],
            flags: ["A3 high"],
          }),
        },
      },
    },
  });

  const sample = await db.sample.create({
    data: {
      projectId: project.id,
      code: "SMP-20260327-01",
      name: "海马组织匀浆 A 组",
      sampleType: "组织样本",
      status: SampleStatus.ACTIVE,
      events: {
        create: [
          {
            eventType: "COLLECTED",
            occurredAt: new Date("2026-03-26T14:00:00+08:00"),
            note: "按 SOP-HT-04 完成采集与编号。",
          },
          {
            eventType: "ALIQUOTED",
            occurredAt: new Date("2026-03-27T08:20:00+08:00"),
            note: "分装 6 管用于 ELISA / qPCR。",
          },
        ],
      },
    },
  });

  const material = await db.material.create({
    data: {
      projectId: project.id,
      code: "MAT-ELISA-TNF",
      name: "TNF-a ELISA 试剂盒",
      category: "REAGENT",
      unit: "kit",
      lots: {
        create: {
          lotNo: "LOT-2409-A",
          expiresAt: new Date("2026-09-30T00:00:00+08:00"),
          quantity: 2,
          location: "4F 冰箱 A-2",
          transactions: {
            create: [
              {
                type: InventoryTxnType.INBOUND,
                quantity: 5,
                occurredAt: new Date("2026-03-01T10:00:00+08:00"),
                note: "采购入库",
              },
              {
                type: InventoryTxnType.OUTBOUND,
                quantity: 3,
                occurredAt: new Date("2026-03-27T09:00:00+08:00"),
                note: "实验领用",
              },
            ],
          },
        },
      },
    },
    include: {
      lots: true,
    },
  });

  const instrument = await db.instrument.create({
    data: {
      projectId: project.id,
      code: "INS-MP-001",
      name: "多功能酶标仪",
      category: "Plate Reader",
      status: InstrumentStatus.AVAILABLE,
      location: "中心实验室 304",
      bookings: {
        create: {
          title: "ELISA 复测读板",
          userId: student.id,
          startAt: new Date("2026-03-27T10:00:00+08:00"),
          endAt: new Date("2026-03-27T11:30:00+08:00"),
          status: BookingStatus.COMPLETED,
        },
      },
    },
  });

  await db.experimentLink.createMany({
    data: [
      {
        experimentId: experiment.id,
        entityType: "SAMPLE",
        entityId: sample.id,
        label: sample.name,
      },
      {
        experimentId: experiment.id,
        entityType: "MATERIAL_LOT",
        entityId: material.lots[0].id,
        label: `${material.name} / ${material.lots[0].lotNo}`,
      },
      {
        experimentId: experiment.id,
        entityType: "INSTRUMENT",
        entityId: instrument.id,
        label: instrument.name,
      },
    ],
  });

  const workflow = await db.workflowRun.create({
    data: {
      projectId: project.id,
      workflowType: "EXPERIMENT_REVIEW",
      entityType: "SAMPLE",
      entityId: sample.id,
      status: WorkflowStatus.IN_PROGRESS,
      tasks: {
        create: [
          {
            stepKey: "mentor-review",
            assigneeId: mentor.id,
            status: WorkflowStatus.IN_PROGRESS,
            comment: "等待导师确认附件补充后归档。",
          },
        ],
      },
    },
  });

  await db.approval.create({
    data: {
      workflowRunId: workflow.id,
      approverId: mentor.id,
      entityType: "SAMPLE",
      entityId: sample.id,
      decision: ApprovalDecision.RETURNED,
      comment: "请先补充 ELISA 标准曲线文件。",
    },
  });

  await db.knowledgeDocument.create({
    data: {
      projectId: project.id,
      title: "ELISA 复测异常值处理经验",
      docType: "NOTE",
      content: "记录异常孔识别、标准曲线校正和导师修改建议。",
    },
  });

  await db.auditEvent.createMany({
    data: [
      {
        actorId: student.id,
        eventType: "EXPERIMENT_SUBMITTED",
        entityType: "Experiment",
        entityId: experiment.id,
        payload: JSON.stringify({ project: project.code }),
      },
      {
        actorId: mentor.id,
        eventType: "EXPERIMENT_RETURNED",
        entityType: "Experiment",
        entityId: experiment.id,
        payload: JSON.stringify({ reason: "missing attachments" }),
      },
    ],
  });
}

main()
  .then(async () => {
    await db.$disconnect();
  })
  .catch(async (error) => {
    console.error(error);
    await db.$disconnect();
    process.exit(1);
  });
