import "server-only";

import type mysql from "mysql2/promise";
import { getElabDbPool } from "@/server/elabftw-db";

type ProjectRow = mysql.RowDataPacket & {
  id: number;
  code: string;
  name: string;
  description: string | null;
  member_count: number;
  entity_count: number;
};

type SampleRow = mysql.RowDataPacket & {
  id: number;
  code: string;
  name: string;
  sample_type: string;
  status: string;
  quantity: string | null;
  unit: string | null;
};

type ReviewRow = mysql.RowDataPacket & {
  id: number;
  status: string;
  entity_type: string;
  entity_id: number;
  requester_name: string;
  assignee_name: string | null;
  comment: string | null;
};

export async function getRouteBOverview() {
  const pool = getElabDbPool();

  const [projects] = await pool.query<ProjectRow[]>(
    `SELECT
      p.id,
      p.code,
      p.name,
      p.description,
      COUNT(DISTINCT pm.user_id) AS member_count,
      COUNT(DISTINCT pe.id) AS entity_count
    FROM smartlab_projects p
    LEFT JOIN smartlab_project_members pm ON pm.project_id = p.id
    LEFT JOIN smartlab_project_entities pe ON pe.project_id = p.id
    GROUP BY p.id
    ORDER BY p.id ASC`,
  );

  const [samples] = await pool.query<SampleRow[]>(
    `SELECT
      id,
      code,
      name,
      sample_type,
      status,
      CAST(quantity AS CHAR) AS quantity,
      unit
    FROM smartlab_samples
    ORDER BY id ASC`,
  );

  const [reviews] = await pool.query<ReviewRow[]>(
    `SELECT
      rf.id,
      rf.status,
      rf.entity_type,
      rf.entity_id,
      CONCAT(req.firstname, ' ', req.lastname) AS requester_name,
      CONCAT(assignee.firstname, ' ', assignee.lastname) AS assignee_name,
      rt.comment
    FROM smartlab_review_flows rf
    JOIN users req ON req.userid = rf.requester_userid
    LEFT JOIN smartlab_review_tasks rt ON rt.flow_id = rf.id
    LEFT JOIN users assignee ON assignee.userid = rt.assignee_userid
    ORDER BY rf.id ASC`,
  );

  return {
    projects,
    samples,
    reviews,
    totals: {
      projectCount: projects.length,
      sampleCount: samples.length,
      reviewCount: reviews.length,
    },
  };
}
