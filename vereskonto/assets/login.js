jQuery(function ($) {

    let sending = false;
    let countdown = null;

    /*
    |--------------------------------------------------------------------------
    | نمایش پیام
    |--------------------------------------------------------------------------
    */

    function vklMessage(message, type) {

        let box = $(".vkl-message");

        box.removeClass("vkl-success vkl-error");

        box.addClass(type);

        box.html(message);

        box.stop(true, true).fadeIn(200);

    }

    /*
    |--------------------------------------------------------------------------
    | شمارش معکوس
    |--------------------------------------------------------------------------
    */

    function startCountdown(seconds) {

    localStorage.setItem(
        "vkl_countdown_end",
        Date.now() + (seconds * 1000)
    );

    runCountdown();

    }

    function runCountdown() {

        clearInterval(countdown);

            $(".vkl-timer").show();

            $("#vkl_resend").hide();

        countdown = setInterval(function () {

        let end = parseInt(
            localStorage.getItem("vkl_countdown_end")
        );

        if (!end) {

            clearInterval(countdown);

            $(".vkl-timer").hide();

            $("#vkl_resend").fadeIn();

            return;

        }

        let remain = Math.ceil(
            (end - Date.now()) / 1000
        );

        if (remain <= 0) {

            clearInterval(countdown);

            localStorage.removeItem(
                "vkl_countdown_end"
            );

            $(".vkl-timer").hide();

            $("#vkl_resend").fadeIn();

            return;

            }

            $("#vkl_countdown").text(remain);

        },1000);

    }

    /*
    |--------------------------------------------------------------------------
    | ارسال کد
    |--------------------------------------------------------------------------
    */

    $("#vkl_send").on("click", function (e) {

        e.preventDefault();

        if (sending) {
            return;
        }

        let mobile = $("#vkl_mobile").val().trim();

        // یک ورودی، دو راه: شماره همراه یا رایانامه
        let isEmail = mobile.indexOf("@") !== -1;
        let okMobile = /^09\d{9}$/.test(mobile);
        let okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(mobile);

        if (!okMobile && !okEmail) {

            vklMessage(
            isEmail ? "نشانی رایانامه درست نیست." : "شماره همراه درست نیست.",
            "vkl-error"
            );

        return;

    }

        sending = true;

        let btn = $(this);

        btn.prop("disabled", true).text("در حال فرستادن...");

        $.ajax({

            url: vkl.ajax,

            type: "POST",

            dataType: "json",

            // Mobile networks here are slow enough that the browser default
            // cut the request off before the gateway had answered.
            timeout: 45000,

            data: {

                action: "vkl_send",

                nonce: vkl.nonce,

                mobile: $("#vkl_mobile").val()

            }

        })

        .done(function (response) {

            if (response.success) {

                vklMessage(response.data.message, "vkl-success");

                $(".vkl-step1").hide();

                // Show what the code went to, so a mistyped digit is
                // visible instead of leaving the customer waiting for an
                // SMS that was never going to arrive.
                $("#vkl_sent_to").text($("#vkl_mobile").val());

                $(".vkl-step2").fadeIn(200, function () {

                $("#vkl_otp").trigger("focus");

                });

                startCountdown(parseInt(vkl.expire, 10) || 120);
            } else {

                vklMessage(response.data.message, "vkl-error");

                // The text message failed: keep the customer on the first
                // step and point them at the email field so the code can
                // reach them another way.
                if (response.data && response.data.need_email) {

                    $(".vkl-step1").show();
                    $(".vkl-step2").hide();

                    var $id = $("#vkl_mobile");

                    $id.val("").attr("type", "text")
                       .attr("inputmode", "email")
                       .attr("placeholder", "name@example.com")
                       .trigger("focus");

                }

            }

        })

        .fail(function (xhr, status) {

            // The request may well have reached the server even though the
            // reply did not come back, so tell the customer their code is
            // still good rather than leaving them guessing.
            vklMessage(
                status === "timeout"
                    ? "پاسخ سرور دیر شد. گذرشماره شما هنوز معتبر است — دوباره «تأیید» را بزنید."
                    : "ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید؛ گذرشماره شما هنوز معتبر است.",
                "vkl-error"
            );

        })

        .always(function () {

            sending = false;

            btn.prop("disabled", false).text("دریافت کد");

        });

    });

    /*
    |--------------------------------------------------------------------------
    | تایید کد
    |--------------------------------------------------------------------------
    */

    $("#vkl_verify").on("click", function (e) {

        e.preventDefault();

        let btn = $(this);

        btn.prop("disabled", true).text("در حال بررسی...");

        $.ajax({

            url: vkl.ajax,

            type: "POST",

            dataType: "json",

            timeout: 45000,

            data: {

                action: "vkl_verify",

                nonce: vkl.nonce,

                mobile: $("#vkl_mobile").val(),

                otp: $("#vkl_otp").val()

            }

        })

        .done(function (response) {

            if (response.success) {

                vklMessage(response.data.message, "vkl-success");

                setTimeout(function () {

                    window.location.href = response.data.redirect;

                }, 500);

            } else {

                vklMessage(response.data.message, "vkl-error");

            }

        })

        .fail(function (xhr, status) {

            // The request may well have reached the server even though the
            // reply did not come back, so tell the customer their code is
            // still good rather than leaving them guessing.
            vklMessage(
                status === "timeout"
                    ? "پاسخ سرور دیر شد. گذرشماره شما هنوز معتبر است — دوباره «تأیید» را بزنید."
                    : "ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید؛ گذرشماره شما هنوز معتبر است.",
                "vkl-error"
            );

        })

        .always(function () {

            btn.prop("disabled", false).text("ورود");

        });

    });


/*
|--------------------------------------------------------------------------
| ادامه تایمر بعد از Refresh
|--------------------------------------------------------------------------
*/

    if (localStorage.getItem("vkl_countdown_end")) {

        $(".vkl-step1").hide();

        $(".vkl-step2").show();

    runCountdown();

    }

/*
|--------------------------------------------------------------------------
| ورود با کلید Enter
|--------------------------------------------------------------------------
*/

    $("#vkl_mobile").on("keypress", function (e) {

        if (e.which === 13) {

            $("#vkl_send").trigger("click");

        }

        });

            $("#vkl_otp").on("keypress", function (e) {

        if (e.which === 13) {

            $("#vkl_verify").trigger("click");

        }

    });

/*
|--------------------------------------------------------------------------
| ارسال مجدد
|--------------------------------------------------------------------------
*/
    $("#vkl_resend").on("click", function (e) {

        e.preventDefault();

        $("#vkl_send").trigger("click");

    });

    // Back to step one with the number still in the field, ready to edit.
    $(document).on("click", "#vkl_change_id", function () {

        $(".vkl-step2").hide();

        $(".vkl-message").empty().removeClass("vkl-success vkl-error");

        $("#vkl_otp").val("");

        $(".vkl-step1").fadeIn(200, function () {

            $("#vkl_mobile").trigger("focus").trigger("select");

        });

    });

});