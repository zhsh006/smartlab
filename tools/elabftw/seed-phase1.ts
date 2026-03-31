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

    await connection.query(
      `INSERT INTO smartlab_primers
        (team_id, code, target_gene, reference_gene, amplicon_bp, annealing_temperature, note, created_by)
       VALUES
        (?, 'PRM-BDNF-001', 'BDNF', 'GAPDH', '142', '60', 'Default qPCR primer pair for BDNF expression analysis.', ?),
        (?, 'PRM-GFAP-001', 'GFAP', 'ACTB', '118', '60', 'Default qPCR primer pair for astrocyte marker detection.', ?),
        (?, 'PRM-IL6-001', 'IL6', 'GAPDH', '126', '60', 'Inflammation marker qPCR primer pair.', ?)
       ON DUPLICATE KEY UPDATE
        target_gene = VALUES(target_gene),
        reference_gene = VALUES(reference_gene),
        amplicon_bp = VALUES(amplicon_bp),
        annealing_temperature = VALUES(annealing_temperature),
        note = VALUES(note)`,
      [actor.team_id, actor.userid, actor.team_id, actor.userid, actor.team_id, actor.userid],
    );

    await connection.query(
      `INSERT INTO smartlab_antibodies
        (team_id, code, name, application_scope, host_species, dilution_hint, target_name, note, created_by)
       VALUES
        (?, 'AB-WB-BDNF', 'Anti-BDNF', 'western_blot', 'Rabbit', '1:1000', 'BDNF', 'Primary antibody for Western Blot BDNF detection.', ?),
        (?, 'AB-WB-GAPDH', 'Anti-GAPDH', 'western_blot', 'Mouse', '1:5000', 'GAPDH', 'Loading control for Western Blot.', ?),
        (?, 'AB-IF-GFAP', 'Anti-GFAP', 'if', 'Rabbit', '1:500', 'GFAP', 'Primary antibody for IF astrocyte staining.', ?),
        (?, 'AB-IHC-KI67', 'Anti-Ki67', 'ihc', 'Rabbit', '1:200', 'Ki67', 'Primary antibody for IHC proliferation scoring.', ?),
        (?, 'AB-SEC-RB-488', 'Alexa Fluor 488 Goat anti-Rabbit', 'if', 'Goat', '1:1000', 'Rabbit IgG', 'Secondary antibody for IF.', ?),
        (?, 'AB-SEC-MS-HRP', 'Goat anti-Mouse HRP', 'western_blot', 'Goat', '1:5000', 'Mouse IgG', 'Secondary antibody for Western Blot.', ?),
        (?, 'AB-SEC-RB-HRP', 'HRP Polymer anti-Rabbit', 'ihc', 'Polymer', 'Ready-to-use', 'Rabbit IgG', 'Secondary antibody for IHC.', ?)
       ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        application_scope = VALUES(application_scope),
        host_species = VALUES(host_species),
        dilution_hint = VALUES(dilution_hint),
        target_name = VALUES(target_name),
        note = VALUES(note)`,
      [
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
      ],
    );

    await connection.query(
      `INSERT INTO smartlab_reagents
        (team_id, code, name, reagent_scope, vendor, model_hint, note, created_by)
       VALUES
        (?, 'RGT-QPCR-TBGRN', 'TB Green Premix Ex Taq II', 'qpcr_kit', 'Takara', 'RR820A', 'Default qPCR master mix.', ?),
        (?, 'RGT-QPCR-QS5', 'ABI QuantStudio 5', 'qpcr_instrument', 'Applied Biosystems', 'QuantStudio 5', 'Default qPCR instrument.', ?),
        (?, 'RGT-WB-ECL', 'ECL Prime', 'western_blot_detection', 'Cytiva', 'ECL Prime', 'Chemiluminescent WB detection reagent.', ?),
        (?, 'RGT-WB-PVDF', 'PVDF', 'western_blot_membrane', 'Millipore', 'IPVH00010', 'Default membrane for WB.', ?),
        (?, 'RGT-IF-BSA5', '5% BSA', 'if_blocking', 'Sigma', 'B2064', 'Default IF blocking buffer.', ?),
        (?, 'RGT-IF-SP8', 'Leica SP8', 'if_microscope', 'Leica', 'SP8', 'Default IF microscope.', ?),
        (?, 'RGT-IHC-CIT6', 'Citrate buffer pH 6.0', 'ihc_antigen_retrieval', 'Solarbio', 'C1032', 'Default IHC antigen retrieval buffer.', ?),
        (?, 'RGT-IHC-HEM', 'Hematoxylin', 'ihc_counterstain', 'Beyotime', 'C0107', 'Default IHC counterstain.', ?),
        (?, 'RGT-IHC-NBF', '10% Neutral Buffered Formalin', 'ihc_fixative', 'Leagene', 'DF0135', 'Default tissue fixative.', ?)
       ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        reagent_scope = VALUES(reagent_scope),
        vendor = VALUES(vendor),
        model_hint = VALUES(model_hint),
        note = VALUES(note)`,
      [
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
      ],
    );

    console.log(`Seeded SmartLab phase1 data. project=${projectId} sample=${sampleId}`);
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
