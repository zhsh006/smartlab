import type { ElabftwExperimentSummary } from "@/server/connectors/elabftw/types";
import { elabftwRequest } from "@/server/connectors/elabftw/client";

export async function listElabftwExperiments() {
  return elabftwRequest("/experiments") as Promise<ElabftwExperimentSummary[]>;
}
