<?php
// Database configuration
$host = 'localhost';
$dbname = 'testdb';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content JSON NOT NULL,
        version INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        content JSON NOT NULL,
        version INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        user_name VARCHAR(100),
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
    )");
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Create uploads directory
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // Save document with versioning
    if ($action === 'save') {
        $title = $_POST['title'] ?? 'Untitled';
        $content = $_POST['content'] ?? '{"ops":[]}';
        $id = $_POST['id'] ?? null;
        
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
                $stmt = $pdo->prepare("UPDATE documents SET title = ?, content = ?, version = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $content, $newVersion, $id]);
                echo json_encode(['success' => true, 'id' => $id, 'version' => $newVersion]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO documents (title, content) VALUES (?, ?)");
                $stmt->execute([$title, $content]);
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
    
    // Load document with versions
    if ($action === 'load') {
        $id = $_POST['id'] ?? 0;
        $version = $_POST['version'] ?? null;
        
        if ($version) {
            $stmt = $pdo->prepare("SELECT * FROM document_versions WHERE document_id = ? AND version = ?");
            $stmt->execute([$id, $version]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$id]);
        }
        
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            echo json_encode(['success' => true, 'document' => $doc]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Document not found']);
        }
        exit;
    }
    
    // Get document versions
    if ($action === 'versions') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT version, created_at FROM document_versions WHERE document_id = ? ORDER BY version DESC");
        $stmt->execute([$id]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'versions' => $versions]);
        exit;
    }
    
    // List documents
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, title, version, created_at, updated_at FROM documents ORDER BY updated_at DESC");
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'documents' => $docs]);
        exit;
    }
    
    // Delete document
    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Add comment
    if ($action === 'add_comment') {
        $id = $_POST['id'] ?? 0;
        $comment = $_POST['comment'] ?? '';
        $userName = $_POST['user_name'] ?? 'Anonymous';
        
        $stmt = $pdo->prepare("INSERT INTO comments (document_id, user_name, comment) VALUES (?, ?, ?)");
        $stmt->execute([$id, $userName, $comment]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get comments
    if ($action === 'get_comments') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM comments WHERE document_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    }
    
    // Image upload
    if (isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($file['name']));
        $targetPath = 'uploads/' . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => true, 'url' => $targetPath]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
        }
        exit;
    }
    
    // AI Suggestion (Mock - Replace with actual AI API)
    if ($action === 'ai_suggest') {
        $text = $_POST['text'] ?? '';
        // This is a mock AI response - replace with OpenAI API
        $suggestions = [
            'Consider adding more specific examples to support this point.',
            'This section could benefit from a short bullet list.',
            'Adding a relevant statistic would strengthen your argument.',
            'A transition sentence here would improve flow.',
            'Consider breaking this long paragraph into smaller chunks.'
        ];
        echo json_encode(['success' => true, 'suggestions' => $suggestions]);
        exit;
    }
    
    // Spell check (Mock)
    if ($action === 'spell_check') {
        $text = $_POST['text'] ?? '';
        // Mock spell check - replace with actual API
        echo json_encode(['success' => true, 'errors' => []]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Ultimate Editor Pro | Next-Gen Writing Experience</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Quill CSS & JS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,100..900&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    
    <!-- Chart.js for Analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* CSS Variables for Theming */
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
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--accent-primary);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent-secondary);
        }
        
        /* Quill Customization */
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
        
        .ql-editor.ql-blank::before {
            color: var(--text-tertiary);
            font-style: normal;
        }
        
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
        
        .fab:hover {
            transform: scale(1.1) rotate(90deg);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.4s ease-out;
        }
        
        .animate-slideInLeft {
            animation: slideInLeft 0.3s ease-out;
        }
        
        .animate-slideInRight {
            animation: slideInRight 0.3s ease-out;
        }
        
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
        .focus-mode .fab:hover {
            opacity: 1;
        }
        
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
        .doc-item-active .doc-date {
            color: white;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .ql-editor {
                padding: 20px !important;
            }
            
            .sidebar {
                position: fixed;
                left: -280px;
                transition: left 0.3s;
                z-index: 50;
            }
            
            .sidebar.open {
                left: 0;
            }
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
                Ultimate Editor Pro
            </h1>
            <p class="text-slate-500 dark:text-slate-400 mt-2">Professional writing experience with AI-powered features</p>
        </div>
        
        <div class="grid lg:grid-cols-4 gap-6">
            
            <!-- Sidebar - Left Panel -->
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
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="searchDocs" placeholder="Search documents..." 
                                   class="w-full pl-9 pr-3 py-2 text-sm border border-slate-200 dark:border-slate-600 rounded-xl focus:outline-none focus:border-purple-300 focus:ring-2 focus:ring-purple-200 dark:focus:ring-purple-800 transition-all bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                        </div>
                    </div>
                    
                    <!-- Document List -->
                    <div id="documentList" class="divide-y divide-slate-100 dark:divide-slate-700 max-h-[600px] overflow-y-auto">
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
            
            <!-- Main Editor Area -->
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
                                    <button onclick="setMode('edit')" id="editModeBtn" 
                                            class="mode-btn px-3 py-1.5 rounded-lg text-sm font-medium transition-all">
                                        <i class="fas fa-edit"></i> Write
                                    </button>
                                    <button onclick="setMode('preview')" id="previewModeBtn" 
                                            class="mode-btn px-3 py-1.5 rounded-lg text-sm font-medium transition-all">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </div>
                                
                                <!-- Action Buttons -->
                                <button onclick="saveDocument()" id="saveBtn"
                                        class="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-save"></i> Save
                                </button>
                                
                                <button onclick="showAIAssistant()" 
                                        class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-robot"></i> AI
                                </button>
                                
                                <button onclick="showVersionHistory()" 
                                        class="bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-history"></i> Versions
                                </button>
                                
                                <button onclick="showAnalytics()" 
                                        class="bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white font-medium px-4 py-1.5 rounded-xl transition-all shadow-md">
                                    <i class="fas fa-chart-line"></i> Stats
                                </button>
                            </div>
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
                        <div id="editor-container"></div>
                    </div>
                    
                    <!-- Preview Mode -->
                    <div id="previewMode" class="hidden">
                        <div class="preview-content p-8 min-h-[600px] bg-white dark:bg-slate-800" id="previewContent"></div>
                    </div>
                    
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- AI Assistant Modal -->
    <div id="aiModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">
                    <i class="fas fa-robot text-purple-500"></i> AI Writing Assistant
                </h3>
                <button onclick="closeModal('aiModal')" class="text-slate-500 hover:text-slate-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="aiSuggestions" class="space-y-3">
                <div class="animate-pulse">Loading suggestions...</div>
            </div>
        </div>
    </div>
    
    <!-- Version History Modal -->
    <div id="versionModal" class="modal hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">
                    <i class="fas fa-history text-blue-500"></i> Version History
                </h3>
                <button onclick="closeModal('versionModal')" class="text-slate-500 hover:text-slate-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="versionList" class="space-y-2 max-h-96 overflow-y-auto">
                Loading versions...
            </div>
        </div>
    </div>
    
    <!-- Analytics Modal -->
    <div id="analyticsModal" class="modal hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">
                    <i class="fas fa-chart-line text-orange-500"></i> Content Analytics
                </h3>
                <button onclick="closeModal('analyticsModal')" class="text-slate-500 hover:text-slate-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
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
    
    <script>
        // ============ GLOBAL VARIABLES ============
        let quill = null;
        let currentDocId = null;
        let currentMode = 'edit';
        let isDirty = false;
        let autoSaveTimer;
        let isFocusMode = false;
        let isTypewriterMode = false;
        let wordChart = null;
        
        // ============ INITIALIZATION ============
        function initEditor() {
            if (quill !== null) return;
            
            try {
                quill = new Quill('#editor-container', {
                    theme: 'snow',
                    placeholder: 'Start writing your masterpiece here... ✨\n\nTips:\n• Use Ctrl+B for bold, Ctrl+I for italic\n• Drag & drop images directly\n• Type "/" for AI commands',
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
                
                // Event listeners
                quill.on('text-change', function(delta, oldDelta, source) {
                    if (source === 'user') {
                        updateStats();
                        autoSave();
                        isDirty = true;
                        document.getElementById('statusText').innerHTML = '<i class="fas fa-pen text-amber-500"></i> Draft';
                    }
                });
                
                // Scroll progress
                const editor = document.querySelector('.ql-editor');
                if (editor) {
                    editor.addEventListener('scroll', updateProgress);
                }
                
                updateStats();
                showToast('Welcome to Ultimate Editor Pro! 🚀', 'success');
                
            } catch (error) {
                console.error('Editor init error:', error);
                showToast('Error initializing editor', 'error');
            }
        }
        
        // ============ IMAGE HANDLER (Upload) ============
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
        
        // ============ VIDEO HANDLER ============
        function videoHandler() {
            const url = prompt('Enter video URL (YouTube, Vimeo, or direct link):\n\nYouTube: https://www.youtube.com/embed/VIDEO_ID');
            if (url) {
                const range = quill.getSelection();
                const index = range ? range.index : quill.getLength();
                quill.insertEmbed(index, 'video', url);
                showToast('Video inserted!', 'success');
            }
        }
        
        // ============ STATISTICS UPDATE ============
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
            
            // Update analytics if modal is open
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
        
        // ============ AUTO SAVE ============
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
        
        // ============ SAVE DOCUMENT ============
        function saveDocument() {
            if (!quill) return;
            
            const title = document.getElementById('docTitle').value.trim() || 'Untitled';
            const content = JSON.stringify(quill.getContents());
            
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('title', title);
            formData.append('content', content);
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
        
        // ============ LOAD DOCUMENTS LIST ============
        function loadDocuments(searchTerm = '') {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=list'
            })
            .then(response => response.json())
            .then(data => {
                const listDiv = document.getElementById('documentList');
                const docCount = document.getElementById('docCount');
                const totalDocs = document.getElementById('totalDocs');
                
                if (data.success && data.documents.length > 0) {
                    let filteredDocs = data.documents;
                    if (searchTerm) {
                        filteredDocs = data.documents.filter(doc => 
                            doc.title.toLowerCase().includes(searchTerm.toLowerCase())
                        );
                    }
                    
                    docCount.innerText = filteredDocs.length;
                    totalDocs.innerText = data.documents.length;
                    
                    listDiv.innerHTML = filteredDocs.map(doc => `
                        <div class="doc-item p-4 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer transition-all group" 
                             onclick="loadDocument(${doc.id})"
                             data-id="${doc.id}">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="font-medium mb-1 flex items-center gap-2 doc-title">
                                        <i class="fas fa-file-alt text-purple-400 text-sm"></i>
                                        ${escapeHtml(doc.title.length > 50 ? doc.title.substring(0, 50) + '...' : doc.title)}
                                    </div>
                                    <div class="text-xs text-slate-400 flex items-center gap-3 doc-date">
                                        <span><i class="far fa-calendar-alt mr-1"></i>${new Date(doc.updated_at).toLocaleDateString()}</span>
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
            });
        }
        
        // ============ LOAD SINGLE DOCUMENT ============
        function loadDocument(id, version = null) {
            if (!quill) return;
            
            const formData = new FormData();
            formData.append('action', 'load');
            formData.append('id', id);
            if (version) formData.append('version', version);
            
            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const doc = data.document;
                    currentDocId = doc.id;
                    document.getElementById('docTitle').value = doc.title;
                    quill.setContents(JSON.parse(doc.content));
                    showToast('Document loaded' + (version ? ' (v' + version + ')' : ''), 'success');
                    updateStats();
                    isDirty = false;
                    
                    // Highlight active
                    document.querySelectorAll('.doc-item').forEach(el => {
                        el.classList.remove('doc-item-active');
                    });
                    const activeDoc = document.querySelector(`.doc-item[data-id="${id}"]`);
                    if (activeDoc) {
                        activeDoc.classList.add('doc-item-active');
                    }
                    
                    closeModal('versionModal');
                }
            });
        }
        
        // ============ DELETE DOCUMENT ============
        function deleteDocument(id, event) {
            event.stopPropagation();
            
            if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (currentDocId === id) {
                            createNewDocument();
                        }
                        showToast('Document deleted', 'success');
                        loadDocuments();
                    }
                });
            }
        }
        
        // ============ CREATE NEW DOCUMENT ============
        function createNewDocument() {
            currentDocId = null;
            document.getElementById('docTitle').value = 'Untitled Masterpiece';
            if (quill) quill.setContents([]);
            showToast('New document created', 'info');
            updateStats();
            isDirty = false;
            
            document.querySelectorAll('.doc-item').forEach(el => {
                el.classList.remove('doc-item-active');
            });
            
            if (currentMode === 'preview') {
                setMode('edit');
            }
        }
        
        // ============ MODE SWITCH ============
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
                editBtn.classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm', 'text-slate-700', 'dark:text-slate-200');
                editBtn.classList.remove('text-slate-500');
                previewBtn.classList.remove('bg-white', 'dark:bg-slate-600', 'shadow-sm', 'text-slate-700', 'dark:text-slate-200');
                previewBtn.classList.add('text-slate-500');
            } else {
                editModeDiv.classList.add('hidden');
                previewModeDiv.classList.remove('hidden');
                previewModeDiv.classList.add('block');
                previewBtn.classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm', 'text-slate-700', 'dark:text-slate-200');
                previewBtn.classList.remove('text-slate-500');
                editBtn.classList.remove('bg-white', 'dark:bg-slate-600', 'shadow-sm', 'text-slate-700', 'dark:text-slate-200');
                editBtn.classList.add('text-slate-500');
                updatePreview();
            }
        }
        
        // ============ UPDATE PREVIEW ============
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
                
                const tempQuill = new Quill(tempDiv, {
                    theme: 'snow',
                    modules: { toolbar: false }
                });
                tempQuill.setContents(delta);
                previewDiv.innerHTML = tempQuill.root.innerHTML;
                
                document.body.removeChild(tempDiv);
            } catch (error) {
                console.error('Preview error:', error);
            }
        }
        
        // ============ AI ASSISTANT ============
        async function showAIAssistant() {
            const modal = document.getElementById('aiModal');
            modal.classList.remove('hidden');
            
            const text = quill.getText();
            const selection = quill.getSelection();
            const selectedText = selection ? quill.getText(selection.index, selection.length) : '';
            
            const suggestionsDiv = document.getElementById('aiSuggestions');
            suggestionsDiv.innerHTML = '<div class="animate-pulse">🤖 AI is analyzing your content...</div>';
            
            // Mock AI suggestions
            setTimeout(() => {
                suggestionsDiv.innerHTML = `
                    <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                        <i class="fas fa-lightbulb text-yellow-500"></i>
                        <strong>Writing Tips:</strong>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li>✓ Your text has ${document.getElementById('wordCount').innerText} words</li>
                            <li>✓ Estimated reading time: ${document.getElementById('readingTime').innerText} minutes</li>
                            <li>💡 Consider adding more headings for better structure</li>
                            <li>💡 Short paragraphs work better for digital reading</li>
                            <li>💡 Add a compelling conclusion to wrap up</li>
                        </ul>
                    </div>
                    <div class="p-3 bg-pink-50 dark:bg-pink-900/20 rounded-lg mt-3">
                        <i class="fas fa-magic text-pink-500"></i>
                        <strong>Quick Actions:</strong>
                        <div class="mt-2 space-y-2">
                            <button onclick="improveClarity()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">✨ Improve clarity</button>
                            <button onclick="makeConcise()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">📝 Make more concise</button>
                            <button onclick="fixGrammar()" class="w-full text-left text-sm p-2 hover:bg-pink-100 dark:hover:bg-pink-800/30 rounded">🔍 Check grammar</button>
                        </div>
                    </div>
                `;
            }, 1000);
        }
        
        function improveClarity() {
            showToast('AI is improving clarity...', 'info');
            closeModal('aiModal');
        }
        
        function makeConcise() {
            showToast('Making content more concise...', 'info');
            closeModal('aiModal');
        }
        
        function fixGrammar() {
            showToast('Checking grammar...', 'info');
            closeModal('aiModal');
        }
        
        // ============ VERSION HISTORY ============
        async function showVersionHistory() {
            if (!currentDocId) {
                showToast('Save the document first to see version history', 'warning');
                return;
            }
            
            const modal = document.getElementById('versionModal');
            modal.classList.remove('hidden');
            
            const versionList = document.getElementById('versionList');
            versionList.innerHTML = '<div class="animate-pulse">Loading versions...</div>';
            
            const formData = new FormData();
            formData.append('action', 'versions');
            formData.append('id', currentDocId);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success && data.versions.length > 0) {
                versionList.innerHTML = data.versions.map(v => `
                    <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer"
                         onclick="loadDocument(${currentDocId}, ${v.version})">
                        <div class="flex justify-between items-center">
                            <span class="font-semibold">Version ${v.version}</span>
                            <span class="text-xs text-slate-500">${new Date(v.created_at).toLocaleString()}</span>
                        </div>
                        <button onclick="event.stopPropagation(); loadDocument(${currentDocId}, ${v.version})" 
                                class="mt-2 text-sm text-purple-500 hover:text-purple-600">
                            <i class="fas fa-undo"></i> Restore this version
                        </button>
                    </div>
                `).join('');
            } else {
                versionList.innerHTML = '<p class="text-center text-slate-500">No version history yet. Save the document to create versions.</p>';
            }
        }
        
        // ============ ANALYTICS ============
        function showAnalytics() {
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
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top' },
                        title: { display: true, text: 'Content Analysis' }
                    }
                }
            });
        }
        
        // ============ PROGRESS BAR ============
        function updateProgress() {
            const editor = document.querySelector('.ql-editor');
            if (!editor) return;
            
            const scrollTop = editor.scrollTop;
            const scrollHeight = editor.scrollHeight - editor.clientHeight;
            const progress = (scrollTop / scrollHeight) * 100;
            document.getElementById('progressBar').style.width = progress + '%';
        }
        
        // ============ TOAST NOTIFICATION ============
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast';
            
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            
            const colors = {
                success: 'border-emerald-500',
                error: 'border-red-500',
                warning: 'border-amber-500',
                info: 'border-purple-500'
            };
            
            toast.className = `toast ${colors[type]}`;
            toast.innerHTML = `<i class="fas ${icons[type]} mr-2"></i> ${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
        
        // ============ MODAL HANDLERS ============
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }
        
        // ============ UTILITY FUNCTIONS ============
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ============ KEYBOARD SHORTCUTS ============
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 's':
                        e.preventDefault();
                        saveDocument();
                        break;
                    case 'b':
                        e.preventDefault();
                        document.execCommand('bold');
                        break;
                    case 'i':
                        e.preventDefault();
                        document.execCommand('italic');
                        break;
                    case 'p':
                        e.preventDefault();
                        setMode(currentMode === 'edit' ? 'preview' : 'edit');
                        break;
                }
            }
        });
        
        // ============ MOBILE MENU ============
        document.getElementById('mobileMenuToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // ============ SEARCH ============
        document.getElementById('searchDocs')?.addEventListener('input', (e) => {
            loadDocuments(e.target.value);
        });
        
        // ============ BEFORE UNLOAD ============
        window.addEventListener('beforeunload', (e) => {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes!';
            }
        });
        
        // ============ DARK MODE PERSISTENCE ============
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }
        
        // ============ INITIALIZE ============
        document.addEventListener('DOMContentLoaded', () => {
            initEditor();
            loadDocuments();
            document.getElementById('editModeBtn').classList.add('bg-white', 'dark:bg-slate-600', 'shadow-sm');
        });
        
        // ============ FOCUS MODE TOGGLE ============
        window.toggleFocusMode = function() {
            isFocusMode = !isFocusMode;
            document.body.classList.toggle('focus-mode');
            showToast(isFocusMode ? 'Focus mode on' : 'Focus mode off', 'info');
        };
        
        // ============ TYPEWRITER MODE ============
        window.toggleTypewriterMode = function() {
            isTypewriterMode = !isTypewriterMode;
            document.body.classList.toggle('typewriter-mode');
            showToast(isTypewriterMode ? 'Typewriter mode on' : 'Typewriter mode off', 'info');
        };
        
        // ============ EXPORT HTML ============
        window.exportHTML = function() {
            const content = quill.root.innerHTML;
            const title = document.getElementById('docTitle').value;
            const html = `<!DOCTYPE html>
            <html>
            <head><meta charset="UTF-8"><title>${title}</title><style>body{max-width:800px;margin:0 auto;padding:40px;font-family:Georgia,serif;line-height:1.6;}</style></head>
            <body><h1>${title}</h1>${content}</body>
            </html>`;
            
            const blob = new Blob([html], { type: 'text/html' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${title}.html`;
            link.click();
            showToast('Exported as HTML', 'success');
        };
    </script>
</body>
</html>