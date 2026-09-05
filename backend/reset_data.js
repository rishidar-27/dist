import mysql from 'mysql2/promise';
import fs from 'fs';
import path from 'path';

async function resetAllData() {
  const configs = [
    { host: 'localhost', user: 'root', password: 'Rishidar123@', database: 'studio', port: 3306 },
    { host: 'localhost', user: 'root', password: 'Rishidar123@', database: 'website', port: 3306 },
    { host: 'localhost', user: 'root', password: '', database: 'studio', port: 3306 },
    { host: 'localhost', user: 'root', password: '', database: 'website', port: 3306 }
  ];

  const tables = ['freelancers', 'portfolio_videos', 'clients', 'direct_messages', 'live_status', 'projects', 'project_files'];

  for (const cfg of configs) {
    try {
      const conn = await mysql.createConnection(cfg);
      console.log(`Connected to MySQL database "${cfg.database}" with user "${cfg.user}"`);

      // Disable foreign key checks for clean truncation
      await conn.query('SET FOREIGN_KEY_CHECKS = 0');
      for (const table of tables) {
        try {
          await conn.query(`TRUNCATE TABLE \`${table}\``);
          console.log(`  - Truncated table: ${table} in ${cfg.database}`);
        } catch (tblErr) {
          // table might not exist
        }
      }
      await conn.query('SET FOREIGN_KEY_CHECKS = 1');
      await conn.end();
    } catch (dbErr) {
      // ignore unreachable configs
    }
  }

  // Clear backend/data/freelancers.json
  const freelancersJsonPath = path.resolve('./data/freelancers.json');
  if (fs.existsSync(freelancersJsonPath)) {
    fs.writeFileSync(freelancersJsonPath, JSON.stringify([], null, 2));
    console.log('Cleared ./data/freelancers.json to []');
  }

  // Clear backend/uploads if desired
  const uploadsDir = path.resolve('./uploads');
  if (fs.existsSync(uploadsDir)) {
    const files = fs.readdirSync(uploadsDir);
    for (const f of files) {
      if (f.startsWith('vid_') || f.endsWith('.mp4')) {
        try {
          fs.unlinkSync(path.join(uploadsDir, f));
          console.log(`  - Removed upload: ${f}`);
        } catch {}
      }
    }
  }

  console.log('Reset complete! Database and JSON storage are clean.');
}

resetAllData().catch(err => {
  console.error('Reset error:', err);
  process.exit(1);
});
