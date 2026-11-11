<?php

function authSessionController(): void {
    jsonSuccess(authSession());
}

function authRegisterController(): void {
    jsonSuccess(authRegister(requestData()));
}

function authVerifyOtpController(): void {
    jsonSuccess(authVerifyOtp(requestData()));
}

function authResendOtpController(): void {
    jsonSuccess(authResendOtp());
}

function authLoginController(): void {
    jsonSuccess(authLogin(requestData()));
}

function authLogoutController(): void {
    jsonSuccess(authLogout());
}
