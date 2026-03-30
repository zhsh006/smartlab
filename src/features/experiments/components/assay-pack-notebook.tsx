"use client";

import Link from "next/link";
import { startTransition, useEffect, useRef, useState } from "react";
import {
  assayPackCatalog,
  assayPacks,
  captureModes,
  type CaptureMode,
} from "@/features/experiments/assay-packs";
import { getQpcrSubmissionGate } from "@/features/experiments/assay-pack-qc";

type CoverState = Record<string, Record<string, string>>;
type StepNotesState = Record<string, string[]>;
type ResultRowsState = Record<string, string[][]>;
type SummaryNotesState = Record<string, string>;
type LinkedSelectionsState = Record<
  string,
  {
    projectId: string;
    sampleId: string;
    lotId: string;
    instrumentId: string;
  }
>;

type AssayPackNotebookContext = {
  projects: Array<{
    id: string;
    code: string;
    name: string;
  }>;
  samples: Array<{
    id: string;
    label: string;
    projectName: string;
  }>;
  materialLots: Array<{
    id: string;
    label: string;
  }>;
  instruments: Array<{
    id: string;
    label: string;
  }>;
};

const DRAFT_STORAGE_KEY = "labsmart-assay-pack-draft-v1";

function buildInitialCoverState() {
  return assayPacks.reduce<CoverState>((acc, pack) => {
    acc[pack.key] = pack.coverFields.reduce<Record<string, string>>((fields, field) => {
      fields[field] = "";
      return fields;
    }, {});
    return acc;
  }, {});
}

function buildInitialStepNotesState() {
  return assayPacks.reduce<StepNotesState>((acc, pack) => {
    acc[pack.key] = pack.protocolSteps.map(() => "");
    return acc;
  }, {});
}

function buildInitialResultRowsState() {
  return {
    qpcr: [
      ["Sample-01", "GAPDH", "22.41", "18.72", "3.69", "0.00", "1.00"],
      ["Sample-02", "GAPDH", "22.08", "18.27", "3.81", "1.12", "2.17"],
      ["NTC", "GAPDH", "Undetermined", "-", "-", "-", "-"],
    ],
    "western-blot": [
      ["Lane 1", "STAT3", "86kDa", "18234", "1.00", "Baseline"],
      ["Lane 2", "STAT3", "86kDa", "26791", "1.47", "Up"],
      ["Lane 3", "GAPDH", "37kDa", "18122", "1.00", "Reference"],
    ],
    if: [
      ["Field-01", "128", "34%", "182.4", "0.41"],
      ["Field-02", "117", "38%", "196.2", "0.44"],
      ["Field-03", "121", "12%", "73.1", "0.08"],
    ],
    ihc: [
      ["Case-01", "胞浆", "62%", "2+", "148", "9"],
      ["Case-02", "胞膜", "47%", "2+", "121", "8"],
      ["Case-03", "阴性", "8%", "1+", "26", "2"],
    ],
  };
}

function buildInitialSummaryNotes() {
  return {
    qpcr: "处理组相对对照组呈上调趋势，等待确认第二批重复。",
    "western-blot": "目标条带可识别，建议补一张低曝光图以便审核。",
    if: "荧光信号存在增强趋势，建议补充共定位定量。",
    ihc: "病理评分已形成差异趋势，建议补充代表性高倍图。",
  };
}

function buildInitialLinkedSelectionsState() {
  return assayPacks.reduce<LinkedSelectionsState>((acc, pack) => {
    acc[pack.key] = {
      projectId: "",
      sampleId: "",
      lotId: "",
      instrumentId: "",
    };
    return acc;
  }, {});
}

function getResultGridClass(columnCount: number) {
  switch (columnCount) {
    case 5:
      return "grid-cols-5";
    case 6:
      return "grid-cols-6";
    case 7:
      return "grid-cols-7";
    default:
      return "grid-cols-6";
  }
}

function getCanvasHint(title: string, mode: CaptureMode) {
  if (mode === "QUICK") {
    return `当前显示 ${title} 的快速模板，只保留最少必填项、核心结果和异常说明。`;
  }

  return `当前显示 ${title} 的标准模板，封面、步骤、结果表、对象引用和 QC 规则全部展开。`;
}

function getQpcrSummary(rows: string[][], note: string) {
  const foldChanges = rows
    .map((row) => Number(row[6]))
    .filter((value) => Number.isFinite(value));
  const maxFoldChange = foldChanges.length ? Math.max(...foldChanges) : null;
  const ntcRow = rows.find((row) => row[0].toUpperCase().includes("NTC"));
  const ntcClean = ntcRow ? ntcRow[2] === "Undetermined" : false;

  return [
    `结论：${
      maxFoldChange && maxFoldChange > 1
        ? `最高 Fold Change 为 ${maxFoldChange.toFixed(2)}，呈上调趋势。`
        : "当前未形成明确上调结论。"
    }`,
    `状态：${ntcClean ? "阴性对照正常，可进入审核。" : "阴性对照需复核，暂不建议归档。"} `,
    `建议：${note || "补充实验后摘要，明确是否需要复验或扩大样本量。"} `,
  ];
}

function getGenericSummary(title: string, note: string) {
  return [
    `结论：${title} 的结构化结果已录入，可继续补充图像与备注。`,
    "状态：模板画布已支持本地编辑，等待接入持久化与对象引用。",
    `建议：${note || "补充本次实验摘要，给出是否成功和下一步建议。"} `,
  ];
}

function getQcFeedback(packKey: string, rows: string[][]) {
  if (packKey === "qpcr") {
    const ntcRow = rows.find((row) => row[0].toUpperCase().includes("NTC"));
    const foldValue = Number(rows[1]?.[6]);

    return [
      ntcRow?.[2] === "Undetermined" ? "阴性对照正常" : "阴性对照异常，需复核",
      Number.isFinite(foldValue) && foldValue > 2
        ? "Fold Change 超过 2，建议加入生物学解释"
        : "Fold Change 仍在温和波动区间",
      rows.some((row) => row.includes("-")) ? "结果表存在未补全单元格" : "结果表字段完整",
    ];
  }

  return [
    "已启用结构化录入，可继续补充图像或评分。",
    rows.some((row) => row.some((cell) => cell.trim() === ""))
      ? "结果表存在空白项，提交前建议补全"
      : "结果表字段完整",
    "后续将把这些提示与正式 QC 规则合并。",
  ];
}

type DraftPayload = {
  experimentIds?: Record<string, string>;
  coverValues: CoverState;
  stepNotes: StepNotesState;
  resultRowsState: ResultRowsState;
  summaryNotes: SummaryNotesState;
  linkedSelections: LinkedSelectionsState;
};

function readDraft(): DraftPayload | null {
  try {
    const raw = window.localStorage.getItem(DRAFT_STORAGE_KEY);
    if (!raw) {
      return null;
    }

    return JSON.parse(raw) as DraftPayload;
  } catch {
    return null;
  }
}

export function AssayPackNotebook({
  context,
}: {
  context: AssayPackNotebookContext;
}) {
  const [activePackKey, setActivePackKey] = useState(assayPacks[0]?.key ?? "qpcr");
  const [mode, setMode] = useState<CaptureMode>("STANDARD");
  const [experimentIds, setExperimentIds] = useState<Record<string, string>>({});
  const [coverValues, setCoverValues] = useState<CoverState>(() => buildInitialCoverState());
  const [stepNotes, setStepNotes] = useState<StepNotesState>(() => buildInitialStepNotesState());
  const [resultRowsState, setResultRowsState] = useState<ResultRowsState>(() =>
    buildInitialResultRowsState(),
  );
  const [summaryNotes, setSummaryNotes] = useState<SummaryNotesState>(() =>
    buildInitialSummaryNotes(),
  );
  const [linkedSelections, setLinkedSelections] = useState<LinkedSelectionsState>(() =>
    buildInitialLinkedSelectionsState(),
  );
  const draftReadyRef = useRef(false);
  const [saveStatus, setSaveStatus] = useState("未保存到数据库");
  const [activeMutation, setActiveMutation] = useState<"SAVE_DRAFT" | "SUBMIT_REVIEW" | null>(
    null,
  );

  const activePack =
    assayPacks.find((pack) => pack.key === activePackKey) ?? assayPacks[0];
  const resultRows = resultRowsState[activePack.key] ?? [];
  const resultGridClass = getResultGridClass(activePack.resultColumns.length);
  const visibleSections =
    mode === "QUICK"
      ? activePack.standardMode.slice(0, 2)
      : activePack.standardMode;
  const visibleFields =
    mode === "QUICK" ? activePack.quickMode : activePack.coverFields;
  const summaryCards =
    activePack.key === "qpcr"
      ? getQpcrSummary(resultRows, summaryNotes[activePack.key] ?? "")
      : getGenericSummary(activePack.title, summaryNotes[activePack.key] ?? "");
  const qcFeedback = getQcFeedback(activePack.key, resultRows);
  const activeLinks = linkedSelections[activePack.key];
  const selectedProject =
    context.projects.find((project) => project.id === activeLinks.projectId) ?? null;
  const selectedSample =
    context.samples.find((sample) => sample.id === activeLinks.sampleId) ?? null;
  const qPcrSubmissionGate = getQpcrSubmissionGate({
    cover: coverValues.qpcr,
    resultRows: resultRowsState.qpcr,
    projectId: linkedSelections.qpcr.projectId,
    sampleId: linkedSelections.qpcr.sampleId,
  });
  const qPcrValidation =
    activePack.key === "qpcr" ? qPcrSubmissionGate.checks.map((item) => item.label) : [];

  useEffect(() => {
    const draft = readDraft();
    if (draft) {
      const frameId = window.requestAnimationFrame(() => {
        startTransition(() => {
          setExperimentIds(draft.experimentIds ?? {});
          setCoverValues(draft.coverValues);
          setStepNotes(draft.stepNotes);
          setResultRowsState(draft.resultRowsState);
          setSummaryNotes(draft.summaryNotes);
          setLinkedSelections(draft.linkedSelections);
          draftReadyRef.current = true;
        });
      });

      return () => window.cancelAnimationFrame(frameId);
    }

    draftReadyRef.current = true;
  }, []);

  useEffect(() => {
    if (!draftReadyRef.current) {
      return;
    }

    const payload: DraftPayload = {
      experimentIds,
      coverValues,
      stepNotes,
      resultRowsState,
      summaryNotes,
      linkedSelections,
    };

    window.localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(payload));
  }, [coverValues, experimentIds, linkedSelections, resultRowsState, stepNotes, summaryNotes]);

  const updateCoverValue = (field: string, value: string) => {
    setCoverValues((current) => ({
      ...current,
      [activePack.key]: {
        ...current[activePack.key],
        [field]: value,
      },
    }));
  };

  const updateStepNote = (stepIndex: number, value: string) => {
    setStepNotes((current) => ({
      ...current,
      [activePack.key]: current[activePack.key].map((note, index) =>
        index === stepIndex ? value : note,
      ),
    }));
  };

  const updateResultCell = (rowIndex: number, cellIndex: number, value: string) => {
    setResultRowsState((current) => ({
      ...current,
      [activePack.key]: current[activePack.key].map((row, currentRowIndex) =>
        currentRowIndex === rowIndex
          ? row.map((cell, currentCellIndex) =>
              currentCellIndex === cellIndex ? value : cell,
            )
          : row,
      ),
    }));
  };

  const updateLinkedSelection = (
    field: keyof LinkedSelectionsState[string],
    value: string,
  ) => {
    setLinkedSelections((current) => ({
      ...current,
      [activePack.key]: {
        ...current[activePack.key],
        [field]: value,
      },
    }));
  };

  const mutateQpcr = async (intent: "SAVE_DRAFT" | "SUBMIT_REVIEW") => {
    if (activePack.key !== "qpcr") {
      setSaveStatus("当前只开放 qPCR 保存");
      return;
    }

    if (!linkedSelections.qpcr.projectId || !coverValues.qpcr["实验目的"]?.trim()) {
      setSaveStatus("请先补全 qPCR 必填项并选择项目");
      return;
    }

    if (intent === "SUBMIT_REVIEW" && !qPcrSubmissionGate.canSubmit) {
      setSaveStatus("qPCR 质控未通过，暂不能提交审核");
      return;
    }

    setActiveMutation(intent);
    setSaveStatus(intent === "SUBMIT_REVIEW" ? "正在提交审核..." : "正在保存到数据库...");

    try {
      const response = await fetch("/api/assay-packs/draft", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent,
          experimentId: experimentIds.qpcr,
          assayPackKey: "qpcr",
          title: `${coverValues.qpcr["实验目的"] || "qPCR"} · ${coverValues.qpcr["课题编号"] || "未命名课题"}`,
          summary: summaryNotes.qpcr,
          performedAt: coverValues.qpcr["实验日期"] || undefined,
          projectId: linkedSelections.qpcr.projectId,
          sampleId: linkedSelections.qpcr.sampleId || undefined,
          lotId: linkedSelections.qpcr.lotId || undefined,
          instrumentId: linkedSelections.qpcr.instrumentId || undefined,
          payload: {
            cover: coverValues.qpcr,
            stepNotes: stepNotes.qpcr,
            resultRows: resultRowsState.qpcr,
            summaryNote: summaryNotes.qpcr,
          },
        }),
      });

      const result = (await response.json()) as {
        ok: boolean;
        experimentId?: string;
        message?: string;
      };

      if (!response.ok || !result.ok || !result.experimentId) {
        throw new Error(result.message ?? "Draft save failed");
      }

      setExperimentIds((current) => ({
        ...current,
        qpcr: result.experimentId!,
      }));
      setSaveStatus(
        intent === "SUBMIT_REVIEW"
          ? `已提交审核 ${result.experimentId}`
          : `已保存到实验草稿 ${result.experimentId}`,
      );
    } catch (error) {
      setSaveStatus(error instanceof Error ? error.message : "保存失败");
    } finally {
      setActiveMutation(null);
    }
  };

  return (
    <>
      <section className="overflow-hidden rounded-[2rem] border border-stone-300/80 bg-[linear-gradient(135deg,_#f8f3ea_0%,_#f1e7d2_48%,_#e3dccd_100%)] shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)]">
        <div className="border-b border-stone-300/70 bg-[linear-gradient(135deg,_#173f5f_0%,_#214a6a_100%)] px-6 py-6 text-stone-50">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-3xl">
              <div className="text-xs font-semibold tracking-[0.26em] text-stone-300 uppercase">
                Assay Pack Notebook
              </div>
              <h1 className="mt-3 text-3xl font-semibold sm:text-4xl">
                不是门户表单，而是可直接开始填写的实验模板画布
              </h1>
              <p className="mt-4 text-sm leading-7 text-stone-200 sm:text-base">
                模板块现在已经打通本地编辑、数据库草稿和提交审核闭环。封面、步骤备注、
                结果表和实验摘要都会随提交一起进入后续审核视图。
              </p>
            </div>
            <div className="grid gap-3 text-sm text-stone-100 sm:grid-cols-2">
              <div className="rounded-[1.25rem] border border-white/15 bg-white/10 px-4 py-3 backdrop-blur">
                当前示例：{activePack.title}
              </div>
              <div className="rounded-[1.25rem] border border-white/15 bg-white/10 px-4 py-3 backdrop-blur">
                模式：{captureModes[mode].title}
              </div>
              <div className="rounded-[1.25rem] border border-white/15 bg-white/10 px-4 py-3 backdrop-blur sm:col-span-2">
                草稿：自动保存在当前浏览器
              </div>
              <div className="rounded-[1.25rem] border border-white/15 bg-white/10 px-4 py-3 backdrop-blur sm:col-span-2">
                数据库：{saveStatus}
              </div>
            </div>
          </div>

          <div className="mt-6 flex flex-wrap gap-3">
            {assayPacks.map((pack) => {
              const isActive = pack.key === activePack.key;

              return (
                <button
                  key={pack.key}
                  type="button"
                  onClick={() =>
                    startTransition(() => {
                      setActivePackKey(pack.key);
                    })
                  }
                  className={
                    isActive
                      ? "rounded-full border border-white/10 bg-white px-4 py-2 text-sm font-semibold text-stone-900"
                      : "rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm text-stone-100 transition hover:bg-white/20"
                  }
                >
                  {pack.title}
                </button>
              );
            })}
          </div>
        </div>

        <div className="grid gap-6 px-5 py-5 lg:grid-cols-[minmax(0,1.4fr)_340px] lg:px-6 lg:py-6">
          <div className="grid gap-5">
            <article className="overflow-hidden rounded-[1.75rem] border border-stone-200/80 bg-white shadow-[0_24px_60px_-44px_rgba(30,41,59,0.35)]">
              <div className="border-b border-stone-200 bg-[linear-gradient(180deg,_#fcfaf6_0%,_#f5efe3_100%)] px-5 py-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <div className="text-xs font-semibold tracking-[0.22em] text-stone-500 uppercase">
                      Notebook Template
                    </div>
                    <h2 className="mt-3 text-2xl font-semibold text-stone-900">
                      {activePack.title} Assay Pack
                    </h2>
                    <p className="mt-3 max-w-2xl text-sm leading-7 text-stone-600">
                      {activePack.summary}
                    </p>
                    <p className="mt-3 text-sm leading-6 text-stone-500">
                      {getCanvasHint(activePack.title, mode)}
                    </p>
                  </div>
                  <div className="min-w-56 rounded-[1.25rem] border border-stone-200 bg-white/90 p-4">
                    <div className="text-xs font-semibold tracking-[0.2em] text-stone-500 uppercase">
                      自动摘要
                    </div>
                    <p className="mt-3 text-sm leading-6 text-stone-700">
                      结果表变化后，这里的摘要会实时更新。qPCR 已接了基础推断逻辑。
                    </p>
                    <button
                      type="button"
                      onClick={() => void mutateQpcr("SAVE_DRAFT")}
                      disabled={activeMutation !== null || activePack.key !== "qpcr"}
                      className="mt-4 inline-flex rounded-full bg-stone-900 px-4 py-2 text-sm font-medium text-stone-50 transition disabled:cursor-not-allowed disabled:bg-stone-300"
                    >
                      {activeMutation === "SAVE_DRAFT" ? "保存中..." : "保存到实验草稿"}
                    </button>
                    <button
                      type="button"
                      onClick={() => void mutateQpcr("SUBMIT_REVIEW")}
                      disabled={
                        activeMutation !== null ||
                        activePack.key !== "qpcr" ||
                        !qPcrSubmissionGate.canSubmit
                      }
                      className="mt-3 inline-flex rounded-full border border-[#173f5f] px-4 py-2 text-sm font-medium text-[#173f5f] transition disabled:cursor-not-allowed disabled:border-stone-300 disabled:text-stone-400"
                    >
                      {activeMutation === "SUBMIT_REVIEW" ? "提交中..." : "提交审核"}
                    </button>
                    {experimentIds.qpcr ? (
                      <div className="mt-3 grid gap-2">
                        <Link
                          href={`/experiments/${experimentIds.qpcr}`}
                          className="inline-flex text-sm font-medium text-stone-900 underline"
                        >
                          打开已保存实验详情
                        </Link>
                        <Link
                          href="/reviews"
                          className="inline-flex text-sm font-medium text-stone-600 underline"
                        >
                          打开审核中心
                        </Link>
                      </div>
                    ) : null}
                  </div>
                </div>
              </div>

              <div className="grid gap-5 px-5 py-5">
                <section className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-4">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-base font-semibold text-stone-900">实验封面</h3>
                    <span className="rounded-full bg-[#dce7ef] px-3 py-1 text-xs font-medium text-[#173f5f]">
                      {captureModes[mode].title}
                    </span>
                  </div>
                  <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {visibleFields.map((field) => {
                      const isQuickField = mode === "QUICK";
                      const value = isQuickField
                        ? ""
                        : coverValues[activePack.key]?.[field] ?? "";

                      return (
                        <label
                          key={field}
                          className="rounded-[1.1rem] border border-stone-200 bg-white px-4 py-3"
                        >
                          <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                            {field}
                          </div>
                          {isQuickField ? (
                            <div className="mt-3 text-sm leading-6 text-stone-600">
                              {field}
                            </div>
                          ) : (
                            <input
                              value={value}
                              onChange={(event) => updateCoverValue(field, event.target.value)}
                              placeholder={`填写${field}`}
                              className="mt-3 w-full border-0 bg-transparent p-0 text-sm text-stone-700 outline-none placeholder:text-stone-400"
                            />
                          )}
                        </label>
                      );
                    })}
                  </div>
                </section>

                <section className="rounded-[1.5rem] border border-stone-200 bg-white p-4">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-base font-semibold text-stone-900">Protocol Skeleton</h3>
                    <span className="text-sm text-stone-500">按步骤记录</span>
                  </div>
                  <div className="mt-4 grid gap-3">
                    {activePack.protocolSteps.map((step, index) => (
                      <div
                        key={step}
                        className="grid gap-3 rounded-[1.15rem] border border-stone-200 bg-stone-50/70 px-4 py-4 md:grid-cols-[auto_1fr]"
                      >
                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-stone-900 text-sm font-semibold text-stone-50">
                          {index + 1}
                        </div>
                        <div>
                          <div className="font-medium text-stone-900">{step}</div>
                          <textarea
                            value={stepNotes[activePack.key]?.[index] ?? ""}
                            onChange={(event) => updateStepNote(index, event.target.value)}
                            placeholder="在这里写本步骤关键参数、异常说明、附件位置或补充观察"
                            rows={3}
                            className="mt-3 w-full resize-none rounded-[1rem] border border-stone-200 bg-white px-3 py-3 text-sm leading-6 text-stone-700 outline-none placeholder:text-stone-400"
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                </section>

                <section className="rounded-[1.5rem] border border-stone-200 bg-white p-4">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-base font-semibold text-stone-900">Structured Sections</h3>
                    <span className="text-sm text-stone-500">
                      {mode === "QUICK" ? "保留核心字段" : "完整字段展开"}
                    </span>
                  </div>
                  <div className="mt-4 grid gap-3">
                    {visibleSections.map((section) => (
                      <div
                        key={section.title}
                        className="rounded-[1.15rem] border border-stone-200 bg-stone-50/70 px-4 py-4"
                      >
                        <div className="font-medium text-stone-900">{section.title}</div>
                        <div className="mt-3 flex flex-wrap gap-2">
                          {section.fields.map((field) => (
                            <div
                              key={field}
                              className="rounded-full border border-white/80 bg-white px-3 py-2 text-sm text-stone-700"
                            >
                              {field}
                            </div>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                </section>

                <section className="rounded-[1.5rem] border border-stone-200 bg-white p-4">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-base font-semibold text-stone-900">Results Table</h3>
                    <span className="text-sm text-stone-500">结构化结果采集</span>
                  </div>
                  <div className="mt-4 overflow-x-auto">
                    <div className="min-w-[760px] rounded-[1.25rem] border border-stone-200">
                      <div className={`grid ${resultGridClass} border-b border-stone-200 bg-stone-100/80`}>
                        {activePack.resultColumns.map((column) => (
                          <div
                            key={column}
                            className="px-4 py-3 text-xs font-semibold tracking-[0.16em] text-stone-500 uppercase"
                          >
                            {column}
                          </div>
                        ))}
                      </div>
                      {resultRows.map((row, rowIndex) => (
                        <div
                          key={`${activePack.key}-${rowIndex}`}
                          className={`grid ${resultGridClass} border-b border-stone-200 last:border-b-0`}
                        >
                          {row.map((cell, cellIndex) => (
                            <div
                              key={`${activePack.key}-${rowIndex}-${cellIndex}`}
                              className="border-r border-stone-200 last:border-r-0"
                            >
                              <input
                                value={cell}
                                onChange={(event) =>
                                  updateResultCell(rowIndex, cellIndex, event.target.value)
                                }
                                className={`w-full border-0 bg-transparent px-4 py-3 text-sm outline-none ${
                                  cellIndex === 0 ? "font-medium text-stone-900" : "text-stone-600"
                                }`}
                              />
                            </div>
                          ))}
                        </div>
                      ))}
                    </div>
                  </div>
                </section>

                <section className="grid gap-5 xl:grid-cols-[1fr_0.9fr]">
                  <div className="rounded-[1.5rem] border border-stone-200 bg-white p-4">
                    <div className="flex items-center justify-between gap-3">
                      <h3 className="text-base font-semibold text-stone-900">样本与试剂关联</h3>
                      <span className="text-sm text-stone-500">Linked Objects</span>
                    </div>
                    <div className="mt-4 grid gap-3">
                      <label className="rounded-[1rem] border border-stone-200 bg-stone-50/70 px-3 py-3">
                        <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                          项目
                        </div>
                        <select
                          value={activeLinks.projectId}
                          onChange={(event) => updateLinkedSelection("projectId", event.target.value)}
                          className="mt-2 w-full border-0 bg-transparent text-sm text-stone-700 outline-none"
                        >
                          <option value="">选择项目</option>
                          {context.projects.map((project) => (
                            <option key={project.id} value={project.id}>
                              {project.code} · {project.name}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label className="rounded-[1rem] border border-stone-200 bg-stone-50/70 px-3 py-3">
                        <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                          样本
                        </div>
                        <select
                          value={activeLinks.sampleId}
                          onChange={(event) => updateLinkedSelection("sampleId", event.target.value)}
                          className="mt-2 w-full border-0 bg-transparent text-sm text-stone-700 outline-none"
                        >
                          <option value="">选择样本</option>
                          {context.samples.map((sample) => (
                            <option key={sample.id} value={sample.id}>
                              {sample.label} · {sample.projectName}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label className="rounded-[1rem] border border-stone-200 bg-stone-50/70 px-3 py-3">
                        <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                          试剂批次
                        </div>
                        <select
                          value={activeLinks.lotId}
                          onChange={(event) => updateLinkedSelection("lotId", event.target.value)}
                          className="mt-2 w-full border-0 bg-transparent text-sm text-stone-700 outline-none"
                        >
                          <option value="">选择试剂批次</option>
                          {context.materialLots.map((lot) => (
                            <option key={lot.id} value={lot.id}>
                              {lot.label}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label className="rounded-[1rem] border border-stone-200 bg-stone-50/70 px-3 py-3">
                        <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                          仪器
                        </div>
                        <select
                          value={activeLinks.instrumentId}
                          onChange={(event) =>
                            updateLinkedSelection("instrumentId", event.target.value)
                          }
                          className="mt-2 w-full border-0 bg-transparent text-sm text-stone-700 outline-none"
                        >
                          <option value="">选择仪器</option>
                          {context.instruments.map((instrument) => (
                            <option key={instrument.id} value={instrument.id}>
                              {instrument.label}
                            </option>
                          ))}
                        </select>
                      </label>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-3">
                      {activePack.linkedObjects.map((item) => (
                        <div
                          key={item}
                          className="rounded-full border border-stone-200 bg-stone-50 px-4 py-2 text-sm text-stone-700"
                        >
                          {item}
                        </div>
                      ))}
                    </div>
                    <div className="mt-4 rounded-[1.15rem] border border-dashed border-stone-300 bg-stone-50/70 px-4 py-4 text-sm leading-6 text-stone-600">
                      {selectedProject || selectedSample
                        ? `已关联 ${selectedProject ? `${selectedProject.code} · ${selectedProject.name}` : "项目待选"}，${selectedSample ? selectedSample.label : "样本待选"}。`
                        : "这里已经接成对象选择器，当前选择会随草稿一起保存在本地。"}
                    </div>
                  </div>

                  <div className="rounded-[1.5rem] border border-stone-200 bg-white p-4">
                    <div className="flex items-center justify-between gap-3">
                      <h3 className="text-base font-semibold text-stone-900">
                        {activePack.key === "qpcr" ? "实验后摘要" : "Image Canvas"}
                      </h3>
                      <span className="text-sm text-stone-500">
                        {activePack.key === "qpcr" ? "Auto Summary" : "Image + Notes"}
                      </span>
                    </div>
                    {activePack.key === "qpcr" ? (
                      <div className="mt-4 space-y-3">
                        {summaryCards.map((item) => (
                          <div
                            key={item}
                            className="rounded-[1.1rem] border border-stone-200 bg-stone-50/75 px-4 py-3 text-sm text-stone-700"
                          >
                            {item}
                          </div>
                        ))}
                        <textarea
                          value={summaryNotes[activePack.key] ?? ""}
                          onChange={(event) =>
                            setSummaryNotes((current) => ({
                              ...current,
                              [activePack.key]: event.target.value,
                            }))
                          }
                          rows={3}
                          placeholder="补充实验员主观判断、下步计划或异常解释"
                          className="w-full resize-none rounded-[1rem] border border-stone-200 bg-white px-3 py-3 text-sm leading-6 text-stone-700 outline-none placeholder:text-stone-400"
                        />
                      </div>
                    ) : (
                      <div className="mt-4 grid gap-3">
                        <div className="grid h-28 place-items-center rounded-[1.1rem] border border-dashed border-stone-300 bg-stone-50 text-sm text-stone-500">
                          原图 / 多通道图
                        </div>
                        <div className="grid h-28 place-items-center rounded-[1.1rem] border border-dashed border-stone-300 bg-stone-50 text-sm text-stone-500">
                          裁剪图 / Merge 图 / 局部放大
                        </div>
                        <textarea
                          value={summaryNotes[activePack.key] ?? ""}
                          onChange={(event) =>
                            setSummaryNotes((current) => ({
                              ...current,
                              [activePack.key]: event.target.value,
                            }))
                          }
                          rows={3}
                          placeholder="记录本次图像判读、病理观察或复验建议"
                          className="w-full resize-none rounded-[1rem] border border-stone-200 bg-white px-3 py-3 text-sm leading-6 text-stone-700 outline-none placeholder:text-stone-400"
                        />
                      </div>
                    )}
                  </div>
                </section>
              </div>
            </article>
          </div>

          <aside className="grid gap-5">
            <article className="rounded-[1.75rem] border border-stone-200 bg-[#f7f5ef] p-5 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)]">
              <div className="text-xs font-semibold tracking-[0.24em] text-stone-500 uppercase">
                QC Guardrails
              </div>
              <h3 className="mt-3 text-lg font-semibold text-stone-900">质控侧栏</h3>
              <ul className="mt-4 space-y-3 text-sm leading-6 text-stone-700">
                {[...qcFeedback, ...qPcrValidation].map((item) => (
                  <li
                    key={item}
                    className="rounded-[1.1rem] border border-stone-200 bg-white px-4 py-3"
                  >
                    {item}
                  </li>
                ))}
              </ul>
            </article>

            <article className="rounded-[1.75rem] border border-stone-200 bg-[#f7f5ef] p-5 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)]">
              <div className="text-xs font-semibold tracking-[0.24em] text-stone-500 uppercase">
                Mode System
              </div>
              <h3 className="mt-3 text-lg font-semibold text-stone-900">快速 / 标准模式</h3>
              <div className="mt-4 space-y-4">
                {(Object.entries(captureModes) as [CaptureMode, (typeof captureModes)[CaptureMode]][]).map(
                  ([key, modeOption]) => {
                    const isActive = key === mode;

                    return (
                      <button
                        key={modeOption.title}
                        type="button"
                        onClick={() =>
                          startTransition(() => {
                            setMode(key);
                          })
                        }
                        className={
                          isActive
                            ? "block w-full rounded-[1.1rem] border border-stone-900 bg-stone-900 px-4 py-4 text-left text-stone-50"
                            : "block w-full rounded-[1.1rem] border border-stone-200 bg-white px-4 py-4 text-left transition hover:border-stone-400"
                        }
                      >
                        <div className={`font-medium ${isActive ? "text-stone-50" : "text-stone-900"}`}>
                          {modeOption.title}
                        </div>
                        <p
                          className={`mt-2 text-sm leading-6 ${
                            isActive ? "text-stone-300" : "text-stone-600"
                          }`}
                        >
                          {modeOption.description}
                        </p>
                      </button>
                    );
                  },
                )}
              </div>
            </article>

            <article className="rounded-[1.75rem] border border-stone-200 bg-[#f7f5ef] p-5 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)]">
              <div className="text-xs font-semibold tracking-[0.24em] text-stone-500 uppercase">
                Reagent Presets
              </div>
              <h3 className="mt-3 text-lg font-semibold text-stone-900">预置试剂字典</h3>
              <div className="mt-4 flex flex-wrap gap-2">
                {activePack.reagentDictionary.map((item) => (
                  <div
                    key={item}
                    className="rounded-full border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700"
                  >
                    {item}
                  </div>
                ))}
              </div>
            </article>
          </aside>
        </div>
      </section>

      <section className="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
        <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
          <div className="border-b border-stone-200 pb-4">
            <h3 className="text-lg font-semibold text-stone-900">Assay Pack Library</h3>
            <p className="mt-2 text-sm leading-6 text-stone-600">
              首批模板不是四张门户卡片，而是一组可复用的 Notebook 结构。
            </p>
          </div>
          <div className="grid gap-4 pt-5">
            {assayPacks
              .filter((pack) => pack.key !== activePack.key)
              .map((pack) => (
                <article
                  key={pack.key}
                  className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5"
                >
                  <div className="flex flex-wrap items-center gap-3">
                    <h3 className="text-lg font-semibold text-stone-900">{pack.title}</h3>
                    <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold tracking-[0.18em] text-stone-500 uppercase">
                      {pack.category}
                    </span>
                  </div>
                  <p className="mt-3 text-sm leading-6 text-stone-600">{pack.summary}</p>
                  <div className="mt-4 grid gap-3 md:grid-cols-3">
                    <div className="rounded-[1.1rem] border border-white/80 bg-white px-4 py-3 text-sm text-stone-700">
                      封面字段：{pack.coverFields.slice(0, 3).join("、")}
                    </div>
                    <div className="rounded-[1.1rem] border border-white/80 bg-white px-4 py-3 text-sm text-stone-700">
                      结果表：{pack.resultColumns.slice(0, 4).join(" / ")}
                    </div>
                    <div className="rounded-[1.1rem] border border-white/80 bg-white px-4 py-3 text-sm text-stone-700">
                      质控：{pack.qcRules[0]}
                    </div>
                  </div>
                </article>
              ))}
          </div>
        </section>

        <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
          <div className="border-b border-stone-200 pb-4">
            <h3 className="text-lg font-semibold text-stone-900">扩展目录</h3>
            <p className="mt-2 text-sm leading-6 text-stone-600">
              后续按实验大类继续扩 Assay Pack，不回退成空白模板页。
            </p>
          </div>
          <div className="grid gap-4 pt-5">
            {assayPackCatalog.map((group) => (
              <article
                key={group.category}
                className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5"
              >
                <div className="text-sm font-semibold text-stone-900">{group.category}</div>
                <div className="mt-4 flex flex-wrap gap-2">
                  {group.packs.map((pack) => (
                    <div
                      key={pack}
                      className="rounded-full border border-white/80 bg-white px-3 py-2 text-sm text-stone-700"
                    >
                      {pack}
                    </div>
                  ))}
                </div>
              </article>
            ))}
          </div>
        </section>
      </section>
    </>
  );
}
