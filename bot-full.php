<?php
/**
 * bot-full.php - همه چیز تو یک فایل (config + کلاس‌ها + منطق وبهوک)
 *
 * ترتیب داخل فایل: تنظیمات/دیتابیس -> TelegramAPI -> User -> Wallet -> Game -> Coupon
 * -> در پایین‌ترین بخش، کد اجرایی وبهوک (خواندن آپدیت تلگرام و هندلرها).
 *
 * این فایل رو مستقیماً به عنوان webhook خودتون به تلگرام معرفی کنید:
 * https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/bot-full.php&secret_token=<WEBHOOK_SECRET>
 *
 * قبل از استفاده، حتماً مقادیر واقعی (توکن، دیتابیس، آی‌دی ادمین، آدرس وبهوک، سکرت)
 * رو در بخش «تنظیمات» همین فایل (چند خط پایین‌تر) جایگزین کنید.
 */

// ==================== config.php ====================
/**
 * فایل تنظیمات ربات بازی تاس تلگرام
 * Configuration File for Telegram Dice Game Bot
 *
 * تغییرات نسبت به نسخه اصلی:
 * 1) توکن ربات و آی‌دی ادمین از Environment Variable خوانده می‌شود
 *    (قرار دادن توکن مستقیم در کد و آپلود آن جایی مثل گیت‌هاب خطرناک است)
 * 2) DEBUG_MODE پیش‌فرض false شد (در نسخه قبلی true بود که یعنی خطاهای
 *    داخلی از جمله اطلاعات دیتابیس ممکن بود در لاگ عمومی افشا شود)
 * 3) اتصال PDO با گزینه‌های امن‌تر (ERRMODE + خاموش کردن emulate prepares)
 */

// خواندن مقادیر حساس از Environment Variable با مقدار پیش‌فرض برای تست محلی
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'telegram_dice_bot');

// توکن ربات تلگرام - هرگز مستقیم در کد ننویسید، از متغیر محیطی بخوانید
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE');

// شناسه ادمین (کاربر مالک ربات)
define('ADMIN_ID', getenv('ADMIN_ID') ?: 'YOUR_ADMIN_ID_HERE');

// درصد کارمزد
define('COMMISSION_PERCENTAGE', 5);

// حالت دیباگ - در پروداکشن باید false باشد
define('DEBUG_MODE', filter_var(getenv('DEBUG_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// وبهوک - secret token برای جلوگیری از جعل درخواست (استفاده در TelegramAPI::verifyWebhookSecret)
define('WEBHOOK_URL', getenv('WEBHOOK_URL') ?: 'https://yourdomain.com/webhook.php');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');

// API Telegram
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// اتصال دیتابیس
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // جلوگیری از برخی حملات و رفتار نادرست type-casting
        ]
    );
} catch (PDOException $e) {
    // در پروداکشن پیام خام دیتابیس را به خروجی نمایش ندهید (افشای اطلاعات)
    if (DEBUG_MODE) {
        die('خطا در اتصال به دیتابیس: ' . $e->getMessage());
    }
    error_log('DB connection failed: ' . $e->getMessage());
    die('خطای سرور. لطفاً بعداً تلاش کنید.');
}

// ==================== TelegramAPI.php ====================
/**
 * کلاس TelegramAPI - ارسال پیام‌ها و مدیریت تلگرام
 * TelegramAPI Class - Send messages and manage Telegram
 *
 * تغییرات نسبت به نسخه اصلی:
 * 1) رفع باگ inlineButton (کلید callback_query_id اشتباه ست می‌شد)
 * 2) فعال‌سازی تایید گواهی SSL در curl (امنیت)
 * 3) اضافه شدن متد verifyWebhookSecret برای جلوگیری از جعل درخواست وبهوک
 * 4) بررسی خطای curl_exec (قبلاً اگر curl fail می‌شد response=false و json_decode روی false کرش می‌کرد)
 */

class TelegramAPI {
    private $bot_token;
    private $api_url;
    private $chat_id;
    private $user_id;

    public function __construct($bot_token, $chat_id = null, $user_id = null) {
        $this->bot_token = $bot_token;
        $this->api_url = 'https://api.telegram.org/bot' . $bot_token . '/';
        $this->chat_id = $chat_id;
        $this->user_id = $user_id;
    }

    /**
     * ارسال پیام
     */
    public function sendMessage($text, $chat_id = null, $keyboard = null, $parse_mode = 'HTML') {
        $chat_id = $chat_id ?? $this->chat_id;

        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ];

        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }

        return $this->makeRequest('sendMessage', $data);
    }

    /**
     * ویرایش پیام
     */
    public function editMessage($message_id, $text, $chat_id = null, $keyboard = null, $parse_mode = 'HTML') {
        $chat_id = $chat_id ?? $this->chat_id;

        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ];

        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }

        return $this->makeRequest('editMessageText', $data);
    }

    /**
     * حذف پیام
     */
    public function deleteMessage($message_id, $chat_id = null) {
        $chat_id = $chat_id ?? $this->chat_id;

        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];

        return $this->makeRequest('deleteMessage', $data);
    }

    /**
     * ساخت کیبورد اینلاین
     */
    public static function inlineKeyboard($buttons) {
        return [
            'inline_keyboard' => $buttons
        ];
    }

    /**
     * ساخت دکمه اینلاین
     *
     * باگ نسخه قبلی: کلید callback_query_id در دکمه ست می‌شد که اصلاً
     * جزو ساختار معتبر دکمه‌های تلگرام نیست و باعث می‌شد بعضی کلاینت‌ها/کتابخانه‌ها
     * دکمه را نامعتبر تشخیص بدهند یا رفتار غیرمنتظره نشان دهند.
     * تنها فیلدهای معتبر: text + (callback_data یا url)
     */
    public static function inlineButton($text, $callback_data, $url = null) {
        $button = ['text' => $text];

        if ($url) {
            $button['url'] = $url;
        } else {
            $button['callback_data'] = $callback_data;
        }

        return $button;
    }

    /**
     * ارسال تاس واقعی تلگرام (انیمیشن + مقدار تصادفی رسمی تلگرام)
     * این خیلی بهتر از تولید عدد رندوم دستی در PHP است، چون کاربر
     * انیمیشن واقعی تاس را می‌بیند و مقدار از سمت تلگرام تضمین می‌شود.
     * emoji می‌تواند 🎲 (۱ تا ۶) یا 🎯 🏀 ⚽ 🎳 🎰 باشد.
     * مقدار خروجی را از: $result['result']['dice']['value'] بخوانید.
     */
    public function sendDice($chat_id, $emoji = '🎲') {
        return $this->makeRequest('sendDice', [
            'chat_id' => $chat_id,
            'emoji' => $emoji
        ]);
    }

    /**
     * پاسخ به callback_query
     */
    public function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
        $data = [
            'callback_query_id' => $callback_query_id,
            'show_alert' => $show_alert ? 'true' : 'false'
        ];

        if ($text) {
            $data['text'] = $text;
        }

        return $this->makeRequest('answerCallbackQuery', $data);
    }

    /**
     * درخواست اطلاعات ربات
     */
    public function getMe() {
        return $this->makeRequest('getMe', []);
    }

    /**
     * دریافت اطلاعات چت
     */
    public function getChat($chat_id = null) {
        $chat_id = $chat_id ?? $this->chat_id;
        return $this->makeRequest('getChat', ['chat_id' => $chat_id]);
    }

    /**
     * تایید صحت درخواست وبهوک با استفاده از secret token
     * (باید هنگام setWebhook مقدار secret_token را هم ست کنید)
     * جلوگیری می‌کند از این‌که هرکسی با دانستن آدرس webhook، آپدیت جعلی بفرستد.
     */
    public function verifyWebhookSecret($expectedSecret) {
        $received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
        return $received !== null && hash_equals($expectedSecret, $received);
    }

    /**
     * ارسال درخواست API
     */
    private function makeRequest($method, $data = []) {
        $url = $this->api_url . $method;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // امنیت: تایید گواهی SSL باید فعال باشد. غیرفعال کردن آن
        // ریسک حمله MITM را باز می‌کند و در نسخه قبلی به اشتباه false بود.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Telegram API cURL Error: " . $curl_error);
            }
            return null;
        }

        if ($http_code !== 200) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Telegram API Error ({$http_code}): " . $response);
            }
        }

        return json_decode($response, true);
    }

    /**
     * تنظیم chat_id
     */
    public function setChatId($chat_id) {
        $this->chat_id = $chat_id;
    }

    /**
     * تنظیم user_id
     */
    public function setUserId($user_id) {
        $this->user_id = $user_id;
    }

    /**
     * دریافت user_id
     */
    public function getUserTelegramId() {
        return $this->user_id;
    }

    /**
     * دریافت chat_id
     */
    public function getChatId() {
        return $this->chat_id;
    }
}

// ==================== User.php ====================
/**
 * کلاس User - مدیریت کاربران
 * User Class - User Management
 *
 * تغییرات نسبت به نسخه اصلی:
 * 1) رفع race condition در subtractBalance: قبلاً ابتدا موجودی چک می‌شد
 *    و بعد در یک تراکنش جدا کم می‌شد. اگر دو درخواست همزمان (مثلاً دو کلیک
 *    سریع روی دکمه شرط‌بندی) برسند، هر دو می‌توانستند از چک اولیه رد شوند
 *    و موجودی کاربر منفی شود (double-spend). حالا کاهش موجودی با یک
 *    UPDATE اتمیک و شرط balance >= amount در همان کوئری انجام می‌شود.
 */

class User {
    private $pdo;
    private $telegram_id;
    private $user_data;

    public function __construct($pdo, $telegram_id) {
        $this->pdo = $pdo;
        $this->telegram_id = $telegram_id;
        $this->loadUser();
    }

    /**
     * بارگذاری یا ایجاد کاربر
     */
    private function loadUser() {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$this->telegram_id]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->createUser();
        } else {
            $this->user_data = $user;
        }
    }

    /**
     * ایجاد کاربر جدید
     */
    private function createUser() {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (telegram_id, created_at, updated_at)
            VALUES (?, NOW(), NOW())
        ");
        $stmt->execute([$this->telegram_id]);

        $user_id = $this->pdo->lastInsertId();

        // ایجاد کیف پول برای کاربر
        $wallet_stmt = $this->pdo->prepare("
            INSERT INTO wallets (user_id, balance, created_at, updated_at)
            VALUES (?, 0, NOW(), NOW())
        ");
        $wallet_stmt->execute([$user_id]);

        $this->loadUser();
    }

    /**
     * به‌روزرسانی اطلاعات کاربر
     */
    public function updateProfile($first_name = null, $last_name = null, $username = null) {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, username = ?, updated_at = NOW()
            WHERE telegram_id = ?
        ");
        $stmt->execute([$first_name, $last_name, $username, $this->telegram_id]);
        $this->loadUser();
    }

    /**
     * دریافت لقب کاربر (یا null اگر هنوز انتخاب نکرده)
     */
    public function getNickname() {
        return $this->user_data['nickname'] ?? null;
    }

    /**
     * نمایش نامی که باید به بقیه نشون داده بشه: لقب (اگه ست کرده) وگرنه اسم تلگرامش
     */
    public function getDisplayName() {
        if (!empty($this->user_data['nickname'])) {
            return $this->user_data['nickname'];
        }
        return $this->user_data['first_name'] ?? ('کاربر ' . $this->telegram_id);
    }

    /**
     * تنظیم لقب - فقط یک‌بار مجاز است (اگه از قبل لقب داشته باشه، false برمی‌گردونه)
     */
    public function setNickname($nickname) {
        if (!empty($this->user_data['nickname'])) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE users SET nickname = ? WHERE id = ?");
        $stmt->execute([$nickname, $this->getId()]);
        $this->loadUser();
        return true;
    }

    /**
     * دریافت شناسه کاربر
     */
    public function getId() {
        return $this->user_data['id'] ?? null;
    }

    /**
     * دریافت تلگرام آی‌دی
     */
    public function getTelegramId() {
        return $this->telegram_id;
    }

    /**
     * دریافت موجودی کیف پول
     */
    public function getBalance() {
        $stmt = $this->pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->execute([$this->getId()]);
        $result = $stmt->fetch();
        return $result['balance'] ?? 0;
    }

    /**
     * بررسی داشتن موجودی کافی (فقط برای نمایش به کاربر مناسب است،
     * برای تصمیم واقعی کم کردن پول از subtractBalance اتمیک استفاده کنید)
     */
    public function hasEnoughBalance($amount) {
        return $this->getBalance() >= $amount;
    }

    /**
     * افزایش موجودی
     */
    public function addBalance($amount, $description = '', $transaction_type = 'deposit', $reference_id = null, $reference_type = null) {
        if ($amount <= 0) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE wallets
                SET balance = balance + ?, total_earned = total_earned + ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$amount, $amount, $this->getId()]);

            $trans_stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, transaction_type, amount, description, reference_id, reference_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $trans_stmt->execute([
                $this->getId(),
                $transaction_type,
                $amount,
                $description,
                $reference_id,
                $reference_type
            ]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('addBalance failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * کاهش موجودی (نسخه اتمیک - جلوگیری از race condition)
     *
     * به جای «چک کن سپس کم کن» که بین این دو مرحله یک درخواست همزمان
     * می‌توانست از چک رد شود، اینجا شرط balance >= amount مستقیماً
     * داخل خودِ UPDATE گذاشته شده. اگر ردیفی تغییر نکند (rowCount == 0)
     * یعنی موجودی کافی نبوده و تراکنش rollback می‌شود.
     */
    public function subtractBalance($amount, $description = '', $transaction_type = 'withdrawal', $reference_id = null, $reference_type = null) {
        if ($amount <= 0) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE wallets
                SET balance = balance - ?, total_spent = total_spent + ?, updated_at = NOW()
                WHERE user_id = ? AND balance >= ?
            ");
            $stmt->execute([$amount, $amount, $this->getId(), $amount]);

            if ($stmt->rowCount() === 0) {
                // موجودی کافی نبود (یا در همین لحظه توسط درخواست دیگری مصرف شده بود)
                $this->pdo->rollBack();
                return false;
            }

            $trans_stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, transaction_type, amount, description, reference_id, reference_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $trans_stmt->execute([
                $this->getId(),
                $transaction_type,
                $amount,
                $description,
                $reference_id,
                $reference_type
            ]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('subtractBalance failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * بررسی ادمین بودن کاربر
     */
    public function isAdmin() {
        return (bool) ($this->user_data['is_admin'] ?? false);
    }

    /**
     * بررسی مسدود بودن کاربر
     */
    public function isBlocked() {
        return (bool) ($this->user_data['is_blocked'] ?? false);
    }

    /**
     * مسدود کردن کاربر
     */
    public function block() {
        $stmt = $this->pdo->prepare("UPDATE users SET is_blocked = TRUE WHERE id = ?");
        $stmt->execute([$this->getId()]);
        $this->loadUser();
    }

    /**
     * رفع مسدودیت کاربر
     */
    public function unblock() {
        $stmt = $this->pdo->prepare("UPDATE users SET is_blocked = FALSE WHERE id = ?");
        $stmt->execute([$this->getId()]);
        $this->loadUser();
    }

    /**
     * دریافت تمام اطلاعات کاربر
     */
    public function getAllData() {
        return $this->user_data;
    }
}

// ==================== Wallet.php ====================
/**
 * کلاس Wallet - مدیریت کیف پول
 * Wallet Class - Wallet Management
 *
 * نکته مهم: فایل اصلی که فرستادید دقیقاً وسط متد deposit() قطع شده بود
 * (به نظر می‌رسد در انتقال فایل از موبایل بریده شده - همان مشکل تکراری
 * file truncation که قبلاً هم داشتید). این نسخه کامل شده و همان باگ
 * race condition که در User.php رفع شد، اینجا هم برای withdraw اعمال شده.
 *
 * همچنین: این کلاس دقیقاً همان کاری را می‌کند که متدهای addBalance/
 * subtractBalance در User.php انجام می‌دهند. داشتن دو پیاده‌سازی جدا
 * برای یک منطق مالی خطرناک است، چون اگر بعداً یکی را اصلاح کنید و
 * دیگری را فراموش کنید، رفتار ناسازگار پیش می‌آید. پیشنهاد: فقط یکی
 * از این دو کلاس را برای عملیات کیف پول نگه دارید.
 */

class Wallet {
    private $pdo;
    private $user_id;

    public function __construct($pdo, $user_id) {
        $this->pdo = $pdo;
        $this->user_id = $user_id;
    }

    /**
     * دریافت موجودی
     */
    public function getBalance() {
        $stmt = $this->pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->execute([$this->user_id]);
        $result = $stmt->fetch();
        return $result['balance'] ?? 0;
    }

    /**
     * افزایش موجودی با تراکنش
     */
    public function deposit($amount, $description = '', $transaction_type = 'deposit', $reference_id = null, $reference_type = null) {
        if ($amount <= 0) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE wallets
                SET balance = balance + ?, total_earned = total_earned + ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$amount, $amount, $this->user_id]);

            $trans_stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, transaction_type, amount, description, reference_id, reference_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $trans_stmt->execute([
                $this->user_id,
                $transaction_type,
                $amount,
                $description,
                $reference_id,
                $reference_type
            ]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('Wallet::deposit failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * کاهش موجودی با تراکنش (نسخه اتمیک، همان اصلاح User::subtractBalance)
     */
    public function withdraw($amount, $description = '', $transaction_type = 'withdrawal', $reference_id = null, $reference_type = null) {
        if ($amount <= 0) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE wallets
                SET balance = balance - ?, total_spent = total_spent + ?, updated_at = NOW()
                WHERE user_id = ? AND balance >= ?
            ");
            $stmt->execute([$amount, $amount, $this->user_id, $amount]);

            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            $trans_stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, transaction_type, amount, description, reference_id, reference_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $trans_stmt->execute([
                $this->user_id,
                $transaction_type,
                $amount,
                $description,
                $reference_id,
                $reference_type
            ]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('Wallet::withdraw failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * تاریخچه تراکنش‌های این کاربر
     */
    public function getTransactionHistory($limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM wallet_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $this->user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

// ==================== Game.php ====================
/**
 * کلاس Game - مدیریت میز بازی تاس
 *
 * نسخه‌ی MVP (ساده‌شده نسبت به کل schema):
 * - هر میز یک راند دارد (num_rounds ثابت = 1) و هر طرف یک تاس می‌اندازد
 *   (num_dice ثابت = 1). ستون‌های num_dice/num_rounds در جدول برای
 *   توسعه‌ی آینده (چند راند/چند تاس) نگه داشته شده‌اند اما فعلاً استفاده
 *   کامل نمی‌شوند - می‌توانید بعداً یک حلقه‌ی round دور همین منطق اضافه کنید.
 * - برنده کل مبلغ شرط دو طرف منهای کارمزد (COMMISSION_PERCENTAGE) را می‌برد.
 * - مبلغ شرط لیدر همان لحظه‌ی ساخت میز از کیف پولش کم می‌شود (رزرو)،
 *   نه در پایان بازی. این جلوی حالتی را می‌گیرد که لیدر میز بسازد،
 *   پول را جای دیگری خرج کند و وقتی حریف پیدا شد پول کافی نداشته باشد.
 */

class Game {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * ساخت میز جدید و رزرو کردن مبلغ شرط از لیدر
     * @param int $numDice تعداد تاس هر راند (پیش‌فرض ۱). بیش از ۳ تاس یعنی مالیات ۱۰٪ به‌جای ۵٪.
     * @return int|false شناسه‌ی میز یا false در صورت موجودی ناکافی
     */
    public function createTable($leaderUser, $betAmount, $numDice = 1) {
        if ($betAmount <= 0 || $numDice <= 0) {
            return false;
        }

        // رزرو مبلغ شرط از همین الان (اتمیک - همان تابع اصلاح‌شده subtractBalance)
        $reserved = $leaderUser->subtractBalance(
            $betAmount,
            'رزرو شرط برای ساخت میز بازی',
            'game_loss', // موقتاً به عنوان کسر ثبت می‌شود؛ اگر لیدر برنده شد در finishRound برگردانده می‌شود
            null,
            'game_table_reserve'
        );

        if (!$reserved) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO game_tables (leader_id, bet_amount, num_dice, num_rounds, status, created_at, updated_at)
            VALUES (?, ?, ?, 1, 'waiting', NOW(), NOW())
        ");
        $stmt->execute([$leaderUser->getId(), $betAmount, $numDice]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * دریافت اطلاعات میز
     */
    public function getTable($tableId) {
        $stmt = $this->pdo->prepare("SELECT * FROM game_tables WHERE id = ?");
        $stmt->execute([$tableId]);
        return $stmt->fetch();
    }

    /**
     * لغو میزی که هنوز کسی جوینش نشده - مبلغ رزرو شده به لیدر برمی‌گردد
     */
    public function cancelTable($tableId, $leaderUser) {
        $table = $this->getTable($tableId);
        if (!$table || $table['status'] !== 'waiting' || (int) $table['leader_id'] !== (int) $leaderUser->getId()) {
            return false;
        }

        $leaderUser->addBalance($table['bet_amount'], 'بازگشت مبلغ رزرو - لغو میز', 'game_win', $tableId, 'game_table_cancel');

        $stmt = $this->pdo->prepare("UPDATE game_tables SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$tableId]);
        return true;
    }

    /**
     * پیوستن نفر دوم به میز - مبلغ شرط او هم رزرو می‌شود
     * @return bool
     */
    public function joinTable($tableId, $memberUser) {
        $table = $this->getTable($tableId);
        if (!$table || $table['status'] !== 'waiting') {
            return false; // میز وجود ندارد یا قبلاً پر/لغو شده
        }
        if ((int) $table['leader_id'] === (int) $memberUser->getId()) {
            return false; // نمی‌تواند با خودش بازی کند
        }

        $reserved = $memberUser->subtractBalance(
            $table['bet_amount'],
            'رزرو شرط برای پیوستن به میز بازی',
            'game_loss',
            $tableId,
            'game_table_reserve'
        );
        if (!$reserved) {
            return false;
        }

        // به صورت اتمیک فقط اگر هنوز waiting است عضو را ثبت کن
        // (جلوگیری از race condition اگر دو نفر همزمان دکمه‌ی پیوستن را بزنند)
        $stmt = $this->pdo->prepare("
            UPDATE game_tables
            SET member_id = ?, status = 'in_progress', updated_at = NOW()
            WHERE id = ? AND status = 'waiting'
        ");
        $stmt->execute([$memberUser->getId(), $tableId]);

        if ($stmt->rowCount() === 0) {
            // یکی دیگر زودتر جوین شد - پول رزرو شده را برگردان
            $memberUser->addBalance($table['bet_amount'], 'بازگشت مبلغ رزرو - میز پر شد', 'game_win', $tableId, 'game_table_reserve');
            return false;
        }

        return true;
    }

    /**
     * آیا این بازیکن قبلاً برای این میز تاس انداخته؟ (برای جلوگیری از دوباره زدن دکمه تاس)
     */
    public function hasPlayerRolled($tableId, $userDbId) {
        $table = $this->getTable($tableId);
        if (!$table) {
            return false;
        }

        $isLeader = (int) $table['leader_id'] === (int) $userDbId;
        $column = $isLeader ? 'leader_total' : 'member_total';

        $stmt = $this->pdo->prepare("SELECT {$column} AS val FROM game_rounds WHERE game_table_id = ? AND round_number = 1");
        $stmt->execute([$tableId]);
        $row = $stmt->fetch();

        return $row && (int) $row['val'] > 0;
    }

    /**
     * ثبت نتیجه‌ی تاس یک بازیکن برای یک میز و تلاش برای تعیین برنده
     * وقتی هر دو طرف تاسشان را انداختند فراخوانی این متد نتیجه را می‌بندد.
     *
     * نسخه‌ی اصلاح‌شده (رفع باگ race condition): قبلاً اگر دو بازیکن تقریباً
     * هم‌زمان تاس می‌انداختند، هر دو درخواست هم‌زمان چک می‌کردند که ردیف
     * game_rounds وجود ندارد و هر کدام یک ردیف جداگانه می‌ساختند - در نتیجه
     * نتیجه‌ی دو بازیکن هیچ‌وقت با هم جمع نمی‌شد و بازی تمام نمی‌شد.
     * الان با INSERT ... ON DUPLICATE KEY UPDATE (که به قید یکتای
     * game_table_id+round_number نیاز دارد) این مسابقه‌ی رقابتی حذف می‌شود.
     *
     * @return array|null اگر بازی هنوز کامل نشده null، وگرنه
     *                     ['winner_id' => int|null, 'leader_value' => int, 'member_value' => int]
     */
    public function submitDiceRoll($tableId, $userDbId, $diceValue) {
        $table = $this->getTable($tableId);
        if (!$table || $table['status'] !== 'in_progress') {
            return null;
        }

        $isLeader = (int) $table['leader_id'] === (int) $userDbId;
        $isMember = (int) $table['member_id'] === (int) $userDbId;
        if (!$isLeader && !$isMember) {
            return null;
        }

        $column = $isLeader ? 'leader_total' : 'member_total';

        // مطمئن می‌شویم ردیف game_rounds برای این میز وجود دارد (بدون رقابت،
        // چون قید یکتا روی game_table_id+round_number باعث می‌شود این INSERT
        // اگر همزمان از دو جا اجرا شود، فقط یک ردیف واقعی ساخته شود)
        $ensure = $this->pdo->prepare("
            INSERT INTO game_rounds (game_table_id, round_number, created_at)
            VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE id = id
        ");
        $ensure->execute([$tableId]);

        // فقط اگر این بازیکن هنوز تاس نینداخته (مقدار ستونش هنوز ۰ است) ثبت کن
        $update = $this->pdo->prepare("
            UPDATE game_rounds
            SET {$column} = ?
            WHERE game_table_id = ? AND round_number = 1 AND {$column} = 0
        ");
        $update->execute([$diceValue, $tableId]);

        if ($update->rowCount() === 0) {
            // این بازیکن قبلاً تاس انداخته بود - دوباره ثبت نکن
            return null;
        }

        $roundStmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE game_table_id = ? AND round_number = 1");
        $roundStmt->execute([$tableId]);
        $round = $roundStmt->fetch();

        // ثبت نتیجه تاس خام (برای شفافیت/تاریخچه)
        $diceStmt = $this->pdo->prepare("
            INSERT INTO dice_results (game_round_id, user_id, dice_value, dice_position, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        $diceStmt->execute([$round['id'], $userDbId, $diceValue]);

        if ((int) $round['leader_total'] > 0 && (int) $round['member_total'] > 0) {
            return $this->finishRound($table, $round);
        }

        return null; // هنوز منتظر تاس نفر دوم هستیم
    }

    /**
     * تعیین برنده و تسویه‌ی مبلغ شرط (پس از کسر کارمزد)
     */
    private function finishRound($table, $round) {
        $tableId = $table['id'];
        $leaderTotal = (int) $round['leader_total'];
        $memberTotal = (int) $round['member_total'];

        $winnerId = null;
        if ($leaderTotal > $memberTotal) {
            $winnerId = $table['leader_id'];
        } elseif ($memberTotal > $leaderTotal) {
            $winnerId = $table['member_id'];
        }
        // در صورت مساوی winnerId=null می‌ماند و هر دو پول رزرو شده‌شان را پس می‌گیرند

        $pot = $table['bet_amount'] * 2;
        $commissionPercent = ((int) $table['num_dice'] > 3) ? 10 : COMMISSION_PERCENTAGE;
        $commission = (int) floor($pot * ($commissionPercent / 100));
        $payout = $pot - $commission;

        if ($winnerId === null) {
            // مساوی: به هرکس مبلغ شرط خودش (بدون کارمزد) برگردد
            $leaderUser = new User($this->pdo, $this->getTelegramIdByDbId($table['leader_id']));
            $memberUser = new User($this->pdo, $this->getTelegramIdByDbId($table['member_id']));
            $leaderUser->addBalance($table['bet_amount'], 'مساوی - بازگشت شرط', 'game_win', $tableId, 'game_table');
            $memberUser->addBalance($table['bet_amount'], 'مساوی - بازگشت شرط', 'game_win', $tableId, 'game_table');
        } else {
            $winnerTelegramId = $this->getTelegramIdByDbId($winnerId);
            $winnerUser = new User($this->pdo, $winnerTelegramId);
            // چون مبلغ شرط خودِ برنده از قبل رزرو (کسر) شده بود، الان کل pot منهای کارمزد را به او می‌دهیم
            $winnerUser->addBalance($payout, 'برد بازی تاس', 'game_win', $tableId, 'game_table');
        }

        $stmt = $this->pdo->prepare("
            UPDATE game_tables
            SET status = 'completed', winner_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$winnerId, $tableId]);

        return [
            'winner_id' => $winnerId,
            'leader_id' => $table['leader_id'],
            'member_id' => $table['member_id'],
            'leader_value' => $leaderTotal,
            'member_value' => $memberTotal,
            'bet_amount' => (int) $table['bet_amount'],
            'payout' => $payout,
            'commission' => $commission,
        ];
    }

    private function getTelegramIdByDbId($dbId) {
        $stmt = $this->pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
        $stmt->execute([$dbId]);
        $row = $stmt->fetch();
        return $row['telegram_id'] ?? null;
    }
}

// ==================== Coupon.php ====================
/**
 * کلاس Coupon - ساخت و استفاده‌ی کد کوپن (شارژ کیف پول)
 */

class Coupon {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * ساخت کوپن جدید (فقط ادمین). کد همیشه به‌صورت خودکار و تصادفی (۱۲ رقم عددی) ساخته می‌شود
     * تا از انتخاب دستی و قابل‌حدس بودن کد جلوگیری شود.
     */
    public function create($amount, $creatorUserId) {
        $code = $this->generateUniqueCode();

        $stmt = $this->pdo->prepare("
            INSERT INTO coupons (code, amount, created_by, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$code, $amount, $creatorUserId]);
        return $code;
    }

    /**
     * تولید یک کد ۱۲ رقمی تصادفی و یکتا (تلاش تا ۵ بار در صورت برخورد نادر)
     */
    private function generateUniqueCode() {
        for ($i = 0; $i < 5; $i++) {
            $code = '';
            for ($j = 0; $j < 12; $j++) {
                $code .= random_int(0, 9);
            }
            $check = $this->pdo->prepare("SELECT id FROM coupons WHERE code = ?");
            $check->execute([$code]);
            if (!$check->fetch()) {
                return $code;
            }
        }
        // fallback بسیار بعیدی که ۵ بار هم برخورد شود
        return (string) time() . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * استفاده از کوپن توسط یک کاربر
     * @return array ['success' => bool, 'message' => string, 'amount' => int|null]
     */
    public function redeem($code, $user) {
        $stmt = $this->pdo->prepare("SELECT * FROM coupons WHERE code = ?");
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();

        if (!$coupon) {
            return ['success' => false, 'message' => 'کد کوپن نامعتبر است.', 'amount' => null];
        }

        // بررسی این‌که همین کاربر قبلاً از این کوپن استفاده نکرده باشد
        $check = $this->pdo->prepare("SELECT id FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?");
        $check->execute([$coupon['id'], $user->getId()]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'شما قبلاً از این کوپن استفاده کرده‌اید.', 'amount' => null];
        }

        try {
            $this->pdo->beginTransaction();

            // درج اتمیک رکورد استفاده - چون UNIQUE KEY(coupon_id, user_id) داریم،
            // اگر دو درخواست همزمان برسند دومی با خطای duplicate key رد می‌شود
            // (این جلوی race condition در ریدیم هم‌زمان را می‌گیرد)
            $insertRedemption = $this->pdo->prepare("
                INSERT INTO coupon_redemptions (coupon_id, user_id, redeemed_at)
                VALUES (?, ?, NOW())
            ");
            $insertRedemption->execute([$coupon['id'], $user->getId()]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'این کوپن هم‌زمان توسط شما استفاده شده است.', 'amount' => null];
        }

        $user->addBalance($coupon['amount'], "استفاده از کوپن {$code}", 'coupon', $coupon['id'], 'coupon');

        return ['success' => true, 'message' => 'کوپن با موفقیت اعمال شد.', 'amount' => $coupon['amount']];
    }
}

// ==================== Withdrawal.php ====================
/**
 * کلاس Withdrawal - درخواست برداشت (تبدیل سکه به پول واقعی)
 *
 * جریان کار:
 * - create(): مبلغ فوراً از کیف پول کاربر رزرو (کسر) می‌شود تا دوباره خرجش نکند،
 *   و یک ردیف با وضعیت pending و یک کد پیگیری یکتا ساخته می‌شود.
 * - approve(): وضعیت را completed می‌کند. چون مبلغ از قبل کسر شده، دیگر کاری
 *   با موجودی نداریم (فرض بر این است که خودتان دستی کارت‌به‌کارت می‌کنید).
 * - reject(): وضعیت را rejected می‌کند و مبلغ رزروشده را به کاربر برمی‌گرداند.
 */

class Withdrawal {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($user, $amount, $cardNumber, $cardHolderName) {
        if ($amount <= 0) {
            return false;
        }

        $reserved = $user->subtractBalance(
            $amount,
            'رزرو مبلغ برای درخواست برداشت',
            'withdrawal',
            null,
            'withdrawal_request'
        );

        if (!$reserved) {
            return false;
        }

        $referenceCode = $this->generateUniqueReference();

        $stmt = $this->pdo->prepare("
            INSERT INTO withdrawal_requests (user_id, amount, card_number, card_holder_name, reference_code, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$user->getId(), $amount, $cardNumber, $cardHolderName, $referenceCode]);

        return [
            'request_id' => (int) $this->pdo->lastInsertId(),
            'reference_code' => $referenceCode,
        ];
    }

    public function getById($requestId) {
        $stmt = $this->pdo->prepare("SELECT * FROM withdrawal_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        return $stmt->fetch();
    }

    /**
     * تایید درخواست - چون مبلغ از قبل کسر شده، فقط وضعیت را عوض می‌کنیم.
     * @return array|false اطلاعات درخواست یا false اگر قبلاً پردازش شده بود
     */
    public function approve($requestId) {
        $request = $this->getById($requestId);
        if (!$request || $request['status'] !== 'pending') {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE withdrawal_requests
            SET status = 'approved', resolved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$requestId]);

        return $stmt->rowCount() > 0 ? $request : false;
    }

    /**
     * رد درخواست - مبلغ رزروشده به کاربر برمی‌گردد.
     * @return array|false اطلاعات درخواست یا false اگر قبلاً پردازش شده بود
     */
    public function reject($requestId) {
        $request = $this->getById($requestId);
        if (!$request || $request['status'] !== 'pending') {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE withdrawal_requests
            SET status = 'rejected', resolved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$requestId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $telegramId = $this->getTelegramIdByUserId($request['user_id']);
        $user = new User($this->pdo, $telegramId);
        $user->addBalance(
            $request['amount'],
            'بازگشت مبلغ - درخواست برداشت رد شد',
            'withdrawal',
            $requestId,
            'withdrawal_request'
        );

        return $request;
    }

    private function getTelegramIdByUserId($userId) {
        $stmt = $this->pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row['telegram_id'] ?? null;
    }

    private function generateUniqueReference() {
        for ($i = 0; $i < 5; $i++) {
            $code = 'WD-' . strtoupper(bin2hex(random_bytes(4)));
            $check = $this->pdo->prepare("SELECT id FROM withdrawal_requests WHERE reference_code = ?");
            $check->execute([$code]);
            if (!$check->fetch()) {
                return $code;
            }
        }
        // fallback بسیار بعیدی که ۵ بار هم برخورد شود
        return 'WD-' . time() . '-' . random_int(100, 999);
    }
}

// ==================== webhook.php ====================
/**
 * webhook.php - نقطه‌ی ورودی تلگرام
 *
 * این فایل رو روی هاست آپلود کنید و آدرسش رو (همون که در WEBHOOK_URL گذاشتید)
 * با یه درخواست یک‌بار مصرف به تلگرام معرفی کنید، مثلاً با باز کردن این
 * آدرس در مرورگر (فقط یک‌بار لازم است):
 *
 * https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<WEBHOOK_SECRET>
 *
 * نکته: مقدار <WEBHOOK_SECRET> باید دقیقاً همان چیزی باشد که در
 * Environment Variable با نام WEBHOOK_SECRET ست کرده‌اید.
 */


$telegram = new TelegramAPI(BOT_TOKEN);

// --- ۱) اعتبارسنجی درخواست (جلوگیری از جعل وبهوک) ---
if (WEBHOOK_SECRET !== '' && !$telegram->verifyWebhookSecret(WEBHOOK_SECRET)) {
    http_response_code(403);
    exit('forbidden');
}

// --- ۲) خواندن آپدیت خام از تلگرام ---
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) {
    http_response_code(200); // به تلگرام 200 بده حتی اگر بدنه خالی/نامعتبر بود
    exit;
}

// --- ۳) الگوی safe_answer برای callback_query ---
// طبق تجربه‌ی قبلی‌تون: اگه answerCallbackQuery دوبار روی یک آپدیت صدا زده بشه
// (مثلاً یه بار داخل هندلر و یه بار در catch)، تلگرام خطای "query is too old"
// می‌ده. این پرچم مطمئن می‌شه فقط یک‌بار پاسخ داده می‌شود.
$callbackAnswered = false;
function safe_answer($telegram, $callbackQueryId, $text = null, $showAlert = false) {
    global $callbackAnswered;
    if ($callbackAnswered) {
        return;
    }
    $telegram->answerCallbackQuery($callbackQueryId, $text, $showAlert);
    $callbackAnswered = true;
}

try {
    if (isset($update['message'])) {
        handleMessage($pdo, $telegram, $update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($pdo, $telegram, $update['callback_query']);
    }
} catch (Throwable $e) {
    if (DEBUG_MODE) {
        error_log('Webhook error: ' . $e->getMessage());
    }
    // در هر حالت به تلگرام 200 برگردون تا آپدیت را دوباره و دوباره ارسال نکند
}

http_response_code(200);
exit;


// ============================================================
// هندلرها
// ============================================================

/**
 * پیام‌های متنی (/start, /help, دکمه‌های reply keyboard و ...)
 */
function handleMessage($pdo, $telegram, $message) {
    $chatId = $message['chat']['id'];
    $telegramId = $message['from']['id'];
    $text = trim($message['text'] ?? '');

    $user = new User($pdo, $telegramId);
    $user->updateProfile(
        $message['from']['first_name'] ?? null,
        $message['from']['last_name'] ?? null,
        $message['from']['username'] ?? null
    );

    if ($user->isBlocked()) {
        $telegram->sendMessage('⛔️ شما مسدود شده‌اید.', $chatId);
        return;
    }

    if ($text === '/start') {
        $telegram->sendMessage(
            "🎲 به ربات بازی تاس خوش آمدید!\n\nموجودی فعلی شما: {$user->getBalance()} سکه",
            $chatId,
            TelegramAPI::inlineKeyboard([
                [TelegramAPI::inlineButton('🎲 ساخت میز جدید', 'new_game')],
                [TelegramAPI::inlineButton('💰 موجودی من', 'balance')],
                [TelegramAPI::inlineButton('🎟 استفاده از کد کوپن', 'coupon_prompt')],
                [TelegramAPI::inlineButton('🏷 انتخاب لقب', 'set_nickname')],
                [TelegramAPI::inlineButton('🏆 رنکینگ', 'ranking')],
                [TelegramAPI::inlineButton('📖 راهنما', 'help')],
            ])
        );
        return;
    }

    if ($text === '/help') {
        send_help_message($telegram, $chatId, $telegramId);
        return;
    }

    // دستور مخصوص ادمین برای ساخت کوپن مستقیم از تلگرام: /addcoupon مبلغ
    // کد به‌صورت خودکار و تصادفی (۱۲ رقم) ساخته می‌شود
    if (str_starts_with($text, '/addcoupon')) {
        if ((string) $telegramId !== (string) ADMIN_ID) {
            $telegram->sendMessage('⛔️ این دستور فقط برای ادمین است.', $chatId);
            return;
        }

        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            $telegram->sendMessage(
                "فرمت درست:\n/addcoupon مبلغ\n\nمثال:\n/addcoupon 1000\n\n(کد به‌صورت خودکار و تصادفی ساخته می‌شود)",
                $chatId
            );
            return;
        }

        $amount = (int) $parts[1];

        $coupon = new Coupon($pdo);
        $createdCode = $coupon->create($amount, $user->getId());
        $telegram->sendMessage("✅ کوپن ساخته شد:\nکد: {$createdCode}\nمبلغ: {$amount} سکه", $chatId);
        return;
    }

    // دستور مخصوص ادمین برای اهدای جایزه هفتگی به ۵ نفر برتر (بر اساس تعداد کد کوپن استفاده‌شده در ۷ روز اخیر)
    if ($text === '/weeklyreward') {
        if ((string) $telegramId !== (string) ADMIN_ID) {
            $telegram->sendMessage('⛔️ این دستور فقط برای ادمین است.', $chatId);
            return;
        }

        run_weekly_reward($pdo, $telegram, $chatId);
        return;
    }

    // وضعیت "منتظر ورودی" کاربر رو یه‌بار می‌خونیم (برای برداشت یا مبلغ دلخواه)
    $awaitingInput = get_awaiting_input($pdo, $user->getId());

    // اگه منتظر اطلاعات برداشت هستیم (کاربر رو دکمه «درخواست برداشت» زده بود)
    if ($awaitingInput === 'withdraw_request') {
        clear_awaiting_input($pdo, $user->getId());

        preg_match('/شماره\s*\(([^)]*)\)/u', $text, $cardMatch);
        preg_match('/مبلغ\s*\(([^)]*)\)/u', $text, $amountMatch);
        preg_match('/اسم\s*\(([^)]*)\)/u', $text, $nameMatch);

        $cardDigitsOnly = isset($cardMatch[1]) ? preg_replace('/\D/', '', $cardMatch[1]) : '';
        $amountDigitsOnly = isset($amountMatch[1]) ? preg_replace('/\D/', '', $amountMatch[1]) : '';
        $holderName = isset($nameMatch[1]) ? trim($nameMatch[1]) : '';

        if (strlen($cardDigitsOnly) !== 16 || $amountDigitsOnly === '' || $holderName === '') {
            send_withdraw_template($telegram, $chatId, true);
            // دوباره منتظر همون فرمت می‌مونیم تا کاربر درست بفرسته
            set_awaiting_input($pdo, $user->getId(), 'withdraw_request');
            return;
        }

        $amount = (int) $amountDigitsOnly;
        $withdrawal = new Withdrawal($pdo);
        $result = $withdrawal->create($user, $amount, $cardDigitsOnly, $holderName);

        if (!$result) {
            $telegram->sendMessage('❌ موجودی کافی نیست.', $chatId);
            return;
        }

        $telegram->sendMessage(
            "✅ درخواست برداشت ثبت شد.\nمبلغ: {$amount} سکه\nکد پیگیری: {$result['reference_code']}\n\nمنتظر تایید ادمین بمانید. مبلغ از موجودی شما کسر شد؛ اگر رد شود، برمی‌گردد.",
            $chatId
        );

        $usernameDisplay = $message['from']['username'] ?? ($message['from']['first_name'] ?? 'کاربر');
        $telegram->sendMessage(
            "🔔 درخواست برداشت جدید\nکاربر: @{$usernameDisplay} (آیدی: {$telegramId})\nمبلغ: {$amount} سکه\nشماره کارت: {$cardDigitsOnly}\nصاحب کارت: {$holderName}\nکد پیگیری: {$result['reference_code']}",
            ADMIN_ID,
            TelegramAPI::inlineKeyboard([
                [
                    TelegramAPI::inlineButton('✅ تایید', 'wd_approve:' . $result['request_id']),
                    TelegramAPI::inlineButton('❌ رد', 'wd_reject:' . $result['request_id']),
                ],
            ])
        );
        return;
    }

    // اگه منتظر مبلغ شرط دلخواه هستیم (کاربر رو دکمه «مبلغ دلخواه» زده بود)
    if ($awaitingInput === 'custom_bet') {
        clear_awaiting_input($pdo, $user->getId());

        if (!ctype_digit($text) || (int) $text <= 0) {
            $telegram->sendMessage('❌ لطفاً فقط یک عدد صحیح مثبت بفرست (مثلاً 2500).', $chatId);
            return;
        }

        $amount = (int) $text;
        send_dice_count_menu($telegram, $chatId, $amount);
        return;
    }

    // اگه منتظر تعداد تاس دلخواه هستیم (مثلاً awaiting_input = "custom_dice:2500")
    if (str_starts_with((string) $awaitingInput, 'custom_dice:')) {
        clear_awaiting_input($pdo, $user->getId());
        $amount = (int) substr($awaitingInput, strlen('custom_dice:'));

        if (!ctype_digit($text) || (int) $text <= 0 || (int) $text > 20) {
            $telegram->sendMessage('❌ لطفاً یک عدد بین ۱ تا ۲۰ برای تعداد تاس بفرست.', $chatId);
            return;
        }

        $numDice = (int) $text;
        $tableId = create_bet_table_and_announce($pdo, $telegram, $user, $chatId, $amount, $numDice);

        if ($tableId === false) {
            $telegram->sendMessage('❌ موجودی کافی نیست.', $chatId);
        }
        return;
    }

    // اگه منتظر لقب هستیم (کاربر رو دکمه «انتخاب لقب» زده بود)
    if ($awaitingInput === 'set_nickname') {
        clear_awaiting_input($pdo, $user->getId());

        $nickname = trim($text);
        if ($nickname === '' || mb_strlen($nickname) > 30) {
            $telegram->sendMessage('❌ لقب باید بین ۱ تا ۳۰ کاراکتر باشد. دوباره امتحان کن.', $chatId);
            return;
        }

        $ok = $user->setNickname($nickname);
        if ($ok) {
            $telegram->sendMessage("✅ لقب شما ثبت شد: {$nickname}", $chatId);
        } else {
            $telegram->sendMessage("شما قبلاً لقب انتخاب کرده‌اید: {$user->getNickname()}\n(هر کاربر فقط یک‌بار می‌تواند لقب انتخاب کند.)", $chatId);
        }
        return;
    }

    // اگر منتظر ورودی کوپن هستیم (state ساده - بدون جدول جدا، فقط برای MVP)
    // برای پروداکشن پیشنهاد می‌شود یک ستون/جدول state واقعی اضافه کنید.
    if (preg_match('/^[A-Za-z0-9]{6,20}$/', $text) && strtoupper($text) === $text) {
        $coupon = new Coupon($pdo);
        $result = $coupon->redeem($text, $user);
        if ($result['success']) {
            $telegram->sendMessage("✅ {$result['amount']} سکه به موجودی شما اضافه شد.", $chatId);
        } else {
            $telegram->sendMessage("❌ {$result['message']}", $chatId);
        }
        return;
    }

    $telegram->sendMessage('دستور نامشخص. برای شروع /start را بزنید.', $chatId);
}

/**
 * کلیک روی دکمه‌های اینلاین
 */
function handleCallbackQuery($pdo, $telegram, $callbackQuery) {
    global $callbackAnswered;

    $callbackId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $telegramId = $callbackQuery['from']['id'];
    $data = $callbackQuery['data'];

    $user = new User($pdo, $telegramId);

    if ($user->isBlocked()) {
        safe_answer($telegram, $callbackId, '⛔️ شما مسدود شده‌اید.', true);
        return;
    }

    $game = new Game($pdo);

    if ($data === 'balance') {
        safe_answer($telegram, $callbackId);
        $telegram->sendMessage(
            "💰 موجودی شما: {$user->getBalance()} سکه",
            $chatId,
            TelegramAPI::inlineKeyboard([
                [TelegramAPI::inlineButton('🏧 درخواست برداشت', 'withdraw_prompt')],
            ])
        );
        return;
    }

    if ($data === 'withdraw_prompt') {
        safe_answer($telegram, $callbackId);
        set_awaiting_input($pdo, $user->getId(), 'withdraw_request');
        send_withdraw_template($telegram, $chatId, false);
        return;
    }

    if ($data === 'help') {
        safe_answer($telegram, $callbackId);
        send_help_message($telegram, $chatId, $telegramId);
        return;
    }

    if ($data === 'set_nickname') {
        safe_answer($telegram, $callbackId);
        if ($user->getNickname()) {
            $telegram->sendMessage("شما قبلاً لقب انتخاب کرده‌اید: {$user->getNickname()}\n(هر کاربر فقط یک‌بار می‌تواند لقب انتخاب کند.)", $chatId);
            return;
        }
        set_awaiting_input($pdo, $user->getId(), 'set_nickname');
        $telegram->sendMessage('لقب خودت رو بفرست (فقط یک‌بار می‌تونی انتخاب کنی، حداکثر ۳۰ کاراکتر):', $chatId);
        return;
    }

    if ($data === 'ranking') {
        safe_answer($telegram, $callbackId);
        send_ranking_message($pdo, $telegram, $chatId);
        return;
    }

    if ($data === 'coupon_prompt') {
        safe_answer($telegram, $callbackId);
        $telegram->sendMessage('کد کوپن را همین‌جا برای من ارسال کنید.', $chatId);
        return;
    }

    if ($data === 'new_game') {
        safe_answer($telegram, $callbackId);
        $telegram->sendMessage(
            'مبلغ شرط را انتخاب کنید:',
            $chatId,
            TelegramAPI::inlineKeyboard([
                [
                    TelegramAPI::inlineButton('1000', 'bet:1000'),
                    TelegramAPI::inlineButton('5000', 'bet:5000'),
                    TelegramAPI::inlineButton('10000', 'bet:10000'),
                ],
                [TelegramAPI::inlineButton('✏️ مبلغ دلخواه', 'bet_custom')],
            ])
        );
        return;
    }

    if ($data === 'bet_custom') {
        safe_answer($telegram, $callbackId);
        set_awaiting_input($pdo, $user->getId(), 'custom_bet');
        $telegram->sendMessage('مبلغ شرط دلخواه رو به عدد بفرست (مثلاً 2500):', $chatId);
        return;
    }

    if (str_starts_with($data, 'bet:')) {
        $amount = (int) substr($data, 4);
        safe_answer($telegram, $callbackId);
        send_dice_count_menu($telegram, $chatId, $amount);
        return;
    }

    if (str_starts_with($data, 'dice_custom:')) {
        $amount = (int) substr($data, strlen('dice_custom:'));
        safe_answer($telegram, $callbackId);
        set_awaiting_input($pdo, $user->getId(), 'custom_dice:' . $amount);
        $telegram->sendMessage('تعداد تاس رو به عدد بفرست (۱ تا ۲۰). بیش از ۳ تاس یعنی مالیات ۱۰٪ به‌جای ۵٪:', $chatId);
        return;
    }

    if (str_starts_with($data, 'dice:')) {
        // فرمت: dice:{amount}:{numDice}
        $payload = substr($data, strlen('dice:'));
        [$amount, $numDice] = array_map('intval', explode(':', $payload));

        $tableId = create_bet_table_and_announce($pdo, $telegram, $user, $chatId, $amount, $numDice);

        if ($tableId === false) {
            safe_answer($telegram, $callbackId, '❌ موجودی کافی نیست.', true);
        } else {
            safe_answer($telegram, $callbackId, '✅ میز ساخته شد.');
        }
        return;
    }

    if (str_starts_with($data, 'cancel:')) {
        $tableId = (int) substr($data, 7);
        $ok = $game->cancelTable($tableId, $user);
        safe_answer($telegram, $callbackId, $ok ? '✅ میز لغو شد و مبلغ برگشت.' : '❌ امکان لغو این میز نیست.', true);
        return;
    }

    if (str_starts_with($data, 'join:')) {
        $tableId = (int) substr($data, 5);
        $joined = $game->joinTable($tableId, $user);

        if (!$joined) {
            safe_answer($telegram, $callbackId, '❌ پیوستن به این میز ممکن نیست (پر شده/موجودی ناکافی).', true);
            return;
        }

        safe_answer($telegram, $callbackId, '✅ به میز پیوستید! تاس خود را بیندازید.');

        // همه‌چیز رو تو همون چتی نگه می‌داریم که بازی توش شروع شده (گروه یا پیوی)
        $tableNumber = format_table_number($tableId);
        $rollKeyboard = TelegramAPI::inlineKeyboard([[TelegramAPI::inlineButton('🎲 انداختن تاس', 'roll:' . $tableId)]]);
        $telegram->sendMessage(
            "🤝 {$user->getDisplayName()} به میز شماره {$tableNumber} پیوست!\nحالا هر دو طرف دکمه زیر را بزنید تا تاس بیندازید:",
            $chatId,
            $rollKeyboard
        );
        return;
    }

    if (str_starts_with($data, 'wd_approve:') || str_starts_with($data, 'wd_reject:')) {
        if ((string) $telegramId !== (string) ADMIN_ID) {
            safe_answer($telegram, $callbackId, '⛔️ فقط ادمین می‌تواند این کار را انجام دهد.', true);
            return;
        }

        $withdrawal = new Withdrawal($pdo);
        $isApprove = str_starts_with($data, 'wd_approve:');
        $requestId = (int) substr($data, $isApprove ? 11 : 10);

        $request = $isApprove ? $withdrawal->approve($requestId) : $withdrawal->reject($requestId);

        if (!$request) {
            safe_answer($telegram, $callbackId, '❌ این درخواست قبلاً پردازش شده.', true);
            return;
        }

        safe_answer($telegram, $callbackId, $isApprove ? '✅ تایید شد.' : '❌ رد شد و مبلغ برگشت.');

        $userTelegramId = get_telegram_id_by_db_id($pdo, $request['user_id']);
        if ($userTelegramId) {
            $msg = $isApprove
                ? "✅ درخواست برداشت شما (کد {$request['reference_code']}) تایید شد.\nحداکثر تا ۱۰ دقیقه دیگر مبلغ به کارتتان واریز می‌شود."
                : "❌ درخواست برداشت شما (کد {$request['reference_code']}) رد شد و مبلغ {$request['amount']} سکه به موجودی‌تان برگشت.";
            $telegram->sendMessage($msg, $userTelegramId);
        }
        return;
    }

    if (str_starts_with($data, 'roll:')) {
        $tableId = (int) substr($data, 5);

        // جلوگیری از باگ قبلی: اگه این بازیکن قبلاً تاس انداخته، دوباره تاس نندازیم
        // (قبلاً هر بار کلیک، یه انیمیشن تاس جدید نشون می‌داد که هیچ اثری نداشت و گیج‌کننده بود)
        if ($game->hasPlayerRolled($tableId, $user->getId())) {
            safe_answer($telegram, $callbackId, 'شما قبلاً تاس انداخته‌اید. منتظر حریف بمانید.', true);
            return;
        }

        $table = $game->getTable($tableId);
        $numDice = $table ? (int) $table['num_dice'] : 1;

        // به تعداد num_dice پشت‌سرهم تاس واقعی تلگرام می‌اندازیم و جمع می‌زنیم
        $diceValue = 0;
        for ($i = 0; $i < $numDice; $i++) {
            $diceResponse = $telegram->sendDice($chatId, '🎲');
            $diceValue += $diceResponse['result']['dice']['value'] ?? 0;
        }

        if ($diceValue === 0) {
            safe_answer($telegram, $callbackId, '❌ خطا در انداختن تاس، دوباره تلاش کنید.', true);
            return;
        }

        safe_answer($telegram, $callbackId);

        $result = $game->submitDiceRoll($tableId, $user->getId(), $diceValue);

        if ($result === null) {
            $telegram->sendMessage("🎲 {$user->getDisplayName()} تاس انداخت: {$diceValue}. منتظر حریف باشید.", $chatId);
            return;
        }

        // بازی تمام شد - پنل «انداختن تاس» رو ببند (کیبورد رو حذف کن) که دیگه قابل کلیک نباشه
        $telegram->editMessage($messageId, '🎲 بازی تمام شد.', $chatId, ['inline_keyboard' => []]);

        $leaderName = get_display_name_by_db_id($pdo, $result['leader_id']);
        $memberName = get_display_name_by_db_id($pdo, $result['member_id']);

        if ($result['winner_id'] === null) {
            $winnerText = "🤝 مساوی شد! ({$leaderName}: {$result['leader_value']} | {$memberName}: {$result['member_value']})\nمبلغ شرط هر دو طرف بدون کسر بازگردانده شد.";
        } else {
            $winnerName = (int) $result['winner_id'] === (int) $result['leader_id'] ? $leaderName : $memberName;
            $winnerText = "🏆 {$winnerName} برنده شد!\n{$leaderName}: {$result['leader_value']} | {$memberName}: {$result['member_value']}\nمبلغ برد (پس از کارمزد): {$result['payout']} سکه";
        }

        $telegram->sendMessage($winnerText, $chatId);

        // پیام جداگانه‌ی افزایش/کاهش موجودی برای هر بازیکن (تو پیوی خودش، مگر این‌که بازی از اول تو همون پیوی بوده)
        $leaderTelegramId = get_telegram_id_by_db_id($pdo, $result['leader_id']);
        $memberTelegramId = get_telegram_id_by_db_id($pdo, $result['member_id']);

        if ($result['winner_id'] === null) {
            $leaderBalanceMsg = "🔁 بازی مساوی شد. {$result['bet_amount']} سکه شرطتان بدون کسر برگشت.";
            $memberBalanceMsg = $leaderBalanceMsg;
        } elseif ((int) $result['winner_id'] === (int) $result['leader_id']) {
            $leaderBalanceMsg = "🎉 شما {$result['payout']} سکه بردید! (کارمزد کسرشده: {$result['commission']} سکه)";
            $memberBalanceMsg = "😔 شما {$result['bet_amount']} سکه باختید.";
        } else {
            $leaderBalanceMsg = "😔 شما {$result['bet_amount']} سکه باختید.";
            $memberBalanceMsg = "🎉 شما {$result['payout']} سکه بردید! (کارمزد کسرشده: {$result['commission']} سکه)";
        }

        if ($leaderTelegramId && (string) $leaderTelegramId !== (string) $chatId) {
            $telegram->sendMessage($leaderBalanceMsg, $leaderTelegramId);
        }
        if ($memberTelegramId && (string) $memberTelegramId !== (string) $chatId && (string) $memberTelegramId !== (string) $leaderTelegramId) {
            $telegram->sendMessage($memberBalanceMsg, $memberTelegramId);
        }
    }
}

/**
 * ارسال راهنما - محتوای متفاوت برای ادمین و اعضای عادی
 */
function send_help_message($telegram, $chatId, $telegramId) {
    if ((string) $telegramId === (string) ADMIN_ID) {
        $telegram->sendMessage(
            "📖 راهنمای ادمین\n\n"
            . "🔹 ساخت کد کوپن:\n/addcoupon مبلغ\nمثال: /addcoupon 1000\n(کد به‌صورت خودکار ۱۲ رقمی ساخته می‌شود)\n\n"
            . "🔹 جایزه‌ی هفتگی:\n/weeklyreward\nبه ۵ نفر برتر (بیشترین کد کوپن استفاده‌شده در ۷ روز اخیر) به‌ترتیب ۵۰/۲۰/۱۰/۵/۵ سکه می‌ده. باید هر هفته دستی اجرا کنید.\n\n"
            . "🔹 درخواست‌های برداشت کاربران:\nوقتی کاربری درخواست برداشت بده، یه پیام با دکمه‌های ✅ تایید / ❌ رد مستقیم برات میاد. بعد از تایید، حداکثر تا ۱۰ دقیقه باید مبلغ رو دستی کارت‌به‌کارت کنی.\n\n"
            . "🔹 دستورات عمومی که خودت هم داری:\n/start - نمایش منوی اصلی\n💰 موجودی من - دیدن موجودی و برداشت\n🎲 ساخت میز جدید - بازی تاس\n🏆 رنکینگ - جدول برترین‌ها",
            $chatId
        );
        return;
    }

    $telegram->sendMessage(
        "📖 راهنما\n\n"
        . "/start - نمایش منوی اصلی\n"
        . "🎲 ساخت میز جدید - انتخاب مبلغ شرط و تعداد تاس (بیش از ۳ تاس یعنی مالیات ۱۰٪)\n"
        . "💰 موجودی من - دیدن موجودی و ثبت درخواست برداشت\n"
        . "🎟 استفاده از کد کوپن - وارد کردن کد کوپن برای دریافت سکه رایگان\n"
        . "🏷 انتخاب لقب - فقط یک‌بار می‌تونی یه لقب برای خودت انتخاب کنی\n"
        . "🏆 رنکینگ - جدول برترین‌ها (بیشترین برد و بیشترین کوپن)\n\n"
        . "برای برداشت، رو «موجودی من» بزن و دکمه‌ی «درخواست برداشت» رو انتخاب کن؛ ربات فرمتی که باید پر کنی رو نشونت می‌ده.",
        $chatId
    );
}

/**
 * ارسال قالب فرم برداشت به کاربر
 */
function send_withdraw_template($telegram, $chatId, $isRetry) {
    $intro = $isRetry
        ? "❌ فرمت درست نبود. لطفاً دقیقاً به همین شکل، خط به خط بفرست (فقط داخل پرانتزها رو با اطلاعات خودت عوض کن):"
        : "برای درخواست برداشت، این ۳ خط رو دقیقاً به همین شکل بفرست (فقط داخل پرانتزها رو با اطلاعات خودت عوض کن):";

    $telegram->sendMessage(
        "{$intro}\n\n"
        . "شماره(شماره کارت ۱۶ رقمی)\n"
        . "مبلغ(مبلغ برداشت به سکه)\n"
        . "اسم(نام و نام خانوادگی صاحب کارت)\n\n"
        . "مثال:\nشماره(6037991199990017)\nمبلغ(5000)\nاسم(علی رضایی)",
        $chatId
    );
}

/**
 * پیدا کردن telegram_id از روی شناسه‌ی داخلی جدول users (برای فرستادن پیام
 * به طرف دیگر بازی که در همین لحظه کاربر فعال webhook نیست)
 */
function get_telegram_id_by_db_id($pdo, $dbId) {
    $stmt = $pdo->prepare('SELECT telegram_id FROM users WHERE id = ?');
    $stmt->execute([$dbId]);
    $row = $stmt->fetch();
    return $row['telegram_id'] ?? null;
}

/**
 * خواندن/نوشتن یه "وضعیت منتظر ورودی بودن" ساده برای هر کاربر
 * (مثلاً وقتی رو دکمه «مبلغ دلخواه» می‌زنه، منتظر می‌مونیم پیام بعدیش رو
 * به‌عنوان عدد مبلغ شرط تفسیر کنیم، نه چیز دیگه‌ای مثل کد کوپن).
 * نیازمند ستون awaiting_input روی جدول users (ALTER TABLE users ADD COLUMN awaiting_input VARCHAR(50) NULL).
 */
function get_awaiting_input($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT awaiting_input FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row['awaiting_input'] ?? null;
}

function set_awaiting_input($pdo, $userId, $value) {
    $stmt = $pdo->prepare('UPDATE users SET awaiting_input = ? WHERE id = ?');
    $stmt->execute([$value, $userId]);
}

function clear_awaiting_input($pdo, $userId) {
    set_awaiting_input($pdo, $userId, null);
}

/**
 * ساخت میز شرط و اعلام عمومی آن (پیوستن/لغو) - مشترک بین انتخاب مبلغ از دکمه‌های آماده و مبلغ دلخواه
 * @return int|false شناسه‌ی میز یا false اگر موجودی کافی نبود
 */
function create_bet_table_and_announce($pdo, $telegram, $user, $chatId, $amount, $numDice = 1) {
    $game = new Game($pdo);
    $tableId = $game->createTable($user, $amount, $numDice);

    if ($tableId === false) {
        return false;
    }

    $tableNumber = format_table_number($tableId);
    $taxNote = $numDice > 3 ? ' (مالیات ۱۰٪ به‌خاطر بیش از ۳ تاس)' : '';

    $telegram->sendMessage(
        "🎲 میز شماره {$tableNumber} ساخته شد.\n"
        . "لقب لیدر: {$user->getDisplayName()}\n"
        . "مبلغ شرط: {$amount} سکه | تعداد تاس: {$numDice}{$taxNote}\n\n"
        . "هرکس می‌خواهد بازی کند «پیوستن» را بزند:",
        $chatId,
        TelegramAPI::inlineKeyboard([
            [TelegramAPI::inlineButton('🤝 پیوستن', 'join:' . $tableId)],
            [TelegramAPI::inlineButton('❌ لغو میز (فقط سازنده)', 'cancel:' . $tableId)],
        ])
    );

    return $tableId;
}

/**
 * نمایش منوی انتخاب تعداد تاس بعد از مشخص شدن مبلغ شرط
 */
function send_dice_count_menu($telegram, $chatId, $amount) {
    $telegram->sendMessage(
        "مبلغ شرط: {$amount} سکه\nحالا تعداد تاس هر نفر رو انتخاب کن (بیش از ۳ تاس یعنی مالیات ۱۰٪ به‌جای ۵٪):",
        $chatId,
        TelegramAPI::inlineKeyboard([
            [
                TelegramAPI::inlineButton('1 تاس', "dice:{$amount}:1"),
                TelegramAPI::inlineButton('2 تاس', "dice:{$amount}:2"),
                TelegramAPI::inlineButton('3 تاس', "dice:{$amount}:3"),
            ],
            [TelegramAPI::inlineButton('✏️ تعداد دلخواه', "dice_custom:{$amount}")],
        ])
    );
}

/**
 * شماره‌ی ۲ رقمی نمایشی میز (فقط برای شناسایی راحت‌تر در گفتگو، نه شناسه‌ی واقعی دیتابیس)
 */
function format_table_number($tableId) {
    return str_pad((string) ($tableId % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * نام نمایشی یک کاربر بر اساس شناسه‌ی داخلی جدول users (لقب اگه ست کرده، وگرنه اسم تلگرام)
 */
function get_display_name_by_db_id($pdo, $dbId) {
    $stmt = $pdo->prepare('SELECT nickname, first_name FROM users WHERE id = ?');
    $stmt->execute([$dbId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'کاربر';
    }
    return !empty($row['nickname']) ? $row['nickname'] : ($row['first_name'] ?? 'کاربر');
}

/**
 * رنکینگ: ۵ نفر برتر بر اساس بیشترین برد، ۵ نفر برتر بر اساس مجموع مبلغ کوپن دریافتی،
 * و ۵ نفر برتر بر اساس تعداد کد کوپن استفاده‌شده - همه به‌جز خودِ ادمین.
 */
function send_ranking_message($pdo, $telegram, $chatId) {
    $lines = ["🏆 رنکینگ\n"];

    $lines[] = "🥇 ۵ نفر برتر بیشترین برد:";
    $stmt = $pdo->prepare("
        SELECT u.nickname, u.first_name, COUNT(*) AS wins
        FROM game_tables gt
        JOIN users u ON u.id = gt.winner_id
        WHERE gt.winner_id IS NOT NULL AND u.telegram_id != ?
        GROUP BY u.id
        ORDER BY wins DESC
        LIMIT 5
    ");
    $stmt->execute([ADMIN_ID]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $lines[] = 'هنوز کسی برد ثبت‌شده‌ای نداره.';
    } else {
        $i = 1;
        foreach ($rows as $row) {
            $name = !empty($row['nickname']) ? $row['nickname'] : ($row['first_name'] ?? 'کاربر');
            $lines[] = "{$i}. {$name} - {$row['wins']} برد";
            $i++;
        }
    }

    $lines[] = "\n🎟 ۵ نفر برتر بیشترین مبلغ کوپن دریافتی:";
    $stmt = $pdo->prepare("
        SELECT u.nickname, u.first_name, SUM(c.amount) AS total_coupon
        FROM coupon_redemptions cr
        JOIN coupons c ON c.id = cr.coupon_id
        JOIN users u ON u.id = cr.user_id
        WHERE u.telegram_id != ?
        GROUP BY u.id
        ORDER BY total_coupon DESC
        LIMIT 5
    ");
    $stmt->execute([ADMIN_ID]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $lines[] = 'هنوز کسی کوپنی استفاده نکرده.';
    } else {
        $i = 1;
        foreach ($rows as $row) {
            $name = !empty($row['nickname']) ? $row['nickname'] : ($row['first_name'] ?? 'کاربر');
            $lines[] = "{$i}. {$name} - {$row['total_coupon']} سکه";
            $i++;
        }
    }

    $lines[] = "\n🔢 ۵ نفر برتر بیشترین تعداد کد کوپن استفاده‌شده:";
    $stmt = $pdo->prepare("
        SELECT u.nickname, u.first_name, COUNT(*) AS redemptions
        FROM coupon_redemptions cr
        JOIN users u ON u.id = cr.user_id
        WHERE u.telegram_id != ?
        GROUP BY u.id
        ORDER BY redemptions DESC
        LIMIT 5
    ");
    $stmt->execute([ADMIN_ID]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $lines[] = 'هنوز کسی کد کوپنی وارد نکرده.';
    } else {
        $i = 1;
        foreach ($rows as $row) {
            $name = !empty($row['nickname']) ? $row['nickname'] : ($row['first_name'] ?? 'کاربر');
            $lines[] = "{$i}. {$name} - {$row['redemptions']} کد";
            $i++;
        }
    }

    $telegram->sendMessage(implode("\n", $lines), $chatId);
}

/**
 * جایزه‌ی هفتگی: به ۵ نفر برتر بر اساس تعداد کد کوپن استفاده‌شده در ۷ روز اخیر
 * (به‌جز ادمین) به‌ترتیب ۵۰ / ۲۰ / ۱۰ / ۵ / ۵ سکه اضافه می‌شود.
 * چون Railway به‌تنهایی کار زمان‌بندی‌شده (cron) اجرا نمی‌کند، این تابع باید
 * دستی توسط ادمین با دستور /weeklyreward هر هفته اجرا شود؛ یا می‌توان یک
 * سرویس رایگان مثل cron-job.org را طوری تنظیم کرد که هفته‌ای یک‌بار یک URL
 * مخصوص را صدا بزند (در صورت تمایل بعداً اضافه می‌کنیم).
 */
function run_weekly_reward($pdo, $telegram, $adminChatId) {
    $rewards = [50, 20, 10, 5, 5];

    $stmt = $pdo->prepare("
        SELECT u.id, u.telegram_id, u.nickname, u.first_name, COUNT(*) AS redemptions
        FROM coupon_redemptions cr
        JOIN users u ON u.id = cr.user_id
        WHERE u.telegram_id != ? AND cr.redeemed_at >= (NOW() - INTERVAL 7 DAY)
        GROUP BY u.id
        ORDER BY redemptions DESC
        LIMIT 5
    ");
    $stmt->execute([ADMIN_ID]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $telegram->sendMessage('هیچ‌کس تو ۷ روز اخیر کد کوپنی استفاده نکرده؛ جایزه‌ای داده نشد.', $adminChatId);
        return;
    }

    $summary = ["🎁 نتیجه‌ی جایزه‌ی هفتگی:"];
    foreach ($rows as $i => $row) {
        $reward = $rewards[$i] ?? 0;
        if ($reward <= 0) {
            break;
        }

        $rewardUser = new User($pdo, $row['telegram_id']);
        $rewardUser->addBalance($reward, 'جایزه هفتگی رنکینگ کوپن', 'coupon', null, 'weekly_reward');

        $name = !empty($row['nickname']) ? $row['nickname'] : ($row['first_name'] ?? 'کاربر');
        $summary[] = ($i + 1) . ". {$name} - {$reward} سکه ({$row['redemptions']} کد در ۷ روز اخیر)";

        $telegram->sendMessage("🎉 تبریک! شما رتبه " . ($i + 1) . " جایزه‌ی هفتگی شدید و {$reward} سکه گرفتید.", $row['telegram_id']);
    }

    $telegram->sendMessage(implode("\n", $summary), $adminChatId);
}

