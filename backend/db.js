import mysql from 'mysql2/promise';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

dotenv.config({ path: path.join(__dirname, '.env') });
dotenv.config({ path: path.join(__dirname, '../.env') });
dotenv.config();

const DB_HOST = process.env.DB_HOST || 'localhost';
const DB_USER = process.env.DB_USER || 'profilei_Hari';
const DB_PASSWORD = process.env.DB_PASSWORD !== undefined ? process.env.DB_PASSWORD : 'Rishidar123@';
const DB_NAME = process.env.DB_NAME || 'profilei_website';
const DB_PORT = Number(process.env.DB_PORT) || 3306;

// MySQL Database Connection Pool
export const pool = mysql.createPool({
  host: DB_HOST,
  user: DB_USER,
  password: DB_PASSWORD,
  database: DB_NAME,
  port: DB_PORT,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  enableKeepAlive: true,
  keepAliveInitialDelay: 0
});

// Test database connectivity
pool.getConnection()
  .then((conn) => {
    console.log(`[DB] Connected successfully to MySQL database "${DB_NAME}" on ${DB_HOST} as user "${DB_USER}"`);
    conn.release();
  })
  .catch((err) => {
    console.warn(`[DB WARNING] MySQL connection notice: ${err.message}. Backend will maintain seamless JSON file storage backup.`);
  });

