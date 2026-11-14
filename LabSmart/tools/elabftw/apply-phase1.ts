import "dotenv/config";

import fs from "node:fs/promises";
import path from "node:path";
import mysql from "mysql2/promise";

async function main() {
  const host = process.env.ELABFTW_DB_HOST;
  const port = Number(process.env.ELABFTW_DB_PORT || "3306");
  const database = process.env.ELABFTW_DB_NAME;
  const user = process.env.ELABFTW_DB_USER;
  const password = process.env.ELABFTW_DB_PASSWORD;

  if (!host || !database || !user || !password) {
    throw new Error("Missing eLabFTW DB configuration in .env");
  }

  const sqlPath = path.join(
    process.cwd(),
    "database",
    "elabftw",
    "001_smartlab_phase1.sql",
  );
  const sql = await fs.readFile(sqlPath, "utf8");

  const connection = await mysql.createConnection({
    host,
    port,
    database,
    user,
    password,
    multipleStatements: true,
  });

  try {
    await connection.query(sql);
    await ensureColumn(
      connection,
      database,
      'smartlab_assay_pack_results',
      'group_label',
      `ALTER TABLE smartlab_assay_pack_results
       ADD COLUMN group_label VARCHAR(255) NOT NULL DEFAULT '' AFTER sample_label`,
    );
    await ensureColumn(
      connection,
      database,
      'smartlab_primers',
      'is_active',
      `ALTER TABLE smartlab_primers
       ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER annealing_temperature`,
    );
    await ensureColumn(
      connection,
      database,
      'smartlab_antibodies',
      'is_active',
      `ALTER TABLE smartlab_antibodies
       ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER target_name`,
    );
    await ensureColumn(
      connection,
      database,
      'smartlab_reagents',
      'is_active',
      `ALTER TABLE smartlab_reagents
       ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER model_hint`,
    );
    console.log("Applied SmartLab phase1 schema to eLabFTW.");
  } finally {
    await connection.end();
  }
}

async function ensureColumn(
  connection: mysql.Connection,
  database: string,
  tableName: string,
  columnName: string,
  alterSql: string,
) {
  const [rows] = await connection.query<mysql.RowDataPacket[]>(
    `SELECT COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ?
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1`,
    [database, tableName, columnName],
  );
  if (!rows.length) {
    await connection.query(alterSql);
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
