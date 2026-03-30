import "dotenv/config";

import mysql from "mysql2/promise";

type UserRow = mysql.RowDataPacket & { userid: number; team_id: number };
type IdRow = mysql.RowDataPacket & { id: number };

async function main() {
  const host = process.env.ELABFTW_DB_HOST;
  const port = Number(process.env.ELABFTW_DB_PORT || "3306");
  const database = process.env.ELABFTW_DB_NAME;
  const user = process.env.ELABFTW_DB_USER;
  const password = process.env.ELABFTW_DB_PASSWORD;

  if (!host || !database || !user || !password) {
    throw new Error("Missing eLabFTW DB configuration in .env");
  }

  const connection = await mysql.createConnection({
    host,
    port,
    database,
    user,
    password,
  });

  try {
    const [userRows] = await connection.query<UserRow[]>(
      `SELECT u.userid, ut.teams_id AS team_id
       FROM users u
       JOIN users2teams ut ON ut.users_id = u.userid
       WHERE u.email = ?
       LIMIT 1`,
      [process.env.ELABFTW_SYSADMIN_EMAIL ?? "smartlab.sysadmin@local.test"],
    );

    if (!userRows.length) {
      throw new Error("Sysadmin user not found in eLabFTW database.");
    }

    const actor = userRows[0];

    const [existingProject] = await connection.query<IdRow[]>(
      "SELECT id FROM smartlab_projects WHERE team_id = ? AND code = ? LIMIT 1",
      [actor.team_id, "SLB-PHASE1"],
    );

    let projectId = existingProject[0]?.id;

    if (!projectId) {
      const [insertProject] = await connection.query<mysql.ResultSetHeader>(
        `INSERT INTO smartlab_projects
          (team_id, code, name, description, created_by)
         VALUES (?, ?, ?, ?, ?)`,
        [
          actor.team_id,
          "SLB-PHASE1",
          "SmartLab Phase 1 Validation",
          "Route B first project seeded on top of eLabFTW core tables.",
          actor.userid,
        ],
      );
      projectId = insertProject.insertId;
    }

    await connection.query(
      `INSERT IGNORE INTO smartlab_project_members
        (project_id, user_id, role)
       VALUES (?, ?, ?)`,
      [projectId, actor.userid, "PI"],
    );

    const [experimentRows] = await connection.query<IdRow[]>(
      "SELECT id FROM experiments ORDER BY id ASC LIMIT 1",
    );

    if (experimentRows.length) {
      const experimentId = experimentRows[0].id;

      await connection.query(
        `INSERT IGNORE INTO smartlab_project_entities
          (project_id, entity_type, entity_id)
         VALUES (?, 'experiment', ?)`,
        [projectId, experimentId],
      );

      const [flowRows] = await connection.query<IdRow[]>(
        `SELECT id FROM smartlab_review_flows
         WHERE entity_type = 'experiment' AND entity_id = ?
         LIMIT 1`,
        [experimentId],
      );

      let flowId = flowRows[0]?.id;

      if (!flowId) {
        const [insertFlow] = await connection.query<mysql.ResultSetHeader>(
          `INSERT INTO smartlab_review_flows
            (project_id, entity_type, entity_id, status, requester_userid)
           VALUES (?, 'experiment', ?, 'submitted', ?)`,
          [projectId, experimentId, actor.userid],
        );
        flowId = insertFlow.insertId;
      }

      const [taskRows] = await connection.query<IdRow[]>(
        `SELECT id FROM smartlab_review_tasks
         WHERE flow_id = ? AND assignee_userid = ?
         LIMIT 1`,
        [flowId, actor.userid],
      );

      if (!taskRows.length) {
        await connection.query(
          `INSERT INTO smartlab_review_tasks
            (flow_id, assignee_userid, comment)
           VALUES (?, ?, ?)`,
          [
            flowId,
            actor.userid,
            "Initial review task created during SmartLab Route B bootstrap.",
          ],
        );
      }
    }

    const [sampleRows] = await connection.query<IdRow[]>(
      "SELECT id FROM smartlab_samples WHERE team_id = ? AND code = ? LIMIT 1",
      [actor.team_id, "SMP-001"],
    );

    let sampleId = sampleRows[0]?.id;

    if (!sampleId) {
      const [insertSample] = await connection.query<mysql.ResultSetHeader>(
        `INSERT INTO smartlab_samples
          (team_id, project_id, code, name, sample_type, status, quantity, unit, metadata, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, JSON_OBJECT('origin', 'bootstrap', 'species', 'rat'), ?)`,
        [
          actor.team_id,
          projectId,
          "SMP-001",
          "TBI rat cortex sample A1",
          "tissue",
          "active",
          3.0,
          "slice",
          actor.userid,
        ],
      );
      sampleId = insertSample.insertId;
    }

    await connection.query(
      `INSERT IGNORE INTO smartlab_project_entities
        (project_id, entity_type, entity_id)
       VALUES (?, 'sample', ?)`,
      [projectId, sampleId],
    );

    const [sampleEventRows] = await connection.query<IdRow[]>(
      `SELECT id FROM smartlab_sample_events
       WHERE sample_id = ? AND event_type = 'registered'
       LIMIT 1`,
      [sampleId],
    );

    if (!sampleEventRows.length) {
      await connection.query(
        `INSERT INTO smartlab_sample_events
          (sample_id, event_type, actor_userid, quantity_delta, note)
         VALUES (?, 'registered', ?, ?, ?)`,
        [
          sampleId,
          actor.userid,
          3.0,
          "Initial sample registration created during SmartLab Route B bootstrap.",
        ],
      );
    }

    console.log(`Seeded SmartLab phase1 data. project=${projectId} sample=${sampleId}`);
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
