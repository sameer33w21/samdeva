<?php
session_start();

// Configuration
define('TV_FILE', 'tv.txt');
define('RECORDINGS_DIR', 'recordings/');
define('RECORDINGS_LOG', 'recordings.json');
define('DOMAIN_LIMITS', [
    'starshare.fun' => 1,
    'newtv.fun' => 3,
    'oldtv.fun' => 3
]);
date_default_timezone_set('Asia/Kolkata'); // Set your timezone

// User credentials (in real app, use database and password hashing)
$users = [
    'Admin' => 'pass123',
    'user' => 'pass123'
];

// Check login
if (!isset($_SESSION['loggedin']) || !isset($_SESSION['username'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        if (isset($users[$username]) && $users[$username] === $password) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
        } else {
            $loginError = 'Invalid username or password';
        }
    }
    
    if (!isset($_SESSION['loggedin'])) {
        // Show login form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>IPTV Recorder - Login</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body {
                    background: linear-gradient(135deg, #1a2a6c, #b21f1f, #1a2a6c);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .login-box {
                    background: rgba(255, 255, 255, 0.1);
                    backdrop-filter: blur(10px);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    border-radius: 15px;
                    padding: 30px;
                    width: 100%;
                    max-width: 400px;
                    box-shadow: 0 15px 25px rgba(0, 0, 0, 0.5);
                }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2 class="text-center text-white mb-4">IPTV Recorder Login</h2>
                <?php if (isset($loginError)): ?>
                    <div class="alert alert-danger"><?= $loginError ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label text-white">Username</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-white">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Create recordings directory if it doesn't exist
if (!file_exists(RECORDINGS_DIR)) {
    mkdir(RECORDINGS_DIR, 0777, true);
}

// Initialize recordings log
if (!file_exists(RECORDINGS_LOG)) {
    file_put_contents(RECORDINGS_LOG, json_encode([]));
}

// Functions
function parseTVFile() {
    $channels = [];
    $lines = file(TV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    $currentChannel = null;
    foreach ($lines as $line) {
        if (strpos($line, '#EXTINF:') === 0) {
            // Parse channel info
            preg_match('/tvg-logo="([^"]+)"/', $line, $logoMatch);
            preg_match('/tvg-name="([^"]+)"/', $line, $nameMatch);
            preg_match('/group-title="([^"]+)"/', $line, $groupMatch);
            preg_match('/,([^,]+)$/', $line, $titleMatch);
            
            $currentChannel = [
                'logo' => $logoMatch[1] ?? '',
                'name' => $nameMatch[1] ?? '',
                'group' => $groupMatch[1] ?? '',
                'title' => $titleMatch[1] ?? 'Unknown Channel',
                'url' => ''
            ];
        } elseif ($currentChannel && filter_var($line, FILTER_VALIDATE_URL)) {
            // Add URL to current channel
            $currentChannel['url'] = $line;
            $channels[] = $currentChannel;
            $currentChannel = null;
        }
    }
    
    return $channels;
}

function getRecordings() {
    $recordings = json_decode(file_get_contents(RECORDINGS_LOG), true) ?: [];
    // Filter out completed recordings older than 1 week
    $recordings = array_filter($recordings, function($rec) {
        return $rec['status'] !== 'completed' || 
               (time() - strtotime($rec['end_time'])) < (7 * 24 * 60 * 60);
    });
    return $recordings;
}

function saveRecordings($recordings) {
    file_put_contents(RECORDINGS_LOG, json_encode($recordings, JSON_PRETTY_PRINT));
}

function checkDomainLimits($url, $startTime = null, $endTime = null) {
    // Domain limits only apply to Admin users
    if ($_SESSION['username'] !== 'Admin') {
        return true;
    }
    
    $domain = parse_url($url, PHP_URL_HOST);
    $recordings = getRecordings();
    $activeCount = 0;
    $scheduledConflicts = 0;
    
    foreach ($recordings as $rec) {
        $recDomain = parse_url($rec['channel_url'], PHP_URL_HOST);
        if ($recDomain === $domain) {
            if ($rec['status'] === 'recording') {
                $activeCount++;
            }
            
            // Check for scheduled conflicts if times are provided
            if ($startTime && $endTime && $rec['status'] === 'scheduled') {
                $recStart = strtotime($rec['scheduled']['start']);
                $recEnd = strtotime($rec['scheduled']['end']);
                $newStart = strtotime($startTime);
                $newEnd = strtotime($endTime);
                
                // Check if times overlap
                if (($newStart >= $recStart && $newStart < $recEnd) ||
                    ($newEnd > $recStart && $newEnd <= $recEnd) ||
                    ($newStart <= $recStart && $newEnd >= $recEnd)) {
                    $scheduledConflicts++;
                }
            }
        }
    }
    
    $limit = DOMAIN_LIMITS[$domain] ?? 5; // Default to 5 if domain not defined
    $totalUsed = $activeCount + $scheduledConflicts;
    
    return $totalUsed < $limit;
}

function generateChannelId($channelTitle) {
    // Generate a unique channel ID based on title
    return 'channel_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($channelTitle)) . '_' . mt_rand(1000, 9999);
}

function startRecording($channel, $duration = null, $scheduled = null) {
    $startTime = $scheduled ? $scheduled['start'] : null;
    $endTime = $scheduled ? $scheduled['end'] : null;
    
    if (!checkDomainLimits($channel['url'], $startTime, $endTime)) {
        return false; // Domain limit reached
    }
    
    $recordings = getRecordings();
    
    // Generate filename in requested format
    $timeStr = date('h:i:sA');
    $dateStr = date('d-m-Y');
    $cleanTitle = preg_replace('/[^a-zA-Z0-9]/', '_', $channel['title']);
    $filename = "{$cleanTitle}-{$timeStr}-{$dateStr}-" . mt_rand(1000, 9999) . ".mkv";
    $outputPath = RECORDINGS_DIR . $filename;
    
    // Generate unique channel ID for screen session
    $channelId = generateChannelId($channel['title']);
    
    // Build ffmpeg command with screen session and restart on failure
    $cmd = "screen -dmS '{$channelId}' bash -c '" .
           "while true; do " .
           "ffmpeg -i " . escapeshellarg($channel['url']) . 
           " -map_metadata -1 -c:v copy -c:a copy -map 0:a -map 0:v " . 
           escapeshellarg($outputPath) . " && break; " .
           "sleep 2; " .
           "done'";
    
    // Execute command
    shell_exec($cmd);
    
    // Get screen session PID
    $pid = trim(shell_exec("screen -ls | grep '{$channelId}' | head -1 | awk '{print $1}' | cut -d. -f1"));
    
    // Calculate end time if duration is set
    $endTimeCalc = null;
    if ($duration) {
        $endTimeCalc = date('Y-m-d H:i:s', time() + ($duration * 60));
    } elseif ($endTime) {
        $endTimeCalc = $endTime;
    }
    
    // Add to recordings log
    $recordings[] = [
        'id' => uniqid(),
        'pid' => $pid,
        'channel_id' => $channelId,
        'channel' => $channel['title'],
        'channel_url' => $channel['url'],
        'logo' => $channel['logo'],
        'start_time' => date('Y-m-d H:i:s'),
        'end_time' => $endTimeCalc,
        'output' => $outputPath,
        'status' => 'recording',
        'duration' => $duration,
        'scheduled' => $scheduled,
        'started_by' => $_SESSION['username']
    ];
    
    saveRecordings($recordings);
    return $pid;
}

function startScheduledRecording($recordingId) {
    $recordings = getRecordings();
    foreach ($recordings as &$rec) {
        if ($rec['id'] === $recordingId && $rec['status'] === 'scheduled') {
            // Check domain limits again before starting
            if (!checkDomainLimits($rec['channel_url'])) {
                $rec['status'] = 'failed';
                $rec['error'] = 'Domain limit exceeded';
                saveRecordings($recordings);
                return false;
            }
            
            // Start the recording
            $channel = [
                'title' => $rec['channel'],
                'url' => $rec['channel_url'],
                'logo' => $rec['logo']
            ];
            
            // Generate unique channel ID for screen session
            $channelId = generateChannelId($channel['title']);
            
            // Generate filename
            $timeStr = date('h:i:sA');
            $dateStr = date('d-m-Y');
            $cleanTitle = preg_replace('/[^a-zA-Z0-9]/', '_', $channel['title']);
            $filename = "{$cleanTitle}-{$timeStr}-{$dateStr}-" . mt_rand(1000, 9999) . ".mkv";
            $outputPath = RECORDINGS_DIR . $filename;
            
            // Build ffmpeg command with screen session
            $cmd = "screen -dmS '{$channelId}' bash -c '" .
                   "while true; do " .
                   "ffmpeg -i " . escapeshellarg($channel['url']) . 
                   " -map_metadata -1 -c:v copy -c:a copy -map 0:a -map 0:v " . 
                   escapeshellarg($outputPath) . " && break; " .
                   "sleep 2; " .
                   "done'";
            
            // Execute command
            shell_exec($cmd);
            
            // Get screen session PID
            $pid = trim(shell_exec("screen -ls | grep '{$channelId}' | head -1 | awk '{print $1}' | cut -d. -f1"));
            
            if ($pid) {
                // Update recording info
                $rec['pid'] = $pid;
                $rec['channel_id'] = $channelId;
                $rec['status'] = 'recording';
                $rec['start_time'] = date('Y-m-d H:i:s');
                $rec['output'] = $outputPath;
                $rec['started_by'] = $_SESSION['username'];
                saveRecordings($recordings);
                return true;
            }
            break;
        }
    }
    return false;
}

function stopRecording($id) {
    $recordings = getRecordings();
    foreach ($recordings as &$rec) {
        if ($rec['id'] === $id && ($rec['status'] === 'recording' || $rec['status'] === 'scheduled')) {
            // Kill screen session if it's running
            if ($rec['status'] === 'recording' && isset($rec['channel_id'])) {
                shell_exec("pkill -f '{$rec['channel_id']}' > /dev/null 2>&1");
                shell_exec("screen -S '{$rec['channel_id']}' -X quit > /dev/null 2>&1");
            }
            
            // Update recording info
            $rec['end_time'] = date('Y-m-d H:i:s');
            $rec['status'] = 'completed';
            break;
        }
    }
    
    saveRecordings($recordings);
}

function deleteRecording($id) {
    $recordings = getRecordings();
    foreach ($recordings as $key => $rec) {
        if ($rec['id'] === $id) {
            // Delete file if exists
            if (file_exists($rec['output'])) {
                unlink($rec['output']);
            }
            // Remove from recordings list
            unset($recordings[$key]);
            break;
        }
    }
    saveRecordings($recordings);
}

// Check and start scheduled recordings
function processScheduledRecordings() {
    $recordings = getRecordings();
    $now = time();
    $changed = false;
    
    foreach ($recordings as &$rec) {
        if ($rec['status'] === 'scheduled' && isset($rec['scheduled']['start'])) {
            $startTime = strtotime($rec['scheduled']['start']);
            
            if ($now >= $startTime) {
                // Start scheduled recording
                $rec['status'] = 'starting';
                saveRecordings($recordings);
                startScheduledRecording($rec['id']);
                $changed = true;
            }
        } elseif ($rec['status'] === 'recording' && $rec['end_time']) {
            $endTime = strtotime($rec['end_time']);
            if ($now >= $endTime) {
                // Stop recording based on duration
                stopRecording($rec['id']);
                $changed = true;
            }
        }
    }
    
    if ($changed) {
        saveRecordings($recordings);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'start_recording' && isset($_POST['channel_id'])) {
            $channels = parseTVFile();
            $channel = $channels[$_POST['channel_id']];
            
            $duration = null;
            $scheduled = null;
            $error = null;
            
            if ($_POST['recording_type'] === 'duration' && !empty($_POST['duration'])) {
                $duration = (int) $_POST['duration'];
                if (!checkDomainLimits($channel['url'])) {
                    $error = 'Domain limit reached. Cannot start recording.';
                } else {
                    startRecording($channel, $duration, $scheduled);
                }
            } elseif ($_POST['recording_type'] === 'scheduled' && 
                      !empty($_POST['start_time']) && !empty($_POST['end_time'])) {
                $scheduled = [
                    'start' => $_POST['start_time'],
                    'end' => $_POST['end_time']
                ];
                
                if (!checkDomainLimits($channel['url'], $scheduled['start'], $scheduled['end'])) {
                    $error = 'Domain limit reached or scheduling conflict. Cannot schedule recording.';
                } else {
                    // Add as scheduled recording
                    $recordings = getRecordings();
                    $recordings[] = [
                        'id' => uniqid(),
                        'pid' => null,
                        'channel_id' => null,
                        'channel' => $channel['title'],
                        'channel_url' => $channel['url'],
                        'logo' => $channel['logo'],
                        'start_time' => null,
                        'end_time' => $_POST['end_time'],
                        'output' => '',
                        'status' => 'scheduled',
                        'duration' => null,
                        'scheduled' => $scheduled,
                        'started_by' => $_SESSION['username']
                    ];
                    saveRecordings($recordings);
                }
            } else {
                // Start recording immediately
                if (!checkDomainLimits($channel['url'])) {
                    $error = 'Domain limit reached. Cannot start recording.';
                } else {
                    startRecording($channel, $duration, $scheduled);
                }
            }
            
            if ($error) {
                $_SESSION['error'] = $error;
            }
        } elseif ($_POST['action'] === 'stop_recording' && isset($_POST['recording_id'])) {
            stopRecording($_POST['recording_id']);
        } elseif ($_POST['action'] === 'delete_recording' && isset($_POST['recording_id'])) {
            deleteRecording($_POST['recording_id']);
        } elseif ($_POST['action'] === 'logout') {
            session_destroy();
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        }
    }
    // Redirect to avoid form resubmission
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Process scheduled recordings
processScheduledRecordings();

// Get data for display
$channels = parseTVFile();
$recordings = getRecordings();
$activeRecordings = array_filter($recordings, fn($r) => $r['status'] === 'recording');
$scheduledRecordings = array_filter($recordings, fn($r) => $r['status'] === 'scheduled' || $r['status'] === 'starting');
$completedRecordings = array_filter($recordings, fn($r) => $r['status'] === 'completed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV Channel Recorder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a2a6c, #b21f1f, #1a2a6c);
            color: #fff;
            min-height: 100vh;
            padding-bottom: 50px;
        }
        .card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            overflow: hidden;
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .channel-logo {
            max-height: 60px;
            max-width: 100%;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .recording-active {
            animation: pulse 2s infinite;
            position: relative;
        }
        .recording-active::before {
            content: "";
            position: absolute;
            top: 10px;
            left: 10px;
            width: 12px;
            height: 12px;
            background-color: #ff0000;
            border-radius: 50%;
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.3; }
            100% { opacity: 1; }
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 0, 0, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(255, 0, 0, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 0, 0, 0); }
        }
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        .btn-action {
            min-width: 100px;
        }
        .nav-tabs .nav-link {
            color: rgba(255, 255, 255, 0.7);
            border: none;
        }
        .nav-tabs .nav-link.active {
            color: #fff;
            background: transparent;
            border-bottom: 2px solid #fff;
        }
        .recording-item {
            border-left: 3px solid #28a745;
        }
        .scheduled-item {
            border-left: 3px solid #ffc107;
        }
        .completed-item {
            border-left: 3px solid #6c757d;
        }
        .user-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.8rem;
        }
        .domain-limits-info {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="display-6 fw-bold"><i class="fas fa-tv me-2"></i>IPTV Channel Recorder</h1>
                <p class="mb-0">Record your favorite TV channels</p>
            </div>
            <div class="text-end">
                <span class="badge bg-dark me-2">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($_SESSION['username']) ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </button>
                </form>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if ($_SESSION['username'] === 'Admin'): ?>
            <div class="domain-limits-info">
                <h5><i class="fas fa-shield-alt me-2"></i>Domain Limits (Admin Only)</h5>
                <div class="row">
                    <?php foreach (DOMAIN_LIMITS as $domain => $limit): ?>
                        <?php
                        $domainActiveCount = 0;
                        $domainScheduledCount = 0;
                        foreach ($recordings as $rec) {
                            $recDomain = parse_url($rec['channel_url'], PHP_URL_HOST);
                            if ($recDomain === $domain) {
                                if ($rec['status'] === 'recording') {
                                    $domainActiveCount++;
                                } elseif ($rec['status'] === 'scheduled') {
                                    $domainScheduledCount++;
                                }
                            }
                        }
                        ?>
                        <div class="col-md-4 mb-2">
                            <div class="d-flex justify-content-between">
                                <span><strong><?= $domain ?></strong></span>
                                <span>
                                    <span class="badge bg-danger"><?= $domainActiveCount ?></span> / 
                                    <span class="badge bg-warning"><?= $domainScheduledCount ?></span> / 
                                    <span class="badge bg-secondary"><?= $limit ?></span>
                                </span>
                            </div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-danger" style="width: <?= ($domainActiveCount / $limit) * 100 ?>%"></div>
                                <div class="progress-bar bg-warning" style="width: <?= ($domainScheduledCount / $limit) * 100 ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <span class="badge bg-danger">Active</span> / 
                    <span class="badge bg-warning">Scheduled</span> / 
                    <span class="badge bg-secondary">Limit</span>
                </small>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="card p-4">
                    <h3 class="mb-4"><i class="fas fa-satellite-dish me-2"></i>Select Channel to Record</h3>
                    
                    <form method="post" id="recordingForm">
                        <div class="mb-3">
                            <label class="form-label">Channel:</label>
                            <div class="input-group">
                                <select class="form-select" id="channelSelect" name="channel_id" required>
                                    <option value="" disabled selected>Select a channel</option>
                                    <?php foreach ($channels as $i => $channel): ?>
                                        <option value="<?= $i ?>" data-logo="<?= htmlspecialchars($channel['logo']) ?>">
                                            <?= htmlspecialchars($channel['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-light" type="button" id="refreshBtn">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3 text-center" id="logoContainer">
                            <img src="" class="channel-logo" id="channelLogo" style="display: none;">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Recording Type:</label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="recording_type" 
                                       id="typeNow" value="now" checked>
                                <label class="form-check-label" for="typeNow">
                                    Record Now (until stopped)
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="recording_type" 
                                       id="typeDuration" value="duration">
                                <label class="form-check-label" for="typeDuration">
                                    Record with Countdown
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recording_type" 
                                       id="typeScheduled" value="scheduled">
                                <label class="form-check-label" for="typeScheduled">
                                    Scheduled Recording
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="durationSection" style="display: none;">
                            <label class="form-label">Recording Duration:</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="duration" 
                                       min="1" max="360" placeholder="Duration in minutes">
                                <span class="input-group-text">minutes</span>
                            </div>
                        </div>
                        
                        <div class="row mb-3" id="scheduleSection" style="display: none;">
                            <div class="col-md-6">
                                <label class="form-label">Start Time:</label>
                                <input type="datetime-local" class="form-control" name="start_time">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time:</label>
                                <input type="datetime-local" class="form-control" name="end_time">
                            </div>
                        </div>
                        
                        <input type="hidden" name="action" value="start_recording">
                        <button type="submit" class="btn btn-danger btn-lg w-100">
                            <i class="fas fa-record-vinyl me-2"></i>Start Recording
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="card p-4">
                    <h3 class="mb-4"><i class="fas fa-wave-square me-2"></i>Recording Status</h3>
                    
                    <div class="d-flex justify-content-between mb-3">
                        <div>
                            <h4><span class="badge bg-danger">Active</span></h4>
                            <p class="mb-0"><?= count($activeRecordings) ?> recording(s)</p>
                        </div>
                        <div>
                            <h4><span class="badge bg-warning">Scheduled</span></h4>
                            <p class="mb-0"><?= count($scheduledRecordings) ?> recording(s)</p>
                        </div>
                        <div>
                            <h4><span class="badge bg-secondary">Completed</span></h4>
                            <p class="mb-0"><?= count($completedRecordings) ?> recording(s)</p>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5>System Status:</h5>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Disk Space:</span>
                            <span><?= round(disk_free_space('.') / (1024 * 1024 * 1024), 1) ?>GB free</span>
                        </div>
                        <div class="progress bg-dark">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?= (1 - (disk_free_space('.') / disk_total_space('.'))) * 100 ?>%">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5>Server Time:</h5>
                        <div class="display-6" id="serverTime">
                            <?= date('h:i:s A') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="active-tab" data-bs-toggle="tab" 
                        data-bs-target="#active" type="button" role="tab">
                    <i class="fas fa-circle text-danger me-2"></i>Active Recordings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="scheduled-tab" data-bs-toggle="tab" 
                        data-bs-target="#scheduled" type="button" role="tab">
                    <i class="fas fa-clock text-warning me-2"></i>Scheduled Recordings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="completed-tab" data-bs-toggle="tab" 
                        data-bs-target="#completed" type="button" role="tab">
                    <i class="fas fa-check-circle text-success me-2"></i>Completed Recordings
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="myTabContent">
            <div class="tab-pane fade show active" id="active" role="tabpanel">
                <?php if (count($activeRecordings) > 0): ?>
                    <div class="row">
                        <?php foreach ($activeRecordings as $rec): ?>
                            <?php
                            $start = strtotime($rec['start_time']);
                            $now = time();
                            $duration = $now - $start;
                            $durationText = gmdate('H\h i\m s\s', $duration);
                            
                            // Calculate progress if scheduled
                            $progress = 0;
                            $progressText = '';
                            $timeLeft = '';
                            if ($rec['end_time']) {
                                $end = strtotime($rec['end_time']);
                                $total = $end - $start;
                                $progress = min(100, max(0, round(($now - $start) / $total * 100)));
                                $progressText = "{$progress}% completed";
                                
                                if ($end > $now) {
                                    $remaining = $end - $now;
                                    $timeLeft = gmdate('H\h i\m s\s', $remaining) . ' remaining';
                                }
                            }
                            ?>
                            <div class="col-md-6 mb-4">
                                <div class="card recording-active p-3">
                                    <span class="badge bg-dark user-badge">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($rec['started_by']) ?>
                                    </span>
                                    <div class="d-flex">
                                        <div class="flex-shrink-0 me-3">
                                            <img src="<?= htmlspecialchars($rec['logo']) ?>" 
                                                 alt="Channel Logo" class="channel-logo">
                                        </div>
                                        <div class="flex-grow-1">
                                            <h5><?= htmlspecialchars($rec['channel']) ?></h5>
                                            <p class="mb-1">
                                                <i class="fas fa-clock me-2"></i>
                                                Started: <?= date('M d, H:i', $start) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-hourglass-half me-2"></i>
                                                Duration: <?= $durationText ?>
                                            </p>
                                            <?php if ($rec['end_time']): ?>
                                                <p class="mb-1">
                                                    <i class="fas fa-calendar me-2"></i>
                                                    Ends at: <?= date('M d, H:i', strtotime($rec['end_time'])) ?>
                                                </p>
                                                <?php if ($timeLeft): ?>
                                                    <p class="mb-1 text-warning">
                                                        <i class="fas fa-stopwatch me-2"></i>
                                                        <?= $timeLeft ?>
                                                    </p>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($progressText): ?>
                                                <p class="mb-1"><?= $progressText ?></p>
                                                <div class="progress mt-2">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                                         role="progressbar" style="width: <?= $progress ?>%">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" class="mt-3">
                                        <input type="hidden" name="action" value="stop_recording">
                                        <input type="hidden" name="recording_id" value="<?= $rec['id'] ?>">
                                        <button type="submit" class="btn btn-outline-light btn-sm w-100">
                                            <i class="fas fa-stop me-2"></i>Stop Recording
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i> No active recordings at the moment.
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tab-pane fade" id="scheduled" role="tabpanel">
                <?php if (count($scheduledRecordings) > 0): ?>
                    <div class="row">
                        <?php foreach ($scheduledRecordings as $rec): ?>
                            <?php
                            $startTime = strtotime($rec['scheduled']['start']);
                            $endTime = strtotime($rec['scheduled']['end']);
                            $now = time();
                            
                            if ($startTime > $now) {
                                $timeLeft = $startTime - $now;
                                $hours = floor($timeLeft / 3600);
                                $minutes = floor(($timeLeft % 3600) / 60);
                                $seconds = $timeLeft % 60;
                                $timeLeftText = "{$hours}h {$minutes}m {$seconds}s";
                            } else {
                                $timeLeftText = "Starting soon";
                            }
                            
                            $totalDuration = $endTime - $startTime;
                            $durationText = gmdate('H\h i\m s\s', $totalDuration);
                            ?>
                            <div class="col-md-6 mb-4">
                                <div class="card scheduled-item p-3">
                                    <span class="badge bg-dark user-badge">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($rec['started_by']) ?>
                                    </span>
                                    <div class="d-flex">
                                        <div class="flex-shrink-0 me-3">
                                            <img src="<?= htmlspecialchars($rec['logo']) ?>" 
                                                 alt="Channel Logo" class="channel-logo">
                                        </div>
                                        <div class="flex-grow-1">
                                            <h5><?= htmlspecialchars($rec['channel']) ?></h5>
                                            <p class="mb-1">
                                                <i class="fas fa-clock me-2"></i>
                                                Starts: <?= date('M d, H:i', $startTime) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-stopwatch me-2"></i>
                                                Ends: <?= date('M d, H:i', $endTime) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-hourglass-start me-2"></i>
                                                Duration: <?= $durationText ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-history me-2"></i>
                                                Starts in: <?= $timeLeftText ?>
                                            </p>
                                        </div>
                                    </div>
                                    <form method="post" class="mt-3">
                                        <input type="hidden" name="action" value="stop_recording">
                                        <input type="hidden" name="recording_id" value="<?= $rec['id'] ?>">
                                        <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                            <i class="fas fa-times me-2"></i>Cancel Recording
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i> No scheduled recordings.
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tab-pane fade" id="completed" role="tabpanel">
                <?php if (count($completedRecordings) > 0): ?>
                    <div class="list-group">
                        <?php foreach ($completedRecordings as $rec): ?>
                            <?php
                            $start = strtotime($rec['start_time']);
                            $end = strtotime($rec['end_time']);
                            $duration = $end - $start;
                            $durationText = gmdate('H\h i\m s\s', $duration);
                            $fileSize = file_exists($rec['output']) ? 
                                round(filesize($rec['output']) / (1024 * 1024), 1) . ' MB' : 'File not found';
                            ?>
                            <div class="list-group-item completed-item mb-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1"><?= htmlspecialchars($rec['channel']) ?></h5>
                                    <small><?= date('M d', $start) ?></small>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <img src="<?= htmlspecialchars($rec['logo']) ?>" 
                                         alt="Channel Logo" class="channel-logo me-2">
                                    <div>
                                        <p class="mb-1"><?= date('H:i', $start) ?> - <?= date('H:i', $end) ?></p>
                                        <p class="mb-1">Duration: <?= $durationText ?></p>
                                        <p class="mb-0">File: <?= basename($rec['output']) ?> (<?= $fileSize ?>)</p>
                                    </div>
                                </div>
                                <div class="d-flex">
                                    <a href="<?= $rec['output'] ?>" 
                                       class="btn btn-sm btn-success me-2" 
                                       download="<?= basename($rec['output']) ?>">
                                        <i class="fas fa-download me-1"></i>Download
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="delete_recording">
                                        <input type="hidden" name="recording_id" value="<?= $rec['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Are you sure you want to delete this recording?')">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i> No completed recordings yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update channel logo when selection changes
        document.getElementById('channelSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const logoUrl = selectedOption.getAttribute('data-logo');
            const logoImg = document.getElementById('channelLogo');
            
            if (logoUrl) {
                logoImg.src = logoUrl;
                logoImg.style.display = 'block';
            } else {
                logoImg.style.display = 'none';
            }
        });
        
        // Show/hide options based on recording type
        const recordingTypeRadios = document.querySelectorAll('input[name="recording_type"]');
        const durationSection = document.getElementById('durationSection');
        const scheduleSection = document.getElementById('scheduleSection');
        
        recordingTypeRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'duration') {
                    durationSection.style.display = 'block';
                    scheduleSection.style.display = 'none';
                } else if (radio.value === 'scheduled') {
                    durationSection.style.display = 'none';
                    scheduleSection.style.display = 'flex';
                } else {
                    durationSection.style.display = 'none';
                    scheduleSection.style.display = 'none';
                }
            });
        });
        
        // Update server time
        function updateServerTime() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', {
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true
            });
            document.getElementById('serverTime').textContent = timeStr;
        }
        setInterval(updateServerTime, 1000);
        
        // Auto-refresh page every 60 seconds
        setInterval(() => {
            window.location.reload();
        }, 60000);
        
        // Refresh button functionality
        document.getElementById('refreshBtn').addEventListener('click', () => {
            window.location.reload();
        });
        
        // Form validation for scheduled recordings
        document.getElementById('recordingForm').addEventListener('submit', function(e) {
            const recordingType = document.querySelector('input[name="recording_type"]:checked').value;
            
            if (recordingType === 'scheduled') {
                const startTime = document.querySelector('input[name="start_time"]').value;
                const endTime = document.querySelector('input[name="end_time"]').value;
                
                if (!startTime || !endTime) {
                    e.preventDefault();
                    alert('Please select both start and end times for scheduled recording.');
                    return;
                }
                
                const start = new Date(startTime);
                const end = new Date(endTime);
                const now = new Date();
                
                if (start <= now) {
                    e.preventDefault();
                    alert('Start time must be in the future.');
                    return;
                }
                
                if (end <= start) {
                    e.preventDefault();
                    alert('End time must be after start time.');
                    return;
                }
            } else if (recordingType === 'duration') {
                const duration = document.querySelector('input[name="duration"]').value;
                
                if (!duration || duration < 1) {
                    e.preventDefault();
                    alert('Please enter a valid duration in minutes.');
                    return;
                }
            }
        });
    </script>
</body>
</html>