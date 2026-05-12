<?php

declare(strict_types=1);

/*
 * Arabic translations of auth_messages keys. Mirror the EN file structure
 * exactly so a future pass can swap __('auth_messages.X') without missing
 * keys per locale.
 */
return [
    // Error codes (mirror AuthErrorCode enum)
    'invalid_credentials' => 'رقم الهاتف أو كلمة المرور غير صحيحة.',
    'phone_not_verified' => 'يرجى التحقق من رقم هاتفك قبل تسجيل الدخول.',
    'too_many_attempts' => 'محاولات كثيرة جداً. حاول مرة أخرى بعد :seconds ثانية.',
    'otp_invalid' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
    'otp_expired' => 'انتهت صلاحية رمز التحقق. اطلب رمزاً جديداً.',
    'reset_token_invalid' => 'رمز إعادة التعيين غير صحيح أو منتهي الصلاحية.',
    'verification_link_invalid' => 'رابط التحقق غير صحيح أو منتهي الصلاحية.',
    'email_not_verified' => 'البريد الإلكتروني غير مؤكد.',
    'already_verified' => 'تم التحقق مسبقاً.',
    'no_pending_registration' => 'لا يوجد تسجيل قيد الانتظار لهذا الرقم.',
    'no_email_on_file' => 'لا يوجد بريد إلكتروني محفوظ. أضف واحداً في ملفك الشخصي أولاً.',

    // Successes & informational
    'otp_sent' => 'تم إرسال رمز التحقق.',
    'phone_verified' => 'تم التحقق من رقم الهاتف. يمكنك الآن تسجيل الدخول.',
    'email_verified' => 'تم التحقق من البريد الإلكتروني.',
    'verification_link_sent' => 'تم إرسال رابط التحقق.',
    'forgot_generic' => 'إذا كان الحساب موجوداً، فقد تم إرسال التعليمات.',
    'registration_pending_otp' => 'تم إرسال رمز التحقق إلى هاتفك. أكد لتفعيل حسابك.',
];
