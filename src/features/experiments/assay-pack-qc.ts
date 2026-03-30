export const qPcrRequiredCoverFields = ["实验目的", "课题编号", "操作者", "实验日期"] as const;

export type QpcrSubmissionInput = {
  cover: Record<string, string>;
  resultRows: string[][];
  projectId?: string;
  sampleId?: string;
};

export type QpcrQcCheck = {
  key: string;
  label: string;
  passed: boolean;
};

export function getQpcrMissingCoverFields(cover: Record<string, string>) {
  return qPcrRequiredCoverFields.filter((field) => !(cover[field] ?? "").trim());
}

export function getQpcrQcChecks(input: QpcrSubmissionInput): QpcrQcCheck[] {
  const missingCoverFields = getQpcrMissingCoverFields(input.cover);
  const ntcRow = input.resultRows.find((row) => row[0]?.toUpperCase().includes("NTC"));
  const hasBlankResultCell = input.resultRows.some((row) =>
    row.some((cell) => cell.trim() === ""),
  );

  return [
    {
      key: "cover",
      label:
        missingCoverFields.length === 0
          ? "封面必填项已填写"
          : `封面缺失：${missingCoverFields.join("、")}`,
      passed: missingCoverFields.length === 0,
    },
    {
      key: "links",
      label:
        input.projectId && input.sampleId
          ? "已关联项目与样本对象"
          : "项目和样本对象仍需补充",
      passed: Boolean(input.projectId && input.sampleId),
    },
    {
      key: "result-grid",
      label: hasBlankResultCell ? "qPCR 结果表存在空白单元格" : "qPCR 结果表字段完整",
      passed: !hasBlankResultCell,
    },
    {
      key: "ntc",
      label:
        ntcRow?.[2] === "Undetermined"
          ? "阴性对照正常，可进入提交"
          : "阴性对照异常，暂不允许提交",
      passed: ntcRow?.[2] === "Undetermined",
    },
  ];
}

export function getQpcrSubmissionGate(input: QpcrSubmissionInput) {
  const checks = getQpcrQcChecks(input);

  return {
    checks,
    canSubmit: checks.every((item) => item.passed),
  };
}
