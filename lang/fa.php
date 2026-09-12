<?php
/**
 * Persian (فارسی) string catalog. Every user-facing string in the bot
 * lives here -- see PLAN.md section 3.4 for why (keeps wording/RLM fixes
 * a one-file change). Looked up and formatted via t() in lang.php.
 *
 * Placeholders use {name} and are substituted by t($key, ['name' => ...]).
 */
return [

    // --- /start, /cancel, fallback -----------------------------------
    'start_welcome' => "👋 به دستیار نوبت‌دهی استودیوی تتو خوش آمدید!\n\n" .
        "برای درخواست نوبت جدید، دستور /book را بفرستید.\n" .
        "برای مشاهده وضعیت درخواست‌هایتان: /myappointments\n" .
        "برای لغو کاری که در حال انجام آن هستید: /cancel",

    'cancel_done' => 'باشه، لغو شد. هر وقت خواستید دوباره شروع کنید، دستور /book را بفرستید.',

    'fallback_unknown' => 'متوجه نشدم 🤔 برای درخواست نوبت، دستور /book را بفرستید.',

    // --- booking: date step --------------------------------------------
    'book_ask_date' => "بریم نوبت تتوی شما را ثبت کنیم! 🖋\n\n" .
        'لطفاً تاریخ موردنظرتان را وارد کنید (به فرمت سال/ماه/روز، مثلاً {example}):',

    'date_invalid' => 'این یک تاریخ معتبر نیست. لطفاً به فرمت سال/ماه/روز وارد کنید (مثلاً {example}).',

    'date_past' => 'این تاریخ گذشته است 🙂 لطفاً تاریخی در آینده انتخاب کنید.',

    // --- booking: time step ---------------------------------------------
    'book_ask_time' => 'بسیار خب. چه ساعتی برایتان مناسب است؟ (فرمت ۲۴ ساعته، مثلاً {example})',

    'time_invalid' => 'این ساعت معتبر نیست. لطفاً به فرمت ۲۴ ساعته وارد کنید (مثلاً {example}).',

    // --- booking: description step --------------------------------------
    'book_ask_desc' => "دریافت شد. لطفاً کمی درباره تتوی موردنظرتان توضیح دهید (سبک، اندازه، محل روی بدن).\n" .
        'اگر ترجیح می‌دهید حضوری صحبت کنید، عبارت «رد شدن» را ارسال کنید.',

    // --- booking: confirmation --------------------------------------------
    'confirm_summary' => "لطفاً درخواست خود را تأیید کنید:\n\n" .
        "📅 تاریخ: {date}\n" .
        "⏰ ساعت: {time}\n" .
        '📝 توضیحات: {desc}',

    'confirm_desc_none' => 'مشخص نشده',

    'btn_confirm' => '✅ تأیید',
    'btn_cancel_inline' => '❌ انصراف',

    'confirm_sent_client' => '✅ درخواست شما برای هنرمند ارسال شد. به‌محض بررسی، نتیجه به شما اطلاع داده می‌شود.',
    'confirm_toast' => 'برای هنرمند ارسال شد!',
    'confirm_nothing' => 'چیزی برای تأیید وجود ندارد؛ برای شروع، دستور /book را بفرستید.',

    'cancel_toast' => 'لغو شد',
    'cancel_edited' => 'درخواست نوبت لغو شد. برای شروع دوباره، دستور /book را بفرستید.',

    // --- artist notification --------------------------------------------
    'artist_notify' => "🆕 درخواست نوبت جدید (#{id})\n\n" .
        "👤 مشتری: {user}\n" .
        "📅 تاریخ: {date}\n" .
        "⏰ ساعت: {time}\n" .
        '📝 توضیحات: {desc}',

    'artist_user_anon' => 'کاربر شماره {id}',

    'btn_approve' => '✅ تأیید',
    'btn_reject' => '❌ رد',

    'artist_only' => 'فقط هنرمند می‌تواند این کار را انجام دهد.',

    // --- artist decision --------------------------------------------------
    'decision_not_found' => 'این درخواست پیدا نشد.',
    'decision_already' => 'این درخواست قبلاً {status} است.',

    'decision_toast_approved' => '✅ تأیید شد',
    'decision_toast_rejected' => '❌ رد شد',

    'decision_edited' => "درخواست #{id} — {date} ساعت {time}\nوضعیت: {decision}",

    'decision_label_approved' => '✅ تأیید شده',
    'decision_label_rejected' => '❌ رد شده',

    'client_approved' => "🎉 خبر خوب! نوبت تتوی شما تأیید شد:\n\n" .
        "📅 تاریخ: {date}\n" .
        "⏰ ساعت: {time}\n\n" .
        'منتظر دیدارتان هستیم!',

    'client_rejected' => "😔 متأسفانه زمان درخواستی‌تان رد شد:\n\n" .
        "📅 تاریخ: {date}\n" .
        "⏰ ساعت: {time}\n\n" .
        'برای انتخاب زمان دیگری، دستور /book را بفرستید.',

    // --- /myappointments ---------------------------------------------------
    'myappt_empty' => 'شما هنوز هیچ درخواست نوبتی ثبت نکرده‌اید. برای ثبت یک درخواست، دستور /book را بفرستید.',
    'myappt_header' => 'درخواست‌های اخیر شما:',
    'myappt_line' => '{emoji} {date} — ساعت {time} — {status}',

    // --- status labels (shared) --------------------------------------------
    'status_pending' => 'در انتظار تأیید',
    'status_approved' => 'تأیید شده',
    'status_rejected' => 'رد شده',
    'status_cancelled' => 'لغو شده',

];
