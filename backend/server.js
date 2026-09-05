import express from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { pool } from './db.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

dotenv.config({ path: path.join(__dirname, '.env') });

const app = express();
const PORT = process.env.PORT || 5001;

// Global Middleware
app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Strict Anti-Caching Middleware (Ensures browser always fetches latest site & API data on reload)
app.use((req, res, next) => {
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
  res.setHeader('Pragma', 'no-cache');
  res.setHeader('Expires', '0');
  res.setHeader('Surrogate-Control', 'no-store');
  next();
});

// Static uploads directory
app.use('/uploads', express.static(path.join(__dirname, './uploads')));

// Health Check Endpoint
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', service: 'Studio GoGangs Backend API', timestamp: new Date().toISOString() });
});

// Projects API
app.get('/api/projects', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM projects ORDER BY id DESC');
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

app.get('/api/projects/:projectId', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM projects WHERE project_id = ?', [req.params.projectId]);
    if (rows.length === 0) return res.status(404).json({ error: 'Project not found' });
    res.json(rows[0]);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Live Status API
app.get('/api/live-status', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM live_status ORDER BY updated_at DESC');
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

app.get('/api/live-status/:projectId', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM live_status WHERE project_id = ?', [req.params.projectId]);
    if (rows.length === 0) return res.status(404).json({ error: 'Live status not found' });
    res.json(rows[0]);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Persistent JSON File Storage Fallback for Hosted Environments
const dataDir = path.join(__dirname, './data');
if (!fs.existsSync(dataDir)) {
  fs.mkdirSync(dataDir, { recursive: true });
}
const freelancersFilePath = path.join(dataDir, 'freelancers.json');

function getFreelancersFromFile() {
  try {
    if (!fs.existsSync(freelancersFilePath)) return [];
    const raw = fs.readFileSync(freelancersFilePath, 'utf8');
    return JSON.parse(raw) || [];
  } catch (err) {
    console.warn('[FILE DB WARNING] Error reading freelancers.json:', err.message);
    return [];
  }
}

function saveFreelancerToFile(record) {
  try {
    const records = getFreelancersFromFile();
    const emailKey = (record.email || '').toLowerCase().trim();
    const usernameKey = (record.username || '').toLowerCase().trim();
    const memberIdKey = (record.member_id || '').toLowerCase().trim();

    const idx = records.findIndex(r => {
      const rEmail = (r.email || '').toLowerCase().trim();
      const rUser = (r.username || '').toLowerCase().trim();
      const rMember = (r.member_id || '').toLowerCase().trim();
      return (emailKey && rEmail === emailKey) ||
             (usernameKey && rUser === usernameKey) ||
             (memberIdKey && rMember === memberIdKey);
    });

    if (idx >= 0) {
      records[idx] = { ...records[idx], ...record, updated_at: new Date().toISOString() };
    } else {
      records.push({ ...record, updated_at: new Date().toISOString() });
    }

    fs.writeFileSync(freelancersFilePath, JSON.stringify(records, null, 2), 'utf8');
    console.log(`[FILE DB SUCCESS] Saved record for ${emailKey || usernameKey} in freelancers.json`);
  } catch (err) {
    console.error('[FILE DB ERROR] Failed to save freelancer to freelancers.json:', err.message);
  }
}

function deleteFreelancerFromFile(emailOrTerm) {
  try {
    const records = getFreelancersFromFile();
    const term = (emailOrTerm || '').toLowerCase().trim();
    const prefix = term.split('@')[0];
    const filtered = records.filter(r => {
      const rEmail = (r.email || '').toLowerCase().trim();
      const rUser = (r.username || '').toLowerCase().trim();
      const rMember = (r.member_id || '').toLowerCase().trim();
      const rPrefix = rEmail.split('@')[0];

      if (term && (rEmail === term || rUser === term || rMember === term)) return false;
      if (prefix.length >= 4 && (rPrefix === prefix || rUser === prefix)) return false;
      return true;
    });

    fs.writeFileSync(freelancersFilePath, JSON.stringify(filtered, null, 2), 'utf8');
    console.log(`[FILE DB SUCCESS] Deleted record matching '${term}' from freelancers.json`);
  } catch (err) {
    console.error('[FILE DB ERROR] Failed to delete freelancer from freelancers.json:', err.message);
  }
}

// Auto-create freelancers table if not exists
pool.query(`
  CREATE TABLE IF NOT EXISTS freelancers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id VARCHAR(50),
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255),
    name VARCHAR(255),
    portfolio_data LONGTEXT,
    has_completed_onboarding TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
`).catch((err) => console.warn('[DB WARNING] Freelancers table check:', err.message));

// Freelancers API
app.get('/api/freelancers', async (req, res) => {
  const seen = new Set();
  const deduplicated = [];

  const addRecord = (r) => {
    const emailLower = (r.email || '').toLowerCase().trim();
    const emailPrefix = emailLower.split('@')[0];
    const usernameLower = (r.username || '').toLowerCase().trim();
    const memberIdLower = (r.member_id || '').toLowerCase().trim();

    if (!emailLower && !usernameLower && !memberIdLower) return;

    if (seen.has(emailLower) || (usernameLower && seen.has(usernameLower)) || (memberIdLower && seen.has(memberIdLower)) || (emailPrefix && emailPrefix.length >= 4 && seen.has(emailPrefix))) {
      return;
    }

    if (emailLower) seen.add(emailLower);
    if (usernameLower) seen.add(usernameLower);
    if (memberIdLower) seen.add(memberIdLower);
    if (emailPrefix && emailPrefix.length >= 4) seen.add(emailPrefix);

    deduplicated.push(r);
  };

  try {
    const [rows] = await pool.query('SELECT * FROM freelancers ORDER BY id DESC');
    for (const r of rows) addRecord(r);
  } catch (error) {
    console.warn('[DB WARNING] Returning file list for freelancers:', error.message);
  }

  // Merge JSON file storage records
  const fileRecords = getFreelancersFromFile();
  for (const r of fileRecords) addRecord(r);

  res.json(deduplicated);
});

// Save or Update Freelancer Portfolio (MySQL DB + File Backup)
app.post('/api/freelancers/save', async (req, res) => {
  const { email, username, name, member_id, portfolio, has_completed_onboarding } = req.body;
  if (!email) return res.status(400).json({ error: 'Email is required' });

  const queryTerm = email.toLowerCase().trim();
  let portfolioObj = portfolio;
  if (typeof portfolioObj === 'string') {
    try { portfolioObj = JSON.parse(portfolioObj); } catch(e) { portfolioObj = {}; }
  }

  let finalOnboardingFlag = has_completed_onboarding ? 1 : 0;
  let finalPortfolioObj = portfolioObj || {};

  const finalEmail = (queryTerm.includes('@') ? queryTerm : (portfolioObj?.email || queryTerm));
  const finalUsername = username || portfolioObj?.username || finalEmail.split('@')[0];
  const finalName = name || portfolioObj?.fullName || '';
  const finalMemberId = member_id || portfolioObj?.userCode || '';

  if (finalPortfolioObj && typeof finalPortfolioObj === 'object') {
    finalPortfolioObj.email = finalEmail;
    if (!finalPortfolioObj.username) finalPortfolioObj.username = finalUsername;
    if (finalOnboardingFlag) finalPortfolioObj.hasCompletedOnboarding = true;
  }

  // 1. ALWAYS save to persistent JSON File Storage FIRST (Guarantees survival on hosted server)
  saveFreelancerToFile({
    email: finalEmail,
    username: finalUsername,
    name: finalName,
    member_id: finalMemberId,
    portfolio_data: finalPortfolioObj,
    has_completed_onboarding: finalOnboardingFlag
  });

  // 2. ALSO save to MySQL Database (profilei_website.freelancers) if pool is connected
  try {
    const usernamePrefix = finalUsername.split('@')[0];
    const [existingRows] = await pool.query(
      'SELECT * FROM freelancers WHERE email = ? OR username = ? OR member_id = ? OR email LIKE ? ORDER BY id DESC', 
      [queryTerm, finalUsername, finalMemberId, `${usernamePrefix}@%`]
    );

    if (existingRows.length > 0) {
      const existingRow = existingRows[0];
      let existingPortfolio = null;
      try {
        existingPortfolio = typeof existingRow.portfolio_data === 'string' 
          ? JSON.parse(existingRow.portfolio_data) 
          : existingRow.portfolio_data;
      } catch (e) {}

      if (existingRow.has_completed_onboarding && !finalOnboardingFlag) {
        finalOnboardingFlag = 1;
        if (finalPortfolioObj && typeof finalPortfolioObj === 'object') {
          finalPortfolioObj.hasCompletedOnboarding = true;
        }
      }

      const existingVideos = (existingPortfolio && Array.isArray(existingPortfolio.videos)) ? existingPortfolio.videos : [];
      const incomingVideos = (finalPortfolioObj && Array.isArray(finalPortfolioObj.videos)) ? finalPortfolioObj.videos : [];
      if (existingVideos.length > 0 && incomingVideos.length === 0) {
        if (finalPortfolioObj && typeof finalPortfolioObj === 'object') {
          finalPortfolioObj.videos = existingVideos;
        }
      }

      if (existingPortfolio?.approvalStatus && !finalPortfolioObj?.approvalStatus) {
        if (finalPortfolioObj && typeof finalPortfolioObj === 'object') {
          finalPortfolioObj.approvalStatus = existingPortfolio.approvalStatus;
          if (existingPortfolio.approvedAt) finalPortfolioObj.approvedAt = existingPortfolio.approvedAt;
        }
      }

      await pool.query(
        `UPDATE freelancers 
         SET email = ?, username = ?, name = ?, member_id = ?, portfolio_data = ?, has_completed_onboarding = ? 
         WHERE id = ?`,
        [finalEmail, finalUsername, finalName, finalMemberId, JSON.stringify(finalPortfolioObj), finalOnboardingFlag, existingRow.id]
      );
      console.log(`[DB SUCCESS] Updated portfolio in MySQL for ${finalEmail}`);
    } else {
      await pool.query(
        `INSERT INTO freelancers (email, username, name, member_id, portfolio_data, has_completed_onboarding)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [finalEmail, finalUsername, finalName, finalMemberId, JSON.stringify(finalPortfolioObj), finalOnboardingFlag]
      );
      console.log(`[DB SUCCESS] Created new portfolio in MySQL for ${finalEmail}`);
    }
  } catch (error) {
    console.warn('[DB WARNING] MySQL save notice (saved to JSON file backup):', error.message);
  }

  return res.json({ status: 'success', email: finalEmail });
});

// Approve Freelancer Creator (MySQL DB + File Backup)
app.post('/api/freelancers/approve', async (req, res) => {
  const { email, status } = req.body;
  if (!email) return res.status(400).json({ error: 'Email is required' });

  const finalEmail = email.toLowerCase().trim();
  const newStatus = status || 'approved'; // 'approved' | 'pending' | 'rejected'
  const approvedAt = newStatus === 'approved' ? new Date().toISOString() : undefined;

  // 1. Update in JSON file storage
  const records = getFreelancersFromFile();
  const index = records.findIndex((r) => {
    const rEmail = (r.email || r.portfolio_data?.email || '').toLowerCase().trim();
    return rEmail === finalEmail;
  });

  if (index >= 0) {
    let pData = records[index].portfolio_data || {};
    if (typeof pData === 'string') {
      try { pData = JSON.parse(pData); } catch(e) {}
    }
    pData.approvalStatus = newStatus;
    if (approvedAt) {
      pData.approvedAt = approvedAt;
    } else if (newStatus === 'pending') {
      delete pData.approvedAt;
    }
    records[index].portfolio_data = pData;
    records[index].updated_at = new Date().toISOString();
    fs.writeFileSync(freelancersFilePath, JSON.stringify(records, null, 2), 'utf8');
    console.log(`[FILE DB SUCCESS] Updated approval status to '${newStatus}' for ${finalEmail}`);
  }

  // 2. Update in MySQL Database if connected
  try {
    const [existingRows] = await pool.query(
      'SELECT id, portfolio_data FROM freelancers WHERE email = ? LIMIT 1',
      [finalEmail]
    );

    if (existingRows && existingRows.length > 0) {
      let pData = {};
      try {
        pData = typeof existingRows[0].portfolio_data === 'string'
          ? JSON.parse(existingRows[0].portfolio_data)
          : (existingRows[0].portfolio_data || {});
      } catch (e) {}

      pData.approvalStatus = newStatus;
      if (approvedAt) {
        pData.approvedAt = approvedAt;
      } else if (newStatus === 'pending') {
        delete pData.approvedAt;
      }

      await pool.query(
        'UPDATE freelancers SET portfolio_data = ? WHERE id = ?',
        [JSON.stringify(pData), existingRows[0].id]
      );
      console.log(`[DB SUCCESS] Updated approval status to '${newStatus}' in MySQL for ${finalEmail}`);
    }
  } catch (error) {
    console.warn('[DB WARNING] MySQL approval update notice:', error.message);
  }

  return res.json({ status: 'success', email: finalEmail, approvalStatus: newStatus, approvedAt });
});

// Delete Freelancer Portfolio (MySQL DB + File Backup)
app.post('/api/freelancers/delete', async (req, res) => {
  const { email, username, member_id, id } = req.body;
  const emailTerm = (email || '').toLowerCase().trim();
  const userTerm = (username || '').toLowerCase().trim();
  const memberTerm = (member_id || '').toLowerCase().trim();
  const idTerm = (id || '').toLowerCase().trim();
  const emailPrefix = emailTerm ? (emailTerm.split('@')[0] + '%') : '%';

  const mainTerm = emailTerm || userTerm || memberTerm || idTerm;
  if (!mainTerm) return res.status(400).json({ error: 'Email or identifier is required to delete' });

  // 1. Delete from persistent JSON File Storage
  deleteFreelancerFromFile(mainTerm);

  // 2. Delete from MySQL DB
  try {
    const [result] = await pool.query(
      `DELETE FROM freelancers 
       WHERE email = ? 
          OR username = ? 
          OR member_id = ? 
          OR (email LIKE ? AND email != '')`,
      [emailTerm || mainTerm, userTerm || mainTerm, memberTerm || mainTerm, emailPrefix]
    );
    console.log(`[DB SUCCESS] Deleted portfolio from MySQL (rows deleted: ${result.affectedRows})`);
  } catch (error) {
    console.warn('[DB WARNING] MySQL delete error:', error.message);
  }

  return res.json({ status: 'success', deleted: mainTerm });
});

// Helper to fetch freelancer by email, username, or member_id (MySQL DB + File Backup)
const getFreelancerByIdentifier = async (identifier) => {
  if (!identifier) return null;
  const queryTerm = identifier.toLowerCase().trim();
  let row = null;

  // 1. Try MySQL Database (profilei_website / studio)
  try {
    const usernamePrefix = queryTerm.split('@')[0];
    const cleanId = queryTerm.replace(/[^a-zA-Z0-9]/g, '');
    const [rows] = await pool.query(
      `SELECT * FROM freelancers 
       WHERE LOWER(email) = ? 
          OR LOWER(username) = ? 
          OR LOWER(member_id) = ? 
          OR LOWER(email) LIKE ? 
          OR LOWER(username) LIKE ? 
          OR LOWER(REPLACE(REPLACE(username, '_', ''), '-', '')) LIKE ? 
          OR LOWER(REPLACE(REPLACE(email, '_', ''), '-', '')) LIKE ?
       ORDER BY id DESC LIMIT 1`, 
      [queryTerm, queryTerm, queryTerm, `${usernamePrefix}%`, `${queryTerm}%`, `${cleanId}%`, `${cleanId}%`]
    );
    if (rows && rows.length > 0) {
      row = rows[0];
    }
  } catch (error) {
    console.warn('[DB WARNING] MySQL fetch notice (using JSON file fallback):', error.message);
  }

  // 2. Fallback to File Storage if MySQL record was not found or error occurred
  if (!row) {
    const fileRecords = getFreelancersFromFile();
    const queryPrefix = queryTerm.split('@')[0];
    const cleanId = queryTerm.replace(/[^a-zA-Z0-9]/g, '');
    const found = fileRecords.find(r => {
      const rEmail = (r.email || '').toLowerCase().trim();
      const rUser = (r.username || '').toLowerCase().trim();
      const rMember = (r.member_id || r.userCode || '').toLowerCase().trim();
      const rCleanUser = rUser.replace(/[^a-zA-Z0-9]/g, '');
      const rCleanEmail = rEmail.replace(/[^a-zA-Z0-9]/g, '');
      return rEmail === queryTerm || 
             rUser === queryTerm || 
             rMember === queryTerm || 
             rEmail.startsWith(queryPrefix) || 
             (cleanId && (rCleanUser.startsWith(cleanId) || rCleanEmail.startsWith(cleanId)));
    });
    if (found) {
      row = found;
    }
  }

  if (!row) return null;
  
  let portfolioObj = null;
  try {
    portfolioObj = typeof row.portfolio_data === 'string' ? JSON.parse(row.portfolio_data) : row.portfolio_data;
  } catch (e) {
    portfolioObj = null;
  }

  if (portfolioObj && typeof portfolioObj === 'object') {
    portfolioObj.hasCompletedOnboarding = Boolean(row.has_completed_onboarding ?? portfolioObj.hasCompletedOnboarding);
    if (row.member_id) portfolioObj.userCode = row.member_id;
    if (row.email) portfolioObj.email = row.email;
    if (row.username) portfolioObj.username = row.username;
    if (row.name) portfolioObj.fullName = row.name;
    return portfolioObj;
  }
  
  return row;
};

// Get Freelancer Portfolio by Email, Username, or Member ID
app.get('/api/freelancers/by-email/:email', async (req, res) => {
  const result = await getFreelancerByIdentifier(req.params.email);
  res.json(result);
});

app.get('/api/freelancers/by-username/:username', async (req, res) => {
  const result = await getFreelancerByIdentifier(req.params.username);
  res.json(result);
});

app.get('/api/freelancers/by-code/:code', async (req, res) => {
  const result = await getFreelancerByIdentifier(req.params.code);
  res.json(result);
});

// Video File Upload Endpoint (Saves uploaded files to /uploads and returns permanent HTTP URL for cross-browser playback)
app.post('/api/upload-video', (req, res) => {
  const { videoData, fileName } = req.body;
  if (!videoData) return res.status(400).json({ error: 'videoData is required' });

  try {
    const uploadsDir = path.join(__dirname, './uploads');
    if (!fs.existsSync(uploadsDir)) {
      fs.mkdirSync(uploadsDir, { recursive: true });
    }

    const matches = videoData.match(/^data:(video\/[a-zA-Z0-9]+);base64,(.+)$/);
    let buffer;
    let ext = '.mp4';

    if (matches && matches.length === 3) {
      ext = matches[1].includes('webm') ? '.webm' : matches[1].includes('mov') ? '.mov' : '.mp4';
      buffer = Buffer.from(matches[2], 'base64');
    } else {
      buffer = Buffer.from(videoData, 'base64');
    }

    const safeName = `vid_${Date.now()}_${Math.random().toString(36).substring(2, 8)}${ext}`;
    const filePath = path.join(uploadsDir, safeName);
    fs.writeFileSync(filePath, buffer);

    const publicUrl = `/uploads/${safeName}`;
    console.log(`[VIDEO UPLOAD SUCCESS] Saved video file to ${publicUrl}`);
    res.json({ status: 'success', videoUrl: publicUrl });
  } catch (err) {
    console.error('[VIDEO UPLOAD ERROR]:', err);
    res.status(500).json({ error: err.message });
  }
});

// Binary Video File Upload Endpoint (Saves to portfolio_videos MySQL DB + stream disk backup)
app.post('/api/upload-video-binary', (req, res) => {
  try {
    const fileName = req.query.filename || 'video.mp4';
    const email = (req.query.email || 'user@gogangs.com').toLowerCase().trim();
    const videoId = 'vid_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
    const uploadsDir = path.join(__dirname, './uploads');
    if (!fs.existsSync(uploadsDir)) {
      fs.mkdirSync(uploadsDir, { recursive: true });
    }

    const ext = path.extname(fileName) || '.mp4';
    const safeName = `${videoId}${ext}`;
    const filePath = path.join(uploadsDir, safeName);
    const writeStream = fs.createWriteStream(filePath);

    const chunks = [];
    req.on('data', (chunk) => {
      chunks.push(chunk);
    });

    req.pipe(writeStream);

    writeStream.on('finish', async () => {
      const buffer = Buffer.concat(chunks);
      const b64Data = buffer.toString('base64');
      const fileType = req.headers['content-type'] || 'video/mp4';

      // 1. Store directly into portfolio_videos MySQL table
      try {
        await pool.query(
          `INSERT INTO portfolio_videos (video_id, email, filename, file_type, video_data)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE video_data = VALUES(video_data)`,
          [videoId, email, fileName, fileType, b64Data]
        );
        console.log(`[DB SUCCESS] Saved video ${videoId} to portfolio_videos table (${buffer.length} bytes)`);
      } catch (dbErr) {
        console.warn('[DB NOTICE] portfolio_videos insert notice:', dbErr.message);
      }

      const streamUrl = `/api/videos/stream?id=${videoId}`;
      console.log(`[VIDEO UPLOAD SUCCESS] Stream URL generated: ${streamUrl}`);
      res.json({ status: 'success', videoId, videoUrl: streamUrl, size: buffer.length });
    });

    writeStream.on('error', (err) => {
      console.error('[VIDEO STREAM WRITE ERROR]:', err);
      res.status(500).json({ error: err.message });
    });
  } catch (err) {
    console.error('[VIDEO UPLOAD ERROR]:', err);
    res.status(500).json({ error: err.message });
  }
});

// Stream Video directly from portfolio_videos MySQL table (GET /api/videos/stream?id=vid_xxx)
app.get('/api/videos/stream', async (req, res) => {
  const videoId = req.query.id;
  if (!videoId) return res.status(400).send('Video ID required');

  try {
    const [rows] = await pool.query('SELECT video_data, file_type FROM portfolio_videos WHERE video_id = ? LIMIT 1', [videoId]);
    if (rows.length > 0) {
      const { video_data, file_type } = rows[0];
      const buffer = Buffer.from(video_data, 'base64');
      res.setHeader('Content-Type', file_type || 'video/mp4');
      res.setHeader('Content-Length', buffer.length);
      res.setHeader('Accept-Ranges', 'bytes');
      res.setHeader('Cache-Control', 'public, max-age=31536000');
      return res.send(buffer);
    }
  } catch (dbErr) {
    console.warn('Stream DB query notice:', dbErr.message);
  }

  // Fallback to disk file if present
  const uploadsDir = path.join(__dirname, './uploads');
  const files = fs.readdirSync(uploadsDir);
  const match = files.find(f => f.startsWith(videoId));
  if (match) {
    return res.sendFile(path.join(uploadsDir, match));
  }

  res.status(404).send('Video file not found in portfolio_videos database table');
});

// Clients API
app.get('/api/clients', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM clients ORDER BY id DESC');
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Direct Messages API
app.get('/api/direct-messages', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM direct_messages ORDER BY created_at DESC');
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

app.post('/api/direct-messages', async (req, res) => {
  const { sender_email, sender_role, sender_name, recipient_email, project_id, message } = req.body;
  if (!sender_email || !message) {
    return res.status(400).json({ error: 'sender_email and message are required' });
  }
  try {
    const [result] = await pool.query(
      `INSERT INTO direct_messages (sender_email, sender_role, sender_name, recipient_email, project_id, message) 
       VALUES (?, ?, ?, ?, ?, ?)`,
      [sender_email, sender_role || 'client', sender_name || 'Client', recipient_email || 'hello@gogangs.com', project_id || null, message]
    );
    res.status(201).json({ id: result.insertId, status: 'sent' });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Deliverables & Project Files API
app.get('/api/project-files/:projectId', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM project_files WHERE project_id = ? ORDER BY created_at DESC', [req.params.projectId]);
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// Serve static frontend build files if present
const distPath = path.join(__dirname, '../frontend/dist');
app.use(express.static(distPath));

app.get('{*splat}', async (req, res, next) => {
  if (req.url.startsWith('/api')) return next();

  const indexPath = path.join(distPath, 'index.html');
  if (!fs.existsSync(indexPath)) {
    return res.json({
      name: 'Studio GoGangs Backend Server',
      status: 'online',
      environment: process.env.NODE_ENV || 'production',
      website: 'https://studio.gogangs.com'
    });
  }

  let rawHtml = fs.readFileSync(indexPath, 'utf8');

  // Check if request is for a portfolio share URL: /c/:userCode/:username, /:userCode/:username, /c/:username, /p/:username
  const urlPath = req.path || req.url;
  const match = urlPath.match(/^\/(?:c|p|portfolio\/)?([a-z0-9_-]+)(?:\/([a-z0-9_-]+))?/i);
  const targetUser = (match ? (match[1] || match[2]) : '').toLowerCase().trim();

  if (targetUser) {
    try {
      const [rows] = await pool.query(
        'SELECT * FROM freelancers WHERE username = ? OR email = ? OR member_id = ?', 
        [targetUser, targetUser, targetUser]
      );

      if (rows.length > 0) {
        const row = rows[0];
        let pObj = null;
        try {
          pObj = typeof row.portfolio_data === 'string' ? JSON.parse(row.portfolio_data) : row.portfolio_data;
        } catch(e) {}

        const fullName = pObj?.fullName || row.name || row.username || 'Video Editor';
        const title = pObj?.title || 'Professional Video Editor';
        const bio = pObj?.bio || `Check out ${fullName}'s video editing portfolio, client projects, and pricing packages on GoGangs Studio.`;
        const avatarUrl = pObj?.avatarUrl || 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=1200&auto=format&fit=crop&q=80';
        
        const pageTitle = `${fullName} — ${title} | GoGangs Studio`;
        const pageDescription = `${fullName} (${title}) • ${bio}`;
        const currentUrl = `https://studio.gogangs.com${req.originalUrl || req.url}`;

        rawHtml = rawHtml
          .replace(/<title>.*?<\/title>/gi, `<title>${escapeHtml(pageTitle)}</title>`)
          .replace(/<meta\s+name="description"\s+content=".*?"\s*\/?>/gi, `<meta name="description" content="${escapeHtml(pageDescription)}" />`)
          .replace(/<meta\s+property="og:title"\s+content=".*?"\s*\/?>/gi, `<meta property="og:title" content="${escapeHtml(pageTitle)}" />`)
          .replace(/<meta\s+property="og:description"\s+content=".*?"\s*\/?>/gi, `<meta property="og:description" content="${escapeHtml(pageDescription)}" />`)
          .replace(/<meta\s+property="og:image"\s+content=".*?"\s*\/?>/gi, `<meta property="og:image" content="${escapeHtml(avatarUrl)}" />`)
          .replace(/<meta\s+property="og:url"\s+content=".*?"\s*\/?>/gi, `<meta property="og:url" content="${escapeHtml(currentUrl)}" />`)
          .replace(/<meta\s+name="twitter:title"\s+content=".*?"\s*\/?>/gi, `<meta name="twitter:title" content="${escapeHtml(pageTitle)}" />`)
          .replace(/<meta\s+name="twitter:description"\s+content=".*?"\s*\/?>/gi, `<meta name="twitter:description" content="${escapeHtml(pageDescription)}" />`)
          .replace(/<meta\s+name="twitter:image"\s+content=".*?"\s*\/?>/gi, `<meta name="twitter:image" content="${escapeHtml(avatarUrl)}" />`);
      }
    } catch (err) {
      console.warn('[OG Meta Injection Warning]:', err.message);
    }
  }

  res.setHeader('Content-Type', 'text/html');
  res.send(rawHtml);
});

// Start Server
app.listen(PORT, () => {
  console.log(`==================================================`);
  console.log(`🚀 STUDIO GOGANGS BACKEND SERVER RUNNING ON PORT ${PORT}`);
  console.log(`📡 URL: http://localhost:${PORT}`);
  console.log(`==================================================`);
});

export default app;
