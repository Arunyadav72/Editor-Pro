<?php
// ============================================
// DATABASE CONFIGURATION
// ============================================
$host = 'localhost';
$dbname = 'testdb';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============================================
    // CREATE ALL TABLES
    // ============================================
    
    // Main documents table
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content LONGTEXT NOT NULL,
        version INT DEFAULT 1,
        password VARCHAR(255) NULL,
        scheduled_date DATETIME NULL,
        is_published BOOLEAN DEFAULT FALSE,
        category VARCHAR(100) DEFAULT 'general',
        tags VARCHAR(500) NULL,
        view_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated (updated_at),
        INDEX idx_published (is_published),
        INDEX idx_category (category)
    )");
    
    // Version history table
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        content LONGTEXT NOT NULL,
        version INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
        INDEX idx_doc_version (document_id, version)
    )");
    
    // Comments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        user_name VARCHAR(100) DEFAULT 'Anonymous',
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
        INDEX idx_doc_comments (document_id)
    )");
    
    // Analytics table
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        user_agent TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doc_analytics (document_id),
        INDEX idx_event_type (event_type)
    )");
    
    // Templates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        content LONGTEXT NOT NULL,
        category VARCHAR(50) DEFAULT 'general',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert default templates if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM templates");
    if ($stmt->fetchColumn() == 0) {
        $templates = [
            ['Blog Post', '{"ops":[{"insert":"How to [Topic] in 5 Easy Steps\\n","attributes":{"header":1}},{"insert":"\\nIntroduction\\n","attributes":{"header":2}},{"insert":"Have you ever wanted to learn about [topic]? In this post, I\'ll show you exactly how.\\n\\n"},{"insert":"Step 1: Get Started\\n","attributes":{"header":2}},{"insert":"The first thing you need to do is...\\n\\n"},{"insert":"Conclusion\\n","attributes":{"header":2}},{"insert":"Now you know how to master [topic]. Start implementing these steps today!"}]}', 'blog'],
            ['Cover Letter', '{"ops":[{"insert":"Dear Hiring Manager,\\n\\n","attributes":{"bold":true}},{"insert":"I am writing to express my interest in the [Position] role at [Company].\\n\\n"},{"insert":"With my experience in [Skill], I believe I would be a great fit.\\n\\n"},{"insert":"Sincerely,\\n[Your Name]"}]}', 'professional'],
            ['Business Proposal', '{"ops":[{"insert":"Project Proposal: [Project Name]\\n","attributes":{"header":1}},{"insert":"\\nExecutive Summary\\n","attributes":{"header":2}},{"insert":"This proposal outlines our approach to...\\n\\n"},{"insert":"Budget Breakdown:\\n\\n","attributes":{"header":2}},{"insert":"Phase 1: $[amount]\\n","attributes":{"list":"bullet"}},{"insert":"Phase 2: $[amount]\\n","attributes":{"list":"bullet"}},{"insert":"Phase 3: $[amount]\\n\\n","attributes":{"list":"bullet"}},{"insert":"Timeline: [X] weeks"}]}', 'business'],
        ];
        
        $stmt = $pdo->prepare("INSERT INTO templates (name, content, category) VALUES (?, ?, ?)");
        foreach ($templates as $template) {
            $stmt->execute($template);
        }
    }
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Create uploads directory
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}

// ============================================
// HANDLE ALL AJAX REQUESTS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // ============================================
    // SAVE DOCUMENT WITH VERSIONING
    // ============================================
    if ($action === 'save') {
        $title = $_POST['title'] ?? 'Untitled';
        $content = $_POST['content'] ?? '{"ops":[]}';
        $id = $_POST['id'] ?? null;
        $category = $_POST['category'] ?? 'general';
        $tags = $_POST['tags'] ?? '';
        
        json_decode($content);
        if (json_last_error() === JSON_ERROR_NONE) {
            if ($id) {
                // Get current version
                $stmt = $pdo->prepare("SELECT version FROM documents WHERE id = ?");
                $stmt->execute([$id]);
                $currentVersion = $stmt->fetchColumn() ?? 0;
                $newVersion = $currentVersion + 1;
                
                // Save version history
                $stmt = $pdo->prepare("INSERT INTO document_versions (document_id, content, version) VALUES (?, ?, ?)");
                $stmt->execute([$id, $content, $newVersion]);
                
                // Update document
                $stmt = $pdo->prepare("UPDATE documents SET title = ?, content = ?, category = ?, tags = ?, version = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $content, $category, $tags, $newVersion, $id]);
                echo json_encode(['success' => true, 'id' => $id, 'version' => $newVersion]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO documents (title, content, category, tags) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $content, $category, $tags]);
                $newId = $pdo->lastInsertId();
                
                // Save initial version
                $stmt = $pdo->prepare("INSERT INTO document_versions (document_id, content, version) VALUES (?, ?, 1)");
                $stmt->execute([$newId, $content]);
                
                echo json_encode(['success' => true, 'id' => $newId, 'version' => 1]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        }
        exit;
    }
    
    // ============================================
    // LOAD DOCUMENT
    // ============================================
    if ($action === 'load') {
        $id = $_POST['id'] ?? 0;
        $version = $_POST['version'] ?? null;
        
        // Check password protection
        $password = $_POST['password'] ?? null;
        if ($password) {
            $stmt = $pdo->prepare("SELECT password FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $hash = $stmt->fetchColumn();
            if ($hash && !password_verify($password, $hash)) {
                echo json_encode(['success' => false, 'error' => 'Wrong password']);
                exit;
            }
        }
        
        if ($version) {
            $stmt = $pdo->prepare("SELECT * FROM document_versions WHERE document_id = ? AND version = ?");
            $stmt->execute([$id, $version]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            // Update view count
            $pdo->prepare("UPDATE documents SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);
            // Add analytics
            $pdo->prepare("INSERT INTO analytics (document_id, event_type, user_agent, ip_address) VALUES (?, 'view', ?, ?)")
                ->execute([$id, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '']);
        }
        
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            echo json_encode(['success' => true, 'document' => $doc]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Document not found']);
        }
        exit;
    }
    
    // ============================================
    // GET VERSIONS
    // ============================================
    if ($action === 'versions') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT version, created_at FROM document_versions WHERE document_id = ? ORDER BY version DESC");
        $stmt->execute([$id]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'versions' => $versions]);
        exit;
    }
    
    // ============================================
    // LIST DOCUMENTS WITH FILTERS
    // ============================================
    if ($action === 'list') {
        $search = $_POST['search'] ?? '';
        $category = $_POST['category'] ?? '';
        $sort = $_POST['sort'] ?? 'updated_at DESC';
        
        $sql = "SELECT id, title, category, tags, version, view_count, created_at, updated_at FROM documents WHERE 1=1";
        $params = [];
        
        if ($search) {
            $sql .= " AND (title LIKE ? OR tags LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($category && $category !== 'all') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY $sort";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'documents' => $docs]);
        exit;
    }
    
    // ============================================
    // DELETE DOCUMENT
    // ============================================
    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============================================
    // ADD COMMENT
    // ============================================
    if ($action === 'add_comment') {
        $id = $_POST['id'] ?? 0;
        $comment = $_POST['comment'] ?? '';
        $userName = $_POST['user_name'] ?? 'Anonymous';
        
        $stmt = $pdo->prepare("INSERT INTO comments (document_id, user_name, comment) VALUES (?, ?, ?)");
        $stmt->execute([$id, $userName, $comment]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============================================
    // GET COMMENTS
    // ============================================
    if ($action === 'get_comments') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM comments WHERE document_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    }
    
    // ============================================
    // IMAGE UPLOAD
    // ============================================
    if (isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type']);
            exit;
        }
        
        $fileName = time() . '_' . uniqid() . '.' . $ext;
        $targetPath = 'uploads/' . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => true, 'url' => $targetPath]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
        }
        exit;
    }
    
    // ============================================
    // SET PASSWORD PROTECTION
    // ============================================
    if ($action === 'set_password') {
        $id = $_POST['id'] ?? 0;
        $password = $_POST['password'] ?? '';
        
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE documents SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            echo json_encode(['success' => true]);
        } else {
            $stmt = $pdo->prepare("UPDATE documents SET password = NULL WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'removed' => true]);
        }
        exit;
    }
    
    // ============================================
    // SCHEDULE PUBLISHING
    // ============================================
    if ($action === 'schedule') {
        $id = $_POST['id'] ?? 0;
        $scheduledDate = $_POST['scheduled_date'] ?? null;
        
        $stmt = $pdo->prepare("UPDATE documents SET scheduled_date = ? WHERE id = ?");
        $stmt->execute([$scheduledDate, $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============================================
    // GET STATISTICS / ANALYTICS
    // ============================================
    if ($action === 'get_stats') {
        $id = $_POST['id'] ?? 0;
        
        // Get view count
        $stmt = $pdo->prepare("SELECT view_count FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $views = $stmt->fetchColumn();
        
        // Get comment count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE document_id = ?");
        $stmt->execute([$id]);
        $comments = $stmt->fetchColumn();
        
        // Get version count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_versions WHERE document_id = ?");
        $stmt->execute([$id]);
        $versions = $stmt->fetchColumn();
        
        // Get daily views last 7 days
        $stmt = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM analytics WHERE document_id = ? AND event_type = 'view' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at)");
        $stmt->execute([$id]);
        $dailyViews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'views' => $views,
                'comments' => $comments,
                'versions' => $versions,
                'daily_views' => $dailyViews
            ]
        ]);
        exit;
    }
    
    // ============================================
    // GET TEMPLATES
    // ============================================
    if ($action === 'get_templates') {
        $category = $_POST['category'] ?? 'all';
        $sql = "SELECT id, name, category FROM templates";
        if ($category !== 'all') {
            $sql .= " WHERE category = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$category]);
        } else {
            $stmt = $pdo->query($sql);
        }
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'templates' => $templates]);
        exit;
    }
    
    // ============================================
    // APPLY TEMPLATE
    // ============================================
    if ($action === 'apply_template') {
        $id = $_POST['template_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT name, content FROM templates WHERE id = ?");
        $stmt->execute([$id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            echo json_encode(['success' => true, 'template' => $template]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Template not found']);
        }
        exit;
    }
    
    // ============================================
    // AI SUGGESTION (Mock - Replace with OpenAI)
    // ============================================
    if ($action === 'ai_suggest') {
        $text = $_POST['text'] ?? '';
        $suggestions = [
            '💡 Consider adding more specific examples to support this point.',
            '📊 A statistic or data point would strengthen your argument here.',
            '✏️ This section could benefit from a shorter, punchier sentence.',
            '🎯 Add a clear call-to-action at the end of this paragraph.',
            '📝 Break this long paragraph into smaller chunks for better readability.',
            '🔍 Use active voice instead of passive voice for more impact.',
            '🏷️ Add relevant keywords to improve SEO.',
            '📎 Consider adding a relevant image or video to illustrate this point.'
        ];
        shuffle($suggestions);
        echo json_encode(['success' => true, 'suggestions' => array_slice($suggestions, 0, 4)]);
        exit;
    }
    
    // ============================================
    // SPELL CHECK (Mock)
    // ============================================
    if ($action === 'spell_check') {
        $text = $_POST['text'] ?? '';
        // Common typos to check
        $commonErrors = [
            'teh' => 'the',
            'recieve' => 'receive',
            'seperate' => 'separate',
            'definately' => 'definitely',
            'accomodate' => 'accommodate',
            'occured' => 'occurred',
            'priviledge' => 'privilege',
            'maintainance' => 'maintenance'
        ];
        
        $errors = [];
        foreach ($commonErrors as $wrong => $correct) {
            if (stripos($text, $wrong) !== false) {
                $errors[] = ['word' => $wrong, 'suggestion' => $correct];
            }
        }
        
        echo json_encode(['success' => true, 'errors' => $errors]);
        exit;
    }
    
    // ============================================
    // BACKUP ALL DOCUMENTS
    // ============================================
    if ($action === 'backup') {
        $stmt = $pdo->query("SELECT id, title, content, category, tags, created_at, updated_at FROM documents");
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $backup = [
            'date' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'documents' => $documents
        ];
        
        echo json_encode(['success' => true, 'backup' => $backup]);
        exit;
    }
    
    // ============================================
    // RESTORE BACKUP
    // ============================================
    if ($action === 'restore') {
        $backupData = $_POST['backup_data'] ?? '';
        $backup = json_decode($backupData, true);
        
        if ($backup && isset($backup['documents'])) {
            foreach ($backup['documents'] as $doc) {
                $stmt = $pdo->prepare("INSERT INTO documents (title, content, category, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$doc['title'], $doc['content'], $doc['category'] ?? 'general', $doc['tags'] ?? '', $doc['created_at'], $doc['updated_at']]);
            }
            echo json_encode(['success' => true, 'restored' => count($backup['documents'])]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid backup file']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>ULTIMATE EDITOR PRO - Complete Writing Suite</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Quill CSS & JS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,100..900&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- html2pdf for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        /* ============================================
           CSS VARIABLES & THEMING
        ============================================ */
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-tertiary: #64748b;
            --border: #e2e8f0;
            --accent-primary: #8b5cf6;
            --accent-secondary: #ec4899;
            --accent-success: #10b981;
            --accent-warning: #f59e0b;
            --accent-danger: #ef4444;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
        }
        
        .dark-mode {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-tertiary: #94a3b8;
            --border: #334155;
        }
        
        * {
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
        
        body {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-tertiary); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-primary); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent-secondary); }
        
        /* Quill Editor Customization */
        .ql-toolbar {
            border: none !important;
            border-bottom: 1px solid var(--border) !important;
            background: var(--bg-primary) !important;
            border-radius: 12px 12px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .ql-container {
            border: none !important;
            font-size: 16px;
            font-family: 'Inter', monospace !important;
            min-height: 500px;
            background: var(--bg-primary);
        }
        
        .ql-editor {
            padding: 40px 60px !important;
            line-height: 1.8 !important;
            color: var(--text-primary);
        }
        
        /* Preview Mode Styling */
        .preview-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
            line-height: 1.8;
        }
        
        .preview-content h1 { font-size: 2.5rem; font-weight: 700; margin: 1.5rem 0 1rem; }
        .preview-content h2 { font-size: 2rem; font-weight: 600; margin: 1.5rem 0 0.75rem; }
        .preview-content h3 { font-size: 1.5rem; font-weight: 600; margin: 1.25rem 0 0.5rem; }
        .preview-content p { margin-bottom: 1rem; }
        .preview-content ul, .preview-content ol { margin: 1rem 0 1rem 2rem; }
        .preview-content blockquote {
            border-left: 4px solid var(--accent-primary);
            padding-left: 1.5rem;
            margin: 1rem 0;
            font-style: italic;
            color: var(--text-secondary);
        }
        .preview-content pre {
            background: var(--bg-tertiary);
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
        }
        .preview-content img { max-width: 100%; border-radius: 8px; margin: 1rem 0; }
        
        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            border-radius: 28px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
        }
        
        .fab:hover { transform: scale(1.1) rotate(90deg); }
        
        /* Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .animate-fadeInUp { animation: fadeInUp 0.4s ease-out; }
        .animate-slideInLeft { animation: slideInLeft 0.3s ease-out; }
        .animate-slideInRight { animation: slideInRight 0.3s ease-out; }
        
        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 100px;
            right: 24px;
            background: var(--bg-primary);
            border-left: 4px solid var(--accent-primary);
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            animation: slideInRight 0.3s ease;
            color: var(--text-primary);
        }
        
        /* Progress Bar */
        .progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            z-index: 10000;
            transition: width 0.3s;
        }
        
        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeInUp 0.3s ease;
        }
        
        .modal-content {
            background: var(--bg-primary);
            border-radius: 16px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        /* Focus Mode */
        .focus-mode .ql-toolbar,
        .focus-mode .sidebar,
        .focus-mode .editor-header,
        .focus-mode .fab {
            opacity: 0.1;
            transition: opacity 0.3s;
        }
        
        .focus-mode .ql-toolbar:hover,
        .focus-mode .sidebar:hover,
        .focus-mode .editor-header:hover,
        .focus-mode .fab:hover { opacity: 1; }
        
        .focus-mode .ql-editor {
            max-width: 800px;
            margin: 0 auto;
            padding: 60px !important;
        }
        
        /* Typewriter Mode */
        .typewriter-mode .ql-editor {
            max-width: 700px;
            margin: 0 auto;
        }
        
        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-tertiary) 25%, var(--border) 50%, var(--bg-tertiary) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Document Item Active */
        .doc-item-active {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: white;
        }
        
        .doc-item-active .doc-title,
        .doc-item-active .doc-date { color: white; }
        
        /* Voice Recording Animation */
        .voice-recording {
            animation: pulse 1s infinite;
            background: var(--accent-danger) !important;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .ql-editor { padding: 20px !important; }
            .sidebar {
                position: fixed;
                left: -280px;
                transition: left 0.3s;
                z-index: 50;
            }
            .sidebar.open { left: 0; }
        }
    </style>
</head>
<body>
    
    <!-- Progress Bar -->
    <div class="progress-bar" id="progressBar"></div>
    
    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-50 lg:hidden bg-gradient-to-r from-purple-500 to-pink-500 text-white p-3 rounded-full shadow-lg">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Floating Action Button -->
    <div class="fab" onclick="createNewDocument()">
        <i class="fas fa-plus text-white text-2xl"></i>
    </div>
    
    <!-- Main Container -->
    <div class="max-w-[1600px] mx-auto px-4 py-6">
        
        <!-- Header -->
        <div class="text-center mb-8 animate-fadeInUp">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-2xl bg-gradient-to-br from-purple-500 to-pink-500 shadow-xl mb-4">
                <i class="fas fa-feather-alt text-white text-3xl"></i>
            </div>
            <h1 class="text-5xl font-bold bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent">
                ULTIMATE EDITOR PRO
            </h1>
            <p class="text-slate-500 dark:text-slate-400 mt-2">Complete writing suite with AI, Voice, Collaboration & More</p>
        </div>
        
        <div class="grid lg:grid-cols-4 gap-6">
            
            <!-- ============================================
                 SIDEBAR - LEFT PANEL
            ============================================ -->
            <div class="lg:col-span-1 animate-slideInLeft" id="sidebar">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden sticky top-6">
                    
                    <!-- Sidebar Header -->
                    <div class="p-5 border-b border-slate-200 dark:border-slate-700 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="font-semibold text-slate-800 dark:text-slate-200">
                                <i class="fas fa-folder-open text-purple-500 mr-2"></i>
                                My Library
                            </h2>
                            <span id="docCount" class="text-xs bg-white dark:bg-slate-700 px-2 py-1 rounded-full text-slate-600 dark:text-slate-300 shadow-sm">0</span>
                        </div>
                        
                        <!-- Search -->
                        <div class="relative mb-3">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="searchDocs" placeholder="Search documents..." 
                                   class="w-full pl-9 pr-3 py-2 text-sm border border-slate-200 dark:border-slate-600 rounded-xl focus:outline-none focus:border-purple-300 focus:ring-2 focus:ring-purple-200 dark:focus:ring-purple-800 transition-all bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                        </div>
                        
                        <!-- Category Filter -->
                        <select id="categoryFilter" class="w-full p-2 text-sm border border-slate-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700">
                            <option value="all">All Categories</option>
                            <option value="general">General</option>
                            <option value="blog">Blog</option>
                            <option value="business">Business</option>
                            <option value="professional">Professional</option>
                        </select>
                    </div>
                    
                    <!-- Document List -->
                    <div id="documentList" class="divide-y divide-slate-100 dark:divide-slate-700 max-h-[500px] overflow-y-auto">
                        <div class="p-6 space-y-3">
                            <div class="skeleton h-20 rounded-xl"></div>
                            <div class="skeleton h-20 rounded-xl"></div>
                            <div class="skeleton h-20 rounded-xl"></div>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="p-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                        <div class="grid grid-cols-2 gap-3 text-center text-sm">
                            <div>
                                <i class="fas fa-file-alt text-purple-500"></i>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Total Docs</p>
                                <p id="totalDocs" class="font-semibold">0</p>
                            </div>
                            <div>
                                <i class="fas fa-clock text-pink-500"></i>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Last Saved</p>
                                <p id="lastSaved" class="font-semibold text-xs">-</p>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            
            <!-- ============================================
                 MAIN EDITOR AREA
            ============================================ -->
            <div class="lg:col-span-3 animate-fadeInUp">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                    
                    <!-- Editor Toolbar -->
                    <div class="p-4 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <div class="flex flex-wrap gap-3 items-center justify-between">
                            <div class="flex-1 min-w-[200px]">
                                <div class="relative">
                                    <i class="fas fa-pen-fancy absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                                    <input type="text" id="docTitle" placeholder="Untitled Masterpiece" 
                                           class="w-full pl-9 pr-4 py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:outline-none focus:border-purple-300 focus:ring-2 focus:ring-purple-200 dark:focus:ring-purple-800 transition-all font-medium bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                                </div>
                            </div>
                            
                            <div class="flex gap-2 flex-wrap">
                                <!-- Mode Toggle -->
                                <div class="bg-slate-100 dark:bg-slate-700 rounded-xl p-1 flex gap-1">
                                    <button onclick="setMode('edit')" id="editModeBtn" class="mode-btn px-3 py-1.5 rounded-lg text-sm font-medium transition-all">
                                        <i class="fas fa-edit"></i> Write
                                    </button>
                                    <button onclick="setMode('preview')" id="previewModeBtn" class="mode-btn px-3 py-1.5 rounded-lg text-sm font-medium transition-all">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </div>
                                
                                <!-- Main Action Buttons -->
                                <button onclick="saveDocument()" class="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-save"></i> Save
                                </button>
                                
                                <button onclick="showAIAssistant()" class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-robot"></i> AI
                                </button>
                            </div>
                        </div>
                        
                        <!-- Secondary Toolbar - All Features -->
                        <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-slate-200 dark:border-slate-700">
                            <button onclick="toggleVoiceTyping()" id="voiceBtn" class="text-xs px-3 py-1.5 bg-green-100 dark:bg-green-900/30 rounded-lg hover:bg-green-200 transition-all">
                                <i class="fas fa-microphone"></i> Voice
                            </button>
                            <button onclick="exportPDF()" class="text-xs px-3 py-1.5 bg-red-100 dark:bg-red-900/30 rounded-lg hover:bg-red-200 transition-all">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button onclick="showTemplates()" class="text-xs px-3 py-1.5 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg hover:bg-yellow-200 transition-all">
                                <i class="fas fa-palette"></i> Templates
                            </button>
                            <button onclick="protectDocument()" class="text-xs px-3 py-1.5 bg-purple-100 dark:bg-purple-900/30 rounded-lg hover:bg-purple-200 transition-all">
                                <i class="fas fa-lock"></i> Protect
                            </button>
                            <button onclick="showSocialPreview()" class="text-xs px-3 py-1.5 bg-blue-100 dark:bg-blue-900/30 rounded-lg hover:bg-blue-200 transition-all">
                                <i class="fas fa-share-alt"></i> Share
                            </button>
                            <button onclick="generateQRCode()" class="text-xs px-3 py-1.5 bg-gray-100 dark:bg-gray-900/30 rounded-lg hover:bg-gray-200 transition-all">
                                <i class="fas fa-qrcode"></i> QR
                            </button>
                            <button onclick="backupAllDocuments()" class="text-xs px-3 py-1.5 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg hover:bg-indigo-200 transition-all">
                                <i class="fas fa-database"></i> Backup
                            </button>
                            <button onclick="startFocusTimer()" id="focusTimerBtn" class="text-xs px-3 py-1.5 bg-orange-100 dark:bg-orange-900/30 rounded-lg hover:bg-orange-200 transition-all">
                                <i class="fas fa-hourglass-half"></i> Focus
                            </button>
                            <button onclick="toggleDarkMode()" class="text-xs px-3 py-1.5 bg-slate-200 dark:bg-slate-700 rounded-lg hover:bg-slate-300 transition-all">
                                <i class="fas fa-moon"></i> Dark
                            </button>
                            <button onclick="toggleFocusMode()" class="text-xs px-3 py-1.5 bg-slate-200 dark:bg-slate-700 rounded-lg hover:bg-slate-300 transition-all">
                                <i class="fas fa-eye-slash"></i> Focus
                            </button>
                            <button onclick="showVersionHistory()" class="text-xs px-3 py-1.5 bg-cyan-100 dark:bg-cyan-900/30 rounded-lg hover:bg-cyan-200 transition-all">
                                <i class="fas fa-history"></i> Versions
                            </button>
                            <button onclick="showAnalytics()" class="text-xs px-3 py-1.5 bg-orange-100 dark:bg-orange-900/30 rounded-lg hover:bg-orange-200 transition-all">
                                <i class="fas fa-chart-line"></i> Stats
                            </button>
                            <button onclick="schedulePublish()" class="text-xs px-3 py-1.5 bg-teal-100 dark:bg-teal-900/30 rounded-lg hover:bg-teal-200 transition-all">
                                <i class="fas fa-calendar-alt"></i> Schedule
                            </button>
                            <button onclick="showComments()" class="text-xs px-3 py-1.5 bg-pink-100 dark:bg-pink-900/30 rounded-lg hover:bg-pink-200 transition-all">
                                <i class="fas fa-comments"></i> Comments
                            </button>
                        </div>
                        
                        <!-- Quick Stats Bar -->
                        <div class="flex flex-wrap gap-4 mt-3 text-xs text-slate-500 dark:text-slate-400">
                            <span><i class="far fa-clock"></i> <span id="readingTime">0</span> min read</span>
                            <span><i class="fas fa-spell-check"></i> <span id="wordCount">0</span> words</span>
                            <span><i class="fas fa-chart-simple"></i> <span id="charCount">0</span> chars</span>
                            <span><i class="fas fa-paragraph"></i> <span id="paraCount">0</span> paragraphs</span>
                            <span id="autoSaveStatus" class="text-emerald-500"><i class="fas fa-cloud-upload-alt"></i> Auto-save on</span>
                        </div>
                    </div>
                    
                    <!-- Editor Container -->
                    <div id="editMode" class="block">
                        <div id="editor-container" style="min-height: 600px;"></div>
                    </div>
                    
                    <!-- Preview Mode -->
                    <div id="previewMode" class="hidden">
                        <div class="preview-content" id="previewContent"></div>
                    </div>
                    
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- ============================================
         MODALS
    ============================================ -->
    
    <!-- AI Assistant Modal -->
    <div id="aiModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-robot text-purple-500"></i> AI Writing Assistant</h3>
                <button onclick="closeModal('aiModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="aiSuggestions" class="space-y-3">
                <div class="animate-pulse">🤖 AI is analyzing your content...</div>
            </div>
        </div>
    </div>
    
    <!-- Version History Modal -->
    <div id="versionModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-history text-blue-500"></i> Version History</h3>
                <button onclick="closeModal('versionModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="versionList" class="space-y-2 max-h-96 overflow-y-auto">Loading versions...</div>
        </div>
    </div>
    
    <!-- Analytics Modal -->
    <div id="analyticsModal" class="modal hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-chart-line text-orange-500"></i> Content Analytics</h3>
                <button onclick="closeModal('analyticsModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <canvas id="wordChart" width="400" height="200"></canvas>
            <div class="mt-4 grid grid-cols-2 gap-3 text-center">
                <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                    <p class="text-2xl font-bold text-purple-500" id="analyticsWords">0</p>
                    <p class="text-xs">Total Words</p>
                </div>
                <div class="p-3 bg-pink-50 dark:bg-pink-900/20 rounded-lg">
                    <p class="text-2xl font-bold text-pink-500" id="analyticsChars">0</p>
                    <p class="text-xs">Characters</p>
                </div>
                <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <p class="text-2xl font-bold text-blue-500" id="analyticsSentences">0</p>
                    <p class="text-xs">Sentences</p>
                </div>
                <div class="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                    <p class="text-2xl font-bold text-green-500" id="analyticsReadTime">0</p>
                    <p class="text-xs">Minutes Read</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Templates Modal -->
    <div id="templatesModal" class="modal hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-palette text-yellow-500"></i> Choose Template</h3>
                <button onclick="closeModal('templatesModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="templatesList" class="space-y-3"></div>
        </div>
    </div>
    
    <!-- Comments Modal -->
    <div id="commentsModal" class="modal hidden">
        <div class="modal-content" style="max-width: 500px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-comments text-pink-500"></i> Comments & Feedback</h3>
                <button onclick="closeModal('commentsModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="commentsList" class="max-h-96 overflow-y-auto mb-4 space-y-3"></div>
            <div class="flex gap-2">
                <input type="text" id="commentInput" placeholder="Write a comment..." class="flex-1 p-2 border rounded-lg dark:bg-slate-700">
                <input type="text" id="userNameInput" placeholder="Your name" class="w-28 p-2 border rounded-lg dark:bg-slate-700">
                <button onclick="addComment()" class="bg-purple-500 text-white px-4 py-2 rounded-lg">Post</button>
            </div>
        </div>
    </div>
    
    <!-- Schedule Modal -->
    <div id="scheduleModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-calendar-alt text-teal-500"></i> Schedule Publishing</h3>
                <button onclick="closeModal('scheduleModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <input type="datetime-local" id="scheduleDate" class="w-full p-2 border rounded-lg mb-4 dark:bg-slate-700">
            <button onclick="confirmSchedule()" class="w-full bg-teal-500 text-white py-2 rounded-lg">Schedule</button>
        </div>
    </div>
    
    <!-- Password Modal -->
    <div id="passwordModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold"><i class="fas fa-lock text-purple-500"></i> Password Protection</h3>
                <button onclick="closeModal('passwordModal')" class="text-slate-500 hover:text-slate-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <input type="password" id="passwordInput" placeholder="Enter password" class="w-full p-2 border rounded-lg mb-4 dark:bg-slate-700">
            <button onclick="confirmPassword()" class="w-full bg-purple-500 text-white py-2 rounded-lg">Set Password</button>
            <button onclick="removePassword()" class="w-full mt-2 bg-red-500 text-white py-2 rounded-lg">Remove Password</button>
        </div>
    </div>
    
    <!-- ============================================
         JAVASCRIPT - ALL FUNCTIONALITY
    ============================================ -->
    <script>
        // ============================================
        // GLOBAL VARIABLES
        // ============================================
        let quill = null;
        let currentDocId = null;
        let currentMode = 'edit';
        let isDirty = false;
        let autoSaveTimer;
        let isFocusMode = false;
        let isTypewriterMode = false;
        let wordChart = null;
        let recognition = null;
        let isListening = false;
        let focusTimer = null;
        let focusTime = 0;
        
        // ============================================
        // INITIALIZATION
        // ============================================
        function initEditor() {
            if (quill !== null) return;
            
            try {
                quill = new Quill('#editor-container', {
                    theme: 'snow',
                    placeholder: 'Start writing your masterpiece here... ✨\n\n✨ Features Available:\n• Bold, Italic, Lists, Headings\n• Drag & drop images\n• Voice typing\n• AI assistance\n• And much more!',
                    modules: {
                        toolbar: {
                            container: [
                                [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                                ['bold', 'italic', 'underline', 'strike'],
                                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                                [{ 'indent': '-1'}, { 'indent': '+1' }],
                                [{ 'align': [] }],
                                ['blockquote', 'code-block'],
                                ['link', 'image', 'video'],
                                ['clean']
                            ],
                            handlers: {
                                'image': imageHandler,
                                'video': videoHandler
                            }
                        },
                        clipboard: { matchVisual: false }
                    }
                });
                
                quill.on('text-change', function(delta, oldDelta, source) {
                    if (source === 'user') {
                        updateStats();
                        autoSave();
                        isDirty = true;
                    }
                });
                
                const editor = document.querySelector('.ql-editor');
                if (editor) editor.addEventListener('scroll', updateProgress);
                
                updateStats();
                showToast('Welcome to Ultimate Editor Pro! 🚀', 'success');
                
                // Load sample content for testing
                setTimeout(() => {
                    if (quill.getText().trim().length === 0) {
                        loadSampleContent();
                    }
                }, 500);
                
            } catch (error) {
                console.error('Editor init error:', error);
                showToast('Error initializing editor', 'error');
            }
        }
        
        // Load sample content
        function loadSampleContent() {
            const sampleDelta = {
                "ops": [
                    { "insert": "Welcome to Ultimate Editor Pro!\n", "attributes": { "header": 1 } },
                    { "insert": "\nThis is a " },
                    { "insert": "complete", "attributes": { "bold": true } },
                    { "insert": " writing suite with everything you need:\n\n" },
                    { "insert": "✨ Features Available:\n", "attributes": { "header": 2 } },
                    { "insert": "\n" },
                    { "insert": "Rich Text Formatting (Bold, Italic, Lists)\n", "attributes": { "list": "bullet" } },
                    { "insert": "Image & Video Upload\n", "attributes": { "list": "bullet" } },
                    { "insert": "Voice Typing\n", "attributes": { "list": "bullet" } },
                    { "insert": "AI Writing Assistant\n", "attributes": { "list": "bullet" } },
                    { "insert": "PDF Export\n", "attributes": { "list": "bullet" } },
                    { "insert": "Version History\n", "attributes": { "list": "bullet" } },
                    { "insert": "Password Protection\n", "attributes": { "list": "bullet" } },
                    { "insert": "And much more!\n\n", "attributes": { "list": "bullet" } },
                    { "insert": "Start writing your masterpiece today! 🚀", "attributes": { "bold": true, "italic": true } }
                ]
            };
            quill.setContents(sampleDelta);
        }
        
        // ============================================
        // IMAGE & VIDEO HANDLERS
        // ============================================
        function imageHandler() {
            const input = document.createElement('input');
            input.setAttribute('type', 'file');
            input.setAttribute('accept', 'image/*');
            input.click();
            
            input.onchange = async () => {
                const file = input.files[0];
                if (!file) return;
                
                const formData = new FormData();
                formData.append('image', file);
                
                showToast('Uploading image...', 'info');
                
                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const data = await response.json();
                    
                    if (data.success) {
                        const range = quill.getSelection();
                        const index = range ? range.index : quill.getLength();
                        quill.insertEmbed(index, 'image', data.url);
                        showToast('Image uploaded!', 'success');
                    } else {
                        showToast('Upload failed', 'error');
                    }
                } catch (error) {
                    showToast('Upload error', 'error');
                }
            };
        }
        
        function videoHandler() {
            const url = prompt('Enter video URL (YouTube, Vimeo, or direct link):\n\nYouTube: https://www.youtube.com/embed/VIDEO_ID');
            if (url) {
                const range = quill.getSelection();
                const index = range ? range.index : quill.getLength();
                quill.insertEmbed(index, 'video', url);
                showToast('Video inserted!', 'success');
            }
        }
        
        // ============================================
        // VOICE TYPING
        // ============================================
        function initSpeechRecognition() {
            if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                const SpeechRecognition = window.webkitSpeechRecognition || window.SpeechRecognition;
                recognition = new SpeechRecognition();
                recognition.continuous = true;
                recognition.interimResults = true;
                recognition.lang = 'en-US';
                
                recognition.onresult = (event) => {
                    let transcript = '';
                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        transcript += event.results[i][0].transcript;
                    }
                    
                    const range = quill.getSelection();
                    const index = range ? range.index : quill.getLength();
                    quill.insertText(index, transcript + ' ');
                };
                
                recognition.onerror = (event) => {
                    showToast('Voice error: ' + event.error, 'error');
                    isListening = false;
                    document.getElementById('voiceBtn')?.classList.remove('voice-recording');
                };
                
                recognition.onend = () => {
                    isListening = false;
                    document.getElementById('voiceBtn')?.classList.remove('voice-recording');
                    showToast('Voice typing stopped', 'info');
                };
            } else {
                showToast('Speech recognition not supported', 'error');
            }
        }
        
        function toggleVoiceTyping() {
            if (!recognition) initSpeechRecognition();
            
            if (isListening) {
                recognition.stop();
                isListening = false;
                document.getElementById('voiceBtn')?.classList.remove('voice-recording');
            } else {
                recognition.start();
                isListening = true;
                document.getElementById('voiceBtn')?.classList.add('voice-recording');
                showToast('Listening... Speak now', 'success');
            }
        }
        
        // ============================================
        // STATISTICS
        // ============================================
        function updateStats() {
            if (!quill) return;
            
            const text = quill.getText();
            const words = text.trim().split(/\s+/).filter(w => w.length > 0).length;
            const chars = text.length;
            const paragraphs = text.split('\n\n').filter(p => p.trim().length > 0).length;
            const sentences = text.split(/[.!?]+/).filter(s => s.trim().length > 0).length;
            const readingTime = Math.max(1, Math.ceil(words / 200));
            
            document.getElementById('wordCount').innerText = words;
            document.getElementById('charCount').innerText = chars;
            document.getElementById('paraCount').innerText = paragraphs;
            document.getElementById('readingTime').innerText = readingTime;
            
            if (document.getElementById('analyticsModal') && !document.getElementById('analyticsModal').classList.contains('hidden')) {
                document.getElementById('analyticsWords').innerText = words;
                document.getElementById('analyticsChars').innerText = chars;
                document.getElementById('analyticsSentences').innerText = sentences;
                document.getElementById('analyticsReadTime').innerText = readingTime;
                if (wordChart) {
                    wordChart.data.datasets[0].data = [words, chars, sentences];
                    wordChart.update();
                }
            }
        }
        
        // ============================================
        // AUTO SAVE
        // ============================================
        function autoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                if (isDirty && quill) {
                    saveDocument();
                    document.getElementById('autoSaveStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Saving...';
                    setTimeout(() => {
                        document.getElementById('autoSaveStatus').innerHTML = '<i class="fas fa-check-circle"></i> Saved';
                        setTimeout(() => {
                            document.getElementById('autoSaveStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Auto-save on';
                        }, 2000);
                    }, 1000);
                }
            }, 3000);
        }
        
        // ============================================
        // SAVE DOCUMENT
        // ============================================
        function saveDocument() {
            if (!quill) return;
            
            const title = document.getElementById('docTitle').value.trim() || 'Untitled';
            const content = JSON.stringify(quill.getContents());
            const category = document.getElementById('categoryFilter')?.value || 'general';
            
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('title', title);
            formData.append('content', content);
            formData.append('category', category);
            if (currentDocId) formData.append('id', currentDocId);
            
            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentDocId = data.id;
                    isDirty = false;
                    document.getElementById('lastSaved').innerHTML = new Date().toLocaleTimeString();
                    showToast('Document saved!', 'success');
                    loadDocuments();
                } else {
                    showToast('Save failed: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                showToast('Save error', 'error');
            });
        }
        
        // ============================================
        // LOAD DOCUMENTS LIST
        // ============================================
        async function loadDocuments(searchTerm = '', category = 'all') {
            const formData = new FormData();
            formData.append('action', 'list');
            if (searchTerm) formData.append('search', searchTerm);
            if (category !== 'all') formData.append('category', category);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            const listDiv = document.getElementById('documentList');
            const docCount = document.getElementById('docCount');
            const totalDocs = document.getElementById('totalDocs');
            
            if (data.success && data.documents.length > 0) {
                docCount.innerText = data.documents.length;
                totalDocs.innerText = data.documents.length;
                
                listDiv.innerHTML = data.documents.map(doc => `
                    <div class="doc-item p-4 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer transition-all group" 
                         onclick="loadDocument(${doc.id})" data-id="${doc.id}">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="font-medium mb-1 flex items-center gap-2 doc-title">
                                    <i class="fas fa-file-alt text-purple-400 text-sm"></i>
                                    ${escapeHtml(doc.title.length > 50 ? doc.title.substring(0, 50) + '...' : doc.title)}
                                </div>
                                <div class="text-xs text-slate-400 flex items-center gap-3 doc-date">
                                    <span><i class="far fa-calendar-alt mr-1"></i>${new Date(doc.updated_at).toLocaleDateString()}</span>
                                    <span><i class="fas fa-chart-simple mr-1"></i>${doc.view_count || 0} views</span>
                                    <span><i class="fas fa-code-branch mr-1"></i>v${doc.version}</span>
                                </div>
                            </div>
                            <button onclick="deleteDocument(${doc.id}, event)" 
                                    class="opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-600 transition-all p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20">
                                <i class="fas fa-trash-alt text-sm"></i>
                            </button>
                        </div>
                    </div>
                `).join('');
            } else {
                docCount.innerText = '0';
                totalDocs.innerText = '0';
                listDiv.innerHTML = `
                    <div class="p-8 text-center">
                        <i class="fas fa-book-open text-5xl text-slate-300 mb-3"></i>
                        <p class="text-slate-500 text-sm">Your library is empty</p>
                        <button onclick="createNewDocument()" class="mt-4 text-purple-500 text-sm hover:text-purple-600 font-medium">
                            Create your first document →
                        </button>
                    </div>
                `;
            }
        }
        
        // ============================================
        // LOAD SINGLE DOCUMENT
        // ============================================
        async function loadDocument(id, password = null) {
            if (!quill) return;
            
            const formData = new FormData();
            formData.append('action', 'load');
            formData.append('id', id);
            if (password) formData.append('password', password);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                const doc = data.document;
                currentDocId = doc.id;
                document.getElementById('docTitle').value = doc.title;
                quill.setContents(JSON.parse(doc.content));
                showToast('Document loaded', 'success');
                updateStats();
                isDirty = false;
                
                document.querySelectorAll('.doc-item').forEach(el => el.classList.remove('doc-item-active'));
                const activeDoc = document.querySelector(`.doc-item[data-id="${id}"]`);
                if (activeDoc) activeDoc.classList.add('doc-item-active');
                
                closeModal('passwordModal');
            } else if (data.error === 'Wrong password') {
                const pwd = prompt('This document is password protected. Enter password:');
                if (pwd) loadDocument(id, pwd);
            } else {
                showToast('Error loading document', 'error');
            }
        }
        
        // ============================================
        // DELETE DOCUMENT
        // ============================================
        async function deleteDocument(id, event) {
            event.stopPropagation();
            
            if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    if (currentDocId === id) createNewDocument();
                    showToast('Document deleted', 'success');
                    loadDocuments();
                }
            }
        }
        
        // ============================================
        // CREATE NEW DOCUMENT
        // ============================================
        function createNewDocument() {
            currentDocId = null;
            document.getElementById('docTitle').value = 'Untitled Masterpiece';
            if (quill) quill.setContents([]);
            showToast('New document created', 'info');
            updateStats();
            isDirty = false;
            
            document.querySelectorAll('.doc-item').forEach(el => el.classList.remove('doc-item-active'));
            if (currentMode === 'preview') setMode('edit');
            
            // Load sample content for new document
            loadSampleContent();
        }
        
        // ============================================
        // MODE SWITCH
        // ============================================
        function setMode(mode) {
            currentMode = mode;
            
            const editModeDiv = document.getElementById('editMode');
            const previewModeDiv = document.getElementById('previewMode');
            const editBtn = document.getElementById('editModeBtn');
            const previewBtn = document.getElementById('previewModeBtn');
            
            if (mode === 'edit') {
                editModeDiv.classList.remove('hidden');
                editModeDiv.classList.add('block');
                previewModeDiv.classList.add('hidden');
                editBtn.classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm');
                previewBtn.classList.remove('bg-white', 'dark:bg-slate-600', 'shadow-sm');
            } else {
                editModeDiv.classList.add('hidden');
                previewModeDiv.classList.remove('hidden');
                previewModeDiv.classList.add('block');
                previewBtn.classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm');
                editBtn.classList.remove('bg-white', 'dark:bg-slate-600', 'shadow-sm');
                updatePreview();
            }
        }
        
        // ============================================
        // UPDATE PREVIEW
        // ============================================
        function updatePreview() {
            if (!quill) return;
            
            const previewDiv = document.getElementById('previewContent');
            if (!previewDiv) return;
            
            try {
                const delta = quill.getContents();
                const tempDiv = document.createElement('div');
                tempDiv.style.position = 'absolute';
                tempDiv.style.left = '-9999px';
                document.body.appendChild(tempDiv);
                
                const tempQuill = new Quill(tempDiv, { theme: 'snow', modules: { toolbar: false } });
                tempQuill.setContents(delta);
                previewDiv.innerHTML = tempQuill.root.innerHTML;
                
                document.body.removeChild(tempDiv);
            } catch (error) {
                console.error('Preview error:', error);
            }
        }
        
        // ============================================
        // AI ASSISTANT
        // ============================================
        async function showAIAssistant() {
            const modal = document.getElementById('aiModal');
            modal.classList.remove('hidden');
            
            const text = quill.getText();
            const suggestionsDiv = document.getElementById('aiSuggestions');
            suggestionsDiv.innerHTML = '<div class="animate-pulse">🤖 AI is analyzing your content...</div>';
            
            const formData = new FormData();
            formData.append('action', 'ai_suggest');
            formData.append('text', text);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                suggestionsDiv.innerHTML = `
                    <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                        <i class="fas fa-lightbulb text-yellow-500"></i>
                        <strong>AI Suggestions:</strong>
                        <ul class="mt-2 space-y-2 text-sm">
                            ${data.suggestions.map(s => `<li>💡 ${s}</li>`).join('')}
                        </ul>
                    </div>
                    <div class="p-3 bg-pink-50 dark:bg-pink-900/20 rounded-lg mt-3">
                        <i class="fas fa-magic text-pink-500"></i>
                        <strong>Quick Actions:</strong>
                        <div class="mt-2 space-y-2">
                            <button onclick="improveClarity()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">✨ Improve clarity</button>
                            <button onclick="makeConcise()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">📝 Make more concise</button>
                            <button onclick="checkSpelling()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">🔍 Check spelling</button>
                        </div>
                    </div>
                `;
            }
        }
        
        function improveClarity() { showToast('AI is improving clarity...', 'info'); closeModal('aiModal'); }
        function makeConcise() { showToast('Making content more concise...', 'info'); closeModal('aiModal'); }
        
        async function checkSpelling() {
            const text = quill.getText();
            const formData = new FormData();
            formData.append('action', 'spell_check');
            formData.append('text', text);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success && data.errors.length > 0) {
                let msg = 'Spelling suggestions:\n';
                data.errors.forEach(e => msg += `${e.word} → ${e.suggestion}\n`);
                alert(msg);
            } else {
                showToast('No spelling errors found!', 'success');
            }
            closeModal('aiModal');
        }
        
        // ============================================
        // VERSION HISTORY
        // ============================================
        async function showVersionHistory() {
            if (!currentDocId) {
                showToast('Save the document first', 'warning');
                return;
            }
            
            const modal = document.getElementById('versionModal');
            modal.classList.remove('hidden');
            
            const formData = new FormData();
            formData.append('action', 'versions');
            formData.append('id', currentDocId);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            const versionList = document.getElementById('versionList');
            if (data.success && data.versions.length > 0) {
                versionList.innerHTML = data.versions.map(v => `
                    <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer"
                         onclick="loadDocumentVersion(${currentDocId}, ${v.version})">
                        <div class="flex justify-between items-center">
                            <span class="font-semibold">Version ${v.version}</span>
                            <span class="text-xs text-slate-500">${new Date(v.created_at).toLocaleString()}</span>
                        </div>
                    </div>
                `).join('');
            } else {
                versionList.innerHTML = '<p class="text-center text-slate-500">No version history yet.</p>';
            }
        }
        
        async function loadDocumentVersion(id, version) {
            const formData = new FormData();
            formData.append('action', 'load');
            formData.append('id', id);
            formData.append('version', version);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                quill.setContents(JSON.parse(data.document.content));
                showToast(`Loaded version ${version}`, 'success');
                closeModal('versionModal');
            }
        }
        
        // ============================================
        // ANALYTICS
        // ============================================
        async function showAnalytics() {
            const modal = document.getElementById('analyticsModal');
            modal.classList.remove('hidden');
            
            const text = quill.getText();
            const words = text.trim().split(/\s+/).filter(w => w.length > 0).length;
            const chars = text.length;
            const sentences = text.split(/[.!?]+/).filter(s => s.trim().length > 0).length;
            const readingTime = Math.max(1, Math.ceil(words / 200));
            
            document.getElementById('analyticsWords').innerText = words;
            document.getElementById('analyticsChars').innerText = chars;
            document.getElementById('analyticsSentences').innerText = sentences;
            document.getElementById('analyticsReadTime').innerText = readingTime;
            
            const ctx = document.getElementById('wordChart').getContext('2d');
            if (wordChart) wordChart.destroy();
            
            wordChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Words', 'Characters', 'Sentences'],
                    datasets: [{
                        label: 'Content Statistics',
                        data: [words, chars, sentences],
                        backgroundColor: ['#8b5cf6', '#ec4899', '#06b6d4'],
                        borderRadius: 8
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'top' } } }
            });
            
            // Fetch server stats if document exists
            if (currentDocId) {
                const formData = new FormData();
                formData.append('action', 'get_stats');
                formData.append('id', currentDocId);
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    document.getElementById('analyticsViews')?.remove();
                    // Add views display
                }
            }
        }
        
        // ============================================
        // TEMPLATES
        // ============================================
        async function showTemplates() {
            const modal = document.getElementById('templatesModal');
            modal.classList.remove('hidden');
            
            const formData = new FormData();
            formData.append('action', 'get_templates');
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            const templatesList = document.getElementById('templatesList');
            if (data.success && data.templates.length > 0) {
                templatesList.innerHTML = data.templates.map(t => `
                    <div onclick="applyTemplate(${t.id})" class="p-4 border rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/20 cursor-pointer transition-all">
                        <div class="font-semibold">${escapeHtml(t.name)}</div>
                        <div class="text-xs text-slate-500">${t.category}</div>
                    </div>
                `).join('');
            } else {
                templatesList.innerHTML = '<p class="text-center text-slate-500">No templates available</p>';
            }
        }
        
        async function applyTemplate(templateId) {
            const formData = new FormData();
            formData.append('action', 'apply_template');
            formData.append('template_id', templateId);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('docTitle').value = data.template.name;
                quill.setContents(JSON.parse(data.template.content));
                showToast('Template applied!', 'success');
                closeModal('templatesModal');
            }
        }
        
        // ============================================
        // COMMENTS
        // ============================================
        async function showComments() {
            if (!currentDocId) {
                showToast('Save document first', 'warning');
                return;
            }
            
            const modal = document.getElementById('commentsModal');
            modal.classList.remove('hidden');
            
            const formData = new FormData();
            formData.append('action', 'get_comments');
            formData.append('id', currentDocId);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            const commentsList = document.getElementById('commentsList');
            if (data.success && data.comments.length > 0) {
                commentsList.innerHTML = data.comments.map(c => `
                    <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
                        <div class="font-semibold text-sm">${escapeHtml(c.user_name)}</div>
                        <div class="text-sm mt-1">${escapeHtml(c.comment)}</div>
                        <div class="text-xs text-slate-400 mt-1">${new Date(c.created_at).toLocaleString()}</div>
                    </div>
                `).join('');
            } else {
                commentsList.innerHTML = '<p class="text-center text-slate-500">No comments yet</p>';
            }
        }
        
        async function addComment() {
            const comment = document.getElementById('commentInput').value;
            const userName = document.getElementById('userNameInput').value || 'Anonymous';
            
            if (!comment) return;
            
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('id', currentDocId);
            formData.append('comment', comment);
            formData.append('user_name', userName);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('commentInput').value = '';
                showToast('Comment added', 'success');
                showComments();
            }
        }
        
        // ============================================
        // PDF EXPORT
        // ============================================
        function exportPDF() {
            const element = document.getElementById('previewContent');
            if (!element.innerHTML) {
                updatePreview();
                setTimeout(() => exportPDF(), 500);
                return;
            }
            
            const opt = {
                margin: [0.5, 0.5, 0.5, 0.5],
                filename: document.getElementById('docTitle').value + '.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, letterRendering: true },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
            };
            
            showToast('Generating PDF...', 'info');
            html2pdf().set(opt).from(element).save();
            setTimeout(() => showToast('PDF ready!', 'success'), 2000);
        }
        
        // ============================================
        // PASSWORD PROTECTION
        // ============================================
        function protectDocument() {
            if (!currentDocId) {
                showToast('Save document first', 'warning');
                return;
            }
            document.getElementById('passwordModal').classList.remove('hidden');
        }
        
        async function confirmPassword() {
            const password = document.getElementById('passwordInput').value;
            if (!password) return;
            
            const formData = new FormData();
            formData.append('action', 'set_password');
            formData.append('id', currentDocId);
            formData.append('password', password);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast('Document protected with password', 'success');
                closeModal('passwordModal');
                document.getElementById('passwordInput').value = '';
            }
        }
        
        async function removePassword() {
            const formData = new FormData();
            formData.append('action', 'set_password');
            formData.append('id', currentDocId);
            formData.append('password', '');
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast('Password removed', 'success');
                closeModal('passwordModal');
            }
        }
        
        // ============================================
        // SCHEDULE PUBLISHING
        // ============================================
        function schedulePublish() {
            if (!currentDocId) {
                showToast('Save document first', 'warning');
                return;
            }
            document.getElementById('scheduleModal').classList.remove('hidden');
        }
        
        async function confirmSchedule() {
            const date = document.getElementById('scheduleDate').value;
            if (!date) return;
            
            const formData = new FormData();
            formData.append('action', 'schedule');
            formData.append('id', currentDocId);
            formData.append('scheduled_date', date);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast(`Scheduled for ${new Date(date).toLocaleString()}`, 'success');
                closeModal('scheduleModal');
            }
        }
        
        // ============================================
        // SOCIAL PREVIEW
        // ============================================
        function showSocialPreview() {
            const title = document.getElementById('docTitle').value;
            const description = quill.getText().slice(0, 150) + '...';
            const url = window.location.href;
            
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 500px;">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold"><i class="fas fa-share-alt"></i> Social Media Preview</h3>
                        <button onclick="this.closest('.modal').remove()" class="text-slate-500">&times;</button>
                    </div>
                    <div class="border rounded-lg p-4 mb-4">
                        <div class="text-xs text-slate-500 mb-2">FACEBOOK / LINKEDIN</div>
                        <div class="font-bold mb-1">${escapeHtml(title)}</div>
                        <div class="text-sm text-slate-600">${escapeHtml(description)}</div>
                        <div class="text-xs text-slate-400 mt-2">${url}</div>
                    </div>
                    <button onclick="copySocialText()" class="w-full bg-purple-500 text-white py-2 rounded-lg">Copy Preview Text</button>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        function copySocialText() {
            const text = `${document.getElementById('docTitle').value}\n\n${quill.getText().slice(0, 200)}...\n\n${window.location.href}`;
            navigator.clipboard.writeText(text);
            showToast('Copied to clipboard!', 'success');
            document.querySelector('.modal')?.remove();
        }
        
        // ============================================
        // QR CODE GENERATOR
        // ============================================
        function generateQRCode() {
            const url = window.location.href;
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(url)}`;
            
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content text-center">
                    <h3 class="text-xl font-bold mb-4">QR Code for this Document</h3>
                    <img src="${qrUrl}" class="mx-auto mb-4 rounded-lg shadow-lg">
                    <p class="text-sm text-slate-500 mb-4">Scan to share this document</p>
                    <button onclick="this.closest('.modal').remove()" class="bg-purple-500 text-white px-4 py-2 rounded-lg">Close</button>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        // ============================================
        // BACKUP & RESTORE
        // ============================================
        async function backupAllDocuments() {
            showToast('Creating backup...', 'info');
            
            const formData = new FormData();
            formData.append('action', 'backup');
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                const blob = new Blob([JSON.stringify(data.backup, null, 2)], { type: 'application/json' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `backup_${new Date().toISOString()}.json`;
                link.click();
                showToast('Backup created!', 'success');
            }
        }
        
        // ============================================
        // FOCUS TIMER
        // ============================================
        function startFocusTimer() {
            if (focusTimer) {
                clearInterval(focusTimer);
                focusTimer = null;
                const minutes = Math.floor(focusTime / 60);
                showToast(`Focus session ended! Time: ${minutes} minute${minutes !== 1 ? 's' : ''}`, 'info');
                focusTime = 0;
                document.getElementById('focusTimerBtn')?.classList.remove('voice-recording');
            } else {
                focusTimer = setInterval(() => {
                    focusTime++;
                    if (focusTime % 300 === 0) { // Every 5 minutes
                        showToast(`🎯 Focused for ${focusTime / 60} minutes! Keep going!`, 'success');
                    }
                }, 1000);
                showToast('Focus timer started! Write without distractions.', 'success');
                document.getElementById('focusTimerBtn')?.classList.add('voice-recording');
            }
        }
        
        // ============================================
        // UI TOGGLES
        // ============================================
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
            showToast(document.body.classList.contains('dark-mode') ? 'Dark mode on' : 'Light mode on', 'success');
        }
        
        function toggleFocusMode() {
            isFocusMode = !isFocusMode;
            document.body.classList.toggle('focus-mode');
            showToast(isFocusMode ? 'Focus mode on - distraction free' : 'Focus mode off', 'info');
        }
        
        // ============================================
        // PROGRESS BAR
        // ============================================
        function updateProgress() {
            const editor = document.querySelector('.ql-editor');
            if (!editor) return;
            
            const scrollTop = editor.scrollTop;
            const scrollHeight = editor.scrollHeight - editor.clientHeight;
            const progress = scrollHeight ? (scrollTop / scrollHeight) * 100 : 0;
            document.getElementById('progressBar').style.width = progress + '%';
        }
        
        // ============================================
        // MODAL & TOAST
        // ============================================
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }
        
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast';
            
            const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
            const colors = { success: 'border-emerald-500', error: 'border-red-500', warning: 'border-amber-500', info: 'border-purple-500' };
            
            toast.className = `toast ${colors[type]}`;
            toast.innerHTML = `<i class="fas ${icons[type]} mr-2"></i> ${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.remove(), 3000);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ============================================
        // EVENT LISTENERS & INITIALIZATION
        // ============================================
        document.getElementById('mobileMenuToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        document.getElementById('searchDocs')?.addEventListener('input', (e) => {
            const category = document.getElementById('categoryFilter')?.value || 'all';
            loadDocuments(e.target.value, category);
        });
        
        document.getElementById('categoryFilter')?.addEventListener('change', (e) => {
            const search = document.getElementById('searchDocs')?.value || '';
            loadDocuments(search, e.target.value);
        });
        
        window.addEventListener('beforeunload', (e) => {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes!';
            }
        });
        
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            initEditor();
            loadDocuments();
            document.getElementById('editModeBtn').classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm');
        });
        
        // Global keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 's': e.preventDefault(); saveDocument(); break;
                    case 'b': e.preventDefault(); document.execCommand('bold'); break;
                    case 'i': e.preventDefault(); document.execCommand('italic'); break;
                    case 'p': e.preventDefault(); setMode(currentMode === 'edit' ? 'preview' : 'edit'); break;
                    case 'd': e.preventDefault(); toggleDarkMode(); break;
                    case 'f': e.preventDefault(); toggleFocusMode(); break;
                    case 'v': e.preventDefault(); toggleVoiceTyping(); break;
                }
            }
        });
    </script>
</body>
</html>