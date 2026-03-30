import "server-only";

import mysql from "mysql2/promise";

let pool: mysql.Pool | null = null;

export function getElabDbPool() {
  if (pool) {
    return pool;
  }

  const host = process.env.ELABFTW_DB_HOST;
  const port = Number(process.env.ELABFTW_DB_PORT || "3306");
  const database = process.env.ELABFTW_DB_NAME;
  const user = process.env.ELABFTW_DB_USER;
  const password = process.env.ELABFTW_DB_PASSWORD;

  if (!host || !database || !user || !password) {
    throw new Error("Missing eLabFTW DB configuration.");
  }

  pool = mysql.createPool({
    host,
    port,
    database,
    user,
    password,
    connectionLimit: 5,
  });

  return pool;
}
