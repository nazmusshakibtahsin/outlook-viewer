<?php require '../lib.php'; require_admin(); $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf(); $act = $_POST['act'] ?? '';
  if ($act === 'add') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$_POST['rt'] || !$_POST['cid']) $msg = 'Email, refresh token and client ID are required.';
    else {
      db()->prepare('INSERT INTO mailboxes (email, enc_refresh_token, enc_client_id, enc_client_secret) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE enc_refresh_token=VALUES(enc_refresh_token), enc_client_id=VALUES(enc_client_id), enc_client_secret=VALUES(enc_client_secret), status="active"')
        ->execute([$email, encrypt_secret(trim($_POST['rt'])), encrypt_secret(trim($_POST['cid'])), encrypt_secret(trim($_POST['cs'] ?? ''))]);
      log_activity($email, 'saved'); $msg = "Saved $email";
    }
  } elseif ($act === 'bulk') {
    $n = 0;
    foreach (preg_split('/\r?\n/', $_POST['lines'] ?? '') as $line) {
      $p = array_map('trim', explode('|', $line));
      if (count($p) >= 3 && filter_var($p[0], FILTER_VALIDATE_EMAIL)) {
        db()->prepare('REPLACE INTO mailboxes (email, enc_refresh_token, enc_client_id, enc_client_secret) VALUES (?,?,?,?)')
          ->execute([strtolower($p[0]), encrypt_secret($p[1]), encrypt_secret($p[2]), encrypt_secret($p[3] ?? '')]); $n++;
      }
    }
    $msg = "Imported $n mailboxes";
  } elseif ($act === 'toggle') {
    db()->prepare("UPDATE mailboxes SET status=IF(status='active','disabled','active') WHERE id=?")->execute([(int)$_POST['id']]);
  } elseif ($act === 'delete') {
    db()->prepare('DELETE FROM mailboxes WHERE id=?')->execute([(int)$_POST['id']]); $msg = 'Deleted';
  } elseif ($act === 'bulk_toggle') {
    $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
    if (!empty($ids)) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      db()->prepare("UPDATE mailboxes SET status=IF(status='active','disabled','active') WHERE id IN ($placeholders)")->execute($ids);
      $msg = count($ids) . " mailbox(es) updated";
    }
  } elseif ($act === 'bulk_delete') {
    $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
    if (!empty($ids)) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      db()->prepare("DELETE FROM mailboxes WHERE id IN ($placeholders)")->execute($ids);
      $msg = count($ids) . " mailbox(es) deleted";
    }
  }
}
$q = trim($_GET['q'] ?? '');
$s = db()->prepare('SELECT id, email, status, last_checked_at FROM mailboxes WHERE email LIKE ? ORDER BY id DESC LIMIT 500'); $s->execute(["%$q%"]);
$rows = $s->fetchAll();
$logs = db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 15')->fetchAll();
$total = db()->query('SELECT COUNT(*) FROM mailboxes')->fetchColumn();
$active = db()->query("SELECT COUNT(*) FROM mailboxes WHERE status='active'")->fetchColumn();
$today = db()->query("SELECT COUNT(*) FROM activity_log WHERE action='fetch_ok' AND DATE(created_at)=CURDATE()")->fetchColumn();
$failToday = db()->query("SELECT COUNT(*) FROM activity_log WHERE action='fetch_fail' AND DATE(created_at)=CURDATE()")->fetchColumn();
$lastOk = db()->query("SELECT action, created_at FROM activity_log WHERE action IN ('fetch_ok','fetch_fail') ORDER BY id DESC LIMIT 1")->fetch();
$mailState = !$lastOk ? ['No data yet','bg-gray-200'] : ($lastOk['action']==='fetch_ok' ? ['Connected','bg-green-200'] : ['Warning','bg-yellow-200']);
$c = csrf(); shell_start('Admin Dashboard', 'admin'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Outlook Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
            .mailbox-table { font-size: 0.875rem; }
        }
        @media (min-width: 641px) and (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (min-width: 1025px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="bg-gradient-to-br from-blue-600 to-blue-700 text-white p-2 rounded-lg">
                        <i class="fas fa-shield-alt text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Admin Dashboard</h1>
                        <p class="text-xs sm:text-sm text-gray-600">Mailbox management & system status</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="../" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        <span class="hidden sm:inline">Back</span>
                    </a>
                    <a href="logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="hidden sm:inline">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <!-- Alert Message -->
        <?php if ($msg): ?>
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center gap-2 animate-pulse">
            <i class="fas fa-check-circle"></i>
            <span><?= h($msg) ?></span>
            <button onclick="this.parentElement.style.display='none'" class="ml-auto text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid gap-4 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Total Mailboxes</p>
                        <p class="text-4xl font-bold text-gray-900 mt-2"><?= (int)$total ?></p>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-inbox text-2xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Active</p>
                        <p class="text-4xl font-bold text-gray-900 mt-2"><?= (int)$active ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= (int)($total - $active) ?> disabled</p>
                    </div>
                    <div class="bg-green-100 p-4 rounded-full">
                        <i class="fas fa-circle-check text-2xl text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Fetches Today</p>
                        <p class="text-4xl font-bold text-gray-900 mt-2"><?= (int)$today ?></p>
                        <?php if ($failToday > 0): ?>
                        <p class="text-xs text-red-600 mt-1">
                            <i class="fas fa-exclamation-circle"></i> <?= (int)$failToday ?> failed
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-purple-100 p-4 rounded-full">
                        <i class="fas fa-envelope text-2xl text-purple-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-yellow-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">System Status</p>
                        <p class="text-xl font-bold mt-2 <?= strpos($mailState[1], 'green') !== false ? 'text-green-600' : (strpos($mailState[1], 'yellow') !== false ? 'text-yellow-600' : 'text-gray-600') ?>">
                            <?= $mailState[0] ?>
                        </p>
                        <?php if ($lastOk): ?>
                        <p class="text-xs text-gray-500 mt-1">Last: <?= date('H:i', strtotime($lastOk['created_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-yellow-100 p-4 rounded-full">
                        <i class="fas fa-server text-2xl text-yellow-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailboxes Section -->
        <div class="bg-white rounded-lg shadow-lg mb-8 overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 text-white">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold flex items-center gap-2">
                            <i class="fas fa-envelope-open-text"></i> Mailboxes
                        </h2>
                        <p class="text-blue-100 text-sm mt-1"><?= count($rows) ?> mailbox(es) found</p>
                    </div>
                    <form method="GET" class="w-full sm:w-64">
                        <div class="relative">
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search mailboxes..." 
                                   class="w-full px-4 py-2 pr-10 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-400">
                            <i class="fas fa-search absolute right-3 top-3 text-gray-400"></i>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!$rows): ?>
            <div class="p-8 text-center">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No mailboxes found</h3>
                <p class="text-gray-500 mb-4">Add your first mailbox using the form below</p>
                <a href="#add" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-plus"></i> Add Mailbox
                </a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <div class="inline-flex gap-2 p-4 bg-gray-100 w-full border-b" id="bulkActionBar" style="display: none;">
                    <input type="checkbox" id="selectAllMB" class="cursor-pointer">
                    <span id="selectedCountMB" class="text-sm text-gray-700 ml-2">0 selected</span>
                    <button onclick="bulkToggleMB()" class="ml-auto bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1 rounded text-sm transition" id="bulkToggleBtn">
                        <i class="fas fa-toggle-on"></i> Toggle Status
                    </button>
                    <button onclick="bulkDeleteMB()" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm transition" id="bulkDeleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
                <table class="w-full mailbox-table">
                    <thead class="bg-gray-100 border-b">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">
                                <input type="checkbox" id="selectAllCheckbox" class="cursor-pointer" onchange="toggleAllMB()">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 hidden sm:table-cell">Credentials</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 hidden md:table-cell">Last Checked</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="mailboxTableBody">
                        <?php foreach ($rows as $r): ?>
                        <tr class="border-b hover:bg-gray-50 transition mailbox-row" data-id="<?= $r['id'] ?>">
                            <td class="px-4 py-3 text-left">
                                <input type="checkbox" class="mailbox-checkbox cursor-pointer" value="<?= $r['id'] ?>" onchange="updateBulkUI()">
                            </td>
                            <td class="px-4 py-3 text-left">
                                <a href="../inbox.php?email=<?= urlencode($r['email']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium truncate block max-w-xs">
                                    <?= h($r['email']) ?>
                                    <i class="fas fa-external-link-alt text-xs ml-1"></i>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-500 font-mono text-sm hidden sm:table-cell">••••••••</td>
                            <td class="px-4 py-3 text-left">
                                <?php if ($r['status'] === 'active'): ?>
                                <span class="inline-flex items-center gap-1 bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-semibold">
                                    <span class="w-2 h-2 bg-green-600 rounded-full"></span> Active
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-semibold">
                                    <span class="w-2 h-2 bg-gray-400 rounded-full"></span> Disabled
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell">
                                <?= h($r['last_checked_at'] ?? '—') ?>
                            </td>
                            <td class="px-4 py-3 text-right space-x-1">
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= $c ?>">
                                    <input type="hidden" name="act" value="toggle">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-xs transition" title="Toggle status">
                                        <i class="fas fa-toggle-on"></i>
                                    </button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this mailbox?')">
                                    <input type="hidden" name="csrf" value="<?= $c ?>">
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs transition" title="Delete mailbox">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Add Mailbox Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8" id="add">
            <!-- Add Single Mailbox -->
            <div class="bg-white rounded-lg shadow-lg p-6 border-t-4 border-blue-600">
                <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-blue-600"></i> Add Mailbox
                </h3>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= $c ?>">
                    <input type="hidden" name="act" value="add">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                               required placeholder="user@outlook.com">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Refresh Token</label>
                        <input type="password" name="rt" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                               required autocomplete="off" placeholder="••••••••">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Client ID</label>
                        <input type="password" name="cid" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                               required autocomplete="off" placeholder="••••••••">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Client Secret <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="password" name="cs" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                               autocomplete="off" placeholder="••••••••">
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-floppy-disk"></i> Save Mailbox
                    </button>
                </form>
            </div>

            <!-- Bulk Import -->
            <div class="bg-white rounded-lg shadow-lg p-6 border-t-4 border-purple-600">
                <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <i class="fas fa-file-upload text-purple-600"></i> Bulk Import
                </h3>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= $c ?>">
                    <input type="hidden" name="act" value="bulk">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">One mailbox per line</label>
                        <p class="text-xs text-gray-600 mb-2">Format: <code class="bg-gray-100 px-2 py-1 rounded">email|refresh_token|client_id|client_secret</code></p>
                        <textarea name="lines" rows="7" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent font-mono text-sm" 
                                  placeholder="user1@outlook.com|token1|id1|secret1&#10;user2@outlook.com|token2|id2|secret2"></textarea>
                    </div>

                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-upload"></i> Import Mailboxes
                    </button>
                </form>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4 text-white">
                <h2 class="text-2xl font-bold flex items-center gap-2">
                    <i class="fas fa-history"></i> Recent Activity
                </h2>
                <p class="text-green-100 text-sm mt-1">Last 15 activities</p>
            </div>

            <?php if (!$logs): ?>
            <div class="p-8 text-center">
                <i class="fas fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-600">No activity yet.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-100 border-b">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">Mailbox</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700">Event</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 hidden sm:table-cell">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l):
                            $badgeClass = $l['action']==='fetch_ok' ? 'bg-green-100 text-green-800' : ($l['action']==='fetch_fail' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800');
                            $icon = $l['action']==='fetch_ok' ? 'fa-check-circle' : ($l['action']==='fetch_fail' ? 'fa-exclamation-circle' : 'fa-info-circle');
                        ?>
                        <tr class="border-b hover:bg-gray-50 transition">
                            <td class="px-4 py-3 text-sm text-gray-600"><?= date('M d, H:i', strtotime($l['created_at'])) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 font-medium truncate max-w-xs"><?= h($l['email']) ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex items-center gap-1 <?= $badgeClass ?> px-3 py-1 rounded-full text-xs font-semibold">
                                    <i class="fas <?= $icon ?>"></i> <?= ucfirst(str_replace('_', ' ', $l['action'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 hidden sm:table-cell"><?= h($l['detail'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
let selectedMB = new Set();

function toggleAllMB() {
    const checkboxes = document.querySelectorAll('.mailbox-checkbox');
    const selectAll = document.getElementById('selectAllCheckbox');
    
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedMB.add(parseInt(cb.value));
        } else {
            selectedMB.delete(parseInt(cb.value));
        }
    });
    updateBulkUI();
}

function updateBulkUI() {
    selectedMB.clear();
    document.querySelectorAll('.mailbox-checkbox:checked').forEach(cb => {
        selectedMB.add(parseInt(cb.value));
    });
    
    const bulkBar = document.getElementById('bulkActionBar');
    const countSpan = document.getElementById('selectedCountMB');
    
    if (selectedMB.size > 0) {
        bulkBar.style.display = 'flex';
        countSpan.textContent = selectedMB.size + ' selected';
    } else {
        bulkBar.style.display = 'none';
    }
    
    document.getElementById('selectAllCheckbox').checked = selectedMB.size > 0 && selectedMB.size === document.querySelectorAll('.mailbox-checkbox').length;
}

function bulkToggleMB() {
    if (selectedMB.size === 0 || !confirm(`Toggle status for ${selectedMB.size} mailbox(es)?`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf" value="<?= $c ?>">
        <input type="hidden" name="act" value="bulk_toggle">
        <input type="hidden" name="ids" value="${Array.from(selectedMB).join(',')}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function bulkDeleteMB() {
    if (selectedMB.size === 0 || !confirm(`Delete ${selectedMB.size} mailbox(es)? This cannot be undone.`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf" value="<?= $c ?>">
        <input type="hidden" name="act" value="bulk_delete">
        <input type="hidden" name="ids" value="${Array.from(selectedMB).join(',')}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Initialize
document.addEventListener('DOMContentLoaded', updateBulkUI);
</script>
</body>
</html>
<?php shell_end(); ?>
