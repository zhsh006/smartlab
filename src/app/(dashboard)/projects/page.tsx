import { ProjectHub } from "@/features/projects/components/project-hub";
import {
  getProjectHub,
  listProjectCards,
} from "@/features/projects/queries";

export default async function ProjectsPage() {
  const [projects, hub] = await Promise.all([
    listProjectCards(),
    getProjectHub(),
  ]);

  return <ProjectHub projects={projects} hub={hub} />;
}
