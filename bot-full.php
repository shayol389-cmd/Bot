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
     * @return int|false شناسه‌ی میز یا false در صورت موجودی ناکافی
     */
    public function createTable($leaderUser, $betAmount) {
        if ($betAmount <= 0) {
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
            VALUES (?, ?, 1, 1, 'waiting', NOW(), NOW())
        ");
        $stmt->execute([$leaderUser->getId(), $betAmount]);

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
     * ثبت نتیجه‌ی تاس یک بازیکن برای یک میز و تلاش برای تعیین برنده
     * وقتی هر دو طرف تاسشان را انداختند فراخوانی این متد نتیجه را می‌بندد.
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

        // یک ردیف game_rounds برای این میز پیدا/بساز (چون فقط ۱ راند داریم، round_number=1)
        $stmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE game_table_id = ? AND round_number = 1");
        $stmt->execute([$tableId]);
        $round = $stmt->fetch();

        if (!$round) {
            $insert = $this->pdo->prepare("
                INSERT INTO game_rounds (game_table_id, round_number, {$column}, created_at)
                VALUES (?, 1, ?, NOW())
            ");
            $insert->execute([$tableId, $diceValue]);
            $roundId = (int) $this->pdo->lastInsertId();
        } else {
            // اگر این بازیکن قبلاً تاس انداخته دوباره قبول نکن
            if ((int) $round[$column] > 0) {
                return null;
            }
            $update = $this->pdo->prepare("UPDATE game_rounds SET {$column} = ? WHERE id = ?");
            $update->execute([$diceValue, $round['id']]);
            $roundId = $round['id'];
        }

        // ثبت نتیجه تاس خام (برای شفافیت/تاریخچه)
        $diceStmt = $this->pdo->prepare("
            INSERT INTO dice_results (game_round_id, user_id, dice_value, dice_position, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        $diceStmt->execute([$roundId, $userDbId, $diceValue]);

        // بررسی این‌که آیا هر دو طرف تاس انداخته‌اند
        $stmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE id = ?");
        $stmt->execute([$roundId]);
        $round = $stmt->fetch();

        if ($round['leader_total'] > 0 && $round['member_total'] > 0) {
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
        $commission = (int) floor($pot * (COMMISSION_PERCENTAGE / 100));
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
            'leader_value' => $leaderTotal,
            'member_value' => $memberTotal,
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
     * ساخت کوپن جدید (فقط ادمین)
     */
    public function create($amount, $creatorUserId, $code = null) {
        $code = $code ?: strtoupper(bin2hex(random_bytes(5))); // مثلاً A1B2C3D4E5

        $stmt = $this->pdo->prepare("
            INSERT INTO coupons (code, amount, created_by, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$code, $amount, $creatorUserId]);
        return $code;
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
            ])
        );
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
    $telegramId = $callbackQuery['from']['id'];
    $data = $callbackQuery['data'];

    $user = new User($pdo, $telegramId);

    if ($user->isBlocked()) {
        safe_answer($telegram, $callbackId, '⛔️ شما مسدود شده‌اید.', true);
        return;
    }

    $game = new Game($pdo);

    if ($data === 'balance') {
        safe_answer($telegram, $callbackId, "موجودی شما: {$user->getBalance()} سکه", true);
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
            ])
        );
        return;
    }

    if (str_starts_with($data, 'bet:')) {
        $amount = (int) substr($data, 4);
        $tableId = $game->createTable($user, $amount);

        if ($tableId === false) {
            safe_answer($telegram, $callbackId, '❌ موجودی کافی نیست.', true);
            return;
        }

        safe_answer($telegram, $callbackId, '✅ میز ساخته شد.');
        // این پیام رو در گروه بفرستید تا بقیه بتونن با دکمه‌ی «پیوستن» وارد بازی بشن.
        // اگه ربات فقط توی چت خصوصی استفاده می‌شه، باید این پیام رو به یه گروه هم فوروارد کنید
        // یا لینک دعوت به چت خصوصی با پارامتر tableId بسازید.
        $telegram->sendMessage(
            "🎲 میز شرط {$amount} سکه‌ای ساخته شد.\nهرکس می‌خواهد بازی کند «پیوستن» را بزند:",
            $chatId,
            TelegramAPI::inlineKeyboard([
                [TelegramAPI::inlineButton('🤝 پیوستن', 'join:' . $tableId)],
                [TelegramAPI::inlineButton('❌ لغو میز (فقط سازنده)', 'cancel:' . $tableId)],
            ])
        );
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

        $table = $game->getTable($tableId);
        $rollKeyboard = TelegramAPI::inlineKeyboard([[TelegramAPI::inlineButton('🎲 انداختن تاس', 'roll:' . $tableId)]]);

        // به حریف (کسی که همین الان پیوست) بگو تاس بیندازد
        $telegram->sendMessage('🎲 حریف پیدا شد! برای انداختن تاس دکمه زیر را بزنید:', $chatId, $rollKeyboard);

        // به لیدر میز هم جدا اطلاع بده و دکمه‌ی تاس بفرست
        // (chat_id خصوصی همیشه با telegram_id کاربر برابر است، پس نیازی به getChat نیست)
        $leaderTelegramId = get_telegram_id_by_db_id($pdo, $table['leader_id']);
        if ($leaderTelegramId) {
            $telegram->sendMessage('🎲 حریف پیدا شد! برای انداختن تاس دکمه زیر را بزنید:', $leaderTelegramId, $rollKeyboard);
        }
        return;
    }

    if (str_starts_with($data, 'roll:')) {
        $tableId = (int) substr($data, 5);
        $diceResponse = $telegram->sendDice($chatId, '🎲');
        $diceValue = $diceResponse['result']['dice']['value'] ?? null;

        if ($diceValue === null) {
            safe_answer($telegram, $callbackId, '❌ خطا در انداختن تاس، دوباره تلاش کنید.', true);
            return;
        }

        safe_answer($telegram, $callbackId);

        $result = $game->submitDiceRoll($tableId, $user->getId(), $diceValue);

        if ($result === null) {
            $telegram->sendMessage("تاس شما ثبت شد ({$diceValue}). منتظر حریف باشید.", $chatId);
            return;
        }

        // بازی تمام شد - به هر دو طرف اعلام کن
        $table = $game->getTable($tableId);
        $winnerText = $result['winner_id'] === null
            ? '🤝 مساوی شد! مبلغ شرط هر دو طرف بازگردانده شد.'
            : "🏆 بازی تمام شد!\nتاس لیدر: {$result['leader_value']} | تاس حریف: {$result['member_value']}";

        $leaderTelegramId = get_telegram_id_by_db_id($pdo, $table['leader_id']);
        $memberTelegramId = get_telegram_id_by_db_id($pdo, $table['member_id']);

        if ($leaderTelegramId) {
            $telegram->sendMessage($winnerText, $leaderTelegramId);
        }
        if ($memberTelegramId && $memberTelegramId !== $leaderTelegramId) {
            $telegram->sendMessage($winnerText, $memberTelegramId);
        }
    }
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

