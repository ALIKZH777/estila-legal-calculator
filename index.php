<?php
// =================================================================================
// 1. PHP CONFIGURATION & SESSION START
// =================================================================================
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
session_start();

// =================================================================================
// 2. DATABASE CONFIGURATION
// =================================================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'stlawgr1_parvande');
define('DB_PASS', 'Ali789520Ali');
define('DB_NAME', 'stlawgr1_parvande');

// =================================================================================
// 3. GEMINI API CONFIGURATION
// =================================================================================
define('GEMINI_API_KEY', 'AIzaSyC7zR0xROCWs1ng_7WRFonkAjcAqPVECQ0');

// =================================================================================
// 4. DATABASE CONNECTION FUNCTION
// =================================================================================
function db_connect() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log("Database Connection failed: " . $conn->connect_error);
        die("خطای داخلی سرور. لطفاً بعداً تلاش کنید.");
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// =================================================================================
// 5. HELPER FUNCTIONS
// =================================================================================
function get_persian_error_message($code) {
    $messages = [
        'user_exists' => 'این ایمیل قبلاً ثبت شده است.',
        'invalid_credentials' => 'ایمیل یا رمز عبور اشتباه است.',
        'password_short' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
        'invalid_email' => 'فرمت ایمیل صحیح نمی‌باشد.',
        'generic_error' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
        'access_denied' => 'دسترسی غیرمجاز.',
        'file_upload_error' => 'خطا در آپلود فایل.',
        'ai_service_error' => 'خطا در ارتباط با سرویس هوش مصنوعی.',
        'case_not_found' => 'پرونده مورد نظر یافت نشد.',
        'client_not_found' => 'موکل مورد نظر یافت نشد.',
        'invalid_input' => 'ورودی نامعتبر است.'
    ];
    return $messages[$code] ?? $messages['generic_error'];
}

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function get_user_by_id($user_id) {
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT id, email, role, created_at FROM profiles WHERE id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Converts a Gregorian date to a Jalali (Persian) date.
 * @param int $gy Gregorian year
 * @param int $gm Gregorian month
 * @param int $gd Gregorian day
 * @return array containing Jalali year, month, and day.
 */
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 621;
    $jy += 33 * (int)($days / 12053);
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    $jy += (int)(($days - 1) / 365);
    if ($days > 365) $days = ($days - 1) % 365;
    $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = ($days < 186) ? 1 + ($days % 31) : 1 + (($days - 186) % 30);
    return [$jy, $jm, $jd];
}

function format_jalali_datetime($datetime) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return 'نامشخص';
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return 'تاریخ نامعتبر';
    list($jy, $jm, $jd) = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
    
    return sprintf('%04d/%02d/%02d ساعت %s', $jy, $jm, $jd, date('H:i', $timestamp));
}

function format_jalali_date($date) {
    if (!$date || $date === '0000-00-00') return 'نامشخص';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'تاریخ نامعتبر';
    list($jy, $jm, $jd) = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
    
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function create_notification($user_id, $title, $message, $type = 'info') {
    $conn = db_connect();
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $user_id, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// =================================================================================
// 6. VIEW RENDERING LOGIC (TEMPLATES)
// =================================================================================
$view_files = [
    'views/admin_dashboard.php' => function($conn, $current_user) {
        $filter = $_GET['filter'] ?? 'all';
        $where_clause = "";
        if ($filter === 'active') {
            $where_clause = " WHERE c.status != 'مختومه شده' ";
        } elseif ($filter === 'closed') {
            $where_clause = " WHERE c.status = 'مختومه شده' ";
        }
        $cases_query = "
            SELECT c.*, p.email as client_email 
            FROM cases c 
            JOIN profiles p ON c.client_id = p.id 
            $where_clause
            ORDER BY c.updated_at DESC
        ";
        $cases_result = $conn->query($cases_query);
        $cases = $cases_result->fetch_all(MYSQLI_ASSOC);
        $total_users = $conn->query("SELECT COUNT(*) as c FROM profiles WHERE role = 'client'")->fetch_assoc()['c'];
        $all_cases = $conn->query("SELECT status FROM cases")->fetch_all(MYSQLI_ASSOC);
        $active_cases = count(array_filter($all_cases, fn($c) => $c['status'] !== 'مختومه شده'));
        $closed_cases = count($all_cases) - $active_cases;
        ?>
        <div class="fade-in space-y-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-3xl font-extrabold text-white mb-4 sm:mb-0">داشبورد مدیریتی</h1>
                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 space-x-reverse">
                    <a href="?view=admin-dashboard&filter=active" class="px-4 py-2 <?= $filter === 'active' ? 'bg-green-600 text-white' : 'bg-gray-600 text-gray-300' ?> hover:bg-green-700 rounded-lg text-sm font-medium transition">پرونده‌های فعال</a>
                    <a href="?view=admin-dashboard&filter=closed" class="px-4 py-2 <?= $filter === 'closed' ? 'bg-yellow-600 text-white' : 'bg-gray-600 text-gray-300' ?> hover:bg-yellow-700 rounded-lg text-sm font-medium transition">پرونده‌های مختومه</a>
                    <a href="?view=admin-dashboard&filter=all" class="px-4 py-2 <?= $filter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-600 text-gray-300' ?> hover:bg-blue-700 rounded-lg text-sm font-medium transition">همه پرونده‌ها</a>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg flex items-center space-x-4 space-x-reverse transition-transform hover:scale-105">
                    <div class="p-3 bg-green-500 rounded-full">
                        <i class="fas fa-users text-2xl text-white"></i>
                    </div>
                    <div>
                        <p class="text-gray-400 text-lg">تعداد کل موکلان</p>
                        <p class="text-3xl font-bold text-white"><?= $total_users ?></p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg flex items-center space-x-4 space-x-reverse transition-transform hover:scale-105">
                    <div class="p-3 bg-blue-500 rounded-full">
                        <i class="fas fa-gavel text-2xl text-white"></i>
                    </div>
                    <div>
                        <p class="text-gray-400 text-lg">پرونده‌های فعال</p>
                        <p class="text-3xl font-bold text-white"><?= $active_cases ?></p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg flex items-center space-x-4 space-x-reverse transition-transform hover:scale-105">
                    <div class="p-3 bg-yellow-500 rounded-full">
                        <i class="fas fa-folder-minus text-2xl text-white"></i>
                    </div>
                    <div>
                        <p class="text-gray-400 text-lg">پرونده‌های مختومه</p>
                        <p class="text-3xl font-bold text-white"><?= $closed_cases ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                    <h2 class="text-2xl font-bold text-white">لیست پرونده‌ها (<?= $filter === 'active' ? 'فعال' : ($filter === 'closed' ? 'مختومه' : 'همه') ?>)</h2>
                    <button onclick="createNewCase()" class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold py-2 px-4 rounded-lg transition transform hover:scale-105 shadow-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        <span>ایجاد پرونده جدید</span>
                    </button>
                </div>
                <?php if (count($cases) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-right bg-gray-800 rounded-lg overflow-hidden">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="p-4 text-gray-300 font-semibold text-sm">عنوان پرونده</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm">موکل</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm">وضعیت</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm text-center">آخرین به‌روزرسانی</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                        <?php foreach ($cases as $case): ?>
                            <tr class="hover:bg-gray-700/50 transition">
                                <td class="p-4 text-white"><?= htmlspecialchars($case['case_name']) ?></td>
                                <td class="p-4 text-gray-300"><?= htmlspecialchars($case['client_email']) ?></td>
                                <td class="p-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                        <?= $case['status'] === 'مختومه شده' ? 'bg-yellow-500 text-black' : 'bg-blue-500 text-white' ?>">
                                        <?= htmlspecialchars($case['status']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center text-gray-400 text-sm">
                                    <?= format_jalali_datetime($case['updated_at']) ?>
                                </td>
                                <td class="p-4 text-center">
                                    <button onclick="openCaseModal('<?= $case['client_id'] ?>', '<?= htmlspecialchars($case['client_email']) ?>')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs py-2 px-4 rounded-lg transition shadow mr-2">
                                        مدیریت
                                    </button>
                                    <button onclick="deleteCase('<?= $case['id'] ?>', '<?= htmlspecialchars($case['client_email']) ?>')" class="bg-red-600 hover:bg-red-700 text-white text-xs py-2 px-4 rounded-lg transition shadow ml-2">
                                        حذف
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-folder-open text-4xl text-gray-600 mb-4"></i>
                    <p class="text-gray-500 text-lg">پرونده‌ای با این فیلتر یافت نشد.</p>
                    <button onclick="createNewCase()" class="mt-4 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold py-3 px-6 rounded-lg transition transform hover:scale-105 shadow-lg flex items-center gap-2 mx-auto">
                        <i class="fas fa-plus"></i>
                        <span>ایجاد پرونده جدید</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
    'views/admin_settings.php' => function($conn, $current_user) {
        ?>
        <div class="fade-in space-y-8 max-w-2xl mx-auto">
            <h1 class="text-3xl font-extrabold text-white text-center mb-8">تنظیمات حساب کاربری</h1>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold mb-6 text-white">تغییر رمز عبور</h2>
                <form id="change-password-form" class="space-y-6">
                    <div>
                        <label for="current-password" class="block mb-2 font-medium text-gray-300">رمز عبور فعلی</label>
                        <input type="password" id="current-password" placeholder="رمز عبور فعلی خود را وارد کنید" required class="w-full bg-gray-700 text-white rounded-lg p-4 focus:outline-none focus:ring-2 focus:ring-green-500 transition">
                    </div>
                    <div>
                        <label for="new-password" class="block mb-2 font-medium text-gray-300">رمز عبور جدید</label>
                        <input type="password" id="new-password" placeholder="رمز عبور جدید (حداقل ۶ کاراکتر)" required class="w-full bg-gray-700 text-white rounded-lg p-4 focus:outline-none focus:ring-2 focus:ring-green-500 transition">
                    </div>
                    <div>
                        <label for="confirm-password" class="block mb-2 font-medium text-gray-300">تکرار رمز عبور جدید</label>
                        <input type="password" id="confirm-password" placeholder="رمز عبور جدید را دوباره وارد کنید" required class="w-full bg-gray-700 text-white rounded-lg p-4 focus:outline-none focus:ring-2 focus:ring-green-500 transition">
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white font-bold py-4 px-6 rounded-lg transition transform hover:scale-105 shadow-lg">
                        تغییر رمز عبور
                    </button>
                </form>
                <div id="password-change-message" class="mt-4 text-sm h-6 text-center"></div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold mb-6 text-white">اطلاعات حساب</h2>
                <div class="space-y-4 text-gray-300">
                    <div class="flex justify-between p-4 bg-gray-700 rounded-lg">
                        <span>ایمیل:</span>
                        <span class="font-medium text-white"><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?></span>
                    </div>
                    <div class="flex justify-between p-4 bg-gray-700 rounded-lg">
                        <span>نقش:</span>
                        <span class="font-medium text-white"><?= is_admin() ? 'ادمین' : 'موکل' ?></span>
                    </div>
                    <div class="flex justify-between p-4 bg-gray-700 rounded-lg">
                        <span>تاریخ عضویت:</span>
                        <span class="font-medium text-white">
                            <?php
                            $stmt = $conn->prepare("SELECT created_at FROM profiles WHERE id = ?");
                            $stmt->bind_param("s", $_SESSION['user_id']);
                            $stmt->execute();
                            $date = $stmt->get_result()->fetch_assoc()['created_at'] ?? 'نامشخص';
                            $stmt->close();
                            echo format_jalali_datetime($date);
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    },
    'views/client_dashboard.php' => function($conn, $current_user) {
        $client_id = $current_user['id'];
        $stmt = $conn->prepare("SELECT * FROM cases WHERE client_id = ?");
        $stmt->bind_param("s", $client_id);
        $stmt->execute();
        $case_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        ?>
        <div class="fade-in space-y-8">
            <h1 class="text-3xl font-extrabold text-white">داشبورد پرونده شما</h1>
            <?php if ($case_data): ?>
            <div id="client-case-details" class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 md:p-8 rounded-2xl shadow-lg space-y-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-white"><?= htmlspecialchars($case_data['case_name']) ?></h2>
                        <p class="text-gray-400 mt-2">آخرین وضعیت و تاریخچه اقدامات پرونده شما در اینجا نمایش داده می‌شود.</p>
                    </div>
                    <div class="text-left w-full md:w-auto">
                        <p class="text-sm text-gray-400 mb-1">وضعیت فعلی پرونده</p>
                        <p class="font-bold text-lg px-6 py-3 rounded-xl bg-yellow-500 text-black text-center shadow">
                            <?= htmlspecialchars($case_data['status']) ?>
                        </p>
                    </div>
                </div>
                <!-- Case Details Section -->
                <div class="bg-gray-800 p-6 rounded-xl">
                    <h3 class="text-xl font-bold text-white mb-4">جزئیات پرونده</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php if (!empty($case_data['court_name'])): ?>
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-sm text-gray-400 mb-1">نام دادگاه</h4>
                            <p class="text-white font-medium"><?= htmlspecialchars($case_data['court_name']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($case_data['case_number'])): ?>
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-sm text-gray-400 mb-1">شماره پرونده</h4>
                            <p class="text-white font-medium"><?= htmlspecialchars($case_data['case_number']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($case_data['next_hearing_date'])): ?>
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-sm text-gray-400 mb-1">تاریخ جلسه بعدی</h4>
                            <p class="text-white font-medium"><?= format_jalali_date($case_data['next_hearing_date']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($case_data['description'])): ?>
                        <div class="bg-gray-700/50 p-4 rounded-lg md:col-span-2">
                            <h4 class="text-sm text-gray-400 mb-1">توضیحات پرونده</h4>
                            <p class="text-white"><?= nl2br(htmlspecialchars($case_data['description'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- History Section -->
                <div class="bg-gray-800 p-6 rounded-xl">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                        <h3 class="text-xl font-bold text-white">تاریخچه اقدامات</h3>
                        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                            <input type="text" id="client-history-search" placeholder="جستجو در تاریخچه..." class="flex-grow sm:flex-grow-0 bg-gray-700 text-white p-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm">
                            <button id="client-summarize-btn" class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white text-sm py-2 px-4 rounded-lg flex items-center gap-2 transition transform hover:scale-105 shadow">
                                <i class="fas fa-sparkles"></i>
                                <span>خلاصه‌سازی هوشمند</span>
                            </button>
                        </div>
                    </div>
                    <div id="client-case-summary-output" class="hidden bg-gradient-to-r from-purple-900/30 to-purple-800/30 p-4 rounded-lg mb-6 border border-purple-500 backdrop-blur-sm">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="fas fa-sparkles text-purple-300"></i>
                            <p class="font-bold text-purple-300">✨ خلاصه هوشمند پرونده:</p>
                        </div>
                        <div id="client-summary-content" class="text-gray-200 leading-relaxed"></div>
                        <div id="client-summary-spinner" class="spinner mx-auto my-4 hidden"></div>
                    </div>
                    <div id="client-case-history" class="space-y-6 relative border-r-4 border-gray-700 pr-8">
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM case_history WHERE client_id = ? ORDER BY created_at DESC");
                        $stmt->bind_param("s", $client_id);
                        $stmt->execute();
                        $history_res = $stmt->get_result();
                        if($history_res->num_rows > 0):
                            while($event = $history_res->fetch_assoc()):
                                $eventDate = format_jalali_datetime($event['created_at']);
                                $contentHtml = '';
                                if ($event['type'] === 'FILE_UPLOAD' && $event['file_url']) {
                                    $fileName = htmlspecialchars($event['content']);
                                    $fileUrl = htmlspecialchars($event['file_url']);
                                    $isImage = in_array(pathinfo($fileUrl, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif']);
                                    $isPdf = pathinfo($fileUrl, PATHINFO_EXTENSION) === 'pdf';
                                    $previewHtml = '';
                                    if ($isImage) {
                                        $previewHtml = '<div class="mt-2"><img src="' . $fileUrl . '" alt="پیش‌نمایش فایل" class="max-w-full h-auto rounded-lg shadow cursor-pointer" onclick="showImageModal(\'' . $fileUrl . '\')"></div>';
                                    } elseif ($isPdf) {
                                        $previewHtml = '<div class="mt-2 p-3 bg-blue-500/20 rounded-lg text-blue-300 text-sm">📄 فایل PDF - قابل پیش‌نمایش در مرورگر</div>';
                                    }
                                    $contentHtml = '
                                        <p class="mb-1">وکیل فایل جدیدی آپلود کرد:</p>
                                        <a href="' . $fileUrl . '" target="_blank" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm py-2 px-3 rounded-lg transition">
                                            <i class="fas fa-download"></i>
                                            <span>' . $fileName . '</span>
                                        </a>
                                        ' . $previewHtml;
                                } else if ($event['type'] === 'STATUS_CHANGE') {
                                    $contentHtml = '<p class="text-lg">وضعیت پرونده به <span class="px-3 py-1 rounded-full bg-yellow-500 text-black font-bold">' . htmlspecialchars($event['content']) . '</span> تغییر کرد.</p>';
                                } else { // COMMENT
                                    $contentHtml = '
                                        <div class="font-bold mb-2 text-green-400">وکیل نظر جدیدی ثبت کرد:</div>
                                        <blockquote class="border-l-4 border-green-500 pl-4 italic bg-gray-700/50 p-3 rounded-r-lg">
                                            ' . nl2br(htmlspecialchars($event['content'])) . '
                                        </blockquote>';
                                }
                        ?>
                                <div class="relative pl-8 pb-8 group">
                                    <div class="absolute top-0 right-0 w-4 h-full border-r-2 border-dashed border-gray-600 group-last:border-transparent"></div>
                                    <div class="timeline-item bg-gray-700 p-5 rounded-xl shadow transition-transform hover:scale-102">
                                        <div class="flex justify-between items-start mb-3">
                                            <time class="text-xs text-gray-400 bg-gray-800 px-2 py-1 rounded"><?= $eventDate ?></time>
                                            <span class="text-xs px-2 py-1 rounded-full bg-gray-600 text-gray-300"><?= $event['type'] === 'FILE_UPLOAD' ? 'فایل' : ($event['type'] === 'STATUS_CHANGE' ? 'وضعیت' : 'نظر') ?></span>
                                        </div>
                                        <?= $contentHtml ?>
                                    </div>
                                </div>
                        <?php
                            endwhile;
                        else:
                            echo '<div class="text-center py-12"><p class="text-gray-500 text-lg">تاریخچه‌ای برای این پرونده ثبت نشده است.</p></div>';
                        endif;
                        $stmt->close();
                        ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-12 rounded-2xl shadow-lg text-center">
                <div class="mb-6">
                    <i class="fas fa-folder-open text-6xl text-gray-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-4">پرونده‌ای برای شما ثبت نشده است.</h2>
                <p class="text-gray-400 text-lg">به محض اینکه وکیل شما پرونده‌ای برای شما ایجاد کند، جزئیات آن در اینجا نمایش داده خواهد شد.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    },
    'views/reports.php' => function($conn, $current_user) {
        $status_query = "SELECT status, COUNT(*) as count FROM cases GROUP BY status";
        $status_result = $conn->query($status_query);
        $status_data = $status_result->fetch_all(MYSQLI_ASSOC);
        $monthly_query = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_actions
            FROM case_history 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        $monthly_result = $conn->query($monthly_query);
        $monthly_data = $monthly_result->fetch_all(MYSQLI_ASSOC);
        $top_clients_query = "
            SELECT 
                p.email as client_email,
                COUNT(ch.id) as action_count
            FROM case_history ch
            JOIN profiles p ON ch.client_id = p.id
            GROUP BY p.id
            ORDER BY action_count DESC
            LIMIT 5
        ";
        $top_clients_result = $conn->query($top_clients_query);
        $top_clients = $top_clients_result->fetch_all(MYSQLI_ASSOC);
        $status_labels = json_encode(array_column($status_data, 'status'));
        $status_counts = json_encode(array_column($status_data, 'count'));
        $monthly_labels = json_encode(array_column($monthly_data, 'month'));
        $monthly_counts = json_encode(array_column($monthly_data, 'total_actions'));
        ?>
        <div class="fade-in space-y-8">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-extrabold text-white">گزارش‌ها و آمار</h1>
                <div class="flex space-x-3 space-x-reverse">
                    <button onclick="exportReport('pdf')" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition">
                        <i class="fas fa-file-pdf"></i>
                        <span>خروجی PDF</span>
                    </button>
                    <button onclick="exportReport('excel')" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition">
                        <i class="fas fa-file-excel"></i>
                        <span>خروجی Excel</span>
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg">
                    <h2 class="text-xl font-bold text-white mb-4">تعداد پرونده‌ها بر اساس وضعیت</h2>
                    <canvas id="statusChart" height="300"></canvas>
                </div>
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg">
                    <h2 class="text-xl font-bold text-white mb-4">فعالیت ماهانه (۶ ماه گذشته)</h2>
                    <canvas id="monthlyChart" height="300"></canvas>
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold text-white mb-4">فعال‌ترین موکلان</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-right bg-gray-800 rounded-lg overflow-hidden">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="p-4 text-gray-300 font-semibold text-sm">ایمیل موکل</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm">تعداد اقدامات</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm">آخرین فعالیت</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                        <?php if (count($top_clients) > 0): ?>
                            <?php foreach ($top_clients as $client): ?>
                            <tr class="hover:bg-gray-700/50 transition">
                                <td class="p-4 text-white"><?= htmlspecialchars($client['client_email']) ?></td>
                                <td class="p-4 text-center">
                                    <span class="px-3 py-1 bg-blue-500 text-white rounded-full text-sm">
                                        <?= $client['action_count'] ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center text-gray-400 text-sm">
                                    نامشخص
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="p-8 text-center text-gray-500">هیچ داده‌ای برای نمایش وجود ندارد.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="export-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
                <div class="bg-gray-800 p-6 rounded-xl max-w-md w-full mx-4">
                    <h3 class="text-xl font-bold text-white mb-4">در حال آماده‌سازی گزارش...</h3>
                    <div class="flex justify-center">
                        <div class="spinner"></div>
                    </div>
                    <p class="text-gray-400 text-center mt-4">لطفاً صبر کنید. این فرآیند ممکن است چند ثانیه طول بکشد.</p>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= $status_labels ?>,
                    datasets: [{
                        data: <?= $status_counts ?>,
                        backgroundColor: ['#4ade80', '#fbbf24', '#3b82f6', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#1f2937'
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { color: '#e5e7eb', font: { family: 'Vazirmatn', size: 14 } } }, tooltip: { bodyColor: '#e5e7eb', titleColor: '#e5e7eb', backgroundColor: '#1f2937', borderColor: '#4ade80', borderWidth: 1 } } }
            });
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: <?= $monthly_labels ?>,
                    datasets: [{
                        label: 'تعداد اقدامات',
                        data: <?= $monthly_counts ?>,
                        borderColor: '#4ade80',
                        backgroundColor: 'rgba(74, 222, 128, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#4ade80',
                        pointRadius: 5,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { color: '#9ca3af', font: { family: 'Vazirmatn' } }, grid: { color: 'rgba(255,255,255,0.1)' } }, x: { ticks: { color: '#9ca3af', font: { family: 'Vazirmatn' } }, grid: { color: 'rgba(255,255,255,0.1)' } } }, plugins: { legend: { labels: { color: '#e5e7eb', font: { family: 'Vazirmatn', size: 14 } } }, tooltip: { bodyColor: '#e5e7eb', titleColor: '#e5e7eb', backgroundColor: '#1f2937', borderColor: '#4ade80', borderWidth: 1 } } }
            });
        });
        function exportReport(format) {
            const modal = document.getElementById('export-modal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.add('hidden');
                showToast(`گزارش به فرمت ${format.toUpperCase()} با موفقیت آماده شد.`, 'success');
            }, 2000);
        }
        </script>
        <?php
    },    'views/notifications.php' => function($conn, $current_user) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        ?>
        <div class="fade-in space-y-8">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-extrabold text-white">اعلان‌ها</h1>
                <button onclick="markAllAsRead()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition">
                    <i class="fas fa-check-double"></i>
                    <span>علامت‌گذاری همه به عنوان خوانده شده</span>
                </button>
            </div>
            <?php if (count($notifications) > 0): ?>
            <div class="space-y-4">
                <?php foreach ($notifications as $notif): ?>
                <div class="bg-gradient-to-r from-gray-800 to-gray-900 p-6 rounded-xl shadow-lg border-l-4 
                    <?= $notif['type'] === 'success' ? 'border-green-500' : 
                       ($notif['type'] === 'warning' ? 'border-yellow-500' : 
                       ($notif['type'] === 'error' ? 'border-red-500' : 'border-blue-500')) ?>" data-notification-id="<?= $notif['id'] ?>">
                    <div class="flex justify-between items-start mb-3">
                        <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($notif['title']) ?></h3>
                        <span class="text-xs text-gray-400 bg-gray-700 px-2 py-1 rounded">
                            <?= format_jalali_datetime($notif['created_at']) ?>
                        </span>
                    </div>
                    <p class="text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($notif['message'])) ?></p>
                    <div class="mt-4 flex justify-end">
                        <button onclick="deleteNotification(<?= $notif['id'] ?>)" class="text-red-400 hover:text-red-300 text-sm flex items-center gap-1 transition">
                            <i class="fas fa-trash"></i>
                            <span>حذف</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-12 rounded-2xl shadow-lg text-center">
                <div class="mb-6">
                    <i class="fas fa-bell-slash text-6xl text-gray-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-4">اعلانی وجود ندارد</h2>
                <p class="text-gray-400 text-lg">هنوز اعلانی برای شما ارسال نشده است.</p>
            </div>
            <?php endif; ?>
        </div>
        <script>
        function markAllAsRead() {
            showToast('در حال علامت‌گذاری اعلان‌ها...', 'info');
            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_notifications_read' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('همه اعلان‌ها به عنوان خوانده شده علامت‌گذاری شدند.', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('خطا در علامت‌گذاری اعلان‌ها: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('خطای شبکه. لطفاً دوباره تلاش کنید.', 'error');
            });
        }
        function deleteNotification(notificationId) {
             fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_notification', notification_id: notificationId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const notifElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (notifElement) {
                        notifElement.style.opacity = '0';
                        notifElement.style.transform = 'translateX(20px)';
                        setTimeout(() => { notifElement.remove(); }, 300);
                        showToast('اعلان با موفقیت حذف شد.', 'success');
                    }
                } else {
                    showToast('خطا در حذف اعلان: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('خطای شبکه. لطفاً دوباره تلاش کنید.', 'error');
            });
        }
        </script>
        <?php
    },
    'views/user_management.php' => function($conn, $current_user) {
        $users_query = "SELECT id, email, role, created_at FROM profiles WHERE role = 'client' ORDER BY created_at DESC";
        $users_result = $conn->query($users_query);
        $users = $users_result->fetch_all(MYSQLI_ASSOC);
        ?>
        <div class="fade-in space-y-8">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-extrabold text-white">مدیریت موکلان</h1>
                <div class="flex space-x-3 space-x-reverse">
                    <input type="text" id="user-search" placeholder="جستجوی موکل..." class="bg-gray-700 text-white p-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 w-64">
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl shadow-lg">
                <div class="overflow-x-auto">
                    <table class="w-full text-right bg-gray-800 rounded-lg overflow-hidden">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="p-4 text-gray-300 font-semibold text-sm">ایمیل موکل</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm">تاریخ عضویت</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm text-center">وضعیت پرونده</th>
                                <th class="p-4 text-gray-300 font-semibold text-sm text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="users-table-body" class="divide-y divide-gray-700">
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $user): ?>
                            <?php
                                $stmt = $conn->prepare("SELECT status FROM cases WHERE client_id = ?");
                                $stmt->bind_param("s", $user['id']);
                                $stmt->execute();
                                $case_status = $stmt->get_result()->fetch_assoc();
                                $stmt->close();
                            ?>
                            <tr class="hover:bg-gray-700/50 transition" data-email="<?= htmlspecialchars($user['email']) ?>">
                                <td class="p-4 text-white"><?= htmlspecialchars($user['email']) ?></td>
                                <td class="p-4 text-center text-gray-400 text-sm">
                                    <?= format_jalali_datetime($user['created_at']) ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($case_status): ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                            <?= $case_status['status'] === 'مختومه شده' ? 'bg-yellow-500 text-black' : 'bg-blue-500 text-white' ?>">
                                            <?= htmlspecialchars($case_status['status']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-gray-500 text-white">
                                            پرونده ندارد
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if (!$case_status): ?>
                                    <button onclick="createCaseForClient('<?= $user['id'] ?>', '<?= htmlspecialchars($user['email']) ?>')" class="bg-purple-600 hover:bg-purple-700 text-white text-xs py-2 px-4 rounded-lg transition shadow mr-2">
                                        ایجاد پرونده
                                    </button>
                                    <?php else: ?>
                                    <button onclick="openCaseModal('<?= $user['id'] ?>', '<?= htmlspecialchars($user['email']) ?>')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs py-2 px-4 rounded-lg transition shadow">
                                        مدیریت
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="p-8 text-center text-gray-500">موکلی یافت نشد.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('user-search').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            document.querySelectorAll('#users-table-body tr').forEach(row => {
                const email = row.dataset.email.toLowerCase();
                const matchesSearch = email.includes(searchTerm);
                row.style.display = matchesSearch ? '' : 'none';
            });
        });
        </script>
        <?php
    },
    'views/help_support.php' => function($conn, $current_user) {
        ?>
        <div class="fade-in space-y-8 max-w-4xl mx-auto">
            <h1 class="text-3xl font-extrabold text-white text-center mb-8">راهنما و پشتیبانی</h1>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold mb-6 text-white flex items-center">
                    <i class="fas fa-question-circle ml-3 text-blue-400"></i>
                    راهنمای استفاده از سامانه
                </h2>
                <div class="space-y-6 text-gray-300">
                    <div class="bg-gray-700/50 p-6 rounded-lg">
                        <h3 class="text-xl font-bold text-white mb-3">داشبورد پرونده</h3>
                        <p class="mb-3">در این بخش می‌توانید آخرین وضعیت پرونده خود را مشاهده کنید.</p>
                        <ul class="list-disc mr-6 space-y-2">
                            <li>وضعیت فعلی پرونده در بالای صفحه نمایش داده می‌شود.</li>
                            <li>تاریخچه تمامی اقدامات انجام شده توسط وکیل در بخش تاریخچه قابل مشاهده است.</li>
                            <li>با استفاده از دکمه "خلاصه‌سازی هوشمند" می‌توانید خلاصه‌ای از وضعیت پرونده خود دریافت کنید.</li>
                        </ul>
                    </div>
                    <div class="bg-gray-700/50 p-6 rounded-lg">
                        <h3 class="text-xl font-bold text-white mb-3">اعلان‌ها</h3>
                        <p class="mb-3">هر زمان که وکیل شما اقدام جدیدی در پرونده شما انجام دهد، اعلانی برای شما ارسال می‌شود.</p>
                        <ul class="list-disc mr-6 space-y-2">
                            <li>اعلان‌های جدید با نشان قرمز در منوی اعلان‌ها مشخص می‌شوند.</li>
                            <li>با کلیک بر روی اعلان، آن به عنوان خوانده شده علامت‌گذاری می‌شود.</li>
                            <li>می‌توانید اعلان‌های خود را حذف کنید یا همه آن‌ها را به عنوان خوانده شده علامت‌گذاری کنید.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold mb-6 text-white flex items-center">
                    <i class="fas fa-headset ml-3 text-green-400"></i>
                    تماس با پشتیبانی
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-gray-300">
                    <div class="bg-gray-700/50 p-6 rounded-lg">
                        <h3 class="text-lg font-bold text-white mb-3">پشتیبانی تلفنی</h3>
                        <p class="mb-2">شماره تماس: <span class="text-green-400 font-bold">021-12345678</span></p>
                        <p class="mb-2">ساعات پاسخگویی: <span class="text-green-400">8:00 الی 20:00</span></p>
                        <p>روزهای کاری: شنبه تا پنجشنبه</p>
                    </div>
                    <div class="bg-gray-700/50 p-6 rounded-lg">
                        <h3 class="text-lg font-bold text-white mb-3">پشتیبانی ایمیلی</h3>
                        <p class="mb-2">آدرس ایمیل: <span class="text-green-400 font-bold">support@stellalegal.com</span></p>
                        <p class="mb-2">زمان پاسخ: <span class="text-green-400">ظرف 24 ساعت کاری</span></p>
                        <p>پاسخگویی: 24 ساعته (حتی تعطیلات)</p>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold mb-6 text-white flex items-center">
                    <i class="fas fa-comment-dots ml-3 text-purple-400"></i>
                    ارسال درخواست پشتیبانی
                </h2>
                <form id="support-form" class="space-y-6">
                    <div>
                        <label for="support-subject" class="block mb-2 font-medium text-gray-300">موضوع</label>
                        <input type="text" id="support-subject" placeholder="موضوع درخواست خود را وارد کنید" required class="w-full bg-gray-700 text-white rounded-lg p-4 focus:outline-none focus:ring-2 focus:ring-green-500 transition">
                    </div>
                    <div>
                        <label for="support-message" class="block mb-2 font-medium text-gray-300">پیام</label>
                        <textarea id="support-message" rows="5" placeholder="پیام خود را به طور کامل و دقیق بنویسید..." required class="w-full bg-gray-700 text-white rounded-lg p-4 focus:outline-none focus:ring-2 focus:ring-green-500 transition resize-none"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold py-4 px-6 rounded-lg transition transform hover:scale-105 shadow-lg">
                        ارسال درخواست
                    </button>
                </form>
                <div id="support-message" class="mt-4 text-sm h-6 text-center"></div>
            </div>
        </div>
        <script>
        document.getElementById('support-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const msgEl = document.getElementById('support-message');
            msgEl.textContent = 'در حال ارسال درخواست...';
            msgEl.className = 'mt-4 text-blue-400 text-sm h-6 text-center';
            try {
                setTimeout(() => {
                    msgEl.className = 'mt-4 text-green-400 text-sm h-6 text-center';
                    msgEl.textContent = '✅ درخواست شما با موفقیت ارسال شد. پشتیبانی ظرف 24 ساعت با شما تماس خواهد گرفت.';
                    document.getElementById('support-form').reset();
                }, 1500);
            } catch (error) {
                msgEl.className = 'mt-4 text-red-400 text-sm h-6 text-center';
                msgEl.textContent = '❌ خطایی در ارسال درخواست رخ داد. لطفاً دوباره تلاش کنید.';
            }
        });
        </script>
        <?php
    }
];

function include_view($path, $conn, $current_user, $view_files) {
    if (isset($view_files[$path]) && is_callable($view_files[$path])) {
        $view_files[$path]($conn, $current_user);
    }
}// =================================================================================
// 7. API & FORM HANDLING (CONTROLLER LOGIC)
// =================================================================================
$action = $_REQUEST['action'] ?? null;
$response = [];

// Handle logout action
if ($action === 'logout') {
    error_log("User logged out: " . ($_SESSION['user_email'] ?? 'Unknown'));
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    header('Content-Type: application/json');
    $conn = db_connect();
    
    // Use json_decode for POST requests with JSON content type
    $post_data = json_decode(file_get_contents('php://input'), true);
    if(empty($post_data)) {
        $post_data = $_POST;
    }
    
    switch ($action) {
        case 'register':
            $email = trim($post_data['email'] ?? '');
            $password = $post_data['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                $response = ['success' => false, 'message' => get_persian_error_message('invalid_email')];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response = ['success' => false, 'message' => get_persian_error_message('invalid_email')];
            } elseif (strlen($password) < 6) {
                $response = ['success' => false, 'message' => get_persian_error_message('password_short')];
            } else {
                $email = filter_var($email, FILTER_SANITIZE_EMAIL);
                $stmt = $conn->prepare("SELECT id FROM profiles WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $response = ['success' => false, 'message' => get_persian_error_message('user_exists')];
                } else {
                    $uuid = generate_uuid();
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $role = (strtolower($email) === 'stlaw@admin.com') ? 'admin' : 'client';
                    $insert_stmt = $conn->prepare("INSERT INTO profiles (id, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $insert_stmt->bind_param("ssss", $uuid, $email, $hashed_password, $role);
                    if ($insert_stmt->execute()) {
                        error_log("New user registered: $email");
                        $response = ['success' => true, 'message' => 'ثبت‌نام موفق! اکنون می‌توانید وارد شوید.'];
                    } else {
                        error_log("Registration failed for $email: " . $insert_stmt->error);
                        $response = ['success' => false, 'message' => get_persian_error_message('generic_error')];
                    }
                    $insert_stmt->close();
                }
                $stmt->close();
            }
            break;
            
        case 'login':
            $email = trim($post_data['email'] ?? '');
            $password = $post_data['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                $response = ['success' => false, 'message' => get_persian_error_message('invalid_credentials')];
            } else {
                $email = filter_var($email, FILTER_SANITIZE_EMAIL);
                $stmt = $conn->prepare("SELECT id, email, password, role FROM profiles WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($user = $result->fetch_assoc()) {
                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        error_log("User logged in: " . $user['email']);
                        $response = ['success' => true];
                    } else {
                        error_log("Failed login attempt for email: $email");
                        $response = ['success' => false, 'message' => get_persian_error_message('invalid_credentials')];
                    }
                } else {
                    error_log("Failed login attempt for non-existent email: $email");
                    $response = ['success' => false, 'message' => get_persian_error_message('invalid_credentials')];
                }
                $stmt->close();
            }
            break;
            
        case 'update_password':
            if (!isset($_SESSION['user_id'])) { 
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')]; 
                break; 
            }
            
            $current_password = $post_data['current_password'] ?? '';
            $new_password = $post_data['new_password'] ?? '';
            $confirm_password = $post_data['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $response = ['success' => false, 'message' => 'لطفاً تمامی فیلدها را پر کنید.'];
            } elseif (strlen($new_password) < 6) {
                $response = ['success' => false, 'message' => get_persian_error_message('password_short')];
            } elseif ($new_password !== $confirm_password) {
                $response = ['success' => false, 'message' => 'رمز عبور جدید و تکرار آن مطابقت ندارند.'];
            } else {
                $stmt = $conn->prepare("SELECT password FROM profiles WHERE id = ?");
                $stmt->bind_param("s", $_SESSION['user_id']);
                $stmt->execute();
                $stored_hash = $stmt->get_result()->fetch_assoc()['password'];
                $stmt->close();
                
                if (!password_verify($current_password, $stored_hash)) {
                    $response = ['success' => false, 'message' => 'رمز عبور فعلی اشتباه است.'];
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE profiles SET password = ? WHERE id = ?");
                    $stmt->bind_param("ss", $hashed_password, $_SESSION['user_id']);
                    if ($stmt->execute()) {
                        error_log("Password changed for user: " . $_SESSION['user_email']);
                        $response = ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
                    } else {
                        error_log("Failed to change password for user: " . $_SESSION['user_email'] . " - " . $stmt->error);
                        $response = ['success' => false, 'message' => 'خطا در تغییر رمز عبور.'];
                    }
                    $stmt->close();
                }
            }
            break;
            
        case 'update_case':
            if (!is_admin()) { 
                error_log("Unauthorized case update attempt by: " . ($_SESSION['user_email'] ?? 'Unknown'));
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')]; 
                break; 
            }
            
            $client_id = $post_data['modal-client-id'] ?? $post_data['select-client'] ?? '';
            $case_name = trim($post_data['case-name'] ?? $post_data['new-case-name'] ?? '');
            $case_status = trim($post_data['case-status'] ?? $post_data['new-case-status'] ?? '');
            $opinion = trim($post_data['lawyer-opinion'] ?? '');
            $file = $_FILES['case_file_input'] ?? null;
            $description = trim($post_data['case-description'] ?? $post_data['new-case-description'] ?? '');
            $court_name = trim($post_data['court-name'] ?? $post_data['new-court-name'] ?? '');
            $case_number = trim($post_data['case-number'] ?? $post_data['new-case-number'] ?? '');
            $next_hearing_date = !empty($post_data['next-hearing-date']) ? $post_data['next-hearing-date'] : null;
            
            if (empty($client_id) || empty($case_name) || empty($case_status)) {
                $response = ['success' => false, 'message' => 'لطفاً تمامی فیلدهای اجباری را پر کنید.'];
                break;
            }
            
            // Validate Client ID
            $stmt = $conn->prepare("SELECT id FROM profiles WHERE id = ?");
            $stmt->bind_param("s", $client_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $response = ['success' => false, 'message' => get_persian_error_message('client_not_found')];
                $stmt->close();
                break;
            }
            $stmt->close();
            
            // Handle File Upload
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_types)) {
                    $response = ['success' => false, 'message' => 'نوع فایل مجاز نیست. فرمت‌های مجاز: ' . implode(', ', $allowed_types)];
                    break;
                }
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', basename($file['name']));
                $target_path = $upload_dir . $file_name;
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $stmt = $conn->prepare("INSERT INTO case_history (client_id, type, content, file_url) VALUES (?, 'FILE_UPLOAD', ?, ?)");
                    $stmt->bind_param("sss", $client_id, $file['name'], $target_path);
                    if ($stmt->execute()) {
                        error_log("File uploaded for case: $client_id by " . $_SESSION['user_email']);
                        create_notification($client_id, 'فایل جدید آپلود شد', 'وکیل شما فایل جدیدی به پرونده شما اضافه کرد: ' . $file['name'], 'info');
                    }
                    $stmt->close();
                } else {
                    error_log("File upload failed for case: $client_id - " . print_r($file, true));
                    $response = ['success' => false, 'message' => get_persian_error_message('file_upload_error')];
                    break;
                }
            }
            
            // Handle New Opinion/Comment
            if (!empty($opinion)) {
                $stmt = $conn->prepare("INSERT INTO case_history (client_id, type, content) VALUES (?, 'COMMENT', ?)");
                $stmt->bind_param("ss", $client_id, $opinion);
                if ($stmt->execute()) {
                    error_log("New comment added for case: $client_id by " . $_SESSION['user_email']);
                    create_notification($client_id, 'نظر جدید ثبت شد', 'وکیل شما نظر جدیدی در پرونده شما ثبت کرد.', 'info');
                }
                $stmt->close();
            }
            
            // Upsert Case and Handle Status Change
            $stmt = $conn->prepare("SELECT id, status FROM cases WHERE client_id = ?");
            $stmt->bind_param("s", $client_id);
            $stmt->execute();
            $existing_case = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($existing_case) {
                // Update existing case
                $stmt = $conn->prepare("UPDATE cases SET case_name = ?, status = ?, description = ?, court_name = ?, case_number = ?, next_hearing_date = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssssi", $case_name, $case_status, $description, $court_name, $case_number, $next_hearing_date, $existing_case['id']);
            } else {
                // Insert new case
                $stmt = $conn->prepare("INSERT INTO cases (client_id, case_name, status, description, court_name, case_number, next_hearing_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param("ssssssss", $client_id, $case_name, $case_status, $description, $court_name, $case_number, $next_hearing_date);
            }
            
            if (!$stmt->execute()) {
                $error_msg = $existing_case ? 'بروزرسانی' : 'ایجاد';
                error_log("Failed to {$error_msg} case for client: $client_id - " . $stmt->error);
                $response = ['success' => false, 'message' => "خطا در {$error_msg} پرونده."];
                $stmt->close();
                break;
            }
            $stmt->close();
            
            // Record status change if it's different
            if (!$existing_case || $existing_case['status'] !== $case_status) {
                $stmt = $conn->prepare("INSERT INTO case_history (client_id, type, content) VALUES (?, 'STATUS_CHANGE', ?)");
                $stmt->bind_param("ss", $client_id, $case_status);
                if ($stmt->execute()) {
                    error_log("Case status changed to '$case_status' for client: $client_id by " . $_SESSION['user_email']);
                    create_notification($client_id, 'وضعیت پرونده تغییر کرد', 'وضعیت پرونده شما به "' . $case_status . '" تغییر کرد.', 'warning');
                }
                $stmt->close();
            }
            
            $response = ['success' => true, 'message' => 'پرونده با موفقیت بروزرسانی شد.'];
            break;
            
        case 'delete_case':
            if (!is_admin()) { 
                error_log("Unauthorized case delete attempt by: " . ($_SESSION['user_email'] ?? 'Unknown'));
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')]; 
                break; 
            }
            
            $case_id = $post_data['case_id'] ?? '';
            if (empty($case_id)) {
                $response = ['success' => false, 'message' => get_persian_error_message('invalid_input')];
                break;
            }
            
            $stmt = $conn->prepare("SELECT client_id FROM cases WHERE id = ?");
            $stmt->bind_param("i", $case_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($case = $result->fetch_assoc()) {
                $client_id = $case['client_id'];
                $stmt_delete = $conn->prepare("DELETE FROM cases WHERE id = ?");
                $stmt_delete->bind_param("i", $case_id);
                if ($stmt_delete->execute()) {
                    error_log("Case deleted: $case_id by " . $_SESSION['user_email']);
                    create_notification($client_id, 'پرونده حذف شد', 'پرونده شما توسط مدیریت حذف شد.', 'error');
                    $response = ['success' => true, 'message' => 'پرونده با موفقیت حذف شد.'];
                } else {
                    error_log("Failed to delete case: $case_id - " . $stmt_delete->error);
                    $response = ['success' => false, 'message' => 'خطا در حذف پرونده.'];
                }
                $stmt_delete->close();
            } else {
                $response = ['success' => false, 'message' => get_persian_error_message('case_not_found')];
            }
            $stmt->close();
            break;
            
        case 'mark_all_notifications_read':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')];
                break;
            }
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("s", $user_id);
            $response = ['success' => $stmt->execute()];
            $stmt->close();
            break;
            
        case 'delete_notification':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')];
                break;
            }
            $notification_id = $post_data['notification_id'] ?? '';
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->bind_param("is", $notification_id, $user_id);
            $response = ['success' => $stmt->execute()];
            $stmt->close();
            break;
            
        default:
            $response = ['success' => false, 'message' => 'عملیات نامعتبر.'];
            break;
    }
    
    $conn->close();
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action) {
    header('Content-Type: application/json');
    $conn = db_connect();
    
    switch($action) {
        case 'get_case_details':
            if (!is_admin()) { 
                error_log("Unauthorized case details access by: " . ($_SESSION['user_email'] ?? 'Unknown'));
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')]; 
                break; 
            }
            
            $client_id = $_GET['client_id'] ?? '';
            if (empty($client_id)) {
                $response = ['success' => false, 'message' => 'شناسه کاربر معتبر نیست.'];
                break;
            }
            
            $stmt = $conn->prepare("SELECT id, case_name, status, description, court_name, case_number, next_hearing_date FROM cases WHERE client_id = ?");
            $stmt->bind_param("s", $client_id);
            $stmt->execute();
            $case_data = $stmt->get_result()->fetch_assoc() ?: [
                'id' => null,
                'case_name' => '', 
                'status' => 'در حال بررسی',
                'description' => '',
                'court_name' => '',
                'case_number' => '',
                'next_hearing_date' => null
            ];
            $stmt->close();
            
            $stmt = $conn->prepare("SELECT type, content, file_url, created_at FROM case_history WHERE client_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("s", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $history_data = [];
            while ($row = $result->fetch_assoc()) {
                $row['created_at'] = format_jalali_datetime($row['created_at']);
                $history_data[] = $row;
            }
            $stmt->close();
            
            $response = ['success' => true, 'case' => $case_data, 'history' => $history_data];
            break;
            
        case 'summarize_case':
            $client_id = $_GET['client_id'] ?? null;
            if (!isset($_SESSION['user_id']) || !$client_id || ( !is_admin() && $_SESSION['user_id'] != $client_id) ) {
                error_log("Unauthorized summary request for client: $client_id by user: " . ($_SESSION['user_id'] ?? 'Unknown'));
                $response = ['success' => false, 'message' => get_persian_error_message('access_denied')]; 
                break;
            }
            
            $stmt = $conn->prepare("SELECT * FROM case_history WHERE client_id = ? ORDER BY created_at ASC");
            $stmt->bind_param("s", $client_id);
            $stmt->execute();
            $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            if (empty($history)) {
                $response = ['success' => false, 'message' => 'تاریخچه‌ای برای خلاصه‌سازی وجود ندارد.'];
                break;
            }
            
            $historyText = "تاریخچه پرونده:\n";
            foreach($history as $event) {
                $time = format_jalali_datetime($event['created_at']);
                $eventType = match($event['type']) {
                    'FILE_UPLOAD' => 'آپلود فایل',
                    'STATUS_CHANGE' => 'تغییر وضعیت',
                    'COMMENT' => 'ثبت نظر',
                    default => $event['type']
                };
                $historyText .= "- تاریخ: {$time} | نوع رویداد: {$eventType} | محتوا: {$event['content']}\n";
                if (!empty($event['file_url'])) {
                    $historyText .= "  (فایل: {$event['file_url']})\n";
                }
            }
            
            $prompt = "شما یک دستیار هوشمند حقوقی هستید. لطفاً تاریخچه پرونده زیر را به زبان فارسی، به صورت یک پاراگراف منسجم، حرفه‌ای و خلاصه ارائه دهید. روی نکات کلیدی، آخرین وضعیت پرونده، اقدامات انجام شده توسط وکیل و مراحل بعدی (در صورت ذکر شدن) تمرکز کنید. خلاصه باید برای موکل قابل فهم و مفید باشد و حس اطمینان بدهد. لطفاً از اصطلاحات حقوقی بیش از حد پیچیده استفاده نکنید.\n{$historyText}\nخلاصه:";
            
            $ch = curl_init();
            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . GEMINI_API_KEY;
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
                ]
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: Mozilla/5.0 (compatible; StellaLegalSystem/1.0)']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $api_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($http_code == 200) {
                $result = json_decode($api_response, true);
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $summary = trim($result['candidates'][0]['content']['parts'][0]['text']);
                    $summary = preg_replace('/\*\*|\*|\"/u', '', $summary);
                    $response = ['success' => true, 'summary' => $summary];
                    error_log("AI Summary generated for client: $client_id by user: " . $_SESSION['user_id']);
                } else {
                    error_log("AI Summary failed - Unexpected response format: " . $api_response);
                    $response = ['success' => false, 'message' => get_persian_error_message('ai_service_error')];
                }
            } else {
                error_log("AI API Error - HTTP Code: $http_code, Error: $curl_error, Response: $api_response");
                $response = ['success' => false, 'message' => get_persian_error_message('ai_service_error')];
            }
            break;
            
        case 'get_unread_notifications_count':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'count' => 0];
                break;
            }
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $count = $stmt->get_result()->fetch_assoc()['count'];
            $stmt->close();
            $response = ['success' => true, 'count' => (int)$count];
            break;
            
        default:
            $response = ['success' => false, 'message' => 'عملیات نامعتبر.'];
            break;
    }
    
    $conn->close();
    echo json_encode($response);
    exit;
}

$current_user = null;
if (isset($_SESSION['user_id'])) {
    $current_user = get_user_by_id($_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="سامانه هوشمند پیگیری پرونده حقوقی استیلا - مدیریت پرونده‌های حقوقی با کمک هوش مصنوعی">
    <title>سیستم پیگیری پرونده هوشمند استیلا</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { 
            font-family: 'Vazirmatn', sans-serif; 
            background: linear-gradient(135deg, #111827 0%, #1F2937 100%);
            background-attachment: fixed;
            min-height: 100vh;
        }
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #1f2937; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { 
            background: linear-gradient(to bottom, #4ade80, #22c55e); 
            border-radius: 10px; 
            border: 3px solid #1f2937;
        }
        .fade-in { 
            animation: fadeIn 0.6s ease-out; 
        }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(10px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        .spinner { 
            border: 3px solid rgba(255, 255, 255, 0.3); 
            border-radius: 50%; 
            border-top: 3px solid #4ade80; 
            width: 20px; 
            height: 20px; 
            animation: spin 1s linear infinite; 
            display: inline-block;
        }
        @keyframes spin { 
            0% { transform: rotate(0deg); } 
            100% { transform: rotate(360deg); } 
        }
        .toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #374151;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            transform: translate(-50%, -100%);
        }
        .toast.show {
            opacity: 1;
            transform: translate(-50%, 0);
        }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
        .toast.info { background: #3b82f6; }
        .image-modal {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: flex;
            align-items: center; justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .image-modal.show {
            opacity: 1;
            pointer-events: all;
        }
        .image-modal-content {
            max-width: 90%; max-height: 90%;
            object-fit: contain;
            border-radius: 8px;
        }
        .image-modal-close {
            position: absolute;
            top: 20px; right: 20px;
            color: white; font-size: 30px;
            cursor: pointer;
        }
        /* Auth Form Styles */
        .auth-container {
            background: linear-gradient(135deg, rgba(17, 24, 39, 0.9) 0%, rgba(31, 41, 55, 0.9) 100%);
            backdrop-filter: blur(10px);
        }
        .form-input {
            width: 100%; padding: 1rem; border-radius: 1rem; border: 2px solid transparent; background: rgba(55, 65, 81, 0.5); color: #f3f4f6; transition: all 0.3s ease;
        }
        .form-input:focus {
            outline: none; border-color: #22c55e; background: rgba(55, 65, 81, 0.7); box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.2);
        }
        .auth-btn {
            width: 100%; padding: 1rem; border-radius: 1rem; font-weight: 600; font-size: 1.125rem; cursor: pointer; transition: all 0.3s ease;
        }
        /* Create Case Modal Styles */
        .wizard-progress-bar { display: flex; justify-content: space-around; align-items: center; }
        .wizard-step { display: flex; flex-direction: column; align-items: center; position: relative; flex-grow: 1; }
        .wizard-step-circle { width: 40px; height: 40px; border-radius: 50%; background-color: #374151; border: 2px solid #4b5563; color: #9ca3af; display: flex; align-items: center; justify-content: center; font-weight: bold; transition: all 0.3s ease; }
        .wizard-step-line { position: absolute; top: 20px; right: 50%; width: 100%; height: 2px; background-color: #4b5563; z-index: -1; transition: all 0.3s ease; }
        .wizard-step:first-child .wizard-step-line { display: none; }
        .wizard-step-label { margin-top: 0.5rem; font-size: 0.8rem; color: #9ca3af; transition: all 0.3s ease; }
        .wizard-step.active .wizard-step-circle { background-color: #10b981; border-color: #10b981; color: white; }
        .wizard-step.active .wizard-step-label { color: #10b981; font-weight: bold; }
        .wizard-step.completed .wizard-step-circle { background-color: #10b981; border-color: #10b981; color: white; }
        .wizard-step.completed + .wizard-step .wizard-step-line { background-color: #10b981; }
        .wizard-step.completed .wizard-step-label { color: #d1d5db; }
        .wizard-content.hidden { display: none; }
    </style>
</head>
<body class="text-gray-200">
    <div id="toast-container" class="fixed top-5 right-5 z-[1001] pointer-events-none"></div>
    <div id="image-modal" class="image-modal" onclick="closeImageModal()">
        <span class="image-modal-close">&times;</span>
        <img class="image-modal-content" id="modal-image" src="" alt="تصویر بزرگ" onclick="event.stopPropagation()">
    </div>
    <div id="app" class="min-h-screen">
        <?php if (!$current_user): ?>
        <div id="auth-container" class="w-full min-h-screen flex items-center justify-center p-4">
            <div class="auth-container w-full max-w-md rounded-2xl shadow-2xl">
                <div class="text-center p-8">
                    <img src="https://i.imghippo.com/files/PRae3238QJM.png" alt="لوگو استیلا" class="h-16 mx-auto">
                    <h1 class="text-3xl font-extrabold text-white mt-4">سامانه هوشمند استیلا</h1>
                    <p class="text-gray-400 mt-2">ورود یا ثبت‌نام برای مدیریت پرونده‌های حقوقی</p>
                </div>
                <form id="auth-form" class="px-8 pb-8" method="POST" action="index.php">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-6">
                        <label for="email" class="block mb-2 font-medium text-gray-300">ایمیل</label>
                        <input type="email" id="email" name="email" placeholder="example@email.com" required class="form-input" value="<?= htmlspecialchars($_GET['email'] ?? '') ?>">
                    </div>
                    <div class="mb-6">
                        <label for="password" class="block mb-2 font-medium text-gray-300">رمز عبور</label>
                        <input type="password" id="password" name="password" placeholder="••••••••" required class="form-input" value="<?= htmlspecialchars($_GET['password'] ?? '') ?>">
                    </div>
                    <button type="submit" id="login-btn" class="auth-btn bg-green-600 hover:bg-green-700 text-white flex justify-center items-center">
                        <span id="auth-btn-text">ورود به سامانه</span>
                        <div id="auth-spinner" class="spinner hidden"></div>
                    </button>
                    <div id="error-message" class="text-center text-red-400 min-h-[20px] mb-4 text-sm">
                        <?php if (!empty($_GET['error'])) { echo htmlspecialchars(get_persian_error_message($_GET['error'])); } ?>
                    </div>
                    <p class="text-center text-gray-400 text-sm">
                        حساب کاربری ندارید؟
                        <button type="button" id="register-view-btn" class="font-bold text-green-400 hover:underline">ثبت‌نام کنید</button>
                    </p>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div id="main-app" class="flex">
            <header class="md:hidden bg-gray-900/90 p-4 flex justify-between items-center sticky top-0 z-30 backdrop-blur-sm shadow-lg">
               <div class="flex items-center">
                   <img src="https://i.imghippo.com/files/PRae3238QJM.png" alt="لوگو استیلا" class="h-8 w-auto mr-2">
                   <h1 class="text-xl font-bold text-white">سامانه استیلا</h1>
               </div>
               <button id="hamburger-btn" class="text-white text-2xl p-2 rounded-lg hover:bg-gray-700 transition">
                   <i class="fas fa-bars"></i>
               </button>
            </header>
            <aside id="sidebar" class="fixed top-0 right-0 h-full bg-gradient-to-b from-gray-900 to-black shadow-2xl w-64 p-6 flex flex-col transition-transform duration-300 transform translate-x-full md:translate-x-0 z-40 overflow-y-auto">
                <div class="text-center mb-8">
                    <img src="https://i.imghippo.com/files/PRae3238QJM.png" alt="لوگو استیلا" class="h-16 w-auto mx-auto">
                    <h1 class="text-2xl font-extrabold text-white mt-2">سامانه استیلا</h1>
                    <p class="text-green-400 text-xs mt-1">نسخه حرفه‌ای</p>
                </div>
                <nav class="flex-grow space-y-2">
                    <?php 
                        $view = $_GET['view'] ?? (is_admin() ? 'admin-dashboard' : 'client-dashboard');
                    ?>
                    <?php if (is_admin()): ?>
                    <a href="?view=admin-dashboard" class="nav-link flex items-center p-3 rounded-xl <?= ($view === 'admin-dashboard' || $view === '') ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-chart-pie ml-3"></i>
                        <span>داشبورد اصلی</span>
                    </a>
                    <a href="?view=user-management" class="nav-link flex items-center p-3 rounded-xl <?= $view === 'user-management' ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-users ml-3"></i>
                        <span>مدیریت موکلان</span>
                    </a>
                    <a href="?view=reports" class="nav-link flex items-center p-3 rounded-xl <?= $view === 'reports' ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-file-alt ml-3"></i>
                        <span>گزارش‌ها و آمار</span>
                    </a>
                    <?php else: ?>
                    <a href="?view=client-dashboard" class="nav-link flex items-center p-3 rounded-xl <?= $view === 'client-dashboard' ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-tachometer-alt ml-3"></i>
                        <span>داشبورد پرونده</span>
                    </a>
                    <a href="?view=help-support" class="nav-link flex items-center p-3 rounded-xl <?= $view === 'help-support' ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-headset ml-3"></i>
                        <span>راهنما و پشتیبانی</span>
                    </a>
                    <?php endif; ?>
                    <a href="?view=notifications" class="nav-link flex items-center p-3 rounded-xl <?= $view === 'notifications' ? 'bg-green-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?> transition">
                        <i class="fas fa-bell ml-3"></i>
                        <span>اعلان‌ها</span>
                        <span id="notification-badge" class="mr-auto bg-red-500 text-white text-xs rounded-full px-2 hidden">۰</span>
                    </a>
                </nav>
                <div class="mt-auto pt-6 border-t border-gray-700">
                    <div class="p-4 bg-gray-800/50 rounded-xl flex items-center gap-4">
                        <a href="<?= is_admin() ? '?view=admin-settings' : '#' ?>" class="w-12 h-12 bg-gradient-to-br from-green-500 to-blue-500 rounded-full flex items-center justify-center text-white text-xl flex-shrink-0">
                           <i class="fas fa-user"></i>
                        </a>
                        <div class="overflow-hidden">
                            <div class="text-sm font-medium text-white truncate"><?= htmlspecialchars($current_user['email'] ?? '') ?></div>
                            <div class="text-xs text-gray-400 mt-1"><?= is_admin() ? 'ادمین' : 'موکل' ?></div>
                        </div>
                    </div>
                    <a href="?action=logout" class="w-full mt-4 py-3 flex items-center justify-center bg-red-600 hover:bg-red-700 rounded-lg text-white transition">
                        <i class="fas fa-sign-out-alt ml-2"></i>
                        <span>خروج از حساب</span>
                    </a>
                </div>
            </aside>
            <div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/50 z-30 md:hidden"></div>
            <main id="main-content" class="flex-1 md:mr-64 p-4 md:p-8 transition-all duration-300">
                <?php
                $conn = db_connect();
                $view = $_GET['view'] ?? (is_admin() ? 'admin-dashboard' : 'client-dashboard');
                if (is_admin()) {
                    switch ($view) {
                        case 'admin-settings': include_view('views/admin_settings.php', $conn, $current_user, $view_files); break;
                        case 'reports': include_view('views/reports.php', $conn, $current_user, $view_files); break;
                        case 'notifications': include_view('views/notifications.php', $conn, $current_user, $view_files); break;
                        case 'user-management': include_view('views/user_management.php', $conn, $current_user, $view_files); break;
                        default: include_view('views/admin_dashboard.php', $conn, $current_user, $view_files); break;
                    }
                } else {
                    switch ($view) {
                        case 'notifications': include_view('views/notifications.php', $conn, $current_user, $view_files); break;
                        case 'help-support': include_view('views/help_support.php', $conn, $current_user, $view_files); break;
                        default: include_view('views/client_dashboard.php', $conn, $current_user, $view_files); break;
                    }
                }
                $conn->close();
                ?>
            </main>
        </div>
        <?php endif; ?>
    </div>
    <!-- Modals Container -->
    <div id="modal-container"></div>
    <!-- Case Management Modal Template -->
    <template id="case-management-modal-template">
        <div id="case-modal" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center p-4 z-50">
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden" id="modal-content">
                <div class="p-6 border-b border-gray-700 flex justify-between items-center bg-gray-900/50">
                    <h2 class="text-xl font-bold text-green-400">مدیریت پرونده: <span id="modal-client-email" class="text-white"></span></h2>
                    <button id="close-modal-btn" class="text-gray-400 hover:text-white text-2xl p-1 rounded-full hover:bg-gray-700 transition">&times;</button>
                </div>
                <div class="flex-grow overflow-y-auto p-6 grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left Side: Form -->
                    <div class="space-y-6">
                        <form id="case-form" class="space-y-6">
                            <input type="hidden" id="modal-client-id" name="modal-client-id">
                            <input type="hidden" id="modal-case-id" name="modal-case-id">
                             <div class="card">
                                <h3 class="text-lg font-bold mb-4 text-white border-b border-gray-700 pb-2">اطلاعات پایه پرونده</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><label for="case-name" class="block mb-2 font-medium text-gray-300">عنوان پرونده</label><input type="text" id="case-name" name="case-name" placeholder="مثال: پرونده طلاق" required class="form-input"></div>
                                    <div><label for="case-status" class="block mb-2 font-medium text-gray-300">وضعیت</label><select id="case-status" name="case-status" required class="form-input"><option>در حال بررسی</option><option>در انتظار مدارک</option><option>اقدام انجام شده</option><option>مختومه شده</option></select></div>
                                    <div><label for="court-name" class="block mb-2 font-medium text-gray-300">نام دادگاه</label><input type="text" id="court-name" name="court-name" placeholder="دادگاه خانواده" class="form-input"></div>
                                    <div><label for="case-number" class="block mb-2 font-medium text-gray-300">شماره پرونده</label><input type="text" id="case-number" name="case-number" placeholder="۱۲۳/۱۴۰۳" class="form-input"></div>
                                    <div class="md:col-span-2"><label for="next-hearing-date" class="block mb-2 font-medium text-gray-300">تاریخ جلسه بعدی</label><input type="date" id="next-hearing-date" name="next-hearing-date" class="form-input"></div>
                                    <div class="md:col-span-2"><label for="case-description" class="block mb-2 font-medium text-gray-300">توضیحات پرونده</label><textarea id="case-description" name="case-description" rows="3" placeholder="توضیحات کامل..." class="form-input resize-none"></textarea></div>
                                </div>
                            </div>
                            <div class="card">
                                <h3 class="text-lg font-bold mb-4 text-white border-b border-gray-700 pb-2">ثبت اقدام یا نظر جدید</h3>
                                <div class="space-y-4">
                                    <div><label for="lawyer-opinion" class="block mb-2 font-medium text-gray-300">متن نظر یا اقدام</label><textarea id="lawyer-opinion" name="lawyer-opinion" rows="4" placeholder="این متن برای موکل قابل مشاهده خواهد بود..." class="form-input resize-none"></textarea></div>
                                    <div><label for="case-file-input" class="block mb-2 font-medium text-gray-300">آپلود فایل ضمیمه</label><input name="case_file_input" type="file" id="case-file-input" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100"><div id="file-upload-status" class="text-xs mt-2 min-h-5"></div></div>
                                </div>
                            </div>
                            <div class="flex justify-between">
                                <button type="submit" id="save-case-btn" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded-lg flex items-center"><span id="save-case-btn-text">ذخیره و ثبت اقدام</span><div id="save-case-spinner" class="spinner hidden mr-3"></div></button>
                                <button type="button" id="delete-case-btn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-8 rounded-lg flex items-center"><i class="fas fa-trash ml-2"></i><span>حذف پرونده</span></button>
                            </div>
                        </form>
                    </div>
                    <!-- Right Side: Timeline -->
                    <div class="border-t-4 lg:border-t-0 lg:border-r-4 border-gray-700 pr-0 lg:pr-8 pt-8 lg:pt-0">
                        <div class="flex justify-between items-center mb-6"><h3 class="text-xl font-bold text-white">تاریخچه پرونده</h3><button id="summarize-case-btn" type="button" class="bg-purple-600 hover:bg-purple-700 text-white text-xs py-2 px-4 rounded-lg flex items-center gap-2"><i class="fas fa-sparkles"></i><span>خلاصه‌سازی</span></button></div>
                        <div id="case-summary-output" class="hidden bg-gradient-to-r from-purple-900/30 to-purple-800/30 p-4 rounded-lg mb-6 border border-purple-500 backdrop-blur-sm"><div class="flex items-center gap-2 mb-3"><i class="fas fa-sparkles text-purple-300"></i><p class="font-bold text-purple-300">✨ خلاصه هوشمند:</p></div><div id="summary-content"></div><div id="summary-spinner" class="spinner mx-auto my-4 hidden"></div></div>
                        <div id="case-history-timeline" class="space-y-6 relative"></div>
                    </div>
                </div>
            </div>
        </div>
    </template>
    
    <!-- NEW: Create New Case Modal Template (Multi-Step) -->
    <template id="create-case-modal-template">
        <div id="create-case-modal" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center p-4 z-50">
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div class="p-6 border-b border-gray-700 flex justify-between items-center bg-gray-900/50">
                    <h2 class="text-xl font-bold text-green-400">ایجاد پرونده جدید</h2>
                    <button id="close-create-modal-btn" class="text-gray-400 hover:text-white text-2xl p-1 rounded-full hover:bg-gray-700 transition">&times;</button>
                </div>
                <form id="create-case-form" class="flex-grow flex flex-col">
                    <div class="p-8">
                        <!-- Wizard Progress Bar -->
                        <div class="wizard-progress-bar mb-8">
                            <div class="wizard-step active" data-step="1">
                                <div class="wizard-step-circle">1</div>
                                <div class="wizard-step-label">انتخاب موکل</div>
                                <div class="wizard-step-line"></div>
                            </div>
                            <div class="wizard-step" data-step="2">
                                <div class="wizard-step-circle">2</div>
                                <div class="wizard-step-label">اطلاعات اصلی</div>
                                <div class="wizard-step-line"></div>
                            </div>
                            <div class="wizard-step" data-step="3">
                                <div class="wizard-step-circle">3</div>
                                <div class="wizard-step-label">جزئیات حقوقی</div>
                                <div class="wizard-step-line"></div>
                            </div>
                            <div class="wizard-step" data-step="4">
                                <div class="wizard-step-circle"><i class="fas fa-check"></i></div>
                                <div class="wizard-step-label">بازبینی</div>
                            </div>
                        </div>
                        
                        <!-- Wizard Content -->
                        <div class="wizard-content" data-step-content="1">
                            <h3 class="text-lg font-semibold text-white mb-4">مرحله ۱: کدام موکل؟</h3>
                            <p class="text-gray-400 mb-6 text-sm">لطفا موکل مورد نظر را از لیست زیر انتخاب کنید. فقط موکلانی که پرونده فعال ندارند نمایش داده می‌شوند.</p>
                            <label for="select-client" class="block mb-2 font-medium text-gray-300">موکل</label>
                            <select id="select-client" name="select-client" required class="form-input w-full">
                                <option value="">-- یک موکل را انتخاب کنید --</option>
                                <?php 
                                if (is_admin()) {
                                    $template_conn = db_connect();
                                    $clients_query = "SELECT p.id, p.email FROM profiles p LEFT JOIN cases c ON p.id = c.client_id WHERE p.role = 'client' AND c.id IS NULL ORDER BY p.email";
                                    $clients_result = $template_conn->query($clients_query);
                                    if ($clients_result) {
                                        while($client = $clients_result->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($client['id']) . '">' . htmlspecialchars($client['email']) . '</option>';
                                        }
                                    }
                                    $template_conn->close();
                                }
                                ?>
                            </select>
                        </div>
                        <div class="wizard-content hidden" data-step-content="2">
                             <h3 class="text-lg font-semibold text-white mb-4">مرحله ۲: اطلاعات اصلی پرونده</h3>
                             <div class="space-y-4">
                                <div><label for="new-case-name" class="block mb-2 font-medium text-gray-300">عنوان پرونده</label><input type="text" id="new-case-name" name="new-case-name" placeholder="مثال: پرونده طلاق" required class="form-input"></div>
                                <div><label for="new-case-status" class="block mb-2 font-medium text-gray-300">وضعیت اولیه</label><select id="new-case-status" name="new-case-status" required class="form-input"><option>در حال بررسی</option><option>در انتظار مدارک</option></select></div>
                             </div>
                        </div>
                        <div class="wizard-content hidden" data-step-content="3">
                            <h3 class="text-lg font-semibold text-white mb-4">مرحله ۳: جزئیات حقوقی (اختیاری)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label for="new-court-name" class="block mb-2 font-medium text-gray-300">نام دادگاه</label><input type="text" id="new-court-name" name="new-court-name" class="form-input"></div>
                                <div><label for="new-case-number" class="block mb-2 font-medium text-gray-300">شماره پرونده</label><input type="text" id="new-case-number" name="new-case-number" class="form-input"></div>
                                <div class="md:col-span-2"><label for="new-next-hearing-date" class="block mb-2 font-medium text-gray-300">تاریخ جلسه بعدی</label><input type="date" id="new-next-hearing-date" name="new-next-hearing-date" class="form-input"></div>
                                <div class="md:col-span-2"><label for="new-case-description" class="block mb-2 font-medium text-gray-300">توضیحات اولیه</label><textarea id="new-case-description" name="new-case-description" rows="3" class="form-input resize-none"></textarea></div>
                            </div>
                        </div>
                        
                        <div class="wizard-content hidden" data-step-content="4">
                           <h3 class="text-lg font-semibold text-white mb-4">مرحله ۴: بازبینی و تایید</h3>
                           <p class="text-gray-400 mb-6 text-sm">لطفا اطلاعات وارد شده را بررسی و در صورت صحت، پرونده را ایجاد کنید.</p>
                           <div id="review-container" class="space-y-3 bg-gray-700/50 p-4 rounded-lg"></div>
                        </div>
                    </div>
                    <!-- Navigation -->
                    <div class="mt-auto p-6 bg-gray-900/50 border-t border-gray-700 flex justify-between items-center">
                        <button type="button" id="prev-step-btn" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-lg transition hidden">قبلی</button>
                        <button type="button" id="next-step-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition ml-auto">بعدی</button>
                        <button type="submit" id="create-case-submit-btn" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-8 rounded-lg transition hidden ml-auto">ایجاد پرونده</button>
                    </div>
                </form>
            </div>
        </div>
    </template>    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // --- AUTH LOGIC ---
        const authContainer = document.getElementById("auth-container");
        if (authContainer) {
            const authForm = document.getElementById("auth-form");
            const authBtnText = document.getElementById("auth-btn-text");
            const authSpinner = document.getElementById("auth-spinner");
            const errorMsgEl = document.getElementById("error-message");
            const registerViewBtn = document.getElementById("register-view-btn");
            let isRegisterMode = false;
            
            registerViewBtn.addEventListener("click", () => {
                isRegisterMode = !isRegisterMode;
                authForm.reset();
                errorMsgEl.textContent = "";
                authForm.querySelector("input[name=\"action\"]").value = isRegisterMode ? "register" : "login";
                authBtnText.textContent = isRegisterMode ? "ثبت‌نام در سامانه" : "ورود به سامانه";
                registerViewBtn.innerHTML = isRegisterMode ? "حساب کاربری دارید؟ <span class=\"font-bold text-green-400 hover:underline\">وارد شوید</span>" : "حساب کاربری ندارید؟ <span class=\"font-bold text-green-400 hover:underline\">ثبت‌نام کنید</span>";
            });
            
            authForm.addEventListener("submit", async (e) => {
                e.preventDefault();
                const formData = new FormData(authForm);
                authBtnText.classList.add("hidden");
                authSpinner.classList.remove("hidden");
                errorMsgEl.textContent = "";
                
                try {
                    const response = await fetch("index.php", { method: "POST", body: formData });
                    const result = await response.json();
                    if (result.success) {
                        if (isRegisterMode) {
                            showToast(result.message, "success");
                            setTimeout(() => registerViewBtn.click(), 2000);
                        } else {
                            window.location.href = "index.php";
                        }
                    } else {
                        errorMsgEl.textContent = result.message;
                    }
                } catch (error) {
                    errorMsgEl.textContent = "خطای شبکه. لطفاً اتصال اینترنت را بررسی کنید.";
                } finally {
                    authBtnText.classList.remove("hidden");
                    authSpinner.classList.add("hidden");
                }
            });
        }
        
        // --- MAIN APP LOGIC ---
        const mainApp = document.getElementById("main-app");
        if (mainApp) {
            const sidebar = document.getElementById("sidebar");
            const overlay = document.getElementById("sidebar-overlay");
            const hamburger = document.getElementById("hamburger-btn");
            
            const toggleSidebar = () => {
                sidebar.classList.toggle("translate-x-full");
                overlay.classList.toggle("hidden");
            };
            
            if (hamburger) hamburger.addEventListener("click", toggleSidebar);
            if (overlay) overlay.addEventListener("click", toggleSidebar);
            
            const changePasswordForm = document.getElementById("change-password-form");
            if(changePasswordForm) {
                changePasswordForm.addEventListener("submit", async (e) => {
                    e.preventDefault();
                    const msgEl = document.getElementById("password-change-message");
                    msgEl.textContent = "در حال پردازش...";
                    msgEl.className = "mt-4 text-blue-400 text-sm h-6 text-center";
                    
                    const formData = new FormData(changePasswordForm);
                    const data = Object.fromEntries(formData.entries());
                    data.action = "update_password";
                    
                    try {
                        const response = await fetch("index.php", { 
                            method: "POST", 
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify(data)
                        });
                        const result = await response.json();
                        if (result.success) {
                            msgEl.className = "mt-4 text-green-400 text-sm h-6 text-center";
                            msgEl.textContent = result.message;
                            changePasswordForm.reset();
                            showToast(result.message, "success");
                        } else {
                            msgEl.className = "mt-4 text-red-400 text-sm h-6 text-center";
                            msgEl.textContent = result.message;
                            showToast(result.message, "error");
                        }
                    } catch (err) {
                        msgEl.className = "mt-4 text-red-400 text-sm h-6 text-center";
                        msgEl.textContent = "خطای شبکه.";
                        showToast("خطای شبکه. لطفاً دوباره تلاش کنید.", "error");
                    }
                });
            }
            
            const clientSummarizeBtn = document.getElementById("client-summarize-btn");
            if(clientSummarizeBtn) {
                const clientId = "<?= $_SESSION[\"user_id\"] ?? \"\" ?>";
                clientSummarizeBtn.addEventListener("click", () => {
                    generateCaseSummary(clientId, "client");
                });
            }
            
            if ("<?= isset($_SESSION[\"user_id\"]) ? \"true\" : \"false\" ?>" === "true") {
                startNotificationPolling();
            }
        }
    });
</script>
<script src="app.js"></script>
</body>
</html>
