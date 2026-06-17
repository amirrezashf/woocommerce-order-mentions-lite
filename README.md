# WooCommerce Order Mentions Lite

Lightweight internal mentions system for private WooCommerce order notes.

## Description

WooCommerce Order Mentions Lite adds a simple internal mention system to WooCommerce private order notes.

Team members can mention each other inside private order notes by typing a username with `@`. The mentioned user receives an admin notification, can see unread mentions from the WordPress admin bar, and can review all mentions from a dedicated WooCommerce submenu.

This plugin is designed for internal support, sales, operations, fulfillment, and management teams that need faster communication inside WooCommerce order workflows.

## Features

- Mention users inside private WooCommerce order notes
- Autocomplete user suggestions while typing `@`
- Mentions only work in private order notes
- Prevents mentions inside customer notes
- Shows warning when mention is used in customer note mode
- Creates unread mention records for mentioned users
- Adds unread mentions count to the WordPress admin bar
- Shows admin notice for unread mentions
- Dedicated “My Mentions” page under WooCommerce
- Filter mentions by all, unread, and read
- Filter mentions by all time or last month
- Ajax-powered mention filters
- Marks mention as read when opened
- Links mentioned usernames inside order notes
- Supports WooCommerce HPOS order edit URLs
- Creates a custom database table using the active WordPress table prefix

## How It Works

When a private order note contains a valid username mention:

```text
@username
```

The plugin checks whether that username belongs to a user who can edit WooCommerce orders.

If the user is valid, a mention record is created for them and appears in their mentions list.

Customer notes are ignored, so mentions are only used for internal team communication.

## Mention Format

Use the WordPress username after `@`.

Examples:

```text
@support_manager
@ali.reza
@sara-ops
```

Allowed username characters:

```text
A-Z
a-z
0-9
_
.
-
```

## Access Control

Mentionable users must have permission to edit WooCommerce orders:

```php
edit_shop_orders
```

Users can only see their own mentions.

The mentions page is available under WooCommerce:

```text
WooCommerce > My Mentions
```

## Database

The plugin creates one custom database table:

```text
{table_prefix}wcoml_order_mentions
```

Examples:

```text
wp_wcoml_order_mentions
site_wcoml_order_mentions
custom_wcoml_order_mentions
```

The table prefix is automatically taken from the active WordPress database prefix.

## Stored Data

Each mention stores:

- Mentioned user ID
- Mention creator user ID
- Order ID
- Order note ID
- Note text
- Read status
- Created date and time

## Compatibility

- WooCommerce order notes
- WooCommerce private notes
- WooCommerce HPOS order edit links
- WordPress admin bar
- WordPress users and roles
- Custom WordPress database table prefixes

## Development Notes

### Main Class

```php
WCOML_Order_Mentions_Lite
```

### Main Prefix

```php
wcoml_
```

### Database Table

```php
wcoml_order_mentions
```

### Main Ajax Action

```php
wcoml_fetch_mentions
```

### Mention Processing

Mentions are captured from:

```php
woocommerce_new_order_note
wp_insert_comment
```

This helps support different WooCommerce note creation flows.

### Read Status

A mention is marked as read when the user opens its order link through the mention URL.

Query parameters used:

```text
wcoml_seen_mention
wcoml_mention_id
```

## Requirements

- WordPress
- WooCommerce
- PHP 7.4+

## Changelog

### 1.0.0

- Initial release
- Private order note mention detection
- User autocomplete for mentions
- Unread mentions admin bar badge
- Admin notice for unread mentions
- My Mentions WooCommerce submenu
- Read and unread mention filters
- Last month and all time filters
- Ajax-powered mentions list
- HPOS-compatible order edit links

---

# فارسی

سیستم سبک منشن داخلی برای یادداشت‌های خصوصی سفارشات ووکامرس.

## توضیحات

افزونه WooCommerce Order Mentions Lite امکان منشن کردن همکاران را داخل یادداشت‌های خصوصی سفارشات ووکامرس فراهم می‌کند.

کاربران می‌توانند با تایپ `@` و نام کاربری همکار، او را در یادداشت خصوصی سفارش منشن کنند. کاربر منشن‌شده اعلان مدیریتی دریافت می‌کند، تعداد منشن‌های خوانده‌نشده را در نوار مدیریت وردپرس می‌بیند و می‌تواند همه منشن‌های خود را از بخش اختصاصی زیر منوی ووکامرس مشاهده کند.

این افزونه برای تیم‌های پشتیبانی، فروش، عملیات، انبار، ارسال و مدیریت که نیاز به هماهنگی سریع داخل سفارشات ووکامرس دارند کاربردی است.

## امکانات

- منشن کاربران در یادداشت خصوصی سفارش ووکامرس
- پیشنهاد خودکار کاربران هنگام تایپ `@`
- فعال بودن منشن فقط در یادداشت خصوصی
- جلوگیری از منشن در یادداشت مشتری
- نمایش هشدار هنگام استفاده از منشن در حالت یادداشت مشتری
- ثبت منشن خوانده‌نشده برای کاربر منشن‌شده
- نمایش تعداد منشن‌های خوانده‌نشده در نوار مدیریت وردپرس
- نمایش اعلان مدیریتی برای منشن‌های خوانده‌نشده
- صفحه اختصاصی «منشن‌های من» در زیرمنوی ووکامرس
- فیلتر منشن‌ها بر اساس همه، خوانده‌نشده و خوانده‌شده
- فیلتر منشن‌ها بر اساس همه زمان‌ها یا یک ماه اخیر
- فیلترهای Ajax بدون رفرش کامل صفحه
- تغییر وضعیت منشن به خوانده‌شده پس از باز کردن لینک
- لینک شدن نام کاربران منشن‌شده داخل یادداشت سفارش
- پشتیبانی از لینک ویرایش سفارش در حالت HPOS
- ساخت جدول اختصاصی با پیشوند فعال دیتابیس وردپرس

## نحوه عملکرد

اگر داخل یادداشت خصوصی سفارش، نام کاربری با `@` نوشته شود:

```text
@username
```

افزونه بررسی می‌کند که این نام کاربری متعلق به کاربری باشد که اجازه ویرایش سفارشات ووکامرس را دارد.

در صورت معتبر بودن کاربر، یک منشن برای او ثبت می‌شود و در صفحه منشن‌های او نمایش داده خواهد شد.

یادداشت‌های مشتری نادیده گرفته می‌شوند و منشن فقط برای ارتباط داخلی تیم استفاده می‌شود.

## فرمت منشن

بعد از `@` باید نام کاربری وردپرس نوشته شود.

نمونه:

```text
@support_manager
@ali.reza
@sara-ops
```

کاراکترهای مجاز:

```text
A-Z
a-z
0-9
_
.
-
```

## سطح دسترسی

کاربران قابل منشن باید دسترسی ویرایش سفارشات ووکامرس را داشته باشند:

```php
edit_shop_orders
```

هر کاربر فقط منشن‌های خودش را مشاهده می‌کند.

صفحه منشن‌ها در مسیر زیر قرار می‌گیرد:

```text
WooCommerce > My Mentions
```

## دیتابیس

افزونه یک جدول اختصاصی می‌سازد:

```text
{table_prefix}wcoml_order_mentions
```

مثال:

```text
wp_wcoml_order_mentions
site_wcoml_order_mentions
custom_wcoml_order_mentions
```

پیشوند جدول به صورت خودکار از پیشوند فعال دیتابیس وردپرس گرفته می‌شود.

## داده‌های ذخیره‌شده

برای هر منشن این موارد ذخیره می‌شود:

- شناسه کاربر منشن‌شده
- شناسه کاربر ایجادکننده منشن
- شناسه سفارش
- شناسه یادداشت سفارش
- متن یادداشت
- وضعیت خوانده‌شده یا خوانده‌نشده
- تاریخ و ساعت ثبت

## سازگاری

- یادداشت‌های سفارش ووکامرس
- یادداشت‌های خصوصی ووکامرس
- لینک ویرایش سفارش در حالت HPOS
- نوار مدیریت وردپرس
- کاربران و نقش‌های وردپرس
- پیشوند سفارشی جدول‌های دیتابیس وردپرس

## نکات توسعه

### کلاس اصلی

```php
WCOML_Order_Mentions_Lite
```

### پیشوند اصلی

```php
wcoml_
```

### جدول دیتابیس

```php
wcoml_order_mentions
```

### اکشن Ajax اصلی

```php
wcoml_fetch_mentions
```

### پردازش منشن‌ها

منشن‌ها از این مسیرها دریافت می‌شوند:

```php
woocommerce_new_order_note
wp_insert_comment
```

این کار باعث می‌شود افزونه با روش‌های مختلف ثبت یادداشت سفارش در ووکامرس سازگارتر باشد.

### وضعیت خوانده‌شده

وقتی کاربر لینک منشن را باز کند، وضعیت آن منشن خوانده‌شده می‌شود.

پارامترهای استفاده‌شده:

```text
wcoml_seen_mention
wcoml_mention_id
```

## پیش‌نیازها

- وردپرس
- ووکامرس
- PHP 7.4 یا بالاتر

## تغییرات

### 1.0.0

- انتشار اولیه
- تشخیص منشن در یادداشت خصوصی سفارش
- پیشنهاد خودکار کاربران هنگام منشن
- نمایش تعداد منشن‌های خوانده‌نشده در نوار مدیریت
- اعلان مدیریتی برای منشن‌های خوانده‌نشده
- صفحه اختصاصی منشن‌های من در ووکامرس
- فیلتر منشن‌های خوانده‌شده و خوانده‌نشده
- فیلتر همه زمان‌ها و یک ماه اخیر
- لیست Ajax منشن‌ها
- لینک ویرایش سفارش سازگار با HPOS

## Author

Amirreza Shayesteh Far  
https://amirrezaa.ir/
