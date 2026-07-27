<?php
/**
 * File Name: index.php
 * Description: Hardened login portal with primary Microsoft authentication and school branding.
 * Version: 2.0
 * UK English spelling and formal presentation maintained.
 */

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; img-src 'self' data: upload.wikimedia.org wikimedia.org; font-src 'self' cdnjs.cloudflare.com;");
header('Content-Type: text/html; charset=utf-8');

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

// Include db.php to initiate the global session correctly
include 'db.php';

if (isset($_SESSION['user_id'])) {
    $target = (strpos($_SESSION['role'], 'student') !== false) ? "student_view.php?p=8ab44051" : "staff_landing.php";
    header("Location: $target");
    exit();
}

$tenantId = "3df55413-ced7-4b48-8f6e-30bc4dac254f";
$clientId = "eb393a58-2841-4188-9e8e-0dd26026b2e6";
$redirectUri = "https://www.qmhsportal.co.uk/auth_handler.php";
$msLoginUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize?client_id=$clientId&response_type=code&redirect_uri=" . urlencode($redirectUri) . "&response_mode=query&scope=openid%20profile%20email&prompt=select_account";
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <title>Sign In | QMHS Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        * { border-radius: 0 !important; }
        .brand-header { background-color: #005a36; color: #ffffff; }
        .login-card { background: white; border-top: 4px solid #005a36; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); }
        .ms-button { background-color: #005a36; color: #fff; transition: all 0.2s; border: none; }
        .ms-button:hover { background-color: #004428; }
        .logo-placeholder { display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; background-color: #005a36; color: white; font-size: 28px; }
        .school-link { color: #005a36; text-decoration: underline; font-weight: 600; }
        .school-link:hover { color: #004428; }
        .cookie-banner { background-color: #ffffff; border-top: 3px solid #005a36; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .btn-accept { background-color: #005a36; color: white; font-weight: bold; }
        .btn-accept:hover { background-color: #004428; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between text-slate-800">

    <!-- Top Branding Header Bar -->
    <div class="brand-header w-full py-3 px-6 text-sm font-semibold tracking-wide shadow-sm">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <span>Queen Mary's High School Portal</span>
            <a href="https://qmhs.org.uk" target="_blank" class="text-white hover:underline text-xs">Official School Website <i class="fas fa-external-link-alt ml-1"></i></a>
        </div>
    </div>

    <!-- Main Content Container -->
    <div class="flex-grow flex items-center justify-center p-6 my-8">
        <div class="w-full max-w-md">

            <div class="login-card p-8 text-slate-800">
                <div class="text-center mb-6">
                    <div class="mb-4 flex justify-center">
                        <?php if (file_exists('school-logo.png')): ?>
                            <img src="school-logo.png" alt="QMHS Logo" class="h-16 w-auto">
                        <?php else: ?>
                            <div class="logo-placeholder"><i class="fas fa-school"></i></div>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">QMHS Portal</h1>
                    <div class="w-12 h-1 bg-[#005a36] mx-auto my-3"></div>

                    <div class="text-sm text-slate-600 mt-4 text-left space-y-3 px-1">
                        <p class="font-semibold text-slate-800 text-center mb-4">
                            Welcome to the QMHS Digital Portal
                        </p>
                        <p class="text-xs leading-relaxed text-slate-500 mb-4 text-center">
                            This is the hub for the Queen Mary's High School digital community.
                        </p>
                        <ul class="space-y-2.5 text-xs border-t border-slate-100 pt-4">
                            <li class="flex items-start gap-2">
                                <i class="fas fa-user-shield text-[#005a36] mt-0.5 shrink-0"></i>
                                <span><strong class="text-slate-800">Staff &amp; Students:</strong> Please log in below to access your secure resources and dashboards.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-info-circle text-slate-400 mt-0.5 shrink-0"></i>
                                <span><strong class="text-slate-800">External Visitors:</strong> For general enquiries and school information, please redirect to the <a href="https://qmhs.org.uk" target="_blank" class="text-[#005a36] hover:underline font-semibold inline-flex items-center gap-0.5">main school website<i class="fas fa-external-link-alt text-[9px]"></i></a>.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div id="error-box" class="hidden mb-6 p-4 bg-rose-50 text-rose-700 border-l-4 border-rose-500">
                    <p id="error-message" class="text-xs font-bold uppercase tracking-tight"></p>
                    <p id="error-details" class="text-[10px] mt-1 opacity-70"></p>
                </div>

                <!-- Primary Microsoft Authentication -->
                <div class="my-6">
                    <a href="<?= $msLoginUrl ?>" class="w-full ms-button py-3.5 flex items-center justify-center gap-3 font-bold text-sm tracking-wide shadow-md no-underline">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 21 21"><path fill="#f25022" d="M0 0h10v10H0z"/><path fill="#7fba00" d="M11 0h10v10H11z"/><path fill="#00a4ef" d="M0 11h10v10H0z"/><path fill="#ffb900" d="M11 11h10v10H11z"/></svg>
                        Sign in with Microsoft Account
                    </a>
                </div>

                <div class="text-center border-t border-slate-100 pt-4 mt-6">
                    <p class="text-xs text-slate-500">
                        For institutional deployment verification. Please use your standard school credentials.
                    </p>
                </div>
            </div>

        </div>
    </div>

    <!-- Institutional Footer Style Layout -->
    <footer class="bg-slate-900 text-slate-300 text-xs py-8 px-6 border-t-4 border-[#005a36]">
        <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
            <div>
                <h3 class="text-white font-bold mb-2 uppercase tracking-wider text-sm">Queen Mary's High School</h3>
                <p class="opacity-80 leading-relaxed">
                    UPPER FORSTER STREET, Walsall<br>
                    West Midlands, WS4 2AE
                </p>
                <p class="mt-3">
                    <span class="font-semibold text-white">W:</span> <a href="https://qmhs.org.uk" target="_blank" class="school-link text-emerald-400">qmhs.org.uk</a>
                </p>
            </div>
            <div class="md:text-right">
                <h3 class="text-white font-bold mb-2 uppercase tracking-wider text-sm">Contact Information</h3>
                <p class="opacity-80">
                    <span class="font-semibold text-white">T:</span> 01922 721013<br>
                    <span class="font-semibold text-white">E:</span> qmarys@qmhs.merciantrust.org.uk
                </p>
                <p class="text-[10px] text-slate-500 mt-4">
                    &copy; <?= date('Y') ?> Queen Mary's High School. All rights reserved.
                </p>
            </div>
        </div>
    </footer>

    <!-- Cookie Notice Banner Component -->
    <div id="cookie-notice" class="cookie-banner fixed bottom-0 left-0 w-full p-4 z-50 hidden">
        <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-xs text-slate-700 leading-relaxed">
                <p>
                    We use necessary cookies to optimise your authentication experience and secure our platform resources. By continuing to use the portal, you agree to our standard organisational cookie protocols.
                </p>
            </div>
            <div class="flex gap-2 shrink-0">
                <button onclick="acceptCookies()" class="btn-accept px-5 py-2 text-xs uppercase tracking-wider">
                    Accept
                </button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            // Error handling mechanisms
            const urlParams = new URLSearchParams(window.location.search);
            const errorMessages = {
                'user_not_found': 'Invalid username or password.',
                'password_mismatch': 'Invalid username or password.',
                'duplicate_account': 'Multiple accounts detected.',
                'ms_failed': 'Microsoft authentication failed.',
                'unauthorised': 'Unauthorised access.',
                'invalid': 'Login failed. Please check your credentials.'
            };

            const code = urlParams.get('error');
            if (code && errorMessages.hasOwnProperty(code)) {
                const box = document.getElementById('error-box');
                const msg = document.getElementById('error-message');
                const det = document.getElementById('error-details');
                if (box && msg) {
                    box.classList.remove('hidden');
                    msg.textContent = errorMessages[code];
                    const details = urlParams.get('details');
                    if (details && det) det.textContent = decodeURIComponent(details);
                }
            }

            // Cookie visibility determination
            if (!localStorage.getItem('qmhs_cookies_accepted')) {
                document.getElementById('cookie-notice').classList.remove('hidden');
            }
        })();

        function acceptCookies() {
            localStorage.setItem('qmhs_cookies_accepted', 'true');
            document.getElementById('cookie-notice').classList.add('hidden');
        }
    </script>
</body>
</html>
