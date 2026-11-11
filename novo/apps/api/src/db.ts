import mysql, { type RowDataPacket } from 'mysql2/promise';
import { config } from './config';

export const pool = mysql.createPool({
  ...config.db,
  charset: 'utf8mb4',
  connectionLimit: 10,
  enableKeepAlive: true,
  dateStrings: true,
  timezone: '-03:00',
});

/** SELECT tipado: cada linha vira T. */
export async function consulta<T>(sql: string, params: unknown[] = []): Promise<T[]> {
  const [linhas] = await pool.query<RowDataPacket[]>(sql, params);
  return linhas as unknown as T[];
}

export async function primeira<T>(sql: string, params: unknown[] = []): Promise<T | null> {
  const linhas = await consulta<T>(sql, params);
  return linhas[0] ?? null;
}
