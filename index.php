<?php
session_start();

// --- VERİ YÖNETİMİ ---
$dataFile = 'data.json';
$archiveDir = 'archives';

if (!is_dir($archiveDir)) mkdir($archiveDir, 0777, true);
if (!file_exists($dataFile)) {
    $initialData = ['employees' => [], 'records' => [], 'payments' => [], 'config' => ['telegram_token' => '', 'telegram_chat_id' => '']];
    file_put_contents($dataFile, json_encode($initialData, JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($dataFile), true);
if (!isset($data['payments'])) $data['payments'] = [];
if (!isset($data['config'])) $data['config'] = ['telegram_token' => '', 'telegram_chat_id' => ''];

// --- TELEGRAM MESAJ GÖNDERME (cURL - EN GÜVENLİ YÖNTEM) ---
function sendTelegramNotification($message, $config) {
    $token = $config['telegram_token'] ?? $config['token'] ?? '';
    $chat_id = $config['telegram_chat_id'] ?? $config['chatId'] ?? '';
    
    if (empty($token) || empty($chat_id)) return false;

    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // VDS/cPanel SSL sorunları için
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 && $result !== false);
}

// --- OTOMATİK ARŞİVLEME ---
function autoArchivePreviousMonth($data, $archiveDir) {
    $prevMonth = date('Y-m', strtotime('first day of last month'));
    $archiveFileName = $archiveDir . "/puantaj_otomatik_" . str_replace('-', '_', $prevMonth) . ".csv";
    if (!file_exists($archiveFileName)) {
        $monthRecords = array_filter($data['records'], fn($r) => isset($r['date']) && strpos($r['date'], $prevMonth) === 0);
        if (!empty($monthRecords)) {
            $output = fopen($archiveFileName, 'w');
            fputs($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Tarih', 'Personel', 'Giris', 'Cikis', 'Sure', 'Tutar'], ';');
            foreach ($monthRecords as $r) {
                $name = "Bilinmeyen";
                foreach($data['employees'] as $e) if($e['id']===$r['employeeId']) $name=$e['name'];
                fputcsv($output, [$r['date'], $name, $r['startTime'], $r['endTime'], $r['calculatedHours'], $r['totalEarning']], ';');
            }
            fclose($output);
        }
    }
}
if (isset($_SESSION['admin_auth'])) autoArchivePreviousMonth($data, $archiveDir);

// --- CSV EXPORT ---
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $month = $_GET['month'] ?? date('Y-m');
    $filename = "puantaj_" . str_replace('-', '_', $month) . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Tarih', 'Personel', 'Giris', 'Cikis', 'Sure', 'Tutar'], ';');
    foreach ($data['records'] as $r) {
        if (isset($r['date']) && strpos($r['date'], $month) === 0) {
            $name = "Bilinmeyen";
            foreach($data['employees'] as $e) if($e['id']===$r['employeeId']) $name=$e['name'];
            fputcsv($output, [$r['date'], $name, $r['startTime'], $r['endTime'], $r['calculatedHours'], $r['totalEarning']], ';');
        }
    }
    fclose($output); exit;
}

// --- POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Telegram Test Mesajı
    if ($action === 'test_telegram' && isset($_SESSION['admin_auth'])) {
        $testConfig = [
            'telegram_token' => $_POST['telegram_token'],
            'telegram_chat_id' => $_POST['telegram_chat_id']
        ];
        $msg = "<b>🔔 TEST MESAJI</b>\n\nPuantaj Pro PHP backend üzerinden cURL bağlantısı başarıyla test edildi!";
        $success = sendTelegramNotification($msg, $testConfig);
        header('Location: ?page=admin_settings&test_result=' . ($success ? '1' : '0')); exit;
    }

    // Sistem Ayarlarını Güncelle (Admin)
    if ($action === 'update_settings' && isset($_SESSION['admin_auth'])) {
        $data['config']['telegram_token'] = $_POST['telegram_token'];
        $data['config']['telegram_chat_id'] = $_POST['telegram_chat_id'];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_settings&success=1'); exit;
    }

    // Mesai Kaydı Düzenle (Admin)
    if ($action === 'edit_record' && isset($_SESSION['admin_auth'])) {
        $recId = $_POST['id'];
        $newStart = $_POST['startTime'];
        $newEnd = $_POST['endTime'];
        $newDate = $_POST['date'];
        
        foreach ($data['records'] as &$r) {
            if ($r['id'] === $recId) {
                $r['date'] = $newDate;
                $r['startTime'] = $newStart;
                $r['endTime'] = $newEnd;
                $hours = round((strtotime($newEnd) - strtotime($newStart)) / 3600, 2);
                if ($hours < 0) $hours += 24;
                $r['calculatedHours'] = $hours;
                
                $rate = 0;
                foreach($data['employees'] as $e) if($e['id'] === $r['employeeId']) $rate = $e['hourlyRate'];
                $r['totalEarning'] = round($hours * $rate, 2);
                break;
            }
        }
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_logs'); exit;
    }

    if ($action === 'make_payment' && isset($_SESSION['admin_auth'])) {
        $data['payments'][] = [
            'id' => uniqid(), 'employeeId' => $_POST['employeeId'], 'amount' => (float)$_POST['amount'], 
            'description' => $_POST['description'], 'method' => $_POST['method'], 'date' => date('Y-m-d H:i')
        ];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_dashboard'); exit;
    }

    if ($action === 'login') {
        if ($_POST['password'] === 'admin123') { $_SESSION['admin_auth'] = true; header('Location: ?page=admin_dashboard'); exit; }
        else $login_error = "Şifre hatalı!";
    }

    if ($action === 'logout') { session_destroy(); header('Location: index.php'); exit; }

    if ($action === 'add_record') {
        $newStart = $_POST['startTime']; $newEnd = $_POST['endTime']; $empId = $_POST['employeeId']; $date = $_POST['date'];
        foreach($data['records'] as $r) {
            if($r['employeeId'] === $empId && $r['date'] === $date) {
                if(($newStart < $r['endTime']) && ($newEnd > $r['startTime'])) {
                    echo "<script>alert('Hata: Belirtilen saatlerde zaten kaydınız var!'); window.history.back();</script>"; exit;
                }
            }
        }
        $hours = round((strtotime($newEnd) - strtotime($newStart)) / 3600, 2);
        if ($hours < 0) $hours += 24;
        
        $empName = "Bilinmeyen";
        $rate = 0; 
        foreach($data['employees'] as $e) {
            if($e['id']===$empId) {
                $rate=$e['hourlyRate'];
                $empName = $e['name'];
            }
        }

        $data['records'][] = [
            'id' => uniqid(), 
            'employeeId' => $empId, 
            'date' => $date, 
            'startTime' => $newStart, 
            'endTime' => $newEnd, 
            'calculatedHours' => $hours, 
            'totalEarning' => round($hours * $rate, 2)
        ];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        
        // Telegram Bildirimi
        $msg = "<b>✅ YENİ MESAİ KAYDI</b>\n\n";
        $msg .= "👤 <b>Personel:</b> {$empName}\n";
        $msg .= "📅 <b>Tarih:</b> " . date('d.m.Y', strtotime($date)) . "\n";
        $msg .= "⏰ <b>Saat:</b> {$newStart} - {$newEnd}\n";
        $msg .= "⏳ <b>Süre:</b> {$hours} Saat";
        sendTelegramNotification($msg, $data['config']);

        echo "<script>alert('Mesai Kaydedildi!'); window.location.href='index.php';</script>"; exit;
    }

    if ($action === 'add_employee' && isset($_SESSION['admin_auth'])) {
        $data['employees'][] = ['id' => uniqid(), 'name' => $_POST['name'], 'hourlyRate' => (int)$_POST['hourlyRate']];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_personnel'); exit;
    }

    if ($action === 'edit_employee' && isset($_SESSION['admin_auth'])) {
        foreach($data['employees'] as &$e) {
            if($e['id'] === $_POST['id']) {
                $e['name'] = $_POST['name'];
                $e['hourlyRate'] = (int)$_POST['hourlyRate'];
            }
        }
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_personnel'); exit;
    }

    if ($action === 'delete_employee' && isset($_SESSION['admin_auth'])) {
        $data['employees'] = array_values(array_filter($data['employees'], fn($e) => $e['id'] !== $_POST['id']));
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_personnel'); exit;
    }

    if ($action === 'delete_record' && isset($_SESSION['admin_auth'])) {
        $data['records'] = array_values(array_filter($data['records'], fn($r) => $r['id'] !== $_POST['id']));
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: ?page=admin_logs'); exit;
    }
}

$page = $_GET['page'] ?? 'landing';
$currentMonth = $_GET['month'] ?? date('Y-m');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Puantaj Pro VDS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.9); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
        .modal.active { display: flex; }
        .method-tile input:checked + div { border-color: #4f46e5; background-color: #eef2ff; color: #4338ca; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .nav-link.active { background-color: #4f46e5; color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 overflow-x-hidden">

<!-- ÖDEME MODALI -->
<div id="paymentModal" class="modal p-6">
    <div class="bg-white w-full max-w-lg p-10 rounded-[2.5rem] shadow-2xl relative">
        <div class="flex items-center justify-between mb-8">
            <h3 class="text-2xl font-extrabold tracking-tight text-slate-900">ÖDEME YAP</h3>
            <button onclick="closeModal('paymentModal')" class="p-2 hover:bg-slate-100 rounded-full transition-colors"><i data-lucide="x" class="w-6 h-6 text-slate-400"></i></button>
        </div>
        <form action="index.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="make_payment">
            <input type="hidden" name="employeeId" id="pModalEmpId">
            <div class="p-4 bg-indigo-50 border border-indigo-100 rounded-2xl">
                <p class="text-xs font-bold text-indigo-400 uppercase tracking-widest mb-1">ALICI PERSONEL</p>
                <p id="pModalEmpName" class="text-lg font-extrabold text-indigo-900 uppercase italic"></p>
            </div>
            <div class="space-y-3">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1">ÖDEME YÖNTEMİ</label>
                <div class="grid grid-cols-2 gap-4">
                    <label class="method-tile cursor-pointer group">
                        <input type="radio" name="method" value="Banka/EFT" class="hidden" checked>
                        <div class="p-5 border-2 border-slate-100 rounded-2xl text-center transition-all group-hover:border-indigo-200">
                            <i data-lucide="building-2" class="w-7 h-7 mx-auto mb-2"></i>
                            <span class="text-xs font-extrabold uppercase">BANKA / EFT</span>
                        </div>
                    </label>
                    <label class="method-tile cursor-pointer group">
                        <input type="radio" name="method" value="Elden" class="hidden">
                        <div class="p-5 border-2 border-slate-100 rounded-2xl text-center transition-all group-hover:border-indigo-200">
                            <i data-lucide="wallet" class="w-7 h-7 mx-auto mb-2"></i>
                            <span class="text-xs font-extrabold uppercase">ELDEN / NAKİT</span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1">TUTAR (₺)</label>
                <input type="number" step="0.01" name="amount" id="pModalAmount" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-black text-3xl text-indigo-600 transition-all" required>
            </div>
            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1">NOT / AÇIKLAMA</label>
                <textarea name="description" placeholder="Açıklama giriniz..." class="w-full p-4 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-semibold h-24 text-sm transition-all" required></textarea>
            </div>
            <button type="submit" class="w-full py-5 bg-indigo-600 text-white rounded-2xl font-extrabold uppercase tracking-widest text-sm shadow-xl shadow-indigo-200 hover:bg-indigo-700 hover:-translate-y-1 transition-all">ÖDEMEYİ KAYDET</button>
        </form>
    </div>
</div>

<!-- MESAI DÜZENLEME MODALI -->
<div id="editRecordModal" class="modal p-6">
    <div class="bg-white w-full max-w-lg p-10 rounded-[2.5rem] shadow-2xl relative">
        <div class="flex items-center justify-between mb-8">
            <h3 class="text-2xl font-extrabold tracking-tight text-slate-900 uppercase italic">MESAI DÜZENLE</h3>
            <button onclick="closeModal('editRecordModal')" class="p-2 hover:bg-slate-100 rounded-full transition-colors"><i data-lucide="x" class="w-6 h-6 text-slate-400"></i></button>
        </div>
        <form action="index.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="edit_record">
            <input type="hidden" name="id" id="editRecId">
            <div class="space-y-3">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">TARIH</label>
                <input type="date" name="date" id="editRecDate" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-lg transition-all" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">GIRIŞ</label>
                    <input type="time" name="startTime" id="editRecStart" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-lg transition-all" required>
                </div>
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">ÇIKIŞ</label>
                    <input type="time" name="endTime" id="editRecEnd" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-lg transition-all" required>
                </div>
            </div>
            <button type="submit" class="w-full py-5 bg-indigo-600 text-white rounded-2xl font-extrabold uppercase tracking-widest text-sm shadow-xl shadow-indigo-200 hover:bg-indigo-700 hover:-translate-y-1 transition-all">GÜNCELLE</button>
        </form>
    </div>
</div>

<!-- PERSONEL DÜZENLEME MODALI -->
<div id="editEmployeeModal" class="modal p-6">
    <div class="bg-white w-full max-w-lg p-10 rounded-[2.5rem] shadow-2xl relative">
        <div class="flex items-center justify-between mb-8">
            <h3 class="text-2xl font-extrabold tracking-tight text-slate-900 uppercase italic">PERSONEL DÜZENLE</h3>
            <button onclick="closeModal('editEmployeeModal')" class="p-2 hover:bg-slate-100 rounded-full transition-colors"><i data-lucide="x" class="w-6 h-6 text-slate-400"></i></button>
        </div>
        <form action="index.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="edit_employee">
            <input type="hidden" name="id" id="editEmpId">
            <div class="space-y-3">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">PERSONEL ADI</label>
                <input type="text" name="name" id="editEmpName" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-lg transition-all" required>
            </div>
            <div class="space-y-3">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">SAATLİK ÜCRET (₺)</label>
                <input type="number" name="hourlyRate" id="editEmpRate" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-lg transition-all" required>
            </div>
            <button type="submit" class="w-full py-5 bg-slate-950 text-white rounded-2xl font-extrabold uppercase tracking-widest text-sm shadow-xl shadow-slate-200 hover:bg-indigo-600 hover:-translate-y-1 transition-all">BİLGİLERİ GÜNCELLE</button>
        </form>
    </div>
</div>

<?php if ($page === 'landing'): ?>
    <div class="h-screen flex items-center justify-center bg-slate-950 text-white p-6">
        <div class="max-w-md w-full text-center space-y-12">
            <div class="space-y-6">
                <div class="w-24 h-24 bg-indigo-600 rounded-[2.5rem] mx-auto flex items-center justify-center shadow-2xl rotate-12"><i data-lucide="trending-up" class="w-12 h-12"></i></div>
                <div class="space-y-2">
                    <h1 class="text-5xl font-black italic tracking-tighter uppercase">PUANTAJ<span class="text-indigo-500">PRO</span></h1>
                    <p class="text-slate-400 font-bold uppercase tracking-[0.3em] text-[10px]">PROFESYONEL TAKİP SİSTEMİ</p>
                </div>
            </div>
            <div class="grid gap-5">
                <a href="?page=public_entry" class="group bg-white p-7 rounded-[2rem] flex items-center justify-between text-slate-950 shadow-2xl hover:bg-indigo-50 transition-all scale-100 hover:scale-[1.03]">
                    <div class="flex items-center space-x-5">
                        <div class="p-4 bg-indigo-100 text-indigo-600 rounded-2xl"><i data-lucide="user-check" class="w-7 h-7"></i></div>
                        <div class="text-left font-extrabold uppercase text-lg italic tracking-tight">MESAI GİRİŞİ</div>
                    </div>
                    <i data-lucide="chevron-right" class="w-6 h-6 text-slate-300 group-hover:text-indigo-600"></i>
                </a>
                <a href="?page=admin_login" class="group bg-slate-900 border border-slate-800 p-7 rounded-[2rem] flex items-center justify-between text-white hover:border-indigo-500 transition-all scale-100 hover:scale-[1.03]">
                    <div class="flex items-center space-x-5">
                        <div class="p-4 bg-slate-800 text-slate-400 rounded-2xl group-hover:bg-indigo-600 group-hover:text-white transition-colors"><i data-lucide="lock" class="w-7 h-7"></i></div>
                        <div class="text-left font-extrabold uppercase text-lg italic tracking-tight">YÖNETİCİ PANELİ</div>
                    </div>
                    <i data-lucide="shield-check" class="w-6 h-6 text-slate-700"></i>
                </a>
            </div>
        </div>
    </div>

<?php elseif ($page === 'public_entry'): ?>
    <div class="min-h-screen bg-slate-950 text-white p-6 flex flex-col items-center justify-center">
        <a href="index.php" class="mb-12 flex items-center space-x-3 text-slate-500 hover:text-white transition-colors group">
            <i data-lucide="arrow-left" class="w-5 h-5 group-hover:-translate-x-1 transition-transform"></i>
            <span class="font-extrabold text-xs uppercase tracking-[0.2em] italic">Anasayfaya Dön</span>
        </a>
        <div class="max-w-md w-full bg-slate-900 p-12 rounded-[3.5rem] border border-slate-800 shadow-2xl">
            <h2 class="text-4xl font-black italic uppercase tracking-tighter mb-12 text-center text-white">MESAI <span class="text-indigo-500">KAYDI</span></h2>
            <form action="index.php" method="POST" class="space-y-8">
                <input type="hidden" name="action" value="add_record">
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">PERSONEL SEÇİMİ</label>
                    <select name="employeeId" class="w-full p-5 bg-slate-950 border-2 border-slate-800 rounded-2xl outline-none font-bold text-lg text-white focus:border-indigo-600 transition-all" required>
                        <option value="">İsminizi Seçiniz...</option>
                        <?php foreach($data['employees'] as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">TARIH</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full p-5 bg-slate-950 border-2 border-slate-800 rounded-2xl outline-none font-bold text-lg text-white focus:border-indigo-600 transition-all" required>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">GIRIŞ</label>
                        <input type="time" name="startTime" value="08:00" class="w-full p-5 bg-slate-950 border-2 border-slate-800 rounded-2xl outline-none font-bold text-lg text-white focus:border-indigo-600 transition-all" required>
                    </div>
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-2">ÇIKIŞ</label>
                        <input type="time" name="endTime" value="17:00" class="w-full p-5 bg-slate-950 border-2 border-slate-800 rounded-2xl outline-none font-bold text-lg text-white focus:border-indigo-600 transition-all" required>
                    </div>
                </div>
                <button type="submit" class="w-full py-6 bg-indigo-600 text-white rounded-3xl font-black uppercase tracking-[0.2em] text-sm shadow-2xl shadow-indigo-900/40 hover:bg-indigo-700 hover:-translate-y-1 transition-all mt-6">KAYDI SİSTEME GÖNDER</button>
            </form>
        </div>
    </div>

<?php elseif ($page === 'admin_login'): ?>
    <div class="h-screen bg-slate-950 text-white flex items-center justify-center p-6 text-center">
        <div class="max-w-md w-full bg-slate-900 p-14 rounded-[3.5rem] border border-slate-800 shadow-2xl">
            <div class="w-20 h-20 bg-slate-950 border border-slate-800 rounded-3xl mx-auto flex items-center justify-center mb-8 shadow-inner"><i data-lucide="lock" class="w-10 h-10 text-indigo-500"></i></div>
            <h2 class="text-4xl font-black italic uppercase tracking-tighter mb-4 text-white">YÖNETİCİ <span class="text-indigo-500">GİRİŞİ</span></h2>
            <?php if(isset($login_error)): ?><div class="bg-rose-500/10 text-rose-500 p-4 rounded-xl text-xs font-bold mb-8 uppercase tracking-widest border border-rose-500/20"><?= $login_error ?></div><?php endif; ?>
            <form action="index.php?page=admin_login" method="POST" class="space-y-8 mt-10">
                <input type="hidden" name="action" value="login">
                <input type="password" name="password" placeholder="ŞİFREYİ GİRİN" class="w-full p-6 bg-slate-950 border-2 border-transparent focus:border-indigo-600 rounded-[2rem] outline-none font-black text-center text-3xl tracking-[0.5em] text-indigo-500 transition-all" autoFocus required>
                <button type="submit" class="w-full py-6 bg-indigo-600 text-white rounded-3xl font-black uppercase tracking-[0.2em] text-sm shadow-2xl shadow-indigo-900/40 hover:bg-indigo-700 transition-all">SİSTEME GİRİŞ YAP</button>
                <a href="index.php" class="block text-slate-600 hover:text-white transition-colors text-xs font-bold uppercase tracking-widest italic pt-4">Geri Dön</a>
            </form>
        </div>
    </div>

<?php elseif (strpos($page, 'admin_') === 0 && isset($_SESSION['admin_auth'])): ?>
    <div class="flex h-screen bg-slate-50 overflow-hidden">
        <aside class="w-80 bg-slate-900 text-white p-10 flex flex-col shadow-2xl shrink-0 z-40">
            <div class="mb-16 flex items-center space-x-4">
                <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg"><i data-lucide="shield-check" class="w-7 h-7"></i></div>
                <div class="leading-none">
                    <h1 class="text-2xl font-black italic tracking-tighter uppercase">ADMİN<span class="text-indigo-500">PRO</span></h1>
                    <p class="text-[8px] font-bold text-slate-500 uppercase tracking-widest mt-1">Kontrol Paneli</p>
                </div>
            </div>
            <nav class="flex-1 space-y-4">
                <a href="?page=admin_dashboard" class="nav-link flex items-center space-x-5 p-5 rounded-2xl transition-all font-bold uppercase text-xs tracking-widest <?= $page === 'admin_dashboard' ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i data-lucide="layout-dashboard" class="w-6 h-6"></i><span>ÖZET PANEL</span>
                </a>
                <a href="?page=admin_payments" class="nav-link flex items-center space-x-5 p-5 rounded-2xl transition-all font-bold uppercase text-xs tracking-widest <?= $page === 'admin_payments' ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i data-lucide="receipt" class="w-6 h-6 text-emerald-500"></i><span>ÖDEME GEÇMİŞİ</span>
                </a>
                <a href="?page=admin_logs" class="nav-link flex items-center space-x-5 p-5 rounded-2xl transition-all font-bold uppercase text-xs tracking-widest <?= $page === 'admin_logs' ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i data-lucide="history" class="w-6 h-6 text-indigo-500"></i><span>MESAI LOGLARI</span>
                </a>
                <a href="?page=admin_personnel" class="nav-link flex items-center space-x-5 p-5 rounded-2xl transition-all font-bold uppercase text-xs tracking-widest <?= $page === 'admin_personnel' ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i data-lucide="users" class="w-6 h-6"></i><span>PERSONEL LİSTESİ</span>
                </a>
                <a href="?page=admin_settings" class="nav-link flex items-center space-x-5 p-5 rounded-2xl transition-all font-bold uppercase text-xs tracking-widest <?= $page === 'admin_settings' ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i data-lucide="settings" class="w-6 h-6"></i><span>AYARLAR</span>
                </a>
            </nav>
            <form action="index.php" method="POST" class="mt-auto border-t border-slate-800 pt-8">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="w-full p-5 bg-rose-500/10 text-rose-500 font-extrabold uppercase text-[10px] tracking-widest hover:bg-rose-600 hover:text-white rounded-2xl transition-all">GÜVENLİ ÇIKIŞ</button>
            </form>
        </aside>

        <main class="flex-1 overflow-y-auto p-12 custom-scrollbar relative">
            <header class="mb-12 flex justify-between items-center bg-white p-6 rounded-[2.5rem] shadow-sm border border-slate-100">
                <div>
                    <h2 class="text-sm font-bold text-slate-400 uppercase tracking-[0.2em] italic">Aktif Görünüm</h2>
                    <p class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter"><?= str_replace('admin_', '', $page) ?></p>
                </div>
                <div class="flex items-center space-x-5">
                    <form action="index.php" method="GET" class="flex items-center space-x-3 bg-slate-100 p-2 rounded-2xl">
                        <input type="hidden" name="page" value="<?= $page ?>">
                        <input type="month" name="month" value="<?= $currentMonth ?>" onchange="this.form.submit()" class="bg-transparent px-4 py-2 rounded-xl text-xs font-black uppercase italic outline-none cursor-pointer">
                    </form>
                    <a href="?action=export_csv&month=<?= $currentMonth ?>" class="bg-slate-950 text-white px-8 py-4 rounded-2xl font-black text-xs uppercase flex items-center space-x-3 hover:bg-indigo-600 transition-all shadow-xl shadow-slate-200">
                        <i data-lucide="download" class="w-5 h-5"></i>
                        <span>CSV DIŞA AKTAR</span>
                    </a>
                </div>
            </header>

            <?php if($page === 'admin_dashboard'): ?>
                <!-- Özet Panel İçeriği (Mevcut Kod) -->
                <h2 class="text-4xl font-black italic tracking-tighter text-slate-900 uppercase mb-12">HAKEDİŞ <span class="text-indigo-600">DURUMU</span></h2>
                <div class="bg-white rounded-[3rem] shadow-xl border border-slate-100 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-950 text-white text-[10px] uppercase font-black tracking-widest">
                            <tr>
                                <th class="px-10 py-8">PERSONEL</th>
                                <th class="px-6 py-8">SAATLİK ÜCRET</th>
                                <th class="px-6 py-8">TOPLAM SAAT</th>
                                <th class="px-6 py-8 text-emerald-400">TOPLAM KAZANÇ</th>
                                <th class="px-6 py-8 text-rose-400">ÖDENEN</th>
                                <th class="px-6 py-8 text-indigo-400">KALAN BAKİYE</th>
                                <th class="px-10 py-8 text-center">İŞLEM</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($data['employees'] as $emp): 
                                $empRecs = array_filter($data['records'], fn($r) => $r['employeeId']===$emp['id']);
                                $empPays = array_filter($data['payments'], fn($p) => $p['employeeId']===$emp['id']);
                                $totalHours = array_sum(array_column($empRecs, 'calculatedHours'));
                                $totalEarn = array_sum(array_column($empRecs, 'totalEarning'));
                                $totalPaid = array_sum(array_column($empPays, 'amount'));
                                $balance = $totalEarn - $totalPaid;
                            ?>
                            <tr class="hover:bg-indigo-50/30 transition-colors group">
                                <td class="px-10 py-10 font-black text-xl italic uppercase text-slate-800"><?= htmlspecialchars($emp['name']) ?></td>
                                <td class="px-6 py-10 font-bold text-slate-400 text-lg italic">₺<?= number_format($emp['hourlyRate'], 0) ?></td>
                                <td class="px-6 py-10 font-black text-slate-900 text-xl italic"><?= number_format($totalHours, 2) ?> <span class="text-[10px] text-slate-300">sa</span></td>
                                <td class="px-6 py-10 font-bold text-emerald-600 text-xl">₺<?= number_format($totalEarn, 2) ?></td>
                                <td class="px-6 py-10 font-bold text-rose-500 text-xl">₺<?= number_format($totalPaid, 2) ?></td>
                                <td class="px-6 py-10 font-black text-indigo-600 text-3xl italic">₺<?= number_format($balance, 2) ?></td>
                                <td class="px-10 py-10 text-center">
                                    <button onclick="openPaymentModal('<?= $emp['id'] ?>', '<?= htmlspecialchars($emp['name'], ENT_QUOTES) ?>', <?= $balance ?>)" class="bg-indigo-600 text-white px-8 py-4 rounded-2xl font-black text-xs uppercase shadow-lg shadow-indigo-100 group-hover:-translate-y-1 transition-all">ÖDEME YAP</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif($page === 'admin_logs'): ?>
                <!-- Mesai Logları İçeriği (Mevcut Kod) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-12">
                    <div class="lg:col-span-3">
                        <h2 class="text-4xl font-black italic tracking-tighter text-slate-900 uppercase mb-12">MESAİ <span class="text-indigo-600">LOGLARI</span></h2>
                        <?php
                        $monthRecords = array_filter($data['records'], fn($r) => isset($r['date']) && strpos($r['date'], $currentMonth) === 0);
                        usort($monthRecords, fn($a, $b) => strcmp($b['date'], $a['date']));
                        $grouped = []; foreach($monthRecords as $r) { $grouped[$r['date']][] = $r; }
                        if(empty($grouped)): ?><div class="p-24 bg-white rounded-[3rem] text-center font-black italic text-slate-300 uppercase tracking-widest text-2xl border-4 border-dashed border-slate-100">KAYIT BULUNAMADI</div><?php endif;
                        foreach($grouped as $date => $recs): ?>
                            <div class="mb-12">
                                <div class="flex items-center space-x-4 mb-4 ml-6">
                                    <div class="p-2 bg-slate-200 text-slate-600 rounded-lg"><i data-lucide="calendar" class="w-4 h-4"></i></div>
                                    <h4 class="font-black text-slate-400 text-sm uppercase italic tracking-[0.2em]"><?= date('d F Y', strtotime($date)) ?></h4>
                                </div>
                                <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                                    <table class="w-full text-left">
                                        <tbody class="divide-y divide-slate-50">
                                            <?php foreach($recs as $r): 
                                                $name = "Bilinmeyen"; foreach($data['employees'] as $e) if($e['id']===$r['employeeId']) $name=$e['name']; ?>
                                                <tr class="hover:bg-slate-50 transition-colors">
                                                    <td class="px-10 py-8 font-black italic uppercase text-xl text-slate-800 w-1/3"><?= htmlspecialchars($name) ?></td>
                                                    <td class="px-10 py-8 text-sm font-black text-slate-400 italic"><?= $r['startTime'] ?> - <?= $r['endTime'] ?></td>
                                                    <td class="px-10 py-8 font-black text-indigo-600 text-lg italic"><?= $r['calculatedHours'] ?> SAAT</td>
                                                    <td class="px-10 py-8 text-right font-black text-xl italic text-slate-900">₺<?= number_format($r['totalEarning'], 2) ?></td>
                                                    <td class="px-10 py-8 text-center">
                                                        <div class="flex items-center justify-center space-x-2">
                                                            <button onclick="openEditRecordModal('<?= $r['id'] ?>', '<?= $r['date'] ?>', '<?= $r['startTime'] ?>', '<?= $r['endTime'] ?>')" class="p-2 bg-indigo-50 text-indigo-600 rounded-xl hover:bg-indigo-600 hover:text-white transition-all">
                                                                <i data-lucide="edit-2" class="w-4 h-4"></i>
                                                            </button>
                                                            <form action="index.php?page=admin_logs" method="POST" class="inline" onsubmit="return confirm('Silinsin mi?')">
                                                                <input type="hidden" name="action" value="delete_record"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                                <button class="text-slate-200 hover:text-rose-600 transition-colors"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif($page === 'admin_payments'): ?>
                <!-- Ödeme Geçmişi İçeriği (Mevcut Kod) -->
                <h2 class="text-4xl font-black italic tracking-tighter text-slate-900 uppercase mb-12">ÖDEME <span class="text-emerald-600">GEÇMİŞİ</span></h2>
                <div class="bg-white rounded-[3.5rem] shadow-2xl border border-slate-100 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-emerald-600 text-white text-[11px] uppercase font-black tracking-[0.2em]">
                            <tr>
                                <th class="px-12 py-10">TARİH / SAAT</th>
                                <th class="px-12 py-10">PERSONEL</th>
                                <th class="px-12 py-10">YÖNTEM</th>
                                <th class="px-12 py-10">AÇIKLAMA</th>
                                <th class="px-12 py-10 text-right">TUTAR</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if(empty($data['payments'])): ?><tr><td colspan="5" class="p-24 text-center font-black italic text-slate-200 uppercase text-3xl">ÖDEME BULUNAMADI</td></tr><?php endif; ?>
                            <?php foreach(array_reverse($data['payments']) as $p): 
                                $name = "Bilinmeyen"; foreach($data['employees'] as $e) if($e['id']===$p['employeeId']) $name=$e['name']; ?>
                                <tr class="hover:bg-emerald-50/20 transition-colors">
                                    <td class="px-12 py-10 text-xs font-bold text-slate-400"><?= date('d.m.Y H:i', strtotime($p['date'])) ?></td>
                                    <td class="px-12 py-10 font-black italic uppercase text-xl text-slate-800"><?= htmlspecialchars($name) ?></td>
                                    <td class="px-12 py-10">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-2 h-2 rounded-full <?= ($p['method']??'') === 'Elden' ? 'bg-orange-500' : 'bg-indigo-500' ?>"></div>
                                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-600"><?= htmlspecialchars($p['method'] ?? 'Banka/EFT') ?></span>
                                        </div>
                                    </td>
                                    <td class="px-12 py-10 text-sm text-slate-500 italic font-medium"><?= htmlspecialchars($p['description']) ?></td>
                                    <td class="px-12 py-10 text-right font-black text-emerald-600 text-2xl italic">₺<?= number_format($p['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif($page === 'admin_personnel'): ?>
                <!-- Personel Yönetimi İçeriği (Mevcut Kod) -->
                <div class="flex items-center justify-between mb-12">
                    <h2 class="text-4xl font-black italic tracking-tighter text-slate-900 uppercase">PERSONEL <span class="text-indigo-600">YÖNETİMİ</span></h2>
                    <button onclick="document.getElementById('addPersonnelForm').classList.toggle('hidden')" class="bg-slate-950 text-white px-8 py-4 rounded-2xl font-black text-xs uppercase shadow-xl shadow-slate-200 hover:bg-indigo-600 transition-all">+ YENİ PERSONEL</button>
                </div>
                <div id="addPersonnelForm" class="hidden bg-white p-8 rounded-[2.5rem] border border-slate-100 mb-12 shadow-sm animate-in fade-in slide-in-from-top-4">
                    <form action="index.php?page=admin_personnel" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                        <input type="hidden" name="action" value="add_employee">
                        <div class="space-y-2">
                            <label class="text-[10px] font-bold uppercase text-slate-400 tracking-widest ml-2">Tam Adı</label>
                            <input type="text" name="name" placeholder="Ad Soyad..." class="w-full p-4 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-xl outline-none font-bold" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-bold uppercase text-slate-400 tracking-widest ml-2">Saatlik Ücret</label>
                            <input type="number" name="hourlyRate" placeholder="Örn: 150" class="w-full p-4 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-xl outline-none font-bold" required>
                        </div>
                        <button type="submit" class="py-4 bg-indigo-600 text-white rounded-xl font-black uppercase text-xs tracking-widest shadow-lg hover:bg-indigo-700 transition-all">EKLE</button>
                    </form>
                </div>
                <div class="bg-white rounded-[3.5rem] border border-slate-100 overflow-hidden shadow-2xl">
                    <table class="w-full text-left">
                        <thead class="bg-slate-950 text-white text-[11px] uppercase font-black tracking-widest">
                            <tr>
                                <th class="px-12 py-8">AD SOYAD</th>
                                <th class="px-12 py-8">SAATLİK ÜCRET</th>
                                <th class="px-12 py-8 text-center">İŞLEMLER</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($data['employees'] as $emp): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-12 py-10 font-black italic uppercase text-2xl text-slate-800"><?= htmlspecialchars($emp['name']) ?></td>
                                <td class="px-12 py-10 font-black text-indigo-600 text-2xl italic tracking-tighter">₺<?= number_format($emp['hourlyRate'], 0) ?> <span class="text-slate-300 text-xs uppercase ml-1">/ saat</span></td>
                                <td class="px-12 py-10 text-center">
                                    <div class="flex items-center justify-center space-x-3">
                                        <button onclick="openEditEmployeeModal('<?= $emp['id'] ?>', '<?= htmlspecialchars($emp['name'], ENT_QUOTES) ?>', <?= $emp['hourlyRate'] ?>)" class="p-3 bg-indigo-50 text-indigo-600 rounded-xl hover:bg-indigo-600 hover:text-white transition-all shadow-sm"><i data-lucide="edit-3" class="w-5 h-5"></i></button>
                                        <form action="index.php?page=admin_personnel" method="POST" onsubmit="return confirm('Personeli silmek istediğinize emin misiniz?')" class="inline">
                                            <input type="hidden" name="action" value="delete_employee"><input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="p-3 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-500 hover:text-white transition-all shadow-sm"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif($page === 'admin_settings'): ?>
                <h2 class="text-4xl font-black italic tracking-tighter text-slate-900 uppercase mb-12">SİSTEM <span class="text-indigo-600">AYARLARI</span></h2>
                <div class="max-w-2xl bg-white p-12 rounded-[3.5rem] shadow-2xl border border-slate-100">
                    <form id="settingsForm" action="index.php" method="POST" class="space-y-8">
                        <input type="hidden" name="action" id="settingsAction" value="update_settings">
                        
                        <?php if(isset($_GET['success'])): ?><div class="bg-emerald-100 text-emerald-700 p-4 rounded-xl text-xs font-bold mb-4 uppercase tracking-widest border border-emerald-200 text-center">Ayarlar Kaydedildi!</div><?php endif; ?>
                        <?php if(isset($_GET['test_result'])): ?>
                            <?php if($_GET['test_result'] === '1'): ?>
                                <div class="bg-emerald-100 text-emerald-700 p-4 rounded-xl text-xs font-bold mb-4 uppercase tracking-widest border border-emerald-200 text-center">Test mesajı başarıyla gönderildi!</div>
                            <?php else: ?>
                                <div class="bg-rose-100 text-rose-700 p-4 rounded-xl text-xs font-bold mb-4 uppercase tracking-widest border border-rose-200 text-center">Test mesajı gönderilemedi! Lütfen bilgileri kontrol edin.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="space-y-3">
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Telegram Bot Token</label>
                            <input type="text" name="telegram_token" value="<?= htmlspecialchars($data['config']['telegram_token'] ?? '') ?>" placeholder="Örn: 123456:ABC-DEF..." class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold transition-all">
                        </div>
                        
                        <div class="space-y-3">
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Telegram Chat ID</label>
                            <input type="text" name="telegram_chat_id" value="<?= htmlspecialchars($data['config']['telegram_chat_id'] ?? '') ?>" placeholder="Örn: 987654321" class="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold transition-all">
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <button type="button" onclick="document.getElementById('settingsAction').value='test_telegram'; document.getElementById('settingsForm').submit();" class="w-full py-6 bg-slate-100 text-slate-600 rounded-[2rem] font-black uppercase tracking-widest text-sm shadow-md hover:bg-slate-200 transition-all flex items-center justify-center space-x-3 group">
                                <i data-lucide="send" class="w-5 h-5 group-hover:translate-x-1 group-hover:-translate-y-1 transition-transform"></i>
                                <span>TEST MESAJI GÖNDER</span>
                            </button>
                            <button type="submit" onclick="document.getElementById('settingsAction').value='update_settings';" class="w-full py-6 bg-slate-950 text-white rounded-[2rem] font-black uppercase tracking-widest text-sm shadow-xl hover:bg-indigo-600 transition-all flex items-center justify-center space-x-3 group">
                                <i data-lucide="save" class="w-5 h-5 group-hover:scale-110 transition-transform"></i>
                                <span>AYARLARI GÜNCELLE</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>

<script>
    lucide.createIcons();
    function openPaymentModal(id, name, balance) { 
        document.getElementById('pModalEmpId').value = id; 
        document.getElementById('pModalEmpName').innerText = name; 
        document.getElementById('pModalAmount').value = Math.max(0, balance).toFixed(2); 
        document.getElementById('paymentModal').classList.add('active'); 
    }
    function openEditEmployeeModal(id, name, rate) {
        document.getElementById('editEmpId').value = id;
        document.getElementById('editEmpName').value = name;
        document.getElementById('editEmpRate').value = rate;
        document.getElementById('editEmployeeModal').classList.add('active');
    }
    function openEditRecordModal(id, date, start, end) {
        document.getElementById('editRecId').value = id;
        document.getElementById('editRecDate').value = date;
        document.getElementById('editRecStart').value = start;
        document.getElementById('editRecEnd').value = end;
        document.getElementById('editRecordModal').classList.add('active');
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>
</body>
</html>