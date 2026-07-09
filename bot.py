# -*- coding: utf-8 -*-
"""
ربات بازی استراتژی/جنگی «جنگ جهانی» - تلگرام
ساخته‌شده با python-telegram-bot==21.4  و  SQLite

تغییرات این نسخه نسبت به نسخه‌ای که برام فرستادی:

1) فایل قبلی در حین کپی/پیست خراب شده بود: وسط خیلی از کلمه‌ها و توکن‌های
   پایتون (اسم تابع‌ها، رشته‌ها، پرانتزها) به‌طور اتفاقی خط جدید افتاده
   بود (مثلاً "colde\n\nf}" به‌جای "coldef}", یا "logger = logging.getLogger(name)"
   که باید "__name__" باشه). این‌ها باعث SyntaxError میشدن و اصلاً اجرا
   نمیشد. همه این‌ها پیدا و درست شدن.

2) عضویت اجباری در چنل کاملاً حذف شد: تابع is_member_of_channel، دکمه
   عضویت، چک‌کردن عضویت در start و require_ready و متن‌ها، همه حذف
   شدن. حالا کاربر فوراً بعد از /start میره سراغ انتخاب کشور.

3) دیتابیس (SQLite) از قبل هم پایدار بود (روی دیسک ذخیره میشه، نه توی
   حافظه) پس با ری‌استارت خودِ برنامه چیزی پاک نمیشه. چیزی که ممکنه
   باعث بشه انگار «هر ۲۴ ساعت از صفر شروع میشه» معمولاً یکی از این‌هاست:
      - مسیر دیتابیس نسبی بود (فقط "wwiii_game.db") و اگه ربات از پوشه‌ی
        دیگه‌ای اجرا بشه، فایل جدید و خالی می‌سازه. الان مسیر را به‌صورت
        مطلق (کنار خود فایل پایتون) ساختیم که این مشکل پیش نیاد.
      - اگه ربات را روی هاست/سروری اجرا می‌کنی که دیسکش «موقت» است
        (Ephemeral) - مثلاً بعضی پلن‌های رایگان Replit/Railway/Render که
        هر بار ری‌استارت یا ری‌دیپلوی، فایل‌ها را پاک می‌کنن - فایل
        wwiii_game.db هم پاک میشه. این یک محدودیت هاست است، نه باگ کد؛
        باید یا از یک هاست با دیسک دائمی (Persistent Disk / Volume)
        استفاده کنی، یا هر چند ساعت یک‌بار از فایل wwiii_game.db یک
        بک‌آپ بگیری (مثلاً کپی به گوگل‌درایو/تلگرام خودت).
   داخل کد یک تابع کوچک /backup برای مالک اضافه کردم که فایل دیتابیس
   فعلی را برات به پیوی خودت (owner) می‌فرسته تا راحت بک‌آپ بگیری.

4) هندلرهای گم‌شده اضافه شدن (این‌ها در فایل قبلی اصلاً ثبت نشده بودن،
   دقیقاً همون چیزی که باعث می‌شد خیلی از دکمه‌ها هیچ واکنشی نداشته باشن):
      - "menu_alliance"  -> alliance_menu_entry
      - "menu_invite"    -> invite_entry
      - ConversationHandler کامل برای اتحاد (al_create/al_broadcast/
        al_private/al_pmto_/al_help و بقیه‌ی دکمه‌های al_*)
      - ConversationHandler کامل برای دعوت کاربر (inv_redeem/inv_getcode)
      - MessageHandler برای متن‌های بازار (فروش ...، B<code>) فقط در پیوی
      - هندلر گروهی ادمین (بن/ارتقا) و آنتی‌اسپم، هرکدوم در گروه جدا
        تا همدیگه رو مسدود نکنن
      - app.run_polling() که اصلاً در فایل قبلی نبود (بدون این خط ربات
        حتی اگر همه‌چی درست بود، هیچ‌وقت واقعاً اجرا نمی‌شد!)

⚠️ نکته‌ی خیلی مهم امنیتی/فنی:
توکنی که قبلاً اینجا پیست کرده بودی ("8859919884:AAFUk3q0wdPZioB-42g0xDiZbZqDI")
از نظر طول با فرمت واقعی توکن‌های تلگرام (که بعد از : حدود ۳۵ کاراکتر دارن)
همخوانی نداره - به‌نظر می‌رسه یا ناقص کپی شده یا از قبل نامعتبره. اگه ربات
حتی بعد از این اصلاحات بالا نیومد، اول از BotFather یه توکن تازه با
/revoke و /token بگیر و دقیقاً کامل (بدون فاصله/کات‌شدگی) اینجا بذار،
چون این رایج‌ترین دلیل «ربات اصلاً روشن نمیشه» است.

BOT_TOKEN و OWNER_USER_ID و OWNER_ANNOUNCE_CHANNEL_ID را خودت پر کن.
"""

import os
import sqlite3
import random
import string
import logging
from datetime import datetime

from telegram import (
    Update,
    InlineKeyboardMarkup,
    InlineKeyboardButton,
)
from telegram.ext import (
    Application,
    ApplicationBuilder,
    CommandHandler,
    CallbackQueryHandler,
    MessageHandler,
    ConversationHandler,
    ContextTypes,
    filters,
)
from telegram.constants import ParseMode

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

# ======================================================================
#                              تنظیمات (CONFIG)
#   این‌ها را با اطلاعات خودت پر کن.
# ======================================================================

BOT_TOKEN = "8658314282:AAGcVifNujg4R2XdIbhWZRiwyufKyHwmg1s"
# ⚠️ توکن رو کامل و تازه (از BotFather) اینجا بذار. اگه قبلاً یه توکن رو
# جایی پیست/شیر کردی، حتماً با /revoke عوضش کن.

# چنلی که دکمه «اعلامیه» پیام‌ها را آنجا منتشر می‌کند (باید ربات ادمینش باشد)
OWNER_ANNOUNCE_CHANNEL_ID = -1003953798967 # ⚠️ پر کن

# آیدی عددی مالک اصلی ربات (خودت). با دستور /id میشه آیدی عددی تلگرام رو گرفت
OWNER_USER_ID = 7837042019 # ⚠️ حتماً آیدی عددی خودتو (خروجی /id) اینجا بذار

# مسیر مطلق دیتابیس - کنار همین فایل ساخته میشه، مهم نیست از کجا اجرا کنی
DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wwiii_game.db")


# ======================================================================
#                          پایگاه داده (DATABASE)
# ======================================================================


def get_conn():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def _safe_add_column(cur, table, coldef):
    """برای مهاجرت دیتابیس‌های قدیمی: اگه ستون از قبل باشه خطا رو نادیده می‌گیریم."""
    try:
        cur.execute(f"ALTER TABLE {table} ADD COLUMN {coldef}")
    except sqlite3.OperationalError:
        pass


def init_db():
    conn = get_conn()
    cur = conn.cursor()

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            country_id INTEGER,
            coins REAL DEFAULT 3000000,
            is_admin INTEGER DEFAULT 0,
            is_owner INTEGER DEFAULT 0,
            warnings INTEGER DEFAULT 0,
            banned INTEGER DEFAULT 0,
            gold_cups INTEGER DEFAULT 0,
            alliance_id INTEGER,
            joined_at TEXT,
            last_mine_collect TEXT,
            last_factory_collect TEXT,
            referred_by INTEGER,
            used_referral_code INTEGER DEFAULT 0
        )
        """
    )
    _safe_add_column(cur, "users", "referred_by INTEGER")
    _safe_add_column(cur, "users", "used_referral_code INTEGER DEFAULT 0")

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS countries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            owner_id INTEGER,
            destroyed INTEGER DEFAULT 0
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS factories (
            user_id INTEGER,
            category TEXT,
            factory_key TEXT,
            blueprint_name TEXT,
            PRIMARY KEY (user_id, category)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS blueprints_owned (
            user_id INTEGER,
            category TEXT,
            unit_name TEXT,
            PRIMARY KEY (user_id, category, unit_name)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS mines (
            user_id INTEGER,
            mine_key TEXT,
            quantity INTEGER DEFAULT 0,
            bonus_quantity INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, mine_key)
        )
        """
    )
    _safe_add_column(cur, "mines", "quantity INTEGER DEFAULT 0")
    _safe_add_column(cur, "mines", "bonus_quantity INTEGER DEFAULT 0")

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS garrisons (
            user_id INTEGER PRIMARY KEY,
            count INTEGER DEFAULT 0
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS units (
            user_id INTEGER,
            category TEXT,
            unit_name TEXT,
            quantity INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, category, unit_name)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS alliances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            leader_id INTEGER,
            created_at TEXT
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS alliance_members (
            alliance_id INTEGER,
            user_id INTEGER,
            joined_at TEXT,
            PRIMARY KEY (alliance_id, user_id)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS alliance_donations (
            user_id INTEGER,
            donate_date TEXT,
            count INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, donate_date)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS market (
            code TEXT PRIMARY KEY,
            seller_id INTEGER,
            category TEXT,
            item_name TEXT,
            quantity INTEGER,
            price REAL,
            active INTEGER DEFAULT 1,
            created_at TEXT
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS battle_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attacker_id INTEGER,
            defender_id INTEGER,
            result TEXT,
            timestamp TEXT
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS reserve_pool (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            amount REAL DEFAULT 0
        )
        """
    )
    cur.execute("INSERT OR IGNORE INTO reserve_pool (id, amount) VALUES (1, 0)")

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS ad_warnings (
            user_id INTEGER,
            chat_id INTEGER,
            count INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, chat_id)
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS referral_codes (
            code TEXT PRIMARY KEY,
            owner_id INTEGER,
            created_at TEXT,
            used_by INTEGER,
            used_at TEXT
        )
        """
    )

    conn.commit()

    cur.execute("SELECT COUNT(*) as c FROM countries")
    if cur.fetchone()["c"] == 0:
        for name in COUNTRIES_LIST:
            cur.execute("INSERT INTO countries (name) VALUES (?)", (name,))
        conn.commit()

    conn.close()


# ======================================================================
#                    GAME DATA  (داده‌های بازی)
# ======================================================================

COUNTRIES_LIST = [
    "آمریکا", "روسیه", "چین", "ایران", "آلمان", "فرانسه", "انگلیس", "ژاپن",
    "کره جنوبی", "هند", "برزیل", "ترکیه", "عربستان", "مصر", "پاکستان",
    "اسرائیل", "ایتالیا", "اسپانیا", "کانادا", "استرالیا", "اوکراین",
    "لهستان", "سوئد", "هلند", "مکزیک", "آرژانتین", "اندونزی", "ویتنام",
    "تایلند", "عراق",
]


def format_money(amount: float) -> str:
    amount = float(amount)
    sign = "-" if amount < 0 else ""
    amount = abs(amount)
    if amount >= 1_000_000_000:
        return f"{sign}{amount / 1_000_000_000:.2f} بیل"
    if amount >= 1_000_000:
        return f"{sign}{amount / 1_000_000:.2f} میل"
    if amount >= 1_000:
        return f"{sign}{amount / 1_000:.2f} کا"
    return f"{sign}{amount:.0f}"


FACTORY_CATEGORIES = {
    "jet": {
        "label": "کارخونه ساخت جت جنگی",
        "types": [
            {"key": "jet_a", "name": "کارخونه جت مدل سبک (Falcon)", "price": 3_000_000, "rate": 1},
            {"key": "jet_b", "name": "کارخونه جت مدل میانی (Phantom)", "price": 6_000_000, "rate": 2},
            {"key": "jet_c", "name": "کارخونه جت مدل سنگین (Raptor)", "price": 12_000_000, "rate": 4},
        ],
    },
    "navy": {
        "label": "کارخونه ساخت ناو جنگی",
        "types": [
            {"key": "navy_a", "name": "اسکله کشتی‌سازی کوچک", "price": 4_000_000, "rate": 1},
            {"key": "navy_b", "name": "اسکله کشتی‌سازی متوسط", "price": 8_000_000, "rate": 2},
            {"key": "navy_c", "name": "اسکله کشتی‌سازی بزرگ", "price": 15_000_000, "rate": 3},
        ],
    },
    "defense": {
        "label": "کارخونه ساخت پدافند هوایی و دریایی",
        "types": [
            {"key": "def_a", "name": "کارخونه پدافند سبک", "price": 2_500_000, "rate": 2},
            {"key": "def_b", "name": "کارخونه پدافند متوسط", "price": 5_000_000, "rate": 3},
            {"key": "def_c", "name": "کارخونه پدافند سنگین", "price": 9_000_000, "rate": 5},
        ],
    },
    "ground": {
        "label": "کارخونه ساخت نیروی زمینی",
        "types": [
            {"key": "grd_a", "name": "کارخونه نظامی کوچک", "price": 2_000_000, "rate": 3},
            {"key": "grd_b", "name": "کارخونه نظامی متوسط", "price": 4_500_000, "rate": 5},
            {"key": "grd_c", "name": "کارخونه نظامی بزرگ", "price": 8_000_000, "rate": 8},
        ],
    },
    "missile": {
        "label": "کارخونه ساخت موشک",
        "types": [
            {"key": "msl_a", "name": "سایت موشکی کوچک", "price": 6_000_000, "rate": 1},
            {"key": "msl_b", "name": "سایت موشکی متوسط", "price": 12_000_000, "rate": 2},
            {"key": "msl_c", "name": "سایت موشکی بزرگ", "price": 20_000_000, "rate": 3},
        ],
    },
}

BLUEPRINTS = {
    "jet": [
        {"name": "جنگنده J-1", "price": 500_000, "power": 12},
        {"name": "جنگنده رهگیر J-2", "price": 900_000, "power": 20},
        {"name": "بمب‌افکن استراتژیک J-3", "price": 1_800_000, "power": 35},
    ],
    "navy": [
        {"name": "ناوچه تندرو", "price": 700_000, "power": 15},
        {"name": "ناوشکن", "price": 1_400_000, "power": 28},
        {"name": "ناو هواپیمابر", "price": 3_000_000, "power": 55},
    ],
    "defense": [
        {"name": "سامانه پدافند کوتاه‌برد", "price": 400_000, "power": 10},
        {"name": "سامانه پدافند میان‌برد", "price": 800_000, "power": 18},
        {"name": "سامانه پدافند دوربرد (S-موشک)", "price": 1_600_000, "power": 32},
    ],
    "ground": [
        {"name": "تانک سبک", "price": 250_000, "power": 6},
        {"name": "تانک سنگین", "price": 500_000, "power": 12},
        {"name": "نفربر زرهی", "price": 350_000, "power": 8},
        {"name": "خودروی زرهی", "price": 200_000, "power": 5},
        {"name": "توپخانه خودکششی", "price": 600_000, "power": 14},
        {"name": "نیروی پیاده مکانیزه", "price": 150_000, "power": 4},
        {"name": "تانک پیشرفته اسموش", "price": 1_000_000, "power": 22},
        {"name": "سامانه راکتی زمینی", "price": 700_000, "power": 16},
    ],
    "missile": [
        {"name": "موشک کوتاه‌برد", "price": 1_000_000, "power": 25},
        {"name": "موشک میان‌برد", "price": 2_000_000, "power": 45},
        {"name": "موشک بالستیک", "price": 4_000_000, "power": 80},
        {"name": "موشک کروز", "price": 3_000_000, "power": 65},
        {"name": "موشک قاره‌پیما", "price": 8_000_000, "power": 150},
    ],
}

CATEGORY_LABELS = {
    "jet": "✈️ جت جنگی",
    "navy": "🚢 ناو جنگی",
    "defense": "🛡 پدافند",
    "ground": "🪖 نیروی زمینی",
    "missile": "🚀 موشک",
}

MINES = {
    "oil": {"name": "نفت", "price": 2_000_000, "income_hour": 40_000},
    "gas": {"name": "گاز طبیعی", "price": 1_800_000, "income_hour": 35_000},
    "gold": {"name": "طلا", "price": 3_000_000, "income_hour": 60_000},
    "diamond": {"name": "الماس", "price": 4_000_000, "income_hour": 80_000},
    "tea": {"name": "چای", "price": 500_000, "income_hour": 10_000},
    "cotton": {"name": "پنبه", "price": 600_000, "income_hour": 12_000},
    "copper": {"name": "مس", "price": 1_200_000, "income_hour": 24_000},
    "iron": {"name": "آهن", "price": 1_000_000, "income_hour": 20_000},
    "uranium": {"name": "اورانیوم", "price": 5_000_000, "income_hour": 100_000},
    "wood": {"name": "چوب", "price": 300_000, "income_hour": 6_000},
}
MAX_MINES_PER_TYPE = 5

STARTER_FREE_MINE_KEY = "wood"

GARRISON_BASE_PRICE = 1_000_000
GARRISON_PRICE_GROWTH = 1.25
GARRISON_CAPACITY_EACH = 50

SPECIAL_DEFENSE_ITEMS = [
    {"key": "shield", "name": "سپر دفاعی موقت (۲۴ ساعت -30٪ خسارت)", "price": 1_500_000},
    {"key": "radar", "name": "رادار پیشرفته (هشدار حمله)", "price": 1_000_000},
    {"key": "emp", "name": "بمب EMP (فلج موشکی حریف در حمله بعدی)", "price": 2_500_000},
]

ALLIANCE_CREATE_COST = 50_000
ALLIANCE_MAX_MEMBERS = 5
ALLIANCE_DAILY_DONATION_LIMIT = 5

WARN_LIMIT = 3

REFERRAL_INVITEE_TEA_BONUS = 1
REFERRAL_OWNER_TEA_BONUS = 2
REFERRAL_INCOME_SHARE = 0.10


# ======================================================================
#                       توابع کمکی پایگاه داده (DB HELPERS)
# ======================================================================


def now_str():
    return datetime.utcnow().isoformat()


def get_user(user_id):
    conn = get_conn()
    row = conn.execute("SELECT * FROM users WHERE user_id=?", (user_id,)).fetchone()
    conn.close()
    return row


def create_user_if_needed(user_id, username):
    conn = get_conn()
    row = conn.execute("SELECT * FROM users WHERE user_id=?", (user_id,)).fetchone()
    if row is None:
        is_owner = 1 if user_id == OWNER_USER_ID else 0
        conn.execute(
            "INSERT INTO users (user_id, username, coins, is_admin, is_owner, joined_at, last_mine_collect, last_factory_collect) "
            "VALUES (?,?,?,?,?,?,?,?)",
            (user_id, username, 3_000_000, is_owner, is_owner, now_str(), now_str(), now_str()),
        )
        conn.execute("INSERT OR IGNORE INTO garrisons (user_id, count) VALUES (?, 0)", (user_id,))
        conn.execute(
            "INSERT OR IGNORE INTO mines (user_id, mine_key, quantity, bonus_quantity) VALUES (?,?,1,0)",
            (user_id, STARTER_FREE_MINE_KEY),
        )
        conn.commit()
    else:
        conn.execute("UPDATE users SET username=? WHERE user_id=?", (username, user_id))
        conn.commit()
    conn.close()


def is_unlimited(user_id):
    u = get_user(user_id)
    return bool(u and (u["is_owner"] or u["is_admin"]))


def get_balance(user_id):
    if is_unlimited(user_id):
        return float("inf")
    u = get_user(user_id)
    return u["coins"] if u else 0


def add_coins(user_id, amount):
    if is_unlimited(user_id):
        return
    conn = get_conn()
    conn.execute("UPDATE users SET coins = coins + ? WHERE user_id=?", (amount, user_id))
    conn.commit()
    conn.close()


def spend_coins(user_id, amount) -> bool:
    if is_unlimited(user_id):
        return True
    conn = get_conn()
    row = conn.execute("SELECT coins FROM users WHERE user_id=?", (user_id,)).fetchone()
    if not row or row["coins"] < amount:
        conn.close()
        return False
    conn.execute("UPDATE users SET coins = coins - ? WHERE user_id=?", (amount, user_id))
    conn.commit()
    conn.close()
    return True


def get_country(country_id):
    conn = get_conn()
    row = conn.execute("SELECT * FROM countries WHERE id=?", (country_id,)).fetchone()
    conn.close()
    return row


def get_country_by_owner(user_id):
    conn = get_conn()
    row = conn.execute("SELECT * FROM countries WHERE owner_id=? AND destroyed=0", (user_id,)).fetchone()
    conn.close()
    return row


def available_countries():
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM countries WHERE owner_id IS NULL AND destroyed=0 ORDER BY name"
    ).fetchall()
    conn.close()
    return rows


def assign_country(user_id, country_id):
    conn = get_conn()
    conn.execute("UPDATE countries SET owner_id=? WHERE id=?", (user_id, country_id))
    conn.execute("UPDATE users SET country_id=? WHERE user_id=?", (country_id, user_id))
    conn.commit()
    conn.close()


def destroy_country(user_id):
    conn = get_conn()
    conn.execute("UPDATE countries SET owner_id=NULL, destroyed=1 WHERE owner_id=?", (user_id,))
    conn.execute("UPDATE users SET country_id=NULL WHERE user_id=?", (user_id,))
    conn.commit()
    conn.close()


# ---------- کارخونه‌ها ----------

def get_user_factory(user_id, category):
    conn = get_conn()
    row = conn.execute(
        "SELECT * FROM factories WHERE user_id=? AND category=?", (user_id, category)
    ).fetchone()
    conn.close()
    return row


def buy_factory(user_id, category, factory_key):
    conn = get_conn()
    conn.execute(
        "INSERT OR REPLACE INTO factories (user_id, category, factory_key, blueprint_name) "
        "VALUES (?,?,?, (SELECT blueprint_name FROM factories WHERE user_id=? AND category=?))",
        (user_id, category, factory_key, user_id, category),
    )
    conn.commit()
    conn.close()


def set_factory_blueprint(user_id, category, unit_name):
    conn = get_conn()
    conn.execute(
        "UPDATE factories SET blueprint_name=? WHERE user_id=? AND category=?",
        (unit_name, user_id, category),
    )
    conn.commit()
    conn.close()


def own_blueprint(user_id, category, unit_name):
    conn = get_conn()
    conn.execute(
        "INSERT OR IGNORE INTO blueprints_owned (user_id, category, unit_name) VALUES (?,?,?)",
        (user_id, category, unit_name),
    )
    conn.commit()
    conn.close()


def get_owned_blueprints(user_id, category):
    conn = get_conn()
    rows = conn.execute(
        "SELECT unit_name FROM blueprints_owned WHERE user_id=? AND category=?",
        (user_id, category),
    ).fetchall()
    conn.close()
    return [r["unit_name"] for r in rows]


def get_all_factories(user_id):
    conn = get_conn()
    rows = conn.execute("SELECT * FROM factories WHERE user_id=?", (user_id,)).fetchall()
    conn.close()
    return rows


# ---------- معدن‌ها ----------

def get_user_mines(user_id):
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM mines WHERE user_id=? AND (quantity>0 OR bonus_quantity>0)", (user_id,)
    ).fetchall()
    conn.close()
    return rows


def get_mine_row(user_id, mine_key):
    conn = get_conn()
    row = conn.execute(
        "SELECT * FROM mines WHERE user_id=? AND mine_key=?", (user_id, mine_key)
    ).fetchone()
    conn.close()
    return row


def mine_paid_quantity(user_id, mine_key):
    row = get_mine_row(user_id, mine_key)
    return row["quantity"] if row else 0


def buy_mine(user_id, mine_key):
    conn = get_conn()
    conn.execute(
        "INSERT INTO mines (user_id, mine_key, quantity, bonus_quantity) VALUES (?,?,1,0) "
        "ON CONFLICT(user_id, mine_key) DO UPDATE SET quantity = quantity + 1",
        (user_id, mine_key),
    )
    conn.commit()
    conn.close()


def add_bonus_mine(user_id, mine_key, qty=1):
    conn = get_conn()
    conn.execute(
        "INSERT INTO mines (user_id, mine_key, quantity, bonus_quantity) VALUES (?,?,0,?) "
        "ON CONFLICT(user_id, mine_key) DO UPDATE SET bonus_quantity = bonus_quantity + ?",
        (user_id, mine_key, qty, qty),
    )
    conn.commit()
    conn.close()


# ---------- پادگان ----------

def get_garrison_count(user_id):
    conn = get_conn()
    row = conn.execute("SELECT count FROM garrisons WHERE user_id=?", (user_id,)).fetchone()
    conn.close()
    return row["count"] if row else 0


def garrison_price(user_id):
    c = get_garrison_count(user_id)
    return round(GARRISON_BASE_PRICE * (GARRISON_PRICE_GROWTH ** c))


def buy_garrison(user_id):
    conn = get_conn()
    conn.execute(
        "INSERT INTO garrisons (user_id, count) VALUES (?,1) "
        "ON CONFLICT(user_id) DO UPDATE SET count = count + 1",
        (user_id,),
    )
    conn.commit()
    conn.close()


def garrison_capacity(user_id):
    return get_garrison_count(user_id) * GARRISON_CAPACITY_EACH


# ---------- واحدهای نظامی ----------

def get_units(user_id):
    conn = get_conn()
    rows = conn.execute("SELECT * FROM units WHERE user_id=? AND quantity>0", (user_id,)).fetchall()
    conn.close()
    return rows


def get_total_units_count(user_id):
    conn = get_conn()
    row = conn.execute(
        "SELECT COALESCE(SUM(quantity),0) as s FROM units WHERE user_id=?", (user_id,)
    ).fetchone()
    conn.close()
    return row["s"]


def add_units(user_id, category, unit_name, qty):
    if qty <= 0:
        return
    conn = get_conn()
    conn.execute(
        "INSERT INTO units (user_id, category, unit_name, quantity) VALUES (?,?,?,?) "
        "ON CONFLICT(user_id, category, unit_name) DO UPDATE SET quantity = quantity + ?",
        (user_id, category, unit_name, qty, qty),
    )
    conn.commit()
    conn.close()


def remove_units(user_id, category, unit_name, qty):
    conn = get_conn()
    conn.execute(
        "UPDATE units SET quantity = MAX(quantity - ?, 0) WHERE user_id=? AND category=? AND unit_name=?",
        (qty, user_id, category, unit_name),
    )
    conn.commit()
    conn.close()


def unit_power_lookup(category, unit_name):
    for it in BLUEPRINTS.get(category, []):
        if it["name"] == unit_name:
            return it["power"]
    return 0


def total_military_power(user_id):
    total = 0.0
    for row in get_units(user_id):
        total += unit_power_lookup(row["category"], row["unit_name"]) * row["quantity"]
    return total


def total_defense_power(user_id):
    total = 0.0
    for row in get_units(user_id):
        p = unit_power_lookup(row["category"], row["unit_name"])
        mult = 1.3 if row["category"] in ("defense", "missile") else 1.0
        total += p * row["quantity"] * mult
    total += get_garrison_count(user_id) * 15
    return total


# ---------- برداشت منابع ----------

def collect_mine_income(user_id):
    u = get_user(user_id)
    if not u:
        return 0
    last = datetime.fromisoformat(u["last_mine_collect"]) if u["last_mine_collect"] else datetime.utcnow()
    hours = max((datetime.utcnow() - last).total_seconds() / 3600.0, 0)
    hours = min(hours, 72)  # سقف ذخیره‌سازی ۷۲ ساعت (این یعنی اگه بیشتر از ۳ روز نیای، فقط ۳ روز حساب میشه - نه اینکه صفر بشه)

    mines = get_user_mines(user_id)
    income = 0.0
    for m in mines:
        total_qty = (m["quantity"] or 0) + (m["bonus_quantity"] or 0)
        income += MINES[m["mine_key"]]["income_hour"] * total_qty
    income *= hours

    conn = get_conn()
    conn.execute("UPDATE users SET last_mine_collect=? WHERE user_id=?", (now_str(), user_id))
    conn.commit()
    conn.close()

    if income > 0:
        add_coins(user_id, income)
        if u["referred_by"]:
            ref_share = income * REFERRAL_INCOME_SHARE
            if ref_share > 0:
                add_coins(u["referred_by"], ref_share)
    return income


def collect_factory_production(user_id):
    u = get_user(user_id)
    if not u:
        return {}
    last = (
        datetime.fromisoformat(u["last_factory_collect"])
        if u["last_factory_collect"]
        else datetime.utcnow()
    )
    hours = max((datetime.utcnow() - last).total_seconds() / 3600.0, 0)
    hours = min(hours, 72)
    produced = {}
    factories = get_all_factories(user_id)
    for f in factories:
        if not f["blueprint_name"]:
            continue
        types = FACTORY_CATEGORIES[f["category"]]["types"]
        rate = next((t["rate"] for t in types if t["key"] == f["factory_key"]), 0)
        qty = int(rate * hours)
        if qty > 0:
            add_units(user_id, f["category"], f["blueprint_name"], qty)
            produced[f["blueprint_name"]] = qty
    conn = get_conn()
    conn.execute("UPDATE users SET last_factory_collect=? WHERE user_id=?", (now_str(), user_id))
    conn.commit()
    conn.close()
    return produced


# ---------- سیستم دعوت / زیرمجموعه‌گیری ----------

def generate_referral_code():
    return "".join(random.choices(string.digits, k=6))


def create_referral_code(owner_id):
    conn = get_conn()
    while True:
        code = generate_referral_code()
        exists = conn.execute("SELECT 1 FROM referral_codes WHERE code=?", (code,)).fetchone()
        if not exists:
            break
    conn.execute(
        "INSERT INTO referral_codes (code, owner_id, created_at) VALUES (?,?,?)",
        (code, owner_id, now_str()),
    )
    conn.commit()
    conn.close()
    return code


def get_referral_code(code):
    conn = get_conn()
    row = conn.execute("SELECT * FROM referral_codes WHERE code=?", (code,)).fetchone()
    conn.close()
    return row


def redeem_referral_code(user_id, code):
    u = get_user(user_id)
    if u and u["used_referral_code"]:
        return False, "شما قبلاً یک کد جایزه استفاده کردی! هر کاربر فقط یک‌بار می‌تونه از این قابلیت استفاده کنه."

    row = get_referral_code(code)
    if not row:
        return False, "این کد جایزه معتبر نیست."
    if row["used_by"]:
        return False, "این کد قبلاً توسط یک نفر دیگه استفاده شده."
    if row["owner_id"] == user_id:
        return False, "نمی‌تونی از کد دعوت خودت استفاده کنی!"

    conn = get_conn()
    conn.execute(
        "UPDATE referral_codes SET used_by=?, used_at=? WHERE code=?",
        (user_id, now_str(), code),
    )
    conn.execute(
        "UPDATE users SET used_referral_code=1, referred_by=? WHERE user_id=?",
        (row["owner_id"], user_id),
    )
    conn.commit()
    conn.close()

    add_bonus_mine(user_id, "tea", REFERRAL_INVITEE_TEA_BONUS)
    add_bonus_mine(row["owner_id"], "tea", REFERRAL_OWNER_TEA_BONUS)

    return True, row["owner_id"]


# ---------- اتحاد (Alliance) ----------

def get_alliance(alliance_id):
    conn = get_conn()
    row = conn.execute("SELECT * FROM alliances WHERE id=?", (alliance_id,)).fetchone()
    conn.close()
    return row


def get_alliance_by_name(name):
    conn = get_conn()
    row = conn.execute("SELECT * FROM alliances WHERE name=?", (name,)).fetchone()
    conn.close()
    return row


def get_user_alliance(user_id):
    u = get_user(user_id)
    if not u or not u["alliance_id"]:
        return None
    return get_alliance(u["alliance_id"])


def alliance_members(alliance_id):
    conn = get_conn()
    rows = conn.execute(
        "SELECT u.* FROM users u JOIN alliance_members am ON u.user_id=am.user_id WHERE am.alliance_id=?",
        (alliance_id,),
    ).fetchall()
    conn.close()
    return rows


def alliance_member_count(alliance_id):
    conn = get_conn()
    row = conn.execute(
        "SELECT COUNT(*) as c FROM alliance_members WHERE alliance_id=?", (alliance_id,)
    ).fetchone()
    conn.close()
    return row["c"]


def create_alliance(user_id, name):
    conn = get_conn()
    try:
        cur = conn.execute(
            "INSERT INTO alliances (name, leader_id, created_at) VALUES (?,?,?)",
            (name, user_id, now_str()),
        )
        alliance_id = cur.lastrowid
        conn.execute(
            "INSERT INTO alliance_members (alliance_id, user_id, joined_at) VALUES (?,?,?)",
            (alliance_id, user_id, now_str()),
        )
        conn.execute("UPDATE users SET alliance_id=? WHERE user_id=?", (alliance_id, user_id))
        conn.commit()
        return alliance_id
    except sqlite3.IntegrityError:
        return None
    finally:
        conn.close()


def join_alliance(user_id, alliance_id):
    conn = get_conn()
    conn.execute(
        "INSERT OR IGNORE INTO alliance_members (alliance_id, user_id, joined_at) VALUES (?,?,?)",
        (alliance_id, user_id, now_str()),
    )
    conn.execute("UPDATE users SET alliance_id=? WHERE user_id=?", (alliance_id, user_id))
    conn.commit()
    conn.close()


def list_open_alliances():
    conn = get_conn()
    rows = conn.execute("SELECT * FROM alliances").fetchall()
    conn.close()
    result = []
    for r in rows:
        cnt = alliance_member_count(r["id"])
        if cnt < ALLIANCE_MAX_MEMBERS:
            result.append((r, cnt))
    return result


def can_donate_today(user_id):
    today = datetime.utcnow().strftime("%Y-%m-%d")
    conn = get_conn()
    row = conn.execute(
        "SELECT count FROM alliance_donations WHERE user_id=? AND donate_date=?", (user_id, today)
    ).fetchone()
    conn.close()
    used = row["count"] if row else 0
    return used < ALLIANCE_DAILY_DONATION_LIMIT, used


def register_donation(user_id):
    today = datetime.utcnow().strftime("%Y-%m-%d")
    conn = get_conn()
    conn.execute(
        "INSERT INTO alliance_donations (user_id, donate_date, count) VALUES (?,?,1) "
        "ON CONFLICT(user_id, donate_date) DO UPDATE SET count = count + 1",
        (user_id, today),
    )
    conn.commit()
    conn.close()


# ---------- بازار فروشندگان (Market) ----------

def generate_market_code():
    conn = get_conn()
    row = conn.execute("SELECT COUNT(*) as c FROM market").fetchone()
    conn.close()
    n = row["c"] + 10
    return f"S{n}"


def create_listing(seller_id, category, item_name, quantity, price):
    code = generate_market_code()
    conn = get_conn()
    conn.execute(
        "INSERT INTO market (code, seller_id, category, item_name, quantity, price, active, created_at) "
        "VALUES (?,?,?,?,?,?,1,?)",
        (code, seller_id, category, item_name, quantity, price, now_str()),
    )
    conn.commit()
    conn.close()
    return code


def get_listing(code):
    conn = get_conn()
    row = conn.execute("SELECT * FROM market WHERE code=? AND active=1", (code,)).fetchone()
    conn.close()
    return row


def deactivate_listing(code):
    conn = get_conn()
    conn.execute("UPDATE market SET active=0 WHERE code=?", (code,))
    conn.commit()
    conn.close()


def listings_by_category(category):
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM market WHERE category=? AND active=1 ORDER BY created_at DESC", (category,)
    ).fetchall()
    conn.close()
    return rows


# ---------- رزرو ویژه و رنکینگ ----------

def add_to_reserve(amount):
    conn = get_conn()
    conn.execute("UPDATE reserve_pool SET amount = amount + ? WHERE id=1", (amount,))
    conn.commit()
    conn.close()


def get_reserve_amount():
    conn = get_conn()
    row = conn.execute("SELECT amount FROM reserve_pool WHERE id=1").fetchone()
    conn.close()
    return row["amount"] if row else 0


def reset_reserve():
    conn = get_conn()
    conn.execute("UPDATE reserve_pool SET amount = 0 WHERE id=1")
    conn.commit()
    conn.close()


def add_gold_cup(user_id, n=1):
    conn = get_conn()
    conn.execute("UPDATE users SET gold_cups = gold_cups + ? WHERE user_id=?", (n, user_id))
    conn.commit()
    conn.close()


def top_10_ranking():
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM users WHERE banned=0 ORDER BY gold_cups DESC, coins DESC LIMIT 10"
    ).fetchall()
    conn.close()
    return rows


def distribute_reserve_pool():
    pool = get_reserve_amount()
    if pool <= 0:
        return []
    top = top_10_ranking()
    if not top:
        return []
    shares = []
    if len(top) >= 1:
        shares.append((top[0], pool * 0.50))
    if len(top) >= 2:
        shares.append((top[1], pool * 0.20))
    if len(top) >= 3:
        shares.append((top[2], pool * 0.10))
    rest = top[3:10]
    if rest:
        remaining_share = pool * 0.20 / len(rest)
        for u in rest:
            shares.append((u, remaining_share))
    for user_row, amount in shares:
        add_coins(user_row["user_id"], amount)
    reset_reserve()
    return shares


# ======================================================================
#                          کیبوردها و منوها (UI)
# ======================================================================

MAIN_MENU_KB = InlineKeyboardMarkup(
    [
        [InlineKeyboardButton("🛒 شاپ", callback_data="menu_shop"),
         InlineKeyboardButton("⚔️ اتک", callback_data="menu_attack")],
        [InlineKeyboardButton("🤝 اتحاد", callback_data="menu_alliance"),
         InlineKeyboardButton("🏆 رنکینگ", callback_data="menu_ranking")],
        [InlineKeyboardButton("📢 اعلامیه", callback_data="menu_announce"),
         InlineKeyboardButton("🏛 وضعیت کشور من", callback_data="menu_status")],
        [InlineKeyboardButton("👤 دعوت کاربر", callback_data="menu_invite")],
        [InlineKeyboardButton("💰 برداشت منابع", callback_data="menu_collect")],
    ]
)

SHOP_MENU_KB = InlineKeyboardMarkup(
    [
        [InlineKeyboardButton("🏭 کارخونه‌ها", callback_data="shop_factories")],
        [InlineKeyboardButton("📜 نقشه‌های ساخت و ساز", callback_data="shop_blueprints")],
        [InlineKeyboardButton("⛏ معدن‌ها", callback_data="shop_mines")],
        [InlineKeyboardButton("🏪 بازار فروشندگان", callback_data="shop_market")],
        [InlineKeyboardButton("🛡 ایتم‌های ویژه دفاعی", callback_data="shop_special")],
        [InlineKeyboardButton("🏰 پادگان", callback_data="shop_garrison")],
        [InlineKeyboardButton("⬅️ بازگشت", callback_data="menu_main")],
    ]
)


def back_button(target="menu_shop"):
    return InlineKeyboardButton("⬅️ بازگشت", callback_data=target)


async def send_main_menu(update_or_query, text="🌍 منوی اصلی بازی جنگ جهانی"):
    if hasattr(update_or_query, "message") and update_or_query.message:
        await update_or_query.message.reply_text(text, reply_markup=MAIN_MENU_KB)
    else:
        await update_or_query.edit_message_text(text, reply_markup=MAIN_MENU_KB)


# ======================================================================
#                          دستور /start  و انتخاب کشور
# (عضویت اجباری در چنل حذف شد - کاربر مستقیم میره سراغ انتخاب کشور)
# ======================================================================


def country_selection_kb(page=0, per_page=10):
    countries = available_countries()
    start = page * per_page
    chunk = countries[start:start + per_page]
    buttons = [
        [InlineKeyboardButton(c["name"], callback_data=f"pickcountry_{c['id']}")] for c in chunk
    ]
    nav = []
    if page > 0:
        nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"countrypage_{page-1}"))
    if start + per_page < len(countries):
        nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"countrypage_{page+1}"))
    if nav:
        buttons.append(nav)
    return InlineKeyboardMarkup(buttons)


async def start_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    create_user_if_needed(user.id, user.username or user.first_name)

    u = get_user(user.id)
    if u["country_id"]:
        country = get_country(u["country_id"])
        await update.message.reply_text(
            f"خوش اومدی رهبر {country['name']} 🏛\nاز منوی زیر بازی رو ادامه بده:",
            reply_markup=MAIN_MENU_KB,
        )
        return

    await update.message.reply_text(
        "🎉 به بازی «جنگ جهانی» خوش اومدی!\n\n"
        "حالا باید یک کشور رو برای رهبری انتخاب کنی. از این به بعد سرنوشت این کشور با توئه؛ "
        "باید قویش کنی، در برابر دشمنان ازش دفاع کنی و به هم‌پیمانات کمک کنی تا دشمنارو شکست بدید.\n\n"
        "🎁 ضمناً یک معدن چوب رایگان برای شروع بهت دادیم.\n\n"
        "👇 یک کشور انتخاب کن:",
        reply_markup=country_selection_kb(),
    )


async def country_page_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    page = int(query.data.split("_")[1])
    await query.answer()
    await query.edit_message_reply_markup(reply_markup=country_selection_kb(page))


async def pick_country_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    user = query.from_user
    country_id = int(query.data.split("_")[1])
    country = get_country(country_id)
    if not country or country["owner_id"] is not None or country["destroyed"]:
        await query.answer("این کشور دیگه در دسترس نیست، یکی دیگه انتخاب کن.", show_alert=True)
        await query.edit_message_reply_markup(reply_markup=country_selection_kb())
        return
    assign_country(user.id, country_id)
    await query.answer("کشور با موفقیت انتخاب شد! 🎉")
    await query.edit_message_text(
        f"🏛 تبریک! تو الان رهبر «{country['name']}» هستی.\n\n"
        "وظیفه‌ات: اقتصادت رو قوی کن، ارتش بساز، از کشورت دفاع کن و به هم‌پیمانات کمک کن.\n\n"
        "از منوی زیر شروع کن:",
    )
    await context.bot.send_message(user.id, "🌍 منوی اصلی:", reply_markup=MAIN_MENU_KB)


# ======================================================================
#                    نیازمند بررسی داشتن کشور
# ======================================================================


async def require_ready(query, context) -> bool:
    """چک می‌کند کاربر کشور انتخاب کرده"""
    user_id = query.from_user.id
    u = get_user(user_id)
    if not u or not u["country_id"]:
        await query.answer("اول باید یک کشور انتخاب کنی! /start رو بزن", show_alert=True)
        return False
    return True


# ======================================================================
#                          روتر منوی اصلی
# ======================================================================


async def main_menu_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    data = query.data

    if data == "menu_main":
        await query.answer()
        await query.edit_message_text("🌍 منوی اصلی بازی جنگ جهانی", reply_markup=MAIN_MENU_KB)
        return

    if not await require_ready(query, context):
        return

    if data == "menu_shop":
        await query.answer()
        await query.edit_message_text("🛒 به شاپ خوش اومدی:", reply_markup=SHOP_MENU_KB)

    elif data == "menu_status":
        await query.answer()
        await show_status(query, context)

    elif data == "menu_collect":
        await query.answer()
        await show_collect_preview(query, context)


async def show_status(query, context):
    user_id = query.from_user.id
    u = get_user(user_id)
    country = get_country(u["country_id"]) if u["country_id"] else None
    balance = "نامحدود ♾" if is_unlimited(user_id) else format_money(u["coins"])
    mines = get_user_mines(user_id)
    garrisons = get_garrison_count(user_id)
    units_total = get_total_units_count(user_id)
    factories = get_all_factories(user_id)
    factory_lines = []
    for f in factories:
        cat_label = CATEGORY_LABELS.get(f["category"], f["category"])
        bp = f["blueprint_name"] or "بدون نقشه"
        factory_lines.append(f"  • {cat_label}: {bp}")
    factories_text = "\n".join(factory_lines) if factory_lines else "  ندارد"

    mine_lines = []
    for m in mines:
        name = MINES[m["mine_key"]]["name"]
        line = f"  • {name}: {m['quantity']}/{MAX_MINES_PER_TYPE}"
        if m["bonus_quantity"]:
            line += f" (+{m['bonus_quantity']} جایزه)"
        mine_lines.append(line)
    mines_text = "\n".join(mine_lines) if mine_lines else "  ندارد"

    text = (
        f"🏛 وضعیت کشور: {country['name'] if country else '—'}\n"
        f"💰 موجودی: {balance}\n"
        f"🏆 کاپ طلا: {u['gold_cups']}\n"
        f"⛏ معدن‌ها:\n{mines_text}\n"
        f"🏰 پادگان: {garrisons} (ظرفیت {garrisons*GARRISON_CAPACITY_EACH})\n"
        f"🪖 مجموع واحد نظامی در پادگان: {units_total}\n"
        f"🏭 کارخونه‌ها:\n{factories_text}\n"
    )
    kb = InlineKeyboardMarkup([[back_button("menu_main")]])
    await query.edit_message_text(text, reply_markup=kb)


async def show_collect_preview(query, context):
    kb = InlineKeyboardMarkup(
        [
            [InlineKeyboardButton("✅ بله، جمع‌آوری کن", callback_data="collect_confirm")],
            [back_button("menu_main")],
        ]
    )
    await query.edit_message_text(
        "💰 آیا می‌خوای درآمد معدن‌ها و تولیدات کارخونه‌هاتو جمع‌آوری کنی؟\n"
        "(این کار موجودی و انبار پادگانتو به‌روز می‌کنه)",
        reply_markup=kb,
    )


async def collect_confirm_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    user_id = query.from_user.id
    income = collect_mine_income(user_id)
    produced = collect_factory_production(user_id)
    lines = ["✅ برداشت انجام شد!", f"💰 درآمد معدن: {format_money(income)}"]
    if produced:
        lines.append("🏭 تولیدات کارخونه:")
        for name, qty in produced.items():
            lines.append(f"  • {name} × {qty}")
    else:
        lines.append("🏭 تولید جدیدی از کارخونه‌ها نبود.")
    await query.answer("جمع‌آوری شد ✅")
    await query.edit_message_text("\n".join(lines), reply_markup=InlineKeyboardMarkup([[back_button("menu_main")]]))


# ======================================================================
#                       شاپ -> کارخونه‌ها
# ======================================================================


def factories_categories_kb():
    buttons = []
    for key, data in FACTORY_CATEGORIES.items():
        buttons.append([InlineKeyboardButton(data["label"], callback_data=f"fac_cat_{key}")])
    buttons.append([back_button("menu_shop")])
    return InlineKeyboardMarkup(buttons)


def factory_types_kb(category):
    buttons = []
    for t in FACTORY_CATEGORIES[category]["types"]:
        buttons.append(
            [InlineKeyboardButton(
                f"{t['name']} - {format_money(t['price'])} (سرعت {t['rate']}/ساعت)",
                callback_data=f"fac_buy_{category}_{t['key']}",
            )]
        )
    buttons.append([InlineKeyboardButton("🔄 تغییر نقشه ساخت‌وساز", callback_data=f"fac_setbp_{category}")])
    buttons.append([back_button("shop_factories")])
    return InlineKeyboardMarkup(buttons)


def blueprint_categories_kb():
    buttons = [[InlineKeyboardButton(CATEGORY_LABELS[k], callback_data=f"bp_cat_{k}")] for k in BLUEPRINTS]
    buttons.append([back_button("menu_shop")])
    return InlineKeyboardMarkup(buttons)


def blueprint_items_kb(category, user_id):
    owned = get_owned_blueprints(user_id, category)
    buttons = []
    for it in BLUEPRINTS[category]:
        mark = "✅ " if it["name"] in owned else ""
        buttons.append(
            [InlineKeyboardButton(
                f"{mark}{it['name']} - {format_money(it['price'])} (قدرت {it['power']})",
                callback_data=f"bp_buy_{category}__{it['name']}",
            )]
        )
    buttons.append([back_button("shop_blueprints")])
    return InlineKeyboardMarkup(buttons)


def mines_kb(user_id):
    conn = get_conn()
    rows = conn.execute("SELECT * FROM mines WHERE user_id=?", (user_id,)).fetchall()
    conn.close()
    owned = {r["mine_key"]: r for r in rows}
    buttons = []
    for key, m in MINES.items():
        row = owned.get(key)
        qty = row["quantity"] if row else 0
        bonus = row["bonus_quantity"] if row else 0
        label = f"{m['name']} {qty}/{MAX_MINES_PER_TYPE}"
        if bonus:
            label += f" (+{bonus}🎁)"
        label += f" - {format_money(m['price'])}"
        buttons.append([InlineKeyboardButton(label, callback_data=f"mine_buy_{key}")])
    buttons.append([back_button("menu_shop")])
    return InlineKeyboardMarkup(buttons)


async def shop_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    data = query.data
    user_id = query.from_user.id

    if data == "shop_factories":
        await query.answer()
        await query.edit_message_text("🏭 دسته کارخونه مورد نظرت رو انتخاب کن:", reply_markup=factories_categories_kb())
        return

    if data.startswith("fac_cat_"):
        category = data.split("fac_cat_")[1]
        await query.answer()
        owned = get_user_factory(user_id, category)
        note = f"\n\n✅ شما یک {CATEGORY_LABELS[category]} دارید." if owned else "\n\nهنوز کارخونه‌ای در این دسته نداری."
        await query.edit_message_text(
            f"{FACTORY_CATEGORIES[category]['label']}{note}\n\nیکی از انواع زیر رو انتخاب کن:",
            reply_markup=factory_types_kb(category),
        )
        return

    if data.startswith("fac_buy_"):
        parts = data.split("_")
        category = parts[2]
        fkey = "_".join(parts[3:])
        types = FACTORY_CATEGORIES[category]["types"]
        t = next((x for x in types if x["key"] == fkey), None)
        if not t:
            await query.answer("خطا", show_alert=True)
            return
        if get_user_factory(user_id, category):
            await query.answer("شما از این دسته قبلاً یک کارخونه دارید! فقط یکی مجازه.", show_alert=True)
            return
        if not spend_coins(user_id, t["price"]):
            await query.answer("موجودی کافی نیست ❌", show_alert=True)
            return
        buy_factory(user_id, category, fkey)
        await query.answer("کارخونه خریداری شد ✅")
        await query.edit_message_text(
            f"✅ {t['name']} ساخته شد.\nحالا باید از بخش «نقشه‌های ساخت‌وساز» یک نقشه بخری و اینجا اضافه‌اش کنی.",
            reply_markup=factory_types_kb(category),
        )
        return

    if data.startswith("fac_setbp_"):
        category = data.split("fac_setbp_")[1]
        owned_bps = get_owned_blueprints(user_id, category)
        if not get_user_factory(user_id, category):
            await query.answer("اول باید یک کارخونه از این دسته بخری!", show_alert=True)
            return
        if not owned_bps:
            await query.answer("هیچ نقشه‌ای در این دسته نداری! از «نقشه‌های ساخت‌وساز» بخر.", show_alert=True)
            return
        buttons = [[InlineKeyboardButton(bp, callback_data=f"fac_assign_{category}_{bp}")] for bp in owned_bps]
        buttons.append([back_button(f"fac_cat_{category}")])
        await query.answer()
        await query.edit_message_text("یکی از نقشه‌های خریداری‌شده‌ات رو انتخاب کن:", reply_markup=InlineKeyboardMarkup(buttons))
        return

    if data.startswith("fac_assign_"):
        rest = data.split("fac_assign_")[1]
        category, unit_name = rest.split("_", 1)
        set_factory_blueprint(user_id, category, unit_name)
        await query.answer("نقشه با موفقیت اضافه شد ✅")
        await query.edit_message_text(
            f"✅ کارخونه {CATEGORY_LABELS[category]} از این به بعد «{unit_name}» تولید می‌کند.",
            reply_markup=factory_types_kb(category),
        )
        return

    if data == "shop_blueprints":
        await query.answer()
        await query.edit_message_text("📜 دسته نقشه مورد نظرت رو انتخاب کن:", reply_markup=blueprint_categories_kb())
        return

    if data.startswith("bp_cat_"):
        category = data.split("bp_cat_")[1]
        await query.answer()
        await query.edit_message_text(
            f"📜 نقشه‌های دسته {CATEGORY_LABELS[category]}:",
            reply_markup=blueprint_items_kb(category, user_id),
        )
        return

    if data.startswith("bp_buy_"):
        rest = data.split("bp_buy_")[1]
        category, unit_name = rest.split("__", 1)
        item = next((x for x in BLUEPRINTS[category] if x["name"] == unit_name), None)
        if not item:
            await query.answer("خطا", show_alert=True)
            return
        if unit_name in get_owned_blueprints(user_id, category):
            await query.answer("این نقشه رو قبلاً خریدی!", show_alert=True)
            return
        if not spend_coins(user_id, item["price"]):
            await query.answer("موجودی کافی نیست ❌", show_alert=True)
            return
        own_blueprint(user_id, category, unit_name)
        await query.answer("نقشه خریداری شد ✅")
        await query.edit_message_text(
            f"✅ نقشه «{unit_name}» خریداری شد.\nحالا از بخش کارخونه‌ها -> {CATEGORY_LABELS[category]} -> "
            f"«تغییر نقشه ساخت‌وساز» اضافه‌اش کن تا کارخونه شروع به ساختش کنه.",
            reply_markup=blueprint_items_kb(category, user_id),
        )
        return

    if data == "shop_mines":
        await query.answer()
        await query.edit_message_text(
            f"⛏ معدن‌ها (از هر نوع حداکثر {MAX_MINES_PER_TYPE} تا می‌تونی بخری):",
            reply_markup=mines_kb(user_id),
        )
        return

    if data.startswith("mine_buy_"):
        mkey = data.split("mine_buy_")[1]
        paid_qty = mine_paid_quantity(user_id, mkey)
        if paid_qty >= MAX_MINES_PER_TYPE:
            await query.answer(f"حداکثر {MAX_MINES_PER_TYPE} تا از این نوع معدن مجازه!", show_alert=True)
            return
        m = MINES[mkey]
        if not spend_coins(user_id, m["price"]):
            await query.answer("موجودی کافی نیست ❌", show_alert=True)
            return
        buy_mine(user_id, mkey)
        new_qty = mine_paid_quantity(user_id, mkey)
        await query.answer("معدن خریداری شد ✅")
        await query.edit_message_text(
            f"✅ یک {m['name']} دیگه خریداری شد. (الان {new_qty}/{MAX_MINES_PER_TYPE})\n"
            f"درآمد هر واحد: {format_money(m['income_hour'])} در ساعت",
            reply_markup=mines_kb(user_id),
        )
        return

    if data == "shop_garrison":
        await query.answer()
        price = garrison_price(user_id)
        cnt = get_garrison_count(user_id)
        kb = InlineKeyboardMarkup(
            [
                [InlineKeyboardButton(f"🏰 خرید پادگان ({format_money(price)})", callback_data="garrison_buy")],
                [back_button("menu_shop")],
            ]
        )
        await query.edit_message_text(
            f"🏰 پادگان‌ها محل نگهداری نیروهای نظامی و ذخیره کشورت هستن.\n"
            f"⚠️ اگر تمام پادگان‌هات نابود بشن، کشورت هم نابود می‌شه!\n\n"
            f"تعداد فعلی: {cnt}\nظرفیت فعلی: {cnt*GARRISON_CAPACITY_EACH}\n"
            f"قیمت پادگان بعدی: {format_money(price)}",
            reply_markup=kb,
        )
        return

    if data == "garrison_buy":
        price = garrison_price(user_id)
        if not spend_coins(user_id, price):
            await query.answer("موجودی کافی نیست ❌", show_alert=True)
            return
        buy_garrison(user_id)
        cnt = get_garrison_count(user_id)
        new_price = garrison_price(user_id)
        await query.answer("پادگان خریداری شد ✅")
        kb = InlineKeyboardMarkup(
            [
                [InlineKeyboardButton(f"🏰 خرید پادگان ({format_money(new_price)})", callback_data="garrison_buy")],
                [back_button("menu_shop")],
            ]
        )
        await query.edit_message_text(
            f"✅ پادگان جدید ساخته شد!\nتعداد: {cnt} | ظرفیت: {cnt*GARRISON_CAPACITY_EACH}\n"
            f"قیمت پادگان بعدی: {format_money(new_price)}",
            reply_markup=kb,
        )
        return

    if data == "shop_special":
        await query.answer()
        buttons = [
            [InlineKeyboardButton(f"{it['name']} - {format_money(it['price'])}", callback_data=f"special_buy_{it['key']}")]
            for it in SPECIAL_DEFENSE_ITEMS
        ]
        buttons.append([back_button("menu_shop")])
        await query.edit_message_text("🛡 ایتم‌های ویژه دفاعی:", reply_markup=InlineKeyboardMarkup(buttons))
        return

    if data.startswith("special_buy_"):
        key = data.split("special_buy_")[1]
        item = next((x for x in SPECIAL_DEFENSE_ITEMS if x["key"] == key), None)
        if not item:
            await query.answer("خطا", show_alert=True)
            return
        if not spend_coins(user_id, item["price"]):
            await query.answer("موجودی کافی نیست ❌", show_alert=True)
            return
        add_units(user_id, "special", item["name"], 1)
        await query.answer("خریداری شد ✅")
        await query.edit_message_text(f"✅ «{item['name']}» خریداری و به انبارت اضافه شد.")
        return


# ======================================================================
#                       شاپ -> بازار فروشندگان (Market)
# ======================================================================

MARKET_CATEGORIES = {
    "ground": "🪖 ابزار جنگی زمینی",
    "jet": "✈️ ابزار جنگی هوایی",
    "navy": "🚢 ابزار جنگی دریایی",
    "defense": "🛡 ابزار دفاعی",
    "special": "⭐ ایتم‌های ویژه",
}


def market_categories_kb():
    buttons = [[InlineKeyboardButton(v, callback_data=f"mkt_cat_{k}")] for k, v in MARKET_CATEGORIES.items()]
    buttons.append([InlineKeyboardButton("📤 راهنمای فروش/خرید", callback_data="mkt_help")])
    buttons.append([back_button("menu_shop")])
    return InlineKeyboardMarkup(buttons)


def market_listing_kb(category):
    rows = listings_by_category(category)
    buttons = []
    for r in rows:
        buttons.append(
            [InlineKeyboardButton(
                f"{r['code']} | {r['item_name']} × {r['quantity']} - {format_money(r['price'])}",
                callback_data=f"mkt_view_{r['code']}",
            )]
        )
    buttons.append([back_button("shop_market")])
    return InlineKeyboardMarkup(buttons)


async def market_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    data = query.data
    user_id = query.from_user.id

    if data == "shop_market":
        await query.answer()
        await query.edit_message_text("🏪 بازار فروشندگان - دسته مورد نظرت رو انتخاب کن:", reply_markup=market_categories_kb())
        return

    if data == "mkt_help":
        await query.answer()
        await query.edit_message_text(
            "📤 برای فروش ایتم:\n"
            "دستور زیر رو در پیوی ربات بفرست:\n"
            "فروش (تعداد) (اسم ایتم) (قیمت)\n"
            "مثال: فروش 5 تانک سبک 200000\n\n"
            "🛒 برای خرید یک ایتم کد اون رو با حرف B بفرست:\n"
            "مثال: B S10\n\n"
            "می‌تونی از لیست بازار هم مستقیم با دکمه خرید کنی.",
            reply_markup=InlineKeyboardMarkup([[back_button("shop_market")]]),
        )
        return

    if data.startswith("mkt_cat_"):
        category = data.split("mkt_cat_")[1]
        await query.answer()
        await query.edit_message_text(
            f"{MARKET_CATEGORIES[category]} - لیست فروشندگان:",
            reply_markup=market_listing_kb(category),
        )
        return

    if data.startswith("mkt_view_"):
        code = data.split("mkt_view_")[1]
        listing = get_listing(code)
        if not listing:
            await query.answer("این آگهی دیگه فعال نیست.", show_alert=True)
            return
        kb = InlineKeyboardMarkup(
            [
                [InlineKeyboardButton("🛒 خرید این ایتم", callback_data=f"mkt_buy_{code}")],
                [back_button(f"mkt_cat_{listing['category']}")],
            ]
        )
        await query.answer()
        await query.edit_message_text(
            f"کد: {listing['code']}\nایتم: {listing['item_name']}\nتعداد: {listing['quantity']}\n"
            f"قیمت کل: {format_money(listing['price'])}",
            reply_markup=kb,
        )
        return

    if data.startswith("mkt_buy_"):
        code = data.split("mkt_buy_")[1]
        await execute_market_buy(query.from_user.id, code, query, context)
        return


async def execute_market_buy(buyer_id, code, query_or_msg, context, is_message=False):
    listing = get_listing(code)
    if not listing:
        text = "❌ این کد آگهی وجود نداره یا قبلاً فروخته شده."
        if is_message:
            await query_or_msg.reply_text(text)
        else:
            await query_or_msg.answer(text, show_alert=True)
        return
    if listing["seller_id"] == buyer_id:
        text = "نمی‌تونی ایتم خودتو بخری!"
        if is_message:
            await query_or_msg.reply_text(text)
        else:
            await query_or_msg.answer(text, show_alert=True)
        return
    if not spend_coins(buyer_id, listing["price"]):
        text = "موجودی کافی نیست ❌"
        if is_message:
            await query_or_msg.reply_text(text)
        else:
            await query_or_msg.answer(text, show_alert=True)
        return
    add_coins(listing["seller_id"], listing["price"])
    add_units(buyer_id, listing["category"], listing["item_name"], listing["quantity"])
    deactivate_listing(code)
    msg = f"✅ خرید موفق! {listing['item_name']} × {listing['quantity']} به انبارت اضافه شد."
    if is_message:
        await query_or_msg.reply_text(msg)
    else:
        await query_or_msg.answer("خرید موفق ✅")
        await query_or_msg.edit_message_text(msg)
    try:
        await context.bot.send_message(
            listing["seller_id"],
            f"💰 ایتم شما «{listing['item_name']}» × {listing['quantity']} به قیمت "
            f"{format_money(listing['price'])} فروخته شد.",
        )
    except Exception:
        pass


ALL_SELLABLE_UNITS = {}
for cat, items in BLUEPRINTS.items():
    for it in items:
        ALL_SELLABLE_UNITS[it["name"]] = cat
for it in SPECIAL_DEFENSE_ITEMS:
    ALL_SELLABLE_UNITS[it["name"]] = "special"


async def text_command_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """هندل کردن دستورات متنی بازار: فروش ..., B<code>  (فقط در پیوی)"""
    if update.effective_chat.type != "private":
        return
    text = (update.message.text or "").strip()
    user_id = update.effective_user.id

    if text.upper().startswith("B") and len(text) > 1:
        code = text[1:].strip().upper().replace(" ", "")
        if code.startswith("S") and code[1:].isdigit():
            await execute_market_buy(user_id, code, update.message, context, is_message=True)
            return

    if text.startswith("فروش"):
        parts = text.split()
        if len(parts) < 4:
            await update.message.reply_text(
                "فرمت درست:\nفروش (تعداد) (اسم ایتم) (قیمت)\nمثال: فروش 5 تانک سبک 200000"
            )
            return
        try:
            qty = int(parts[1])
            price = float(parts[-1])
            item_name = " ".join(parts[2:-1])
        except ValueError:
            await update.message.reply_text("تعداد و قیمت باید عدد باشن!")
            return
        category = ALL_SELLABLE_UNITS.get(item_name)
        if not category:
            await update.message.reply_text("این ایتم شناخته‌شده نیست! اسم ایتم رو دقیق بنویس.")
            return
        conn = get_conn()
        row = conn.execute(
            "SELECT quantity FROM units WHERE user_id=? AND category=? AND unit_name=?",
            (user_id, category, item_name),
        ).fetchone()
        conn.close()
        have = row["quantity"] if row else 0
        if have < qty:
            await update.message.reply_text(f"موجودی کافی نداری! فقط {have} عدد داری.")
            return
        remove_units(user_id, category, item_name, qty)
        code = create_listing(user_id, category, item_name, qty, price)
        await update.message.reply_text(
            f"✅ آگهی فروش ثبت شد!\nکد: {code}\nایتم: {item_name} × {qty}\nقیمت: {format_money(price)}\n\n"
            f"بقیه می‌تونن با فرستادن «B {code}» این رو بخرن."
        )
        return


# ======================================================================
#                              اتحاد (ALLIANCE)
# ======================================================================

ASK_ALLIANCE_NAME, ASK_PRIVATE_MSG_TARGET, ASK_PRIVATE_MSG_TEXT, ASK_BROADCAST_TEXT, ASK_HELP_TEXT = range(5)


def alliance_main_kb():
    return InlineKeyboardMarkup(
        [
            [InlineKeyboardButton("🏗 ساخت اتحاد", callback_data="al_create")],
            [InlineKeyboardButton("📋 لیست اتحادها", callback_data="al_list")],
            [InlineKeyboardButton("👥 مشاهده اعضا", callback_data="al_members")],
            [InlineKeyboardButton("📣 پیام گروهی", callback_data="al_broadcast")],
            [InlineKeyboardButton("✉️ پیام خصوصی", callback_data="al_private")],
            [InlineKeyboardButton("🆘 درخواست کمک", callback_data="al_help")],
            [InlineKeyboardButton("🎁 اهدای ایتم", callback_data="al_donate")],
            [back_button("menu_main")],
        ]
    )


async def alliance_menu_entry(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    await query.answer()
    my_alliance = get_user_alliance(query.from_user.id)
    header = f"🤝 اتحاد فعلی تو: {my_alliance['name']}\n\n" if my_alliance else "🤝 تو هنوز عضو هیچ اتحادی نیستی.\n\n"
    await query.edit_message_text(header + "یکی از گزینه‌های زیر رو انتخاب کن:", reply_markup=alliance_main_kb())


async def alliance_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return ConversationHandler.END
    data = query.data
    user_id = query.from_user.id

    if data == "al_create":
        if get_user_alliance(user_id):
            await query.answer("تو همین الان عضو یک اتحاد هستی!", show_alert=True)
            return ConversationHandler.END
        if get_balance(user_id) < ALLIANCE_CREATE_COST and not is_unlimited(user_id):
            await query.answer("موجودی کافی برای ساخت اتحاد نداری!", show_alert=True)
            return ConversationHandler.END
        await query.answer()
        await query.edit_message_text(
            f"هزینه ساخت اتحاد {format_money(ALLIANCE_CREATE_COST)} کسر میشه.\n"
            "اسم اتحادتو چی میزاری؟ (فقط تایپ کن و بفرست، یا /cancel برای لغو)"
        )
        return ASK_ALLIANCE_NAME

    if data == "al_list":
        await query.answer()
        open_list = list_open_alliances()
        if not open_list:
            await query.edit_message_text("در حال حاضر هیچ اتحاد بازی وجود نداره.", reply_markup=InlineKeyboardMarkup([[back_button("menu_alliance")]]))
            return ConversationHandler.END
        buttons = []
        lines = ["📋 اتحادهای دارای ظرفیت خالی:\n"]
        for al, cnt in open_list:
            lines.append(f"• {al['name']} ({cnt}/{ALLIANCE_MAX_MEMBERS})")
            buttons.append([InlineKeyboardButton(f"عضویت در {al['name']}", callback_data=f"al_join_{al['id']}")])
        buttons.append([back_button("menu_alliance")])
        await query.edit_message_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(buttons))
        return ConversationHandler.END

    if data.startswith("al_join_"):
        alliance_id = int(data.split("al_join_")[1])
        if get_user_alliance(user_id):
            await query.answer("تو همین الان عضو یک اتحاد هستی!", show_alert=True)
            return ConversationHandler.END
        if alliance_member_count(alliance_id) >= ALLIANCE_MAX_MEMBERS:
            await query.answer("این اتحاد پره!", show_alert=True)
            return ConversationHandler.END
        join_alliance(user_id, alliance_id)
        al = get_alliance(alliance_id)
        await query.answer("عضویت با موفقیت انجام شد ✅")
        await query.edit_message_text(f"✅ به اتحاد «{al['name']}» پیوستی!", reply_markup=InlineKeyboardMarkup([[back_button("menu_alliance")]]))
        return ConversationHandler.END

    if data == "al_members":
        my_alliance = get_user_alliance(user_id)
        await query.answer()
        if not my_alliance:
            await query.edit_message_text("اول باید عضو یک اتحاد بشی.", reply_markup=InlineKeyboardMarkup([[back_button("menu_alliance")]]))
            return ConversationHandler.END
        members = alliance_members(my_alliance["id"])
        lines = [f"👥 اعضای اتحاد «{my_alliance['name']}»:\n"]
        for m in members:
            country = get_country(m["country_id"]) if m["country_id"] else None
            crown = "👑 " if m["user_id"] == my_alliance["leader_id"] else ""
            lines.append(f"{crown}@{m['username']} - {country['name'] if country else '—'}")
        await query.edit_message_text("\n".join(lines), reply_markup=InlineKeyboardMarkup([[back_button("menu_alliance")]]))
        return ConversationHandler.END

    if data == "al_broadcast":
        my_alliance = get_user_alliance(user_id)
        if not my_alliance:
            await query.answer("اول باید عضو یک اتحاد بشی.", show_alert=True)
            return ConversationHandler.END
        await query.answer()
        await query.edit_message_text("متن پیامی که می‌خوای به همه هم‌اتحادی‌هات بفرستی رو بنویس (یا /cancel):")
        return ASK_BROADCAST_TEXT

    if data == "al_private":
        my_alliance = get_user_alliance(user_id)
        if not my_alliance:
            await query.answer("اول باید عضو یک اتحاد بشی.", show_alert=True)
            return ConversationHandler.END
        if my_alliance["leader_id"] != user_id:
            await query.answer("فقط لیدر اتحاد می‌تونه پیام خصوصی بفرسته!", show_alert=True)
            return ConversationHandler.END
        members = [m for m in alliance_members(my_alliance["id"]) if m["user_id"] != user_id]
        if not members:
            await query.answer("عضو دیگه‌ای در اتحاد نیست.", show_alert=True)
            return ConversationHandler.END
        buttons = [
            [InlineKeyboardButton(f"@{m['username']}", callback_data=f"al_pmto_{m['user_id']}")] for m in members
        ]
        buttons.append([back_button("menu_alliance")])
        await query.answer()
        await query.edit_message_text("پیام خصوصی رو برای کدوم عضو بفرستم؟", reply_markup=InlineKeyboardMarkup(buttons))
        return ConversationHandler.END

    if data.startswith("al_pmto_"):
        target_id = int(data.split("al_pmto_")[1])
        context.user_data["pm_target"] = target_id
        await query.answer()
        await query.edit_message_text("متن پیام خصوصی رو بنویس (یا /cancel):")
        return ASK_PRIVATE_MSG_TEXT

    if data == "al_help":
        my_alliance = get_user_alliance(user_id)
        if not my_alliance:
            await query.answer("اول باید عضو یک اتحاد بشی.", show_alert=True)
            return ConversationHandler.END
        await query.answer()
        await query.edit_message_text("چه چیزی لازم داری؟ بنویس تا برای همه هم‌اتحادی‌هات ارسال بشه (یا /cancel):")
        return ASK_HELP_TEXT

    if data == "al_donate":
        my_alliance = get_user_alliance(user_id)
        if not my_alliance:
            await query.answer("اول باید عضو یک اتحاد بشی.", show_alert=True)
            return ConversationHandler.END
        can, used = can_donate_today(user_id)
        if not can:
            await query.answer(f"سقف اهدای امروزت پر شده! ({used}/{ALLIANCE_DAILY_DONATION_LIMIT})", show_alert=True)
            return ConversationHandler.END
        units = get_units(user_id)
        donatable = [u for u in units if u["category"] in ("ground", "jet", "navy", "defense", "missile", "special")]
        if not donatable:
            await query.answer("چیزی در انبارت برای اهدا نداری!", show_alert=True)
            return ConversationHandler.END
        buttons = [
            [InlineKeyboardButton(f"{u['unit_name']} × {u['quantity']}", callback_data=f"al_donate_pick_{u['category']}__{u['unit_name']}")]
            for u in donatable
        ]
        buttons.append([back_button("menu_alliance")])
        await query.answer()
        await query.edit_message_text(f"چه ایتمی رو اهدا می‌کنی؟ ({used}/{ALLIANCE_DAILY_DONATION_LIMIT} امروز استفاده شده)", reply_markup=InlineKeyboardMarkup(buttons))
        return ConversationHandler.END

    if data.startswith("al_donate_pick_"):
        rest = data.split("al_donate_pick_")[1]
        category, unit_name = rest.split("__", 1)
        my_alliance = get_user_alliance(user_id)
        members = [m for m in alliance_members(my_alliance["id"]) if m["user_id"] != user_id]
        if not members:
            await query.answer("عضو دیگه‌ای برای اهدا وجود نداره.", show_alert=True)
            return ConversationHandler.END
        buttons = [
            [InlineKeyboardButton(f"@{m['username']}", callback_data=f"al_donate_to_{m['user_id']}__{category}__{unit_name}")]
            for m in members
        ]
        buttons.append([back_button("menu_alliance")])
        await query.answer()
        await query.edit_message_text("این ایتم رو به کی اهدا کنم؟", reply_markup=InlineKeyboardMarkup(buttons))
        return ConversationHandler.END

    if data.startswith("al_donate_to_"):
        rest = data.split("al_donate_to_")[1]
        target_id_s, category, unit_name = rest.split("__", 2)
        target_id = int(target_id_s)
        can, used = can_donate_today(user_id)
        if not can:
            await query.answer("سقف اهدای امروزت پر شده!", show_alert=True)
            return ConversationHandler.END
        remove_units(user_id, category, unit_name, 1)
        add_units(target_id, category, unit_name, 1)
        register_donation(user_id)
        await query.answer("اهدا شد ✅")
        target_user = get_user(target_id)
        target_name = target_user["username"] if target_user else str(target_id)
        await query.edit_message_text(f"✅ یک عدد «{unit_name}» به @{target_name} اهدا شد.")
        try:
            await context.bot.send_message(target_id, f"🎁 یک عدد «{unit_name}» از طرف هم‌اتحادیت دریافت کردی!")
        except Exception:
            pass
        return ConversationHandler.END

    return ConversationHandler.END


async def alliance_name_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    name = update.message.text.strip()
    if get_alliance_by_name(name):
        await update.message.reply_text("این اسم قبلاً استفاده شده! یه اسم دیگه بگو:")
        return ASK_ALLIANCE_NAME
    if not spend_coins(user_id, ALLIANCE_CREATE_COST):
        await update.message.reply_text("موجودی کافی نداری!", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END
    create_alliance(user_id, name)
    await update.message.reply_text(f"✅ اتحاد «{name}» ساخته شد! تو لیدر این اتحاد هستی.", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


async def broadcast_text_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    my_alliance = get_user_alliance(user_id)
    text = update.message.text.strip()
    if not my_alliance:
        await update.message.reply_text("اتحادت پیدا نشد.", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END
    members = [m for m in alliance_members(my_alliance["id"]) if m["user_id"] != user_id]
    sent = 0
    for m in members:
        try:
            await context.bot.send_message(
                m["user_id"], f"📣 پیام گروهی از اتحاد «{my_alliance['name']}»:\n{text}"
            )
            sent += 1
        except Exception:
            pass
    await update.message.reply_text(f"✅ پیام برای {sent} نفر ارسال شد.", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


async def private_msg_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    target_id = context.user_data.get("pm_target")
    text = update.message.text.strip()
    if not target_id:
        await update.message.reply_text("خطا در ارسال پیام.", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END
    my_alliance = get_user_alliance(update.effective_user.id)
    try:
        await context.bot.send_message(
            target_id, f"✉️ پیام خصوصی از لیدر اتحاد «{my_alliance['name']}»:\n{text}"
        )
        await update.message.reply_text("✅ پیام ارسال شد.", reply_markup=MAIN_MENU_KB)
    except Exception:
        await update.message.reply_text("ارسال پیام ناموفق بود.", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


async def help_text_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    my_alliance = get_user_alliance(user_id)
    text = update.message.text.strip()
    if not my_alliance:
        await update.message.reply_text("اتحادت پیدا نشد.", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END
    members = [m for m in alliance_members(my_alliance["id"]) if m["user_id"] != user_id]
    sent = 0
    for m in members:
        try:
            await context.bot.send_message(
                m["user_id"],
                f"🆘 درخواست کمک از @{update.effective_user.username or update.effective_user.first_name} "
                f"(اتحاد {my_alliance['name']}):\n{text}",
            )
            sent += 1
        except Exception:
            pass
    await update.message.reply_text(f"✅ درخواست کمکت برای {sent} نفر ارسال شد.", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


async def conv_cancel(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("لغو شد.", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


# ======================================================================
#                       دعوت کاربر / زیرمجموعه‌گیری (INVITE)
# ======================================================================

ASK_REFERRAL_CODE = 300


def invite_main_kb():
    return InlineKeyboardMarkup(
        [
            [InlineKeyboardButton("🎁 کد جایزه", callback_data="inv_redeem")],
            [InlineKeyboardButton("👥 زیرمجموعه‌گیری", callback_data="inv_getcode")],
            [back_button("menu_main")],
        ]
    )


async def invite_entry(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    await query.answer()
    await query.edit_message_text(
        "👤 بخش دعوت کاربر:\n\n"
        "🎁 کد جایزه: کد ۶ رقمی که یکی از دوستات بهت داده رو اینجا وارد کن تا یک معدن چای رایگان بگیری.\n"
        "(هر کاربر فقط یک‌بار می‌تونه از این گزینه استفاده کنه)\n\n"
        "👥 زیرمجموعه‌گیری: یک کد ۶ رقمی اختصاصی برات ساخته میشه. هر بار که یه نفر جدید اون کد رو "
        "وارد کنه، تو ۲ معدن چای رایگان (خارج از سقف) + ۱۰٪ از درآمد روزانه‌ی اون رو برای همیشه می‌گیری.",
        reply_markup=invite_main_kb(),
    )


async def invite_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return ConversationHandler.END
    data = query.data
    user_id = query.from_user.id

    if data == "inv_getcode":
        code = create_referral_code(user_id)
        await query.answer()
        await query.edit_message_text(
            "👥 کد دعوت اختصاصی تو ساخته شد:\n\n"
            f"{code}\n\n"
            "این کد رو برای دوستت بفرست تا از بخش «دعوت کاربر» -> «کد جایزه» واردش کنه.\n"
            "به‌محض فعال شدن، تو ۲ معدن چای رایگان (خارج از سقف ۵تایی) می‌گیری و "
            "برای همیشه ۱۰٪ از درآمد معدن‌های اون هم به حساب تو اضافه میشه.\n\n"
            "می‌تونی چندبار این دکمه رو بزنی و چند کد مختلف برای چند نفر بسازی.",
            reply_markup=InlineKeyboardMarkup([[back_button("menu_main")]]),
        )
        return ConversationHandler.END

    if data == "inv_redeem":
        u = get_user(user_id)
        if u and u["used_referral_code"]:
            await query.answer("شما قبلاً یک کد جایزه استفاده کردی!", show_alert=True)
            return ConversationHandler.END
        await query.answer()
        await query.edit_message_text("کد ۶ رقمی جایزه رو بفرست (یا /cancel برای لغو):")
        return ASK_REFERRAL_CODE

    return ConversationHandler.END


async def referral_code_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    code = update.message.text.strip()
    if not (code.isdigit() and len(code) == 6):
        await update.message.reply_text("کد باید دقیقاً ۶ رقم باشه! دوباره بفرست یا /cancel بزن.")
        return ASK_REFERRAL_CODE

    ok, info = redeem_referral_code(user_id, code)
    if not ok:
        await update.message.reply_text(f"❌ {info}", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END

    await update.message.reply_text(
        "🎉 کد با موفقیت فعال شد! یک معدن چای رایگان (خارج از سقف) گرفتی.",
        reply_markup=MAIN_MENU_KB,
    )
    owner_id = info
    try:
        await context.bot.send_message(
            owner_id,
            "🎉 یک نفر با کد دعوت تو وارد بازی شد!\n"
            "🎁 ۲ معدن چای رایگان گرفتی و از این به بعد ۱۰٪ از درآمد معدن‌های اون هم به حساب تو اضافه میشه.",
        )
    except Exception:
        pass
    return ConversationHandler.END


# ======================================================================
#                               اعلامیه (ANNOUNCE)
# ======================================================================

ASK_ANNOUNCE_TEXT = 100


async def announce_entry(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return ConversationHandler.END
    await query.answer()
    await query.edit_message_text(
        "📢 اعلامیه خود را بگویید:\n\n"
        "فرمت پیام شما به این شکل در چنل منتشر می‌شود:\n"
        "«من کشور (اسم کشورت) اعلام میکنم: (پیام تو)»\n\n"
        "همین الان پیامتو بنویس (یا /cancel):"
    )
    return ASK_ANNOUNCE_TEXT


async def announce_text_received(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    u = get_user(user_id)
    country = get_country(u["country_id"]) if u and u["country_id"] else None
    if not country:
        await update.message.reply_text("کشورت پیدا نشد!", reply_markup=MAIN_MENU_KB)
        return ConversationHandler.END
    text = update.message.text.strip()
    final_text = f"📢 من کشور {country['name']} اعلام میکنم: {text}"
    try:
        await context.bot.send_message(OWNER_ANNOUNCE_CHANNEL_ID, final_text)
        await update.message.reply_text("✅ اعلامیه شما با موفقیت در چنل منتشر شد.", reply_markup=MAIN_MENU_KB)
    except Exception as e:
        logger.warning(f"announce failed: {e}")
        await update.message.reply_text("❌ ارسال اعلامیه با خطا مواجه شد. (شاید ربات ادمین چنل نیست)", reply_markup=MAIN_MENU_KB)
    return ConversationHandler.END


# ======================================================================
#                                  اتک (ATTACK)
# ======================================================================


def active_countries_kb(exclude_user_id, page=0, per_page=10):
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM countries WHERE owner_id IS NOT NULL AND destroyed=0 AND owner_id != ? ORDER BY name",
        (exclude_user_id,),
    ).fetchall()
    conn.close()
    start = page * per_page
    chunk = rows[start:start + per_page]
    buttons = [
        [InlineKeyboardButton(f"{c['name']}", callback_data=f"attack_target_{c['owner_id']}")] for c in chunk
    ]
    nav = []
    if page > 0:
        nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"attackpage_{page-1}"))
    if start + per_page < len(rows):
        nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"attackpage_{page+1}"))
    if nav:
        buttons.append(nav)
    buttons.append([back_button("menu_main")])
    return InlineKeyboardMarkup(buttons)


async def attack_entry(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    await query.answer()
    await query.edit_message_text(
        "⚔️ یکی از کشورهای زیر رو برای حمله انتخاب کن:",
        reply_markup=active_countries_kb(query.from_user.id),
    )


async def attack_page_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    page = int(query.data.split("_")[1])
    await query.answer()
    await query.edit_message_reply_markup(reply_markup=active_countries_kb(query.from_user.id, page))


def resolve_battle(attacker_id, defender_id):
    atk_power = total_military_power(attacker_id)
    if atk_power <= 0:
        return {"error": "شما هیچ نیروی نظامی‌ای در پادگان نداری! اول ارتش بساز."}

    def_power = total_defense_power(defender_id)

    total_power = atk_power + def_power + 1
    win_chance = atk_power / total_power
    win_chance = max(0.05, min(0.95, win_chance))
    attacker_wins = random.random() < win_chance

    loss_ratio = random.uniform(0.10, 0.30)
    attacker_units = get_units(attacker_id)
    for u in attacker_units:
        if u["category"] == "special":
            continue
        lost = int(u["quantity"] * loss_ratio)
        if lost > 0:
            remove_units(attacker_id, u["category"], u["unit_name"], lost)

    result = {"attacker_wins": attacker_wins, "country_destroyed": False}

    if attacker_wins:
        add_gold_cup(attacker_id, 1)
        defender_balance = get_balance(defender_id)
        defender_balance = 0 if defender_balance == float("inf") else defender_balance

        garrisons_before = get_garrison_count(defender_id)
        garrison_loss = max(1, int(garrisons_before * random.uniform(0.15, 0.35)))
        conn = get_conn()
        conn.execute(
            "UPDATE garrisons SET count = MAX(count - ?, 0) WHERE user_id=?",
            (garrison_loss, defender_id),
        )
        conn.commit()
        conn.close()
        garrisons_after = get_garrison_count(defender_id)

        if garrisons_after <= 0 and garrisons_before > 0:
            loot_to_attacker = defender_balance * 0.10
            reserve_share = defender_balance * 0.40
            if not is_unlimited(defender_id):
                spend_coins(defender_id, defender_balance)
            add_coins(attacker_id, loot_to_attacker)
            add_to_reserve(reserve_share)
            destroy_country(defender_id)
            result["country_destroyed"] = True
            result["loot"] = loot_to_attacker
            result["reserve_added"] = reserve_share
            reward_shares = distribute_reserve_pool()
            result["reserve_distributed"] = reward_shares
        else:
            loot = defender_balance * random.uniform(0.05, 0.15)
            if not is_unlimited(defender_id):
                spend_coins(defender_id, loot)
            add_coins(attacker_id, loot)
            result["loot"] = loot
        result["garrisons_lost"] = garrison_loss
    else:
        result["loot"] = 0

    return result


async def attack_target_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    defender_id = int(query.data.split("attack_target_")[1])
    attacker_id = query.from_user.id
    defender_country = get_country_by_owner(defender_id)
    if not defender_country:
        await query.answer("این کشور دیگه در دسترس نیست.", show_alert=True)
        return

    await query.answer("در حال شبیه‌سازی نبرد... ⚔️")
    result = resolve_battle(attacker_id, defender_id)

    if "error" in result:
        await query.edit_message_text(result["error"], reply_markup=InlineKeyboardMarkup([[back_button("menu_main")]]))
        return

    attacker_country = get_country_by_owner(attacker_id)
    if result["attacker_wins"]:
        text = f"🎉 پیروزی! نیروهای {attacker_country['name']} بر {defender_country['name']} غالب شدن!\n"
        text += f"💰 غنیمت: {format_money(result.get('loot', 0))}\n"
        if result.get("garrisons_lost"):
            text += f"🏰 {result['garrisons_lost']} پادگان دشمن نابود شد.\n"
        if result["country_destroyed"]:
            text += f"\n💥 کشور {defender_country['name']} به طور کامل نابود شد!\n"
            text += f"🏦 {format_money(result['reserve_added'])} به مخزن ویژه اضافه و بین برترین‌های رنکینگ تقسیم شد."
    else:
        text = f"😢 حمله ناموفق بود! دفاع {defender_country['name']} قوی‌تر بود.\nبخشی از نیروهات در این نبرد از دست رفت."

    await query.edit_message_text(text, reply_markup=InlineKeyboardMarkup([[back_button("menu_main")]]))

    try:
        if result["attacker_wins"]:
            await context.bot.send_message(
                defender_id,
                f"⚠️ کشورت مورد حمله {attacker_country['name']} قرار گرفت و شکست خوردی!"
                + ("\n💥 کشورت به طور کامل نابود شد!" if result["country_destroyed"] else ""),
            )
        else:
            await context.bot.send_message(
                defender_id, f"🛡 کشورت مورد حمله {attacker_country['name']} قرار گرفت ولی با موفقیت دفاع کردی!"
            )
    except Exception:
        pass


# ======================================================================
#                                رنکینگ (RANKING)
# ======================================================================


async def ranking_entry(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not await require_ready(query, context):
        return
    await query.answer()
    top = top_10_ranking()
    lines = ["🏆 رنکینگ ۱۰ کشور برتر:\n"]
    medals = ["🥇", "🥈", "🥉"]
    for i, u in enumerate(top):
        country = get_country(u["country_id"]) if u["country_id"] else None
        icon = medals[i] if i < 3 else f"{i+1}."
        lines.append(f"{icon} {country['name'] if country else '—'} - 🏆{u['gold_cups']} کاپ")
    lines.append(f"\n🏦 مخزن ویژه فعلی: {format_money(get_reserve_amount())}")
    await query.edit_message_text("\n".join(lines), reply_markup=InlineKeyboardMarkup([[back_button("menu_main")]]))


# ======================================================================
#                     دستورات ادمین (ADMIN COMMANDS)
# ======================================================================


def is_owner_or_admin(user_id):
    u = get_user(user_id)
    return bool(u and (u["is_owner"] or u["is_admin"]))


async def admin_group_text_router(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """دستورات ادمین داخل گروه: بن (با ریپلای) و ارتقا (با ریپلای)"""
    if update.effective_chat.type not in ("group", "supergroup"):
        return
    msg = update.message
    if not msg or not msg.text:
        return
    text = msg.text.strip()
    user_id = update.effective_user.id

    if text in ("بن",) and msg.reply_to_message:
        if not is_owner_or_admin(user_id):
            return
        target = msg.reply_to_message.from_user
        try:
            await context.bot.ban_chat_member(update.effective_chat.id, target.id)
            await msg.reply_text(f"🚫 کاربر {target.first_name} از گروه بن شد.")
        except Exception as e:
            await msg.reply_text(f"خطا در بن کردن: {e}")
        return

    if text in ("ارتقا",) and msg.reply_to_message:
        if not is_owner_or_admin(user_id):
            return
        u = get_user(user_id)
        if not u["is_owner"]:
            await msg.reply_text("فقط مالک اصلی می‌تونه ادمین جدید بسازه.")
            return
        target = msg.reply_to_message.from_user
        create_user_if_needed(target.id, target.username or target.first_name)
        conn = get_conn()
        conn.execute("UPDATE users SET is_admin=1 WHERE user_id=?", (target.id,))
        conn.commit()
        conn.close()
        try:
            await context.bot.promote_chat_member(
                update.effective_chat.id,
                target.id,
                can_delete_messages=True,
                can_restrict_members=True,
                can_invite_users=True,
                can_pin_messages=True,
            )
        except Exception:
            pass
        await msg.reply_text(f"⭐️ {target.first_name} به عنوان ادمین دوم (فقط از طریق ربات) ارتقا یافت.")
        return


AD_KEYWORDS = ["t.me/", "@", "http://", "https://", "www."]


def looks_like_ad(text: str) -> bool:
    if not text:
        return False
    lowered = text.lower()
    return any(k in lowered for k in AD_KEYWORDS)


async def anti_spam_filter(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_chat.type not in ("group", "supergroup"):
        return
    msg = update.message
    if not msg or not msg.text:
        return
    user_id = update.effective_user.id
    if is_owner_or_admin(user_id):
        return
    if not looks_like_ad(msg.text):
        return

    chat_id = update.effective_chat.id
    conn = get_conn()
    row = conn.execute(
        "SELECT count FROM ad_warnings WHERE user_id=? AND chat_id=?", (user_id, chat_id)
    ).fetchone()
    count = (row["count"] if row else 0) + 1
    conn.execute(
        "INSERT INTO ad_warnings (user_id, chat_id, count) VALUES (?,?,?) "
        "ON CONFLICT(user_id, chat_id) DO UPDATE SET count=?",
        (user_id, chat_id, count, count),
    )
    conn.commit()
    conn.close()

    try:
        await msg.delete()
    except Exception:
        pass

    if count >= WARN_LIMIT:
        try:
            await context.bot.ban_chat_member(chat_id, user_id)
            await context.bot.send_message(
                chat_id, f"⛔️ کاربر {update.effective_user.first_name} به دلیل تبلیغات مکرر (اخطار سوم) از گروه بلاک شد."
            )
        except Exception as e:
            logger.warning(f"ban failed: {e}")
        conn = get_conn()
        conn.execute("UPDATE ad_warnings SET count=0 WHERE user_id=? AND chat_id=?", (user_id, chat_id))
        conn.commit()
        conn.close()
    else:
        try:
            await context.bot.send_message(
                chat_id,
                f"⚠️ کاربر {update.effective_user.first_name}، تبلیغ/لینک مجاز نیست!\n"
                f"اخطار {count} از {WARN_LIMIT}. با سومین اخطار از گروه بلاک می‌شی.",
            )
        except Exception:
            pass


# ======================================================================
#                        دستورات کمکی /id  و  /backup
# ======================================================================


async def id_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(f"آیدی عددی شما: {update.effective_user.id}\nآیدی این چت: {update.effective_chat.id}")


async def backup_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """فقط مالک: فایل دیتابیس فعلی رو برای بک‌آپ گرفتن می‌فرسته"""
    user_id = update.effective_user.id
    u = get_user(user_id)
    if not u or not u["is_owner"]:
        await update.message.reply_text("این دستور فقط برای مالک ربات است.")
        return
    if not os.path.exists(DB_PATH):
        await update.message.reply_text("فایل دیتابیس پیدا نشد.")
        return
    try:
        await update.message.reply_document(document=open(DB_PATH, "rb"), filename="wwiii_game_backup.db")
    except Exception as e:
        await update.message.reply_text(f"ارسال بک‌آپ با خطا مواجه شد: {e}")


# ======================================================================
#                                   MAIN
# ======================================================================


def main():
    init_db()
    app: Application = ApplicationBuilder().token(BOT_TOKEN).build()

    # ---------- دستورات پایه ----------
    app.add_handler(CommandHandler("start", start_command))
    app.add_handler(CommandHandler("id", id_command))
    app.add_handler(CommandHandler("backup", backup_command))

    # ---------- انتخاب کشور ----------
    app.add_handler(CallbackQueryHandler(country_page_callback, pattern="^countrypage_"))
    app.add_handler(CallbackQueryHandler(pick_country_callback, pattern="^pickcountry_"))

    # ---------- منوی اصلی ----------
    app.add_handler(CallbackQueryHandler(collect_confirm_callback, pattern="^collect_confirm$"))
    app.add_handler(CallbackQueryHandler(main_menu_router, pattern="^menu_(main|shop|status|collect)$"))

    # ---------- شاپ ----------
    shop_patterns = "^(shop_(factories|blueprints|mines|garrison|special)|fac_cat_|fac_buy_|fac_setbp_|fac_assign_|bp_cat_|bp_buy_|mine_buy_|garrison_buy|special_buy_)"
    app.add_handler(CallbackQueryHandler(shop_router, pattern=shop_patterns))

    # ---------- بازار ----------
    market_patterns = "^(shop_market|mkt_help|mkt_cat_|mkt_view_|mkt_buy_)"
    app.add_handler(CallbackQueryHandler(market_router, pattern=market_patterns))

    # ---------- اتک ----------
    app.add_handler(CallbackQueryHandler(attack_entry, pattern="^menu_attack$"))
    app.add_handler(CallbackQueryHandler(attack_page_callback, pattern="^attackpage_"))
    app.add_handler(CallbackQueryHandler(attack_target_callback, pattern="^attack_target_"))

    # ---------- رنکینگ ----------
    app.add_handler(CallbackQueryHandler(ranking_entry, pattern="^menu_ranking$"))

    # ---------- اعلامیه (Conversation) ----------
    announce_conv = ConversationHandler(
        entry_points=[CallbackQueryHandler(announce_entry, pattern="^menu_announce$")],
        states={
            ASK_ANNOUNCE_TEXT: [MessageHandler(filters.TEXT & ~filters.COMMAND, announce_text_received)],
        },
        fallbacks=[CommandHandler("cancel", conv_cancel)],
    )
    app.add_handler(announce_conv)

    # ---------- اتحاد: منو + Conversation ----------
    app.add_handler(CallbackQueryHandler(alliance_menu_entry, pattern="^menu_alliance$"))

    alliance_conv = ConversationHandler(
        entry_points=[
            CallbackQueryHandler(
                alliance_router,
                pattern="^al_(create|list|join_|members|broadcast|private|pmto_|help|donate|donate_pick_|donate_to_)",
            )
        ],
        states={
            ASK_ALLIANCE_NAME: [MessageHandler(filters.TEXT & ~filters.COMMAND, alliance_name_received)],
            ASK_BROADCAST_TEXT: [MessageHandler(filters.TEXT & ~filters.COMMAND, broadcast_text_received)],
            ASK_PRIVATE_MSG_TEXT: [MessageHandler(filters.TEXT & ~filters.COMMAND, private_msg_received)],
            ASK_HELP_TEXT: [MessageHandler(filters.TEXT & ~filters.COMMAND, help_text_received)],
        },
        fallbacks=[CommandHandler("cancel", conv_cancel)],
    )
    app.add_handler(alliance_conv)

    # ---------- دعوت کاربر: منو + Conversation ----------
    app.add_handler(CallbackQueryHandler(invite_entry, pattern="^menu_invite$"))

    invite_conv = ConversationHandler(
        entry_points=[CallbackQueryHandler(invite_router, pattern="^inv_(redeem|getcode)$")],
        states={
            ASK_REFERRAL_CODE: [MessageHandler(filters.TEXT & ~filters.COMMAND, referral_code_received)],
        },
        fallbacks=[CommandHandler("cancel", conv_cancel)],
    )
    app.add_handler(invite_conv)

    # ---------- متن‌های بازار در پیوی (فروش ..., B<code>) ----------
    app.add_handler(
        MessageHandler(filters.TEXT & ~filters.COMMAND & filters.ChatType.PRIVATE, text_command_router)
    )

    # ---------- گروه: دستورات ادمین (گروه جدا تا آنتی‌اسپم رو بلاک نکنه) ----------
    app.add_handler(
        MessageHandler(
            filters.TEXT & ~filters.COMMAND & (filters.ChatType.GROUP | filters.ChatType.SUPERGROUP),
            admin_group_text_router,
        ),
        group=1,
    )

    # ---------- گروه: آنتی‌اسپم (گروه جدا) ----------
    app.add_handler(
        MessageHandler(
            filters.TEXT & ~filters.COMMAND & (filters.ChatType.GROUP | filters.ChatType.SUPERGROUP),
            anti_spam_filter,
        ),
        group=2,
    )

    logger.info("ربات در حال اجراست...")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
