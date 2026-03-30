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
    "infra",
    "elabftw",
    "sql",
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
    console.log("Applied SmartLab phase1 schema to eLabFTW.");
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});

