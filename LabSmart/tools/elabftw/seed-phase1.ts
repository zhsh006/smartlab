import "dotenv/config";

import * as mysql from "mysql2/promise";
import { randomBytes } from "node:crypto";

type UserRow = mysql.RowDataPacket & { userid: number; team_id: number };
type IdRow = mysql.RowDataPacket & { id: number };
type ExperimentRow = mysql.RowDataPacket & { id: number; title: string };

const EMPTY_CAN_JSON = '{"teams": [], "teamgroups": [], "users": []}';

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
        (?, 'AB-ELISA-BDNF-CAP', 'Anti-BDNF Capture', 'elisa', 'Mouse', '2 ug/mL', 'BDNF', 'Capture antibody for BDNF ELISA.', ?),
        (?, 'AB-ELISA-BDNF-DET', 'Biotin Anti-BDNF', 'elisa', 'Rabbit', '0.5 ug/mL', 'BDNF', 'Detection antibody for BDNF ELISA.', ?),
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
        (?, 'RGT-IHC-NBF', '10% Neutral Buffered Formalin', 'ihc_fixative', 'Leagene', 'DF0135', 'Default tissue fixative.', ?),
        (?, 'RGT-ELISA-BDNF-KIT', 'Rat BDNF ELISA Kit', 'elisa_kit', 'Elabscience', 'E-EL-R0011', 'Default ELISA kit for BDNF quantification.', ?),
        (?, 'RGT-ELISA-TMB', 'TMB Substrate', 'elisa_substrate', 'Thermo Fisher', '34021', 'Chromogenic substrate for ELISA.', ?),
        (?, 'RGT-ELISA-READER', 'BioTek Synergy H1', 'elisa_reader', 'Agilent', 'Synergy H1', 'Default ELISA microplate reader.', ?)
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
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
        actor.team_id, actor.userid,
      ],
    );

    const sampleLinkId = sampleId;
    const lotId = await ensureDemoLot(connection, actor.team_id, projectId, actor.userid);
    const assayCatalog = await loadAssayCatalog(connection, actor.team_id);

    const qpcrExperimentId = await ensureDemoExperiment(
      connection,
      actor.team_id,
      actor.userid,
      "SmartLab Demo qPCR Analysis",
      "Structured qPCR assay pack demo with fold-change analysis.",
    );
    const wbExperimentId = await ensureDemoExperiment(
      connection,
      actor.team_id,
      actor.userid,
      "SmartLab Demo Western Blot",
      "Structured Western Blot assay pack demo with normalized band intensity analysis.",
    );
    const ifExperimentId = await ensureDemoExperiment(
      connection,
      actor.team_id,
      actor.userid,
      "SmartLab Demo Immunofluorescence",
      "Structured IF assay pack demo with field-level quantification.",
    );
    const ihcExperimentId = await ensureDemoExperiment(
      connection,
      actor.team_id,
      actor.userid,
      "SmartLab Demo Immunohistochemistry",
      "Structured IHC assay pack demo with region scoring.",
    );
    const elisaExperimentId = await ensureDemoExperiment(
      connection,
      actor.team_id,
      actor.userid,
      "SmartLab Demo ELISA Quantification",
      "Structured ELISA assay pack demo with standard curve and concentration back-calculation.",
    );

    for (const experimentId of [qpcrExperimentId, wbExperimentId, ifExperimentId, ihcExperimentId, elisaExperimentId]) {
      await connection.query(
        `INSERT IGNORE INTO smartlab_project_entities
          (project_id, entity_type, entity_id)
         VALUES (?, 'experiment', ?)`,
        [projectId, experimentId],
      );
    }

    await upsertAssayPack(connection, {
      experimentId: qpcrExperimentId,
      teamId: actor.team_id,
      userId: actor.userid,
      packKey: "qpcr",
      packMode: "standard",
      payload: {
        cover: {
          experiment_type: "qPCR",
          objective: "Validate BDNF induction after treatment",
          project_code: "SLB-PHASE1",
          operator_name: "SmartLab Demo",
          sample_source: "rat cortex RNA",
          run_date: "2026-03-31",
          linked_sample_id: sampleLinkId,
          linked_lot_id: lotId,
        },
        sample: {
          sample_origin: "Rat cortex tissue",
          treatment_group: "Sham vs TBI + treatment",
          rna_concentration: "265 ng/uL",
          a260_a280: "1.98",
          reverse_transcription_batch: "RT-20260331-A",
        },
        primer: {
          target_gene: "BDNF",
          reference_gene: "GAPDH",
          primer_code: "PRM-BDNF-001",
          amplicon_bp: "142",
          annealing_temperature: "60",
          object: assayCatalog.primers["PRM-BDNF-001"],
        },
        setup: {
          kit_name: "TB Green Premix Ex Taq II",
          kit_object: assayCatalog.reagents["RGT-QPCR-TBGRN"],
          reaction_volume: "20 uL",
          cycler_program: "95C 30s; 40 cycles; melt curve",
          instrument_model: "ABI QuantStudio 5",
          instrument_object: assayCatalog.reagents["RGT-QPCR-QS5"],
          mode: "standard",
        },
        qc: {
          negative_control_amplified: "no",
          melting_curve_single_peak: "yes",
          notes: "Demo data with clear treatment upregulation.",
        },
      },
      qcStatus: "pass",
      qcSummary: {
        status: "pass",
        feedback: ["当前 qPCR 结果未触发明显质控警报，可进入结果解读。"],
      },
      autoSummary:
        `本次 qPCR 记录围绕 BDNF 展开。以内参 GAPDH 做归一化。已绑定引物对象 PRM-BDNF-001。已绑定试剂盒对象 RGT-QPCR-TBGRN。已绑定仪器对象 RGT-QPCR-QS5。已关联主样本 #${sampleLinkId}。已关联试剂批次 #${lotId}。共记录 4 行结果。其中上调 3 行、下调 0 行、接近稳定 1 行。当前质控检查通过。`,
      results: [
        {
          row_index: 1,
          sample_label: "Sham-1",
          group_label: "Sham",
          target_gene: "BDNF",
          ct_value: 25.4,
          reference_ct: 19.9,
          delta_ct: 5.5,
          delta_delta_ct: 0,
          fold_change: 1,
          replicate_count: 3,
          is_outlier: 0,
          note: "baseline",
        },
        {
          row_index: 2,
          sample_label: "Tx-1",
          group_label: "Treatment",
          target_gene: "BDNF",
          ct_value: 23.1,
          reference_ct: 19.7,
          delta_ct: 3.4,
          delta_delta_ct: -2.1,
          fold_change: 4.2871,
          replicate_count: 3,
          is_outlier: 0,
          note: "clear induction",
        },
        {
          row_index: 3,
          sample_label: "Tx-2",
          group_label: "Treatment",
          target_gene: "BDNF",
          ct_value: 22.8,
          reference_ct: 19.6,
          delta_ct: 3.2,
          delta_delta_ct: -2.3,
          fold_change: 4.9246,
          replicate_count: 3,
          is_outlier: 0,
          note: "strong induction",
        },
        {
          row_index: 4,
          sample_label: "Tx-3",
          group_label: "Treatment",
          target_gene: "BDNF",
          ct_value: 23.4,
          reference_ct: 19.9,
          delta_ct: 3.5,
          delta_delta_ct: -2.0,
          fold_change: 4,
          replicate_count: 3,
          is_outlier: 0,
          note: "repeat replicate",
        },
      ],
    });

    await upsertAssayPack(connection, {
      experimentId: wbExperimentId,
      teamId: actor.team_id,
      userId: actor.userid,
      packKey: "western_blot",
      packMode: "standard",
      payload: {
        cover: {
          experiment_type: "Western Blot",
          objective: "Compare BDNF band intensity after treatment",
          project_code: "SLB-PHASE1",
          operator_name: "SmartLab Demo",
          sample_source: "rat cortex protein",
          run_date: "2026-03-31",
          linked_sample_id: sampleLinkId,
          linked_lot_id: lotId,
        },
        sample: {
          sample_origin: "Cortex lysate",
          lysis_buffer: "RIPA + protease inhibitor",
          protein_concentration: "2.1 mg/mL",
          loading_amount: "25 ug",
          denaturation_condition: "95C 5 min",
        },
        run: {
          gel_percentage: "10%",
          electrophoresis_condition: "120 V 70 min",
          transfer_condition: "300 mA 90 min",
          membrane_type: "PVDF",
          mode: "standard",
        },
        reagent: {
          primary_antibody: "Anti-BDNF",
          primary_dilution: "1:1000",
          primary_object: assayCatalog.antibodies["AB-WB-BDNF"],
          secondary_antibody: "Goat anti-Mouse HRP",
          secondary_dilution: "1:5000",
          secondary_object: assayCatalog.antibodies["AB-SEC-MS-HRP"],
          detection_reagent: "ECL Prime",
          detection_object: assayCatalog.reagents["RGT-WB-ECL"],
          membrane_object: assayCatalog.reagents["RGT-WB-PVDF"],
        },
        analysis: {
          target_band_kda: "14",
          reference_control: "GAPDH",
          normalization_method: "Target / GAPDH",
          conclusion: "Treatment group shows higher normalized BDNF signal.",
        },
        results: [
          { label: "Sham-1", target_signal: "0.42", reference_signal: "0.76", normalized_signal: "0.55", trend: "stable", note: "baseline" },
          { label: "Tx-1", target_signal: "0.95", reference_signal: "0.74", normalized_signal: "1.28", trend: "up", note: "clear induction" },
          { label: "Tx-2", target_signal: "1.12", reference_signal: "0.73", normalized_signal: "1.53", trend: "up", note: "strong induction" },
        ],
        image: {
          raw_image_ref: "uploads/demo/wb_raw_image.png",
          cropped_image_ref: "uploads/demo/wb_cropped_image.png",
          annotation_note: "Lane 2 and 3 show visibly stronger target bands.",
        },
        qc: {
          transfer_uniform: "yes",
          background_clean: "yes",
          replicate_consistent: "yes",
          notes: "Demo blot with acceptable background.",
        },
      },
      qcStatus: "pass",
      qcSummary: {
        status: "pass",
        feedback: ["Western Blot 当前未触发明显质控警报，可继续整理结果。"],
      },
      autoSummary:
        "本次 Western Blot 已按结构化实验包归档。实验目标为Compare BDNF band intensity after treatment。当前结论为Treatment group shows higher normalized BDNF signal.。已绑定对象 AB-WB-BDNF。已绑定对象 AB-SEC-MS-HRP。已绑定对象 RGT-WB-ECL。已绑定对象 RGT-WB-PVDF。共整理 3 行结构化结果。当前质控检查通过。",
    });

    await upsertAssayPack(connection, {
      experimentId: ifExperimentId,
      teamId: actor.team_id,
      userId: actor.userid,
      packKey: "if",
      packMode: "standard",
      payload: {
        cover: {
          experiment_type: "Immunofluorescence",
          objective: "Quantify GFAP positive astrocytes across fields",
          project_code: "SLB-PHASE1",
          operator_name: "SmartLab Demo",
          sample_source: "primary astrocyte culture",
          run_date: "2026-03-31",
          linked_sample_id: sampleLinkId,
          linked_lot_id: lotId,
        },
        sample: {
          sample_origin: "Primary astrocyte culture",
          seeding_density: "5e4 cells/well",
          treatment_condition: "LPS 100 ng/mL 24 h",
          fixation_method: "4% PFA 15 min",
        },
        staining: {
          blocking_buffer: "5% BSA",
          permeabilization: "0.2% Triton X-100 10 min",
          primary_antibody: "Anti-GFAP",
          primary_object: assayCatalog.antibodies["AB-IF-GFAP"],
          secondary_antibody: "Alexa Fluor 488 Goat anti-Rabbit",
          secondary_object: assayCatalog.antibodies["AB-SEC-RB-488"],
          dapi: "DAPI 1 ug/mL",
          dark_incubation: "yes",
          blocking_object: assayCatalog.reagents["RGT-IF-BSA5"],
        },
        imaging: {
          microscope: "Leica SP8",
          objective: "20x",
          excitation: "488/405",
          exposure: "consistent",
          batch: "IF-20260331-A",
          microscope_object: assayCatalog.reagents["RGT-IF-SP8"],
          mode: "standard",
        },
        analysis: {
          field_count: "3",
          positive_ratio: "63.4",
          mean_intensity: "812",
          colocalization: "GFAP high in treatment fields",
          conclusion: "Treatment increases GFAP positive cell proportion.",
        },
        results: [
          { label: "Field-1", cell_count: "116", positive_ratio: "58.2", mean_intensity: "760", colocalization: "moderate", note: "baseline field" },
          { label: "Field-2", cell_count: "121", positive_ratio: "64.5", mean_intensity: "830", colocalization: "high", note: "treatment field" },
          { label: "Field-3", cell_count: "119", positive_ratio: "67.4", mean_intensity: "846", colocalization: "high", note: "treatment field" },
        ],
        image: {
          raw_image_ref: "uploads/demo/if_raw_image.tif",
          merged_image_ref: "uploads/demo/if_merged_image.png",
          annotation_note: "Treatment fields show broader GFAP-positive area.",
        },
        qc: {
          negative_control_ok: "yes",
          background_ok: "yes",
          exposure_consistent: "yes",
          notes: "Exposure matched across three fields.",
        },
      },
      qcStatus: "pass",
      qcSummary: {
        status: "pass",
        feedback: ["Immunofluorescence 当前未触发明显质控警报，可继续整理结果。"],
      },
      autoSummary:
        "本次 Immunofluorescence 已按结构化实验包归档。实验目标为Quantify GFAP positive astrocytes across fields。当前结论为Treatment increases GFAP positive cell proportion.。已绑定对象 AB-IF-GFAP。已绑定对象 AB-SEC-RB-488。已绑定对象 RGT-IF-BSA5。已绑定对象 RGT-IF-SP8。共整理 3 行结构化结果。当前质控检查通过。",
    });

    await upsertAssayPack(connection, {
      experimentId: ihcExperimentId,
      teamId: actor.team_id,
      userId: actor.userid,
      packKey: "ihc",
      packMode: "standard",
      payload: {
        cover: {
          experiment_type: "Immunohistochemistry",
          objective: "Score Ki67 positive proliferation regions",
          project_code: "SLB-PHASE1",
          operator_name: "SmartLab Demo",
          sample_source: "formalin-fixed paraffin sections",
          run_date: "2026-03-31",
          linked_sample_id: sampleLinkId,
          linked_lot_id: lotId,
        },
        sample: {
          tissue_id: "TISSUE-KI67-01",
          fixative: "10% Neutral Buffered Formalin",
          embedding_batch: "FFPE-20260331-A",
          section_thickness: "4 um",
          fixative_object: assayCatalog.reagents["RGT-IHC-NBF"],
        },
        staining: {
          antigen_retrieval: "Citrate buffer pH 6.0",
          antigen_retrieval_object: assayCatalog.reagents["RGT-IHC-CIT6"],
          blocking: "3% H2O2 + serum block",
          primary_antibody: "Anti-Ki67",
          primary_object: assayCatalog.antibodies["AB-IHC-KI67"],
          secondary_antibody: "HRP Polymer anti-Rabbit",
          secondary_object: assayCatalog.antibodies["AB-SEC-RB-HRP"],
          dab_time: "90 s",
          counterstain: "Hematoxylin",
          counterstain_object: assayCatalog.reagents["RGT-IHC-HEM"],
        },
        analysis: {
          localization: "Nuclear staining in lesion border",
          positive_area: "34.6",
          staining_intensity: "2+ to 3+",
          h_score: "220",
          conclusion: "Treatment region retains focal Ki67 high-expression areas.",
        },
        results: [
          { label: "Core", positive_ratio: "28.4", intensity_score: "2", h_score: "165", irs_score: "6", note: "moderate positivity" },
          { label: "Border", positive_ratio: "41.2", intensity_score: "3", h_score: "220", irs_score: "9", note: "high proliferation zone" },
          { label: "Remote", positive_ratio: "15.3", intensity_score: "1", h_score: "82", irs_score: "3", note: "low positivity" },
        ],
        image: {
          low_magnification_ref: "uploads/demo/ihc_low_mag.png",
          high_magnification_ref: "uploads/demo/ihc_high_mag.png",
          zoomed_region_ref: "uploads/demo/ihc_zoom_region.png",
        },
        qc: {
          background_ok: "yes",
          positive_control_ok: "yes",
          scoring_complete: "yes",
          notes: "Scoring completed on three representative regions.",
        },
      },
      qcStatus: "pass",
      qcSummary: {
        status: "pass",
        feedback: ["Immunohistochemistry 当前未触发明显质控警报，可继续整理结果。"],
      },
      autoSummary:
        "本次 Immunohistochemistry 已按结构化实验包归档。实验目标为Score Ki67 positive proliferation regions。当前结论为Treatment region retains focal Ki67 high-expression areas.。已绑定对象 AB-IHC-KI67。已绑定对象 AB-SEC-RB-HRP。已绑定对象 RGT-IHC-NBF。已绑定对象 RGT-IHC-CIT6。已绑定对象 RGT-IHC-HEM。共整理 3 行结构化结果。当前质控检查通过。",
    });

    await upsertAssayPack(connection, {
      experimentId: elisaExperimentId,
      teamId: actor.team_id,
      userId: actor.userid,
      packKey: "elisa",
      packMode: "standard",
      payload: {
        cover: {
          experiment_type: "ELISA",
          objective: "Quantify serum BDNF concentration after treatment",
          project_code: "SLB-PHASE1",
          operator_name: "SmartLab Demo",
          sample_source: "rat serum",
          run_date: "2026-03-31",
          linked_sample_id: sampleLinkId,
          linked_lot_id: lotId,
        },
        sample: {
          sample_origin: "Rat serum",
          sample_type: "serum",
          dilution_factor: "1:4",
          batch_id: "ELISA-20260331-A",
        },
        reagent: {
          target_name: "BDNF",
          kit_name: "Rat BDNF ELISA Kit",
          kit_object: assayCatalog.reagents["RGT-ELISA-BDNF-KIT"],
          capture_antibody: "Anti-BDNF Capture",
          capture_object: assayCatalog.antibodies["AB-ELISA-BDNF-CAP"],
          detection_antibody: "Biotin Anti-BDNF",
          detection_object: assayCatalog.antibodies["AB-ELISA-BDNF-DET"],
          substrate_name: "TMB Substrate",
          substrate_object: assayCatalog.reagents["RGT-ELISA-TMB"],
        },
        run: {
          plate_layout: "A1-A2 blank, A3-A8 standards, B1-D3 samples",
          incubation_condition: "37C 90 min + RT substrate 15 min",
          wash_cycles: "5",
          reader_model: "BioTek Synergy H1",
          reader_object: assayCatalog.reagents["RGT-ELISA-READER"],
          wavelength_nm: "450",
          mode: "standard",
        },
        analysis: {
          standard_curve_r2: "0.997",
          blank_od450: "0.051",
          cv_threshold: "15%",
          conclusion: "Treatment group serum BDNF concentration is elevated versus sham.",
        },
        results: [
          { label: "Sham-1", group_label: "Sham", od450: "0.312", concentration: "42.8", cv_percent: "4.1", note: "baseline" },
          { label: "Sham-2", group_label: "Sham", od450: "0.328", concentration: "45.6", cv_percent: "5.3", note: "baseline repeat" },
          { label: "Tx-1", group_label: "Treatment", od450: "0.612", concentration: "96.4", cv_percent: "6.8", note: "clear increase" },
          { label: "Tx-2", group_label: "Treatment", od450: "0.645", concentration: "102.7", cv_percent: "7.2", note: "clear increase" },
        ],
        qc: {
          blank_control_ok: "yes",
          standard_curve_ok: "yes",
          replicate_cv_ok: "yes",
          notes: "Standard curve fit and duplicate CV are both acceptable.",
        },
      },
      qcStatus: "pass",
      qcSummary: {
        status: "pass",
        feedback: ["ELISA 当前未触发明显质控警报，可继续整理结果。"],
      },
      autoSummary:
        "本次 ELISA 已按结构化实验包归档。实验目标为Quantify serum BDNF concentration after treatment。当前结论为Treatment group serum BDNF concentration is elevated versus sham.。已绑定对象 AB-ELISA-BDNF-CAP。已绑定对象 AB-ELISA-BDNF-DET。已绑定对象 RGT-ELISA-BDNF-KIT。已绑定对象 RGT-ELISA-TMB。已绑定对象 RGT-ELISA-READER。共整理 4 行结构化结果。当前质控检查通过。",
    });

    console.log(
      `Seeded SmartLab phase1 data. project=${projectId} sample=${sampleId} lot=${lotId} experiments=${[qpcrExperimentId, wbExperimentId, ifExperimentId, ihcExperimentId, elisaExperimentId].join(",")}`,
    );
  } finally {
    await connection.end();
  }
}

async function ensureDemoLot(
  connection: mysql.Connection,
  teamId: number,
  projectId: number,
  userId: number,
): Promise<number> {
  const materialCode = "MAT-DEMO-001";
  const lotNo = "LOT-DEMO-001";

  const [materialRows] = await connection.query<IdRow[]>(
    "SELECT id FROM smartlab_materials WHERE team_id = ? AND code = ? LIMIT 1",
    [teamId, materialCode],
  );

  let materialId = materialRows[0]?.id;
  if (!materialId) {
    const [insertMaterial] = await connection.query<mysql.ResultSetHeader>(
      `INSERT INTO smartlab_materials
        (team_id, project_id, code, name, category, unit, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [teamId, projectId, materialCode, "SmartLab Demo Assay Reagent", "demo", "box", userId],
    );
    materialId = insertMaterial.insertId;
  }

  const [lotRows] = await connection.query<IdRow[]>(
    "SELECT id FROM smartlab_material_lots WHERE material_id = ? AND lot_no = ? LIMIT 1",
    [materialId, lotNo],
  );

  let lotId = lotRows[0]?.id;
  if (!lotId) {
    const [insertLot] = await connection.query<mysql.ResultSetHeader>(
      `INSERT INTO smartlab_material_lots
        (material_id, lot_no, expires_at, quantity, location_label, note)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [materialId, lotNo, "2027-12-31", 12.0, "Demo freezer A1", "Reusable assay demo lot."],
    );
    lotId = insertLot.insertId;
  }

  return lotId;
}

async function ensureDemoExperiment(
  connection: mysql.Connection,
  teamId: number,
  userId: number,
  title: string,
  body: string,
): Promise<number> {
  const [rows] = await connection.query<ExperimentRow[]>(
    "SELECT id, title FROM experiments WHERE team = ? AND title = ? LIMIT 1",
    [teamId, title],
  );

  if (rows.length) {
    return rows[0].id;
  }

  const [insertExperiment] = await connection.query<mysql.ResultSetHeader>(
    `INSERT INTO experiments
      (team, title, date, body, category, status, elabid, canread_base, canwrite_base, canread, canwrite, canread_is_immutable, canwrite_is_immutable, metadata, custom_id, userid, content_type, rating, hide_main_text, created_from_type, created_from_id)
     VALUES (?, ?, CURRENT_DATE(), ?, NULL, NULL, ?, 30, 20, ?, ?, 0, 0, NULL, NULL, ?, 1, 0, 0, NULL, NULL)`,
    [teamId, title, `<p>${body}</p>`, createElabid(), EMPTY_CAN_JSON, EMPTY_CAN_JSON, userId],
  );

  return insertExperiment.insertId;
}

async function loadAssayCatalog(connection: mysql.Connection, teamId: number) {
  const [primerRows] = await connection.query<(mysql.RowDataPacket & { id: number; code: string; target_gene: string })[]>(
    "SELECT id, code, target_gene FROM smartlab_primers WHERE team_id = ?",
    [teamId],
  );
  const [antibodyRows] = await connection.query<(mysql.RowDataPacket & { id: number; code: string; name: string })[]>(
    "SELECT id, code, name FROM smartlab_antibodies WHERE team_id = ?",
    [teamId],
  );
  const [reagentRows] = await connection.query<(mysql.RowDataPacket & { id: number; code: string; name: string })[]>(
    "SELECT id, code, name FROM smartlab_reagents WHERE team_id = ?",
    [teamId],
  );

  return {
    primers: Object.fromEntries(
      primerRows.map((row) => [row.code, { id: row.id, code: row.code, name: row.target_gene }]),
    ) as Record<string, { id: number; code: string; name: string }>,
    antibodies: Object.fromEntries(
      antibodyRows.map((row) => [row.code, { id: row.id, code: row.code, name: row.name }]),
    ) as Record<string, { id: number; code: string; name: string }>,
    reagents: Object.fromEntries(
      reagentRows.map((row) => [row.code, { id: row.id, code: row.code, name: row.name }]),
    ) as Record<string, { id: number; code: string; name: string }>,
  };
}

async function upsertAssayPack(
  connection: mysql.Connection,
  input: {
    experimentId: number;
    teamId: number;
    userId: number;
    packKey: string;
    packMode: string;
    payload: Record<string, unknown>;
    qcStatus: string;
    qcSummary: Record<string, unknown>;
    autoSummary: string;
    results?: Array<Record<string, unknown>>;
  },
): Promise<void> {
  await connection.query(
    `INSERT INTO smartlab_assay_packs
      (experiment_id, team_id, pack_key, pack_mode, payload, qc_status, qc_summary, auto_summary, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
      pack_key = VALUES(pack_key),
      pack_mode = VALUES(pack_mode),
      payload = VALUES(payload),
      qc_status = VALUES(qc_status),
      qc_summary = VALUES(qc_summary),
      auto_summary = VALUES(auto_summary),
      updated_by = VALUES(updated_by)`,
    [
      input.experimentId,
      input.teamId,
      input.packKey,
      input.packMode,
      JSON.stringify(input.payload),
      input.qcStatus,
      JSON.stringify(input.qcSummary),
      input.autoSummary,
      input.userId,
      input.userId,
    ],
  );

  const [packRows] = await connection.query<IdRow[]>(
    "SELECT id FROM smartlab_assay_packs WHERE experiment_id = ? LIMIT 1",
    [input.experimentId],
  );
  const packId = packRows[0]?.id;
  if (!packId) {
    throw new Error(`Failed to find assay pack for experiment ${input.experimentId}`);
  }

  await connection.query(
    "DELETE FROM smartlab_assay_pack_results WHERE assay_pack_id = ?",
    [packId],
  );

  if (!input.results?.length) {
    return;
  }

  for (const row of input.results) {
    await connection.query(
      `INSERT INTO smartlab_assay_pack_results
        (assay_pack_id, row_index, sample_label, group_label, target_gene, ct_value, reference_ct, delta_ct, delta_delta_ct, fold_change, replicate_count, is_outlier, note)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        packId,
        row.row_index ?? null,
        row.sample_label ?? "",
        row.group_label ?? "",
        row.target_gene ?? "",
        row.ct_value ?? null,
        row.reference_ct ?? null,
        row.delta_ct ?? null,
        row.delta_delta_ct ?? null,
        row.fold_change ?? null,
        row.replicate_count ?? null,
        row.is_outlier ?? 0,
        row.note ?? "",
      ],
    );
  }
}

function createElabid(): string {
  return `demo-${randomBytes(10).toString("hex")}`;
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
