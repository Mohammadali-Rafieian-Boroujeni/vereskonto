<?php echo vkl_login_accent_style(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from a validated hex colour. ?>
<div class="vkl-box" dir="rtl">

    <div class="vkl-head">
        <span class="vkl-logo vkl-logo-img">
            <img src="<?php echo esc_url(vkl_login_logo_url()); ?>"
                 alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="96" height="84">
        </span>
        <p class="vkl-sub"><?php echo esc_html(vkl_login_text('subtitle')); ?></p>
    </div>

    <div class="vkl-message"></div>

    <div class="vkl-step1">
        <label class="vkl-label" for="vkl_mobile"><?php echo esc_html__('شماره همراه یا رایانامه', 'vereskala-login'); ?></label>
        <div class="vkl-input">
            <span class="vkl-icon">📱</span>
            <input
                type="text"
                inputmode="email"
                autocomplete="username"
                dir="ltr"
                maxlength="60"
                id="vkl_mobile"
                placeholder="0912 345 6789  |  name@mail.com">
        </div>
        <button id="vkl_send"><?php echo esc_html(vkl_login_text('send')); ?></button>
        <p class="vkl-hint"><?php echo esc_html(vkl_login_text('hint')); ?></p>
    </div>

    <div class="vkl-step2" style="display:none">
    <form id="vkl_otp_form" action="#" method="post" autocomplete="on" novalidate>
        <label class="vkl-label" for="vkl_otp"><?php echo esc_html__('گذرشماره فرستاده‌شده', 'vereskala-login'); ?></label>
        <div class="vkl-input">
            <span class="vkl-icon">🔑</span>
            <input
                type="text"
                inputmode="numeric"
                name="otp"
                autocomplete="one-time-code"
                dir="ltr"
                maxlength="<?php echo (int) vkl_otp_length(); ?>"
                id="vkl_otp"
                placeholder="<?php echo esc_attr(trim(str_repeat('_ ', vkl_otp_length()))); ?>">
            <button type="button" id="vkl_paste" class="vkl-paste" title="<?php echo esc_attr__('جای‌گذاری گذرشماره', 'vereskala-login'); ?>">📋 <?php echo esc_html__('جای‌گذاری', 'vereskala-login'); ?></button>
        </div>

        <button id="vkl_verify" type="submit"><?php echo esc_html(vkl_login_text('verify')); ?></button>

        <p class="vkl-timer">
            <?php echo esc_html__('فرستادن دوباره تا', 'vereskala-login'); ?>
            <span id="vkl_countdown"><?php echo (int) vkl_otp_expire(); ?></span>
            <?php echo esc_html__('ثانیه', 'vereskala-login'); ?>
        </p>

        <button id="vkl_resend" type="button" style="display:none"><?php echo esc_html__('فرستادن دوباره گذرشماره', 'vereskala-login'); ?></button>

        <p class="vkl-sent-to" style="margin:10px 0 0;font-size:13px;color:#556">
            <?php echo esc_html__('فرستاده شد به', 'vereskala-login'); ?>
            <strong id="vkl_sent_to" dir="ltr"></strong>
            <button id="vkl_change_id" type="button"
                    style="background:none;border:0;padding:0;margin-inline-start:6px;color:#157f3a;text-decoration:underline;cursor:pointer;font:inherit">
                <?php echo esc_html__('ویرایش', 'vereskala-login'); ?>
            </button>
        </p>
    </form>
    </div>

    <p class="vkl-foot"><?php echo esc_html(vkl_login_text('footer')); ?></p>
</div>


<script>
(function () {
    var otp    = document.getElementById('vkl_otp');
    var form   = document.getElementById('vkl_otp_form');
    var verify = document.getElementById('vkl_verify');
    var paste  = document.getElementById('vkl_paste');
    var step2  = document.querySelector('.vkl-step2');
    var CODE_LEN = <?php echo (int) vkl_otp_length(); ?>;
    var lastSubmit = 0;
    var ac = null;

    if (!otp) { return; }

    // Persian / Arabic-Indic digits → Latin; a code typed or inserted by
    // the keyboard in the SMS's own digits would otherwise never match.
    function latin(s) {
        return String(s || '')
            .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
            .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
    }

    // A pasted/delivered text may be the whole SMS: prefer a standalone
    // CODE_LEN-digit group over gluing every digit of the message together.
    function extractCode(text) {
        var s = latin(text);
        var m = s.match(new RegExp('(?:^|[^0-9])([0-9]{' + CODE_LEN + '})(?:[^0-9]|$)'));
        if (m) { return m[1]; }
        return s.replace(/\D/g, '').slice(0, CODE_LEN);
    }

    // Set the value the way a user's typing would, so the page's own
    // jQuery/handlers (and any framework wrapper) all see it.
    function setValue(code) {
        var setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        if (setter && setter.set) { setter.set.call(otp, code); } else { otp.value = code; }
        otp.dispatchEvent(new Event('input', { bubbles: true }));
        otp.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Verify at most once per couple of seconds: Chrome's own auto-submit,
    // the input listener and the Web OTP callback can all fire together.
    function submitOnce() {
        var now = Date.now();
        if (now - lastSubmit < 2500) { return; }
        lastSubmit = now;
        if (verify) { verify.click(); }
    }

    function fill(text) {
        var code = extractCode(text);
        if (!code) { return false; }
        setValue(code);
        otp.focus();
        if (code.length === CODE_LEN) { submitOnce(); }
        return true;
    }

    // Typing, keyboard "from Messages" suggestions and autofill all land
    // here: clean the value up and verify as soon as it is complete.
    otp.addEventListener('input', function () {
        var clean = latin(otp.value).replace(/\D/g, '').slice(0, CODE_LEN);
        if (clean !== otp.value) { otp.value = clean; }
        if (clean.length === CODE_LEN) { submitOnce(); }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitOnce();
        });
    }

    // Web OTP API. It is requested only once the code field is actually
    // on screen — started at page load (the old way) the request sat
    // outstanding before any SMS had been asked for and was dropped when
    // the tab lost focus while the customer switched to Messages.
    function startWebOtp() {
        if (!('OTPCredential' in window) || !navigator.credentials) { return; }
        if (!step2 || step2.offsetParent === null) { return; }
        if (ac) { ac.abort(); }
        ac = new AbortController();
        var mine = ac;
        navigator.credentials.get({ otp: { transport: ['sms'] }, signal: mine.signal })
            .then(function (cred) {
                if (cred && cred.code) {
                    fill(cred.code);
                }
            })
            .catch(function () { /* declined, aborted or unsupported — typing and the paste button remain */ });
    }

    if (step2 && 'MutationObserver' in window) {
        new MutationObserver(function () {
            if (step2.offsetParent !== null) { startWebOtp(); }
        }).observe(step2, { attributes: true, attributeFilter: ['style', 'class'] });
    }

    // Back from the Messages app: re-arm the request if the browser dropped it.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !otp.value) { startWebOtp(); }
    });
    window.addEventListener('beforeunload', function () { if (ac) { ac.abort(); } });

    startWebOtp(); // Already visible (resumed countdown after a reload).

    // Manual paste: the browser shows its clipboard-permission prompt.
    if (paste) {
        paste.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.readText) {
                navigator.clipboard.readText().then(function (text) {
                    if (!fill(text)) { otp.focus(); }
                }).catch(function () { otp.focus(); });
            } else {
                otp.focus();
            }
        });
    }
})();
</script>
