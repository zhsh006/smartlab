import { notFound } from "next/navigation";
import { ProjectHub } from "@/features/projects/components/project-hub";
import {
  getProjectHub,
  listProjectCards,
} from "@/features/projects/queries";

export default async function ProjectDetailPage({
  params,
}: {
  params: Promise<{ projectId: string }>;
}) {
  const { projectId } = await params;
  const [projects, hub] = await Promise.all([
    listProjectCards(),
    getProjectHub(projectId),
  ]);

  if (!hub) {
    notFound();
  }

  return <ProjectHub projects={projects} hub={hub} />;
}
