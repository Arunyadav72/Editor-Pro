<?php
// Database configuration
$host = 'localhost';
$dbname = 'testdb';
$username = 'root';
$password = '';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');

  if ($_POST['action'] === 'save') {
    $title = $_POST['title'] ?? 'Untitled';
    $content = $_POST['content'] ?? '{"ops":[]}';
    $id = $_POST['id'] ?? null;

    json_decode($content);
    if (json_last_error() === JSON_ERROR_NONE) {
      if ($id) {
        $stmt = $pdo->prepare("UPDATE documents SET title = ?, content = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$title, $content, $id]);
        echo json_encode(['success' => true, 'id' => $id]);
      } else {
        $stmt = $pdo->prepare("INSERT INTO documents (title, content) VALUES (?, ?)");
        $stmt->execute([$title, $content]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
      }
    } else {
      echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    }
    exit;
  }

  if ($_POST['action'] === 'load') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
      echo json_encode(['success' => true, 'document' => $doc]);
    } else {
      echo json_encode(['success' => false, 'error' => 'Document not found']);
    }
    exit;
  }

  if ($_POST['action'] === 'list') {
    $stmt = $pdo->query("SELECT id, title, created_at, updated_at FROM documents ORDER BY updated_at DESC");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'documents' => $docs]);
    exit;
  }

  if ($_POST['action'] === 'delete') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modern Editor | Professional Writing Experience</title>

  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Quill CSS -->
  <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,100;14..32,200;14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&display=swap" rel="stylesheet">

  <!-- Syntax Highlighting for Code Blocks -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>

  <style>
    * {
      font-family: 'Inter', sans-serif;
    }

    /* Custom Scrollbar */
    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }

    ::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }

    /* Quill Customization */
    .ql-toolbar {
      border: none !important;
      border-bottom: 1px solid #e2e8f0 !important;
      background: #fafbfc;
      border-radius: 12px 12px 0 0;
      padding: 12px !important;
    }

    .ql-container {
      border: none !important;
      font-size: 16px;
      font-family: 'Inter', monospace !important;
      height: calc(100vh - 280px);
      min-height: 500px;
    }

    .ql-editor {
      padding: 40px 60px !important;
      line-height: 1.8 !important;
      font-size: 16px;
    }

    /* Professional Preview Styling - Medium/Notion Style */
    .preview-content {
      max-width: 800px;
      margin: 0 auto;
      padding: 60px 40px;
      font-family: 'Inter', sans-serif;
      line-height: 1.8;
      color: #1a202c;
    }

    /* Typography Scale */
    .preview-content h1 {
      font-family: 'Playfair Display', serif;
      font-size: 3.5rem;
      font-weight: 700;
      line-height: 1.2;
      margin-top: 2rem;
      margin-bottom: 1.5rem;
      color: #0f172a;
      letter-spacing: -0.02em;
      border-bottom: none;
    }

    .preview-content h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2.5rem;
      font-weight: 600;
      line-height: 1.3;
      margin-top: 2rem;
      margin-bottom: 1rem;
      color: #1e293b;
      letter-spacing: -0.015em;
    }

    .preview-content h3 {
      font-size: 1.75rem;
      font-weight: 600;
      line-height: 1.4;
      margin-top: 1.75rem;
      margin-bottom: 0.75rem;
      color: #334155;
    }

    .preview-content h4 {
      font-size: 1.25rem;
      font-weight: 600;
      margin-top: 1.5rem;
      margin-bottom: 0.5rem;
      color: #475569;
    }

    .preview-content p {
      margin-bottom: 1.25rem;
      font-size: 1.0625rem;
      color: #334155;
    }

    .preview-content p:first-child {
      font-size: 1.125rem;
      color: #475569;
    }

    /* Blockquote Styling */
    .preview-content blockquote {
      border-left: 4px solid #8b5cf6;
      padding-left: 1.5rem;
      margin: 1.5rem 0;
      font-style: italic;
      color: #475569;
      background: linear-gradient(to right, #f8fafc, transparent);
      padding: 1rem 1rem 1rem 1.5rem;
      border-radius: 0 8px 8px 0;
    }

    /* List Styling */
    .preview-content ul {
      margin: 1rem 0 1rem 1.5rem;
      list-style-type: disc;
    }

    .preview-content ol {
      margin: 1rem 0 1rem 1.5rem;
      list-style-type: decimal;
    }

    .preview-content li {
      margin-bottom: 0.5rem;
      color: #334155;
    }

    .preview-content li>ul,
    .preview-content li>ol {
      margin-top: 0.5rem;
      margin-bottom: 0.5rem;
    }

    /* Code Blocks */
    .preview-content pre {
      background: #1e293b;
      color: #e2e8f0;
      padding: 1.25rem;
      border-radius: 12px;
      overflow-x: auto;
      margin: 1.5rem 0;
      font-family: 'Courier New', monospace;
      font-size: 0.875rem;
      line-height: 1.5;
    }

    .preview-content code {
      background: #f1f5f9;
      padding: 0.2rem 0.4rem;
      border-radius: 6px;
      font-family: 'Courier New', monospace;
      font-size: 0.875rem;
      color: #d946ef;
    }

    .preview-content pre code {
      background: transparent;
      padding: 0;
      color: inherit;
    }

    /* Links */
    .preview-content a {
      color: #8b5cf6;
      text-decoration: none;
      border-bottom: 1px solid #c4b5fd;
      transition: all 0.2s;
    }

    .preview-content a:hover {
      color: #7c3aed;
      border-bottom-color: #7c3aed;
    }

    /* Images */
    .preview-content img {
      max-width: 100%;
      height: auto;
      border-radius: 12px;
      margin: 1.5rem 0;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    /* Tables */
    .preview-content table {
      width: 100%;
      border-collapse: collapse;
      margin: 1.5rem 0;
    }

    .preview-content th,
    .preview-content td {
      border: 1px solid #e2e8f0;
      padding: 0.75rem;
      text-align: left;
    }

    .preview-content th {
      background: #f8fafc;
      font-weight: 600;
    }

    /* Horizontal Rule */
    .preview-content hr {
      border: none;
      height: 2px;
      background: linear-gradient(to right, #e2e8f0, #cbd5e1, #e2e8f0);
      margin: 2rem 0;
    }

    /* Animations */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideInLeft {
      from {
        opacity: 0;
        transform: translateX(-20px);
      }

      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    .animate-fadeInUp {
      animation: fadeInUp 0.4s ease-out;
    }

    .animate-slideInLeft {
      animation: slideInLeft 0.3s ease-out;
    }

    /* Skeleton Loading */
    .skeleton {
      background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
      background-size: 200% 100%;
      animation: loading 1.5s infinite;
    }

    @keyframes loading {
      0% {
        background-position: 200% 0;
      }

      100% {
        background-position: -200% 0;
      }
    }

    /* Toast Animation */
    @keyframes slideInRight {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    .toast {
      animation: slideInRight 0.3s ease-out;
    }

    /* Active Document */
    .doc-item-active {
      background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%);
      border-left: 3px solid #8b5cf6;
    }

    /* Floating Action Button */
    .fab {
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: 56px;
      height: 56px;
      border-radius: 28px;
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
      box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s;
      z-index: 50;
    }

    .fab:hover {
      transform: scale(1.1);
      box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
    }
  </style>
</head>

<body class="bg-gradient-to-br from-slate-50 via-white to-slate-100">

  <!-- Floating Action Button for New Document -->
  <div class="fab" onclick="createNewDocument()" title="New Document">
    <i class="fas fa-plus text-white text-xl"></i>
  </div>

  <!-- Toast Container -->
  <div id="toastContainer" class="fixed bottom-24 right-6 z-50 hidden toast"></div>

  <div class="max-w-[1600px] mx-auto px-4 py-6">

    <!-- Header -->
    <div class="text-center mb-8 animate-fadeInUp">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-lg mb-4">
        <i class="fas fa-feather-alt text-white text-2xl"></i>
      </div>
      <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-600 to-purple-600 bg-clip-text text-transparent">
        StorySpace
      </h1>
      <p class="text-slate-500 mt-2">Where ideas come to life</p>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

      <!-- Sidebar -->
      <div class="lg:col-span-1 animate-slideInLeft">
        <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-lg border border-slate-200/50 overflow-hidden sticky top-6">
          <div class="p-5 border-b border-slate-200/50 bg-gradient-to-r from-violet-50/50 to-purple-50/50">
            <div class="flex items-center justify-between mb-3">
              <h2 class="font-semibold text-slate-800">
                <i class="fas fa-folder-open text-violet-500 mr-2"></i>
                Library
              </h2>
              <span id="docCount" class="text-xs bg-white/80 px-2 py-1 rounded-full text-slate-600 shadow-sm">0</span>
            </div>

            <!-- Search Box -->
            <div class="relative">
              <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
              <input type="text" id="searchDocs" placeholder="Search documents..."
                class="w-full pl-9 pr-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-violet-300 focus:ring-2 focus:ring-violet-200 transition-all bg-white/80">
            </div>
          </div>

          <div id="documentList" class="divide-y divide-slate-100 max-h-[600px] overflow-y-auto">
            <div class="p-6 space-y-3">
              <div class="skeleton h-20 rounded-xl"></div>
              <div class="skeleton h-20 rounded-xl"></div>
              <div class="skeleton h-20 rounded-xl"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Editor Area -->
      <div class="lg:col-span-2 animate-fadeInUp">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200/50 overflow-hidden">

          <!-- Toolbar -->
          <div class="p-4 border-b border-slate-200/50 bg-white/50 backdrop-blur-sm">
            <div class="flex flex-wrap gap-3 items-center justify-between">
              <div class="flex-1 min-w-[200px]">
                <div class="relative">
                  <i class="fas fa-pen-fancy absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                  <input type="text" id="docTitle" placeholder="Untitled Story"
                    class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:border-violet-300 focus:ring-2 focus:ring-violet-200 transition-all font-medium text-slate-700 bg-white/80">
                </div>
              </div>

              <div class="flex gap-2">
                <div class="bg-slate-100 rounded-xl p-1 flex gap-1">
                  <button onclick="setMode('edit')" id="editModeBtn"
                    class="mode-btn px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-1">
                    <i class="fas fa-edit text-xs"></i> Write
                  </button>
                  <button onclick="setMode('preview')" id="previewModeBtn"
                    class="mode-btn px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-1">
                    <i class="fas fa-eye text-xs"></i> Preview
                  </button>
                </div>

                <button onclick="saveDocument()" id="saveBtn"
                  class="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-medium px-5 py-2 rounded-xl transition-all duration-200 shadow-md hover:shadow-lg flex items-center gap-1">
                  <i class="fas fa-save text-sm"></i> Save
                </button>
              </div>
            </div>
          </div>

          <!-- Editor -->
          <div id="editMode" class="block">
            <div id="editor-container"></div>
          </div>

          <!-- Professional Preview -->
          <div id="previewMode" class="hidden bg-gradient-to-b from-white to-slate-50">
            <div class="preview-content" id="previewContent"></div>
          </div>

          <!-- Status Bar -->
          <div class="px-5 py-3 bg-slate-50/80 border-t border-slate-200/50 flex justify-between items-center text-xs text-slate-500 backdrop-blur-sm">
            <div class="flex items-center gap-2">
              <i class="fas fa-circle-info text-slate-400"></i>
              <span id="statusText">Ready</span>
            </div>
            <div class="flex items-center gap-4">
              <span><i class="far fa-clock mr-1"></i> <span id="wordCount">0</span> words</span>
              <span><i class="far fa-keyboard mr-1"></i> Auto-save enabled</span>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

  <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

  <script>
    let quill = null;
    let currentDocId = null;
    let currentMode = 'edit';
    let isDirty = false;

    const editorPreview = {
      "ops": [{
          "insert": "The Art of Digital Writing\n",
          "attributes": {
            "header": 1
          }
        },
        // { "insert": "\n" },
        {
          "insert": "In the modern era of digital communication, the way we write has transformed dramatically. "
        },
        {
          "insert": "Gone are the days",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": " when writing was confined to pen and paper.\n\n"
        },
        {
          "insert": "The Evolution of Writing\n",
          "attributes": {
            "header": 2
          }
        },
        // { "insert": "\n" },
        {
          "insert": "Writing has come a long way from the cave paintings of our ancestors. The journey began with pictograms, evolved into hieroglyphs, transformed through alphabets, and now exists in the ",
          "attributes": {
            "italic": true
          }
        },
        {
          "insert": "ethereal realm of binary code",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": ".\n\n"
        },
        {
          "insert": "Famous Quote on Writing\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "The most important things to remember about writing is that everyone has a story and most of it needs editing.\n",
          "attributes": {
            "blockquote": true
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Essential Elements of Digital Writing\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "To create content that resonates, you must master these ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "three core elements",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": ":\n\n"
        },
        {
          "insert": "First, ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "clarity is king",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": ". Your message should be immediately understandable.\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Second, ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "brevity is valuable",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": ". Respect your reader's time.\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Third, ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "personality is powerful",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": ". Let your authentic voice shine.\n\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "The Psychology of Screen Reading\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Research has shown that reading on screens is fundamentally different. Studies reveal that:\n\n"
        },
        {
          "insert": "People read 25% slower on screens than on paper\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Readers scan in an F-shaped pattern, not line by line\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Attention spans have dropped from 12 seconds to 8 seconds\n\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "This means you must ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "adapt your writing style",
          "attributes": {
            "bold": true,
            "italic": true
          }
        },
        {
          "insert": " for modern readers.\n\n"
        },
        {
          "insert": "A Word of Wisdom\n",
          "attributes": {
            "header": 3
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Anne Lamott once wrote in her classic book Bird by Bird:\n\n"
        },
        {
          "insert": "Almost all good writing begins with terrible first efforts. You need to start somewhere.\n",
          "attributes": {
            "blockquote": true
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "The Power of Lists\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "The essential elements of compelling digital content include:\n\n"
        },
        {
          "insert": "Clarity of purpose: Every piece should have a clear goal\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Audience awareness: Write for specific readers\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Authentic voice: Let your unique personality shine\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Value proposition: Give readers something worthwhile\n\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "The Writing Process\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Professional writers follow a systematic approach:\n\n"
        },
        {
          "insert": "The Planning Phase - Answer key questions before writing\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "The Drafting Phase - Write freely without editing\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "The Editing Phase - Refine and polish your work\n\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Ernest Hemingway's Famous Advice\n",
          "attributes": {
            "header": 3
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "The great writer Ernest Hemingway once said:\n\n"
        },
        {
          "insert": "The first draft of anything is shit.\n",
          "attributes": {
            "blockquote": true
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Final Thoughts\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "The fundamentals of good writing remain constant:\n\n"
        },
        {
          "insert": "Write clearly\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Respect your reader\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Edit ruthlessly\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Be authentically you\n\n",
          "attributes": {
            "list": "bullet"
          }
        },
        {
          "insert": "Your voice matters. ",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": "Your perspective is unique. ",
          "attributes": {
            "italic": true
          }
        },
        {
          "insert": "The world needs what only you can write.\n\n"
        },
        {
          "insert": "Happy Writing! 🚀\n",
          "attributes": {
            "bold": true,
            "italic": true
          }
        }
      ]
    };

    const completeSampleData = {
      "ops": [
        // Main Heading
        {
          "insert": "🎨 Visual Storytelling Guide\n",
          "attributes": {
            "header": 1
          }
        },
        {
          "insert": "\n"
        },

        // Introduction
        {
          "insert": "In today's digital world, "
        },
        {
          "insert": "visual content",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": " is more important than ever. Images and videos help convey emotions, explain complex ideas, and keep readers engaged.\n\n"
        },

        // Sample Image 1
        {
          "insert": "\n"
        },
        {
          "insert": "Beautiful Nature Scene\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "image": "https://picsum.photos/id/104/800/400\n"
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "This stunning image captures the beauty of nature. ",
          "attributes": {
            "italic": true
          }
        },
        {
          "insert": "Visuals like this can instantly grab attention",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": " and create emotional connection.\n\n"
        },

        // Blockquote
        {
          "insert": "Why Images Matter\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "According to research, people process visual information 60,000 times faster than text. The brain remembers 80% of what it sees compared to only 20% of what it reads.\n",
          "attributes": {
            "blockquote": true
          }
        },
        {
          "insert": "\n"
        },

        // Sample Image 2
        {
          "insert": "Technology & Innovation\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "image": "https://picsum.photos/id/0/800/400\n"
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Technology images work great for blog posts about ",
          "attributes": {
            "italic": true
          }
        },
        {
          "insert": "innovation, coding, and digital transformation",
          "attributes": {
            "bold": true
          }
        },
        {
          "insert": ".\n\n"
        },

        // Video Section
        {
          "insert": "📹 Video Content Examples\n",
          "attributes": {
            "header": 1
          }
        },
        {
          "insert": "\n"
        },

        {
          "insert": "Sample Video 1 - Nature\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "video": "https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4\n"
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "This beautiful flower video demonstrates how motion can capture attention.\n\n"
        },

        {
          "insert": "Sample Video 2 - Earth from Space\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "video": "https://interactive-examples.mdn.mozilla.net/media/cc0-videos/earth.mp4\n"
          }
        },
        {
          "insert": "\n"
        },

        // YouTube Video
        {
          "insert": "YouTube Video Example\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "video": "https://www.youtube.com/embed/8XjYACRoX-I\n"
          }
        },
        {
          "insert": "\n"
        },

        // Tips Section with List
        {
          "insert": "Best Practices for Visual Content\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Follow these tips to optimize your visual content:\n\n"
        },
        {
          "insert": "Use high-quality, relevant images\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Keep videos under 2 minutes for better engagement\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Add captions or alt text for accessibility\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Optimize file sizes for faster loading\n",
          "attributes": {
            "list": "ordered"
          }
        },
        {
          "insert": "Use consistent visual style across your content\n\n",
          "attributes": {
            "list": "ordered"
          }
        },

        // More Images
        {
          "insert": "More Visual Examples\n",
          "attributes": {
            "header": 2
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "image": "https://picsum.photos/id/100/800/400\n"
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": {
            "image": "https://picsum.photos/id/12/800/400\n"
          }
        },
        {
          "insert": "\n"
        },

        // Final Quote
        {
          "insert": "Final Thought\n",
          "attributes": {
            "header": 3
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "A picture is worth a thousand words, and a video is worth a million.\n",
          "attributes": {
            "blockquote": true
          }
        },
        {
          "insert": "\n"
        },
        {
          "insert": "Start adding visuals to your content today! 🚀\n",
          "attributes": {
            "bold": true,
            "italic": true
          }
        }
      ]
    };

    // Calculate word count
    function updateWordCount() {
      if (!quill) return;
      const text = quill.getText();
      const words = text.trim().split(/\s+/).filter(word => word.length > 0).length;
      document.getElementById('wordCount').innerText = words;
    }

    // Initialize Quill
    function initEditor() {
      if (quill !== null) return;

      try {
        quill = new Quill('#editor-container', {
          theme: 'snow',
          placeholder: 'Start writing your masterpiece... ✨',
          modules: {
            toolbar: [
              [{
                'header': [1, 2, 3, 4, 5, 6, false]
              }],
              ['bold', 'italic', 'underline', 'strike'],
              [{
                'list': 'ordered'
              }, {
                'list': 'bullet'
              }],
              [{
                'indent': '-1'
              }, {
                'indent': '+1'
              }],
              [{
                'align': []
              }],
              ['blockquote', 'code-block'],
              ['link', 'image'],
              ['clean']
            ]
          }
        });

        quill.on('text-change', function() {
          updateWordCount();
          if (currentMode === 'preview') {
            updatePreview();
          }
          isDirty = true;
          document.getElementById('statusText').innerHTML = '<span class="text-amber-600"><i class="fas fa-pen mr-1"></i> Draft saved locally</span>';
        });

        updatePreview();
        updateWordCount();
        showToast('Ready to write', 'success');

      } catch (error) {
        console.error('Editor init error:', error);
        showToast('Error initializing editor', 'error');
      }
    }

    // Professional Preview Update
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
          modules: {
            toolbar: false
          }
        });
        tempQuill.setContents(delta);
        let html = tempQuill.root.innerHTML;

        // Enhance preview styling
        html = html.replace(/<pre><code>/g, '<pre><code class="language-javascript">');
        previewDiv.innerHTML = html;

        // Apply syntax highlighting
        if (typeof Prism !== 'undefined') {
          Prism.highlightAllUnder(previewDiv);
        }

        document.body.removeChild(tempDiv);
      } catch (error) {
        console.error('Preview error:', error);
      }
    }

    // Switch Mode
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
        editBtn.classList.add('bg-white', 'shadow-sm', 'text-slate-700');
        editBtn.classList.remove('text-slate-500');
        previewBtn.classList.remove('bg-white', 'shadow-sm', 'text-slate-700');
        previewBtn.classList.add('text-slate-500');
      } else {
        editModeDiv.classList.add('hidden');
        previewModeDiv.classList.remove('hidden');
        previewModeDiv.classList.add('block');
        previewBtn.classList.add('bg-white', 'shadow-sm', 'text-slate-700');
        previewBtn.classList.remove('text-slate-500');
        editBtn.classList.remove('bg-white', 'shadow-sm', 'text-slate-700');
        editBtn.classList.add('text-slate-500');
        updatePreview();
      }
    }

    // Load Documents
    function loadDocuments(searchTerm = '') {
      fetch('', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'action=list'
        })
        .then(response => response.json())
        .then(data => {
          const listDiv = document.getElementById('documentList');
          const docCount = document.getElementById('docCount');

          if (data.success && data.documents.length > 0) {
            let filteredDocs = data.documents;
            if (searchTerm) {
              filteredDocs = data.documents.filter(doc =>
                doc.title.toLowerCase().includes(searchTerm.toLowerCase())
              );
            }

            docCount.innerText = filteredDocs.length;
            listDiv.innerHTML = filteredDocs.map(doc => `
            <div class="doc-item p-4 hover:bg-slate-50/80 cursor-pointer transition-all group relative border-l-3 border-transparent hover:border-l-violet-400" 
                 onclick="loadDocument(${doc.id})"
                 data-id="${doc.id}">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-slate-800 mb-1 flex items-center gap-2">
                            <i class="fas fa-file-alt text-violet-400 text-sm"></i>
                            ${escapeHtml(doc.title.length > 40 ? doc.title.substring(0, 40) + '...' : doc.title)}
                        </div>
                        <div class="text-xs text-slate-400 flex items-center gap-3">
                            <span><i class="far fa-calendar-alt mr-1"></i>${new Date(doc.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                            <span><i class="far fa-clock mr-1"></i>${new Date(doc.updated_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                        </div>
                    </div>
                    <button onclick="deleteDocument(${doc.id}, event)" 
                            class="opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-600 transition-all p-2 rounded-lg hover:bg-red-50">
                        <i class="fas fa-trash-alt text-sm"></i>
                    </button>
                </div>
            </div>`).join('');
          } else {
            docCount.innerText = '0';
            listDiv.innerHTML = `
            <div class="p-8 text-center">
                <i class="fas fa-book-open text-5xl text-slate-300 mb-3"></i>
                <p class="text-slate-500 text-sm">Your library is empty</p>
                <button onclick="createNewDocument()" class="mt-4 text-violet-500 text-sm hover:text-violet-600 font-medium">
                    Create your first story →
                </button>
            </div>`;
          }
        });
    }

    // Save Document
    function saveDocument() {
      if (!quill) return;

      const title = document.getElementById('docTitle').value.trim() || 'Untitled';
      const content = JSON.stringify(quill.getContents());

      const formData = new FormData();
      formData.append('action', 'save');
      formData.append('title', title);
      formData.append('content', content);
      if (currentDocId) formData.append('id', currentDocId);

      fetch('', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            currentDocId = data.id;
            isDirty = false;
            document.getElementById('statusText').innerHTML = '<span class="text-emerald-600"><i class="fas fa-check-circle mr-1"></i> Saved to cloud</span>';
            showToast('Document saved successfully!', 'success');
            loadDocuments();

            setTimeout(() => {
              if (!isDirty) {
                document.getElementById('statusText').innerHTML = '<span class="text-slate-500"><i class="fas fa-cloud-check mr-1"></i> All changes saved</span>';
              }
            }, 2000);
          } else {
            showToast('Error saving document', 'error');
          }
        });
    }

    // Load Document
    function loadDocument(id) {
      if (!quill) return;

      const formData = new FormData();
      formData.append('action', 'load');
      formData.append('id', id);

      fetch('', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            const doc = data.document;
            currentDocId = doc.id;
            document.getElementById('docTitle').value = doc.title;
            quill.setContents(completeSampleData);
            showToast('Document loaded', 'info');
            updateWordCount();

            document.querySelectorAll('.doc-item').forEach(el => {
              el.classList.remove('doc-item-active');
            });
            const activeDoc = document.querySelector(`.doc-item[data-id="${id}"]`);
            if (activeDoc) {
              activeDoc.classList.add('doc-item-active');
            }
          }
        });
    }

    // Delete Document
    function deleteDocument(id, event) {
      event.stopPropagation();

      if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch('', {
            method: 'POST',
            body: formData
          })
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

    // Create New Document
    function createNewDocument() {
      currentDocId = null;
      document.getElementById('docTitle').value = 'Untitled Story';
      if (quill) quill.setContents([]);
      showToast('New story created', 'info');
      updateWordCount();

      document.querySelectorAll('.doc-item').forEach(el => {
        el.classList.remove('doc-item-active');
      });

      if (currentMode === 'preview') {
        setMode('edit');
      }
    }

    // Show Toast
    function showToast(message, type = 'info') {
      const toast = document.getElementById('toastContainer');
      const colors = {
        success: 'bg-emerald-500',
        error: 'bg-red-500',
        info: 'bg-violet-500'
      };
      const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle'
      };

      toast.className = `fixed bottom-24 right-6 z-50 toast ${colors[type]} text-white px-5 py-3 rounded-xl shadow-xl flex items-center gap-2`;
      toast.innerHTML = `<i class="fas ${icons[type]}"></i><span class="text-sm">${message}</span>`;
      toast.classList.remove('hidden');

      setTimeout(() => {
        toast.classList.add('hidden');
      }, 3000);
    }

    // Search functionality
    document.getElementById('searchDocs')?.addEventListener('input', function(e) {
      loadDocuments(e.target.value);
    });

    // Escape HTML
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Auto Save
    let autoSaveInterval = setInterval(() => {
      if (isDirty && quill) {
        saveDocument();
      }
    }, 30000);

    // Before unload
    window.addEventListener('beforeunload', (e) => {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes!';
      }
    });

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
      initEditor();
      loadDocuments();
      document.getElementById('editModeBtn').classList.add('bg-white', 'shadow-sm', 'text-slate-700');
    });
  </script>
</body>

</html>